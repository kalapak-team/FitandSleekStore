<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Services\RielpayApi;
use App\Services\RielpayPaymentService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class BakongPaymentController extends Controller
{
    public function __construct(
        private readonly RielpayPaymentService $rielpayPayments,
        private readonly RielpayApi $rielpayApi,
    ) {
    }

    public function create(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'integer', 'exists:orders,id'],
        ]);

        $order = Order::with('items.product')->findOrFail($data['order_id']);
        $this->ensureOrderOwner($order, $request);

        if ($order->payment_method !== 'bakong_khqr') {
            return response()->json(['message' => 'Order payment method is not Bakong KHQR.'], 422);
        }

        if ($order->payment_status === 'paid') {
            return response()->json(['message' => 'Order already paid.'], 409);
        }

        Payment::where('order_id', $order->id)
            ->khqrProvider()
            ->where('status', 'pending')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update(['status' => 'expired']);

        $activePayment = Payment::where('order_id', $order->id)
            ->khqrProvider()
            ->where('status', 'pending')
            ->whereNotNull('qr_string')
            ->whereNotNull('md5')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->latest('id')
            ->first();

        if ($activePayment) {
            return response()->json($this->formatPaymentResponse($activePayment));
        }

        $payment = Payment::where('order_id', $order->id)
            ->khqrProvider()
            ->whereIn('status', ['pending', 'failed'])
            ->latest('id')
            ->first();

        if ($payment) {
            $payment->update([
                'status' => 'pending',
                'amount' => $order->total,
                'currency' => config('services.rielpay.currency', 'KHR'),
                'provider' => 'rielpay',
                'bill_number' => null,
                'md5' => null,
                'qr_string' => null,
                'khqr_payload' => null,
                'qr_image_base64' => null,
                'expires_at' => null,
                'paid_at' => null,
            ]);
        } else {
            $payment = Payment::create([
                'order_id' => $order->id,
                'provider' => 'rielpay',
                'method' => 'bakong_khqr',
                'status' => 'pending',
                'amount' => $order->total,
                'currency' => config('services.rielpay.currency', 'KHR'),
            ]);
        }

        try {
            $result = $this->rielpayPayments->generate($order, $payment);
        } catch (\Throwable $e) {
            report($e);
            $payment->update(['status' => 'failed']);

            $response = [
                'message' => $e->getMessage() ?: 'Unable to create KHQR payment. Please try again.',
                'detail' => $e->getMessage(),
            ];

            if (config('app.debug')) {
                $response['exception'] = $e::class;
            }

            return response()->json($response, 500);
        }

        try {
            $this->persistKhqrResult($payment, $result);
        } catch (UniqueConstraintViolationException $e) {
            if (! str_contains($e->getMessage(), 'bill_number')) {
                throw $e;
            }

            $payment->update(['bill_number' => null]);
            $result = $this->rielpayPayments->generate($order, $payment->fresh());
            $this->persistKhqrResult($payment, $result);
        }

        return response()->json($this->formatPaymentResponse($payment->fresh()));
    }

    public function status(Payment $payment, Request $request): JsonResponse
    {
        $payment->load('order.items.product');
        $order = $payment->order;

        if (! $order) {
            return response()->json(['message' => 'Payment order not found'], 404);
        }

        $this->ensureOrderOwner($order, $request);

        if ($payment->status === 'paid' || $order->payment_status === 'paid') {
            return response()->json(array_merge(
                $this->formatPaymentResponse($payment),
                ['status' => 'paid']
            ));
        }

        if (empty($payment->md5)) {
            try {
                $result = $this->rielpayPayments->generate($order, $payment);
                $this->persistKhqrResult($payment, $result);

                return response()->json($this->formatPaymentResponse($payment->fresh()));
            } catch (\Throwable $e) {
                report($e);

                return response()->json([
                    'status' => 'pending',
                    'message' => 'Unable to refresh KHQR, please try again.',
                ], 500);
            }
        }

        try {
            $payment = $this->rielpayPayments->refreshStatus($payment, $order);
        } catch (\Throwable $e) {
            Log::warning('Rielpay status check failed', [
                'payment_id' => $payment->id,
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            $payment->refresh();
            $order->refresh();

            if ($payment->status === 'paid' || $order->payment_status === 'paid') {
                return response()->json(array_merge(
                    $this->formatPaymentResponse($payment),
                    ['status' => 'paid']
                ));
            }

            return response()->json(array_merge(
                $this->formatPaymentResponse($payment),
                [
                    'status' => 'pending',
                    'message' => 'Waiting for payment confirmation. Keep this screen open after you pay in your banking app.',
                ]
            ));
        }

        $order->refresh();

        if ($payment->status === 'paid' || $order->payment_status === 'paid') {
            return response()->json(array_merge(
                $this->formatPaymentResponse($payment),
                ['status' => 'paid']
            ));
        }

        return response()->json(array_merge(
            $this->formatPaymentResponse($payment),
            ['status' => $payment->status]
        ));
    }

    public function deeplink(Payment $payment, Request $request): JsonResponse
    {
        $payment->load('order');
        $order = $payment->order;

        if (! $order) {
            return response()->json(['message' => 'Payment order not found'], 404);
        }

        $this->ensureOrderOwner($order, $request);

        if ($order->payment_status === 'paid' || $payment->status === 'paid') {
            return response()->json(['message' => 'Order already paid.'], 409);
        }

        if (blank($payment->qr_string)) {
            return response()->json(['message' => 'QR payment is not ready yet.'], 422);
        }

        try {
            $shortLink = $this->rielpayApi->generateDeeplink((string) $payment->qr_string);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => $e->getMessage() ?: 'Unable to generate payment deeplink.',
            ], 500);
        }

        return response()->json([
            'payment_id' => $payment->id,
            'short_link' => $shortLink,
        ]);
    }

    private function ensureOrderOwner(Order $order, Request $request): void
    {
        $userId = $request->user()?->id;

        if ($order->user_id !== $userId) {
            abort(403, 'Unauthorized');
        }
    }

    /**
     * @param  array{bill_number: string, md5: string, payload: array, qr_string: string, expires_at: \Illuminate\Support\Carbon}  $result
     */
    private function persistKhqrResult(Payment $payment, array $result): void
    {
        $payment->update([
            'provider' => 'rielpay',
            'bill_number' => $result['bill_number'],
            'md5' => $result['md5'],
            'khqr_payload' => $result['payload'],
            'qr_string' => $result['qr_string'],
            'qr_image_base64' => null,
            'expires_at' => $result['expires_at'],
            'raw_response' => $result['payload'],
        ]);
    }

    private function formatPaymentResponse(Payment $payment): array
    {
        return [
            'payment_id' => $payment->id,
            'status' => $payment->status,
            'bill_number' => $payment->bill_number,
            'qr_string' => $payment->qr_string,
            'md5' => $payment->md5,
            'expires_at' => optional($payment->expires_at)->toIso8601String(),
            'amount' => $payment->amount,
            'currency' => $payment->currency,
        ];
    }
}
