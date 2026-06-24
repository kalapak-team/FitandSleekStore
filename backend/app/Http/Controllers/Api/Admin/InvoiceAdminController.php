<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\InvoiceService;
use Barryvdh\DomPDF\Facade\Pdf;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\Request;
use App\Support\BrandLogo;

class InvoiceAdminController extends Controller
{
    public function show(Order $order, InvoiceService $invoiceService)
    {
        $invoice = $invoiceService->buildOrderInvoiceData($order);

        return response()->json([
            'message' => 'Invoice retrieved successfully',
            'data' => $invoice,
        ]);
    }

    public function downloadPdf(Order $order, InvoiceService $invoiceService)
    {
        $invoice = $invoiceService->buildOrderInvoiceData($order);
        $invoice['qr_data_uri'] = $this->buildQrDataUri($invoice['qr_payload'] ?? $invoice['driver_scan_url']);
        $invoice['logo_url'] = BrandLogo::url();

        $pdf = Pdf::loadView('invoices.bulk', [
            'invoices' => [$invoice],
            'isBulk' => false,
        ])
            ->setOptions([
                'isRemoteEnabled' => true,
                'isHtml5ParserEnabled' => true,
                'defaultFont' => 'DejaVu Sans',
            ])
            ->setHttpContext(stream_context_create([
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                ],
            ]))
            ->setPaper('A4', 'portrait');

        $filename = 'invoice-' . ($invoice['order_number'] ?: $order->id) . '.pdf';
        $content = $pdf->output();

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    public function bulkDownloadPdf(Request $request, InvoiceService $invoiceService)
    {
        $validated = $request->validate([
            'order_ids' => ['required', 'array', 'min:1'],
            'order_ids.*' => ['integer', 'exists:orders,id'],
        ]);

        $orderIds = collect($validated['order_ids'])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $orders = Order::with(['user', 'items.product', 'shipment'])
            ->whereIn('id', $orderIds)
            ->orderByDesc('id')
            ->get();

        $invoices = $orders->map(function (Order $order) use ($invoiceService) {
            $invoice = $invoiceService->buildOrderInvoiceData($order);
            $invoice['qr_data_uri'] = $this->buildQrDataUri($invoice['qr_payload'] ?? $invoice['driver_scan_url']);
            $invoice['logo_url'] = BrandLogo::url();
            return $invoice;
        })->values()->all();

        $pdf = Pdf::loadView('invoices.bulk', [
            'invoices' => $invoices,
            'isBulk' => true,
        ])
            ->setOptions([
                'isRemoteEnabled' => true,
                'isHtml5ParserEnabled' => true,
                'defaultFont' => 'DejaVu Sans',
            ])
            ->setHttpContext(stream_context_create([
                'ssl' => [
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                    'allow_self_signed' => false,
                ],
            ]))
            ->setPaper('A4', 'portrait');

        $filename = 'invoices-bulk-' . now()->format('Ymd_His') . '.pdf';
        $content = $pdf->output();

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    private function buildQrDataUri(?string $url): ?string
    {
        if (empty($url)) {
            return null;
        }

        $writer = new PngWriter();
        $qrCode = QrCode::create($url)
            ->setEncoding(new Encoding('UTF-8'))
            ->setSize(260)
            ->setMargin(10)
            ->setForegroundColor(new Color(0, 0, 0))
            ->setBackgroundColor(new Color(255, 255, 255));

        $result = $writer->write($qrCode);

        return 'data:' . $result->getMimeType() . ';base64,' . base64_encode($result->getString());
    }
}
