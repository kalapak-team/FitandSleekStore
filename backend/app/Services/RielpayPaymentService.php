<?php

namespace App\Services;

use App\Models\Cart;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Setting;
use App\Services\PaidOrderInventory;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class RielpayPaymentService
{
    public function __construct(private readonly RielpayApi $rielpay)
    {
    }

    /**
     * @return array{bill_number: string, md5: string, qr_string: string, expires_at: Carbon, payload: array<string, mixed>}
     */
    public function generate(Order $order, Payment $payment): array
    {
        $currency = strtoupper($payment->currency ?: (string) config('services.rielpay.currency', 'KHR'));
        $amount = $this->resolveAmount($order, $payment, $currency);

        if ($amount <= 0) {
            throw new RuntimeException(
                'Payment amount must be greater than zero. Order total: $'
                . number_format((float) ($payment->amount ?: $order->total ?: 0), 2)
                . ' USD.'
            );
        }

        $billNumber = $payment->bill_number ?: $this->makeBillNumber($order, $payment);
        $billNumber = $this->ensureUniqueBillNumber($billNumber, $payment->id);
        $billNumber = Str::limit($billNumber, 35, '');

        $payload = [
            'amount' => $amount,
            'currency' => $currency,
            'bill_number' => $billNumber,
            'metadata' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
                'payment_id' => $payment->id,
                'usd_amount' => round((float) ($payment->amount ?: $order->total ?: 0), 2),
            ],
        ];

        $response = $this->createPaymentWithBillRetry($payload, $order, $payment);

        $qrString = (string) ($response['qr_string'] ?? '');
        $md5 = (string) ($response['md5'] ?? '');
        $responseBill = (string) ($response['bill_number'] ?? $billNumber);

        if ($qrString === '' || $md5 === '') {
            throw new RuntimeException('Rielpay did not return QR data.');
        }

        $expiresAt = $this->parseExpiresAt($response['expires_at'] ?? null);

        return [
            'bill_number' => $responseBill,
            'md5' => $md5,
            'qr_string' => $qrString,
            'expires_at' => $expiresAt,
            'payload' => $response,
            'khr_amount' => $currency === 'KHR' ? (int) $amount : null,
            'usd_amount' => round((float) ($payment->amount ?: $order->total ?: 0), 2),
        ];
    }

    public function getExchangeRate(): int
    {
        $rate = (int) (Setting::where('key', 'usd_khr_exchange_rate')->value('value') ?? 4100);

        return max(1, $rate);
    }

    public function khrAmountForOrder(Order $order): int
    {
        return (int) round((float) $order->total * $this->getExchangeRate());
    }

    public function refreshStatus(Payment $payment, Order $order): Payment
    {
        if (in_array($payment->status, ['paid', 'failed', 'expired', 'cancelled'], true)) {
            return $payment;
        }

        if ($payment->expires_at && now()->greaterThan($payment->expires_at)) {
            $payment->update(['status' => 'expired']);

            return $payment->fresh();
        }

        if (blank($payment->md5)) {
            return $payment;
        }

        $response = $this->rielpay->checkPayment((string) $payment->md5);
        $status = strtolower((string) ($response['status'] ?? 'pending'));

        if ($status === 'paid') {
            $this->markPaid($payment, $order, $response);
        } elseif ($status === 'expired') {
            $payment->update([
                'status' => 'expired',
                'raw_response' => array_merge($payment->raw_response ?? [], $response),
            ]);
        } else {
            $payment->update([
                'raw_response' => array_merge($payment->raw_response ?? [], $response),
            ]);
        }

        return $payment->fresh();
    }

    /**
     * @param  array<string, mixed>  $response
     */
    public function markPaid(Payment $payment, Order $order, array $response): void
    {
        DB::transaction(function () use ($payment, $order, $response) {
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $lockedPayment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($lockedPayment->status === 'paid') {
                return;
            }

            $paidAt = $this->parsePaidAt($response['paid_at'] ?? null);

            $lockedPayment->update([
                'status' => 'paid',
                'paid_at' => $paidAt ?? now(),
                'raw_response' => array_merge($lockedPayment->raw_response ?? [], $response),
            ]);

            if ($lockedOrder->payment_status !== 'paid') {
                $lockedOrder->update([
                    'payment_status' => 'paid',
                    'status' => 'processing',
                ]);

                $this->clearUserCart((int) $lockedOrder->user_id);

                $lockedOrder->load('items.product');
                PaidOrderInventory::applyForOrder($lockedOrder);
            }
        });
    }

    private function parseExpiresAt(mixed $value): Carbon
    {
        if (filled($value)) {
            try {
                return Carbon::parse($value);
            } catch (\Throwable) {
                // fall through
            }
        }

        return now()->addSeconds((int) config('services.rielpay.expires_in', 300));
    }

    private function parsePaidAt(mixed $value): ?Carbon
    {
        if (! filled($value)) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function makeBillNumber(Order $order, ?Payment $payment = null, bool $fresh = false): string
    {
        $base = $order->order_number
            ?: 'FS-' . now()->format('Ymd') . '-' . Str::upper(Str::random(6));

        if ($payment?->id) {
            $base .= '-P' . $payment->id;
        }

        if ($fresh) {
            $base .= '-' . Str::upper(Str::random(4));
        }

        return Str::limit($base, 31, '');
    }

    private function ensureUniqueBillNumber(string $base, ?int $paymentId = null): string
    {
        $candidate = $base;

        while (
            Payment::where('bill_number', $candidate)
                ->when($paymentId, fn ($q) => $q->where('id', '!=', $paymentId))
                ->exists()
        ) {
            $candidate = Str::limit($base . '-' . Str::upper(Str::random(3)), 35, '');
        }

        return Str::limit($candidate, 35, '');
    }

    private function clearUserCart(int $userId): void
    {
        $cart = Cart::with('items')->where('user_id', $userId)->first();
        if ($cart) {
            $cart->items()->delete();
        }
    }

    private function resolveAmount(Order $order, Payment $payment, string $currency): float|int
    {
        $rawAmount = (float) ($payment->amount ?: $order->total ?: 0);

        if ($currency === 'KHR') {
            $rate = $this->getExchangeRate();

            return max(0, (int) round($rawAmount * $rate));
        }

        return round($rawAmount, 2);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function createPaymentWithBillRetry(array $payload, Order $order, Payment $payment): array
    {
        try {
            return $this->rielpay->createPayment($payload);
        } catch (RuntimeException $e) {
            if (! $this->isDuplicateBillError($e)) {
                throw $e;
            }

            $billNumber = $this->makeBillNumber($order, $payment, true);
            $billNumber = $this->ensureUniqueBillNumber($billNumber, $payment->id);
            $billNumber = Str::limit($billNumber, 35, '');
            $payload['bill_number'] = $billNumber;

            return $this->rielpay->createPayment($payload);
        }
    }

    private function isDuplicateBillError(RuntimeException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'bill')
            && (
                str_contains($message, 'taken')
                || str_contains($message, 'duplicate')
                || str_contains($message, 'already')
                || str_contains($message, 'exists')
            );
    }
}
