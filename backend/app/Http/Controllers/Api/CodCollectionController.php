<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\CodCollectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class CodCollectionController extends Controller
{
    public function __construct(
        private readonly CodCollectionService $codCollection,
    ) {
    }

    public function quote(Order $order): JsonResponse
    {
        $quote = $this->codCollection->quote($order);

        return response()->json([
            'message' => 'COD quote retrieved.',
            'data' => [
                'order_id' => $order->id,
                'payment_status' => $order->payment_status,
                'payment_method' => $order->payment_method,
                'usd_amount' => $quote['usd_amount'],
                'khr_amount' => $quote['khr_amount'],
                'exchange_rate' => $quote['exchange_rate'],
            ],
        ]);
    }

    public function collectCash(Request $request, Order $order): JsonResponse
    {
        $validated = $request->validate([
            'settlement_method' => 'required|in:cod_cash_usd,cod_cash_khr',
            'reference_code' => 'nullable|string|max:100',
            'actual_amount_received' => 'nullable|numeric|min:0',
            'courier_name' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ]);

        $payment = $this->codCollection->collectCash(
            $order,
            $validated['settlement_method'],
            (int) $request->user()->id,
            $validated['reference_code'] ?? null,
            [
                'actual_amount_received' => $validated['actual_amount_received'] ?? null,
                'courier_name' => $validated['courier_name'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ],
        );

        return response()->json([
            'message' => 'COD payment recorded.',
            'data' => [
                'payment' => $payment,
                'order' => $order->fresh(['user', 'items.product', 'payments']),
            ],
        ]);
    }

    public function createKhqr(Request $request, Order $order): JsonResponse
    {
        $validated = $request->validate([
            'courier_name' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
        ]);

        try {
            $result = $this->codCollection->createCodKhqr($order, [
                'courier_name' => $validated['courier_name'] ?? null,
                'notes' => $validated['notes'] ?? null,
            ]);

            return response()->json([
                'message' => 'COD KHQR generated.',
                'data' => $result,
            ]);
        } catch (\Throwable $e) {
            Log::error('COD KHQR create failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage() ?: 'Failed to generate COD KHQR.',
            ], 500);
        }
    }

    public function khqrStatus(Order $order): JsonResponse
    {
        try {
            $payment = $this->codCollection->refreshCodKhqr($order);

            return response()->json([
                'payment' => [
                    'id' => $payment->id,
                    'status' => $payment->status,
                    'method' => $payment->method,
                    'paid_at' => optional($payment->paid_at)->toIso8601String(),
                ],
                'order' => $order->fresh(['payments']),
            ]);
        } catch (\Throwable $e) {
            Log::error('COD KHQR status failed', [
                'order_id' => $order->id,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => $e->getMessage() ?: 'Failed to check COD KHQR status.',
            ], 500);
        }
    }
}
