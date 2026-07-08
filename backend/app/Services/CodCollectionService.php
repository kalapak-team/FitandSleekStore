<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Payment;
use App\Models\Setting;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class CodCollectionService
{
    public const CASH_SETTLEMENTS = [
        'cod_cash_usd',
        'cod_cash_khr',
    ];

    public function __construct(
        private readonly RielpayPaymentService $rielpayPayments,
    ) {
    }

    public function getExchangeRate(): int
    {
        $setting = Setting::where('key', 'usd_khr_exchange_rate')->first();
        $rate = (int) ($setting?->value ?? 4100);

        return max(1, $rate);
    }

    public function khrAmountForOrder(Order $order): int
    {
        return (int) round((float) $order->total * $this->getExchangeRate());
    }

    public function assertCollectibleCodOrder(Order $order): void
    {
        if ($order->payment_method !== 'cod') {
            throw ValidationException::withMessages([
                'order' => ['This order is not a cash-on-delivery order.'],
            ]);
        }

        if ($order->payment_status === 'paid') {
            throw ValidationException::withMessages([
                'order' => ['This order is already paid.'],
            ]);
        }
    }

    /**
     * @return array{payment: Payment, exchange_rate: int, khr_amount: int}
     */
    public function quote(Order $order): array
    {
        $this->assertCollectibleCodOrder($order);

        $payment = $this->pendingCodPayment($order);
        $exchangeRate = $this->getExchangeRate();

        return [
            'payment' => $payment,
            'exchange_rate' => $exchangeRate,
            'khr_amount' => $this->khrAmountForOrder($order),
            'usd_amount' => round((float) $order->total, 2),
        ];
    }

    /**
     * @param  array{actual_amount_received?: float|int|null, notes?: string|null, courier_name?: string|null}  $meta
     */
    public function collectCash(
        Order $order,
        string $settlementMethod,
        ?int $verifiedBy,
        ?string $referenceCode = null,
        array $meta = [],
    ): Payment {
        if (! in_array($settlementMethod, self::CASH_SETTLEMENTS, true)) {
            throw ValidationException::withMessages([
                'settlement_method' => ['Invalid cash settlement method.'],
            ]);
        }

        $this->assertCollectibleCodOrder($order);

        return DB::transaction(function () use ($order, $settlementMethod, $verifiedBy, $referenceCode, $meta) {
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $payment = $this->pendingCodPayment($lockedOrder);

            if ($lockedOrder->payment_status === 'paid') {
                return $payment->fresh();
            }

            $currency = $settlementMethod === 'cod_cash_khr' ? 'KHR' : 'USD';
            $expectedAmount = $settlementMethod === 'cod_cash_khr'
                ? $this->khrAmountForOrder($lockedOrder)
                : round((float) $lockedOrder->total, 2);
            $actualAmount = isset($meta['actual_amount_received'])
                ? ($currency === 'KHR'
                    ? (int) round((float) $meta['actual_amount_received'])
                    : round((float) $meta['actual_amount_received'], 2))
                : $expectedAmount;

            $payment->update([
                'provider' => 'cod',
                'method' => $settlementMethod,
                'amount' => $actualAmount,
                'currency' => $currency,
                'status' => 'paid',
                'paid_at' => now(),
                'verified_by' => $verifiedBy,
                'verified_at' => now(),
                'reference_code' => $referenceCode ?: strtoupper(str_replace('cod_', '', $settlementMethod)).'-'.now()->timestamp,
                'raw_response' => $this->collectionMeta(
                    channel: 'outdoor_cash',
                    channelLabel: 'លុយក្រៅ / Outdoor cash',
                    settlementMethod: $settlementMethod,
                    currency: $currency,
                    extra: [
                        'expected_amount' => $expectedAmount,
                        'actual_amount_received' => $actualAmount,
                        'courier_name' => $meta['courier_name'] ?? null,
                        'notes' => $meta['notes'] ?? null,
                    ],
                ),
            ]);

            $lockedOrder->update([
                'payment_status' => 'paid',
            ]);

            $lockedOrder->load('items.product');
            PaidOrderInventory::applyForOrder($lockedOrder);

            return $payment->fresh();
        });
    }

    /**
     * @param  array{notes?: string|null, courier_name?: string|null}  $meta
     * @return array{payment: array<string, mixed>, exchange_rate: int, khr_amount: int}
     */
    public function createCodKhqr(Order $order, array $meta = []): array
    {
        $this->assertCollectibleCodOrder($order);

        $exchangeRate = $this->getExchangeRate();
        $khrAmount = $this->khrAmountForOrder($order);

        $payment = $this->pendingCodPayment($order);
        $collectionMeta = $this->collectionMeta(
            channel: 'khqr_in_app',
            channelLabel: 'ក្នុងកុង / In-app KHQR',
            settlementMethod: 'cod_khqr',
            currency: 'KHR',
            extra: [
                'expected_amount' => $khrAmount,
                'courier_name' => $meta['courier_name'] ?? null,
                'notes' => $meta['notes'] ?? null,
            ],
        );

        $existing = Payment::where('order_id', $order->id)
            ->where('method', 'cod_khqr')
            ->khqrProvider()
            ->where('status', 'pending')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->latest('id')
            ->first();

        if ($existing && $existing->qr_string) {
            return [
                'payment' => $this->sanitizeKhqrPayment($existing),
                'exchange_rate' => $exchangeRate,
                'khr_amount' => $khrAmount,
            ];
        }

        Payment::where('order_id', $order->id)
            ->khqrProvider()
            ->where('status', 'pending')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);

        $payment->update([
            'provider' => 'rielpay',
            'method' => 'cod_khqr',
            'amount' => $khrAmount,
            'currency' => 'KHR',
            'status' => 'pending',
            'raw_response' => $collectionMeta,
        ]);

        try {
            $result = $this->rielpayPayments->generate($order, $payment->fresh());

            $payment->update([
                'bill_number' => $result['bill_number'],
                'md5' => $result['md5'],
                'khqr_payload' => $result['payload'],
                'qr_string' => $result['qr_string'],
                'expires_at' => $result['expires_at'],
                'raw_response' => array_merge($collectionMeta, $result['payload'] ?? []),
            ]);

            return [
                'payment' => $this->sanitizeKhqrPayment($payment->fresh()),
                'exchange_rate' => $exchangeRate,
                'khr_amount' => $khrAmount,
            ];
        } catch (\Throwable $e) {
            $payment->update(['status' => 'failed']);

            throw new RuntimeException($e->getMessage() ?: 'Failed to generate COD KHQR.', 0, $e);
        }
    }

    public function refreshCodKhqr(Order $order): Payment
    {
        $this->assertCollectibleCodOrder($order);

        $payment = Payment::where('order_id', $order->id)
            ->where('method', 'cod_khqr')
            ->khqrProvider()
            ->latest('id')
            ->first();

        if (! $payment) {
            throw ValidationException::withMessages([
                'payment' => ['No COD KHQR payment found for this order.'],
            ]);
        }

        if (in_array($payment->status, ['paid', 'failed', 'expired', 'cancelled'], true)) {
            return $payment;
        }

        return $this->rielpayPayments->refreshStatus($payment, $order);
    }

    private function pendingCodPayment(Order $order): Payment
    {
        $payment = Payment::where('order_id', $order->id)
            ->whereIn('status', ['pending'])
            ->latest('id')
            ->first();

        if ($payment) {
            return $payment;
        }

        return Payment::create([
            'order_id' => $order->id,
            'provider' => 'cod',
            'method' => 'cod',
            'amount' => $order->total,
            'currency' => 'USD',
            'status' => 'pending',
        ]);
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function collectionMeta(
        string $channel,
        string $channelLabel,
        string $settlementMethod,
        string $currency,
        array $extra = [],
    ): array {
        $meta = array_merge([
            'collection_channel' => $channel,
            'collection_channel_label' => $channelLabel,
            'currency' => $currency,
            'settlement_method' => $settlementMethod,
        ], $extra);

        return array_filter(
            $meta,
            static fn ($value) => $value !== null && $value !== '',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function sanitizeKhqrPayment(Payment $payment): array
    {
        return [
            'id' => $payment->id,
            'order_id' => $payment->order_id,
            'method' => $payment->method,
            'amount' => $payment->amount,
            'currency' => $payment->currency,
            'status' => $payment->status,
            'bill_number' => $payment->bill_number,
            'md5' => $payment->md5,
            'qr_string' => $payment->qr_string,
            'expires_at' => optional($payment->expires_at)->toIso8601String(),
            'paid_at' => optional($payment->paid_at)->toIso8601String(),
        ];
    }
}
