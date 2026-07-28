<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shipment;
use App\Models\ShipmentTrackingEvent;
use App\Models\TelegramOrderSubscription;
use App\Models\TelegramUser;
use App\Support\TelegramBotTexts;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelegramService
{
    public function __construct(
        private readonly OrderReceiptTokenService $receiptTokens,
        private readonly CodCollectionService $codCollection,
        private readonly RielpayPaymentService $rielpayPayments,
        private readonly InvoiceService $invoices,
    ) {
    }

    public function isEnabled(): bool
    {
        return filled(config('services.telegram.bot_token'));
    }

    public function botUsername(): ?string
    {
        $name = trim((string) config('services.telegram.bot_name', ''));

        return $name !== '' ? ltrim($name, '@') : null;
    }

    public function buildBotDeepLink(string $startPayload): ?string
    {
        $username = $this->botUsername();
        if (! $username) {
            return null;
        }

        return 'https://t.me/'.$username.'?start='.rawurlencode($startPayload);
    }

    public function buildOrderConnectLink(Order $order): ?string
    {
        $key = $order->order_number ?: (string) $order->id;

        return $this->buildBotDeepLink('order_'.$key);
    }

    public function buildAccountConnectLink(int $userId): ?string
    {
        $signature = substr(hash_hmac('sha256', (string) $userId, (string) config('app.key')), 0, 16);

        return $this->buildBotDeepLink('link_'.$userId.'_'.$signature);
    }

    public function verifyAccountLinkSignature(int $userId, string $signature): bool
    {
        $expected = substr(hash_hmac('sha256', (string) $userId, (string) config('app.key')), 0, 16);

        return hash_equals($expected, $signature);
    }

    public function notifyOrderPaid(Order $order): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $order->loadMissing(['user', 'items', 'shipment']);

        $this->notifyAdminOfNewOrder($order);
        $this->createInAppNotification(
            $order,
            'order_paid',
            'Order confirmed',
            'Your payment was received. We are preparing your order.'
        );

        $label = $order->order_number ?: '#'.$order->id;
        $total = number_format((float) $order->total, 2);

        $this->notifyOrderRecipients($order, function (string $locale) use ($order, $label, $total) {
            $message = TelegramBotTexts::get($locale, 'order_confirmed', [
                'label' => $label,
                'total' => $total,
            ]);

            return [$message, $this->buildOrderActionKeyboard($order, null, $locale)];
        });
    }

    public function notifyOrderPlacedCod(Order $order): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $order->loadMissing(['user', 'items', 'shipment']);

        $this->notifyAdminOfNewCodOrder($order);
        $this->createInAppNotification(
            $order,
            'order_placed_cod',
            'COD order placed',
            'Your COD order was placed. Pay when it is delivered.'
        );

        $label = $order->order_number ?: '#'.$order->id;
        $total = number_format((float) $order->total, 2);

        $this->notifyOrderRecipients($order, function (string $locale) use ($order, $label, $total) {
            $message = TelegramBotTexts::get($locale, 'order_placed_cod', [
                'label' => $label,
                'total' => $total,
            ]);

            return [$message, $this->buildOrderActionKeyboard($order, null, $locale)];
        });
    }

    /**
     * Customer Telegram alert for order status changes (processing, preparing, cancelled, …).
     * Skips statuses that already have dedicated shipment/payment handlers.
     */
    public function notifyOrderStatusChanged(Order $order, string $status): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $status = strtolower(trim($status));
        $skip = ['paid', 'pending_payment', 'shipped', 'delivered', 'completed', 'in_transit'];
        if (in_array($status, $skip, true)) {
            return;
        }

        $order->loadMissing(['user', 'shipment']);
        $label = $order->order_number ?: '#'.$order->id;

        $textKey = match ($status) {
            'processing', 'preparing' => 'order_processing',
            'cancelled' => 'order_cancelled',
            'failed' => 'order_failed',
            'returned' => 'order_returned',
            default => null,
        };

        if ($textKey === null) {
            return;
        }

        $this->createInAppNotification(
            $order,
            'order_status_'.$status,
            'Order update',
            'Order '.$label.' is now '.$status.'.'
        );

        $this->notifyOrderRecipients($order, function (string $locale) use ($order, $label, $textKey) {
            $message = TelegramBotTexts::get($locale, $textKey, ['label' => $label]);

            return [$message, $this->buildOrderActionKeyboard($order, null, $locale)];
        });
    }

    public function notifyShipmentStatusChanged(Shipment $shipment): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $status = strtolower(trim((string) $shipment->status));

        if ($status === 'shipped') {
            $this->notifyOrderShipped($shipment);

            return;
        }

        if ($status === 'delivered') {
            $this->notifyOrderDelivered($shipment);

            return;
        }

        $shipment->loadMissing('order.user');
        $order = $shipment->order;
        if (! $order) {
            return;
        }

        $label = $order->order_number ?: '#'.$order->id;
        $trackingCode = $shipment->tracking_code ?: '—';

        $textKey = match ($status) {
            'processing', 'pending' => 'order_processing',
            'in_transit' => 'order_in_transit',
            'failed' => 'order_failed',
            'returned' => 'order_returned',
            default => null,
        };

        if ($textKey === null) {
            return;
        }

        // Avoid duplicate noise: pending/processing are covered by order status alerts.
        if (in_array($status, ['pending', 'processing'], true)) {
            return;
        }

        $this->createInAppNotification(
            $order,
            'shipment_'.$status,
            'Shipment update',
            'Order '.$label.' shipment is now '.$status.'.'
        );

        $this->notifyOrderRecipients($order, function (string $locale) use ($order, $shipment, $label, $trackingCode, $textKey) {
            $message = TelegramBotTexts::get($locale, $textKey, [
                'label' => $label,
                'provider' => $shipment->provider ?: TelegramBotTexts::get($locale, 'courier_fallback'),
                'tracking' => $trackingCode,
            ]);

            return [$message, $this->buildOrderActionKeyboard($order, $shipment, $locale)];
        });
    }

    public function notifyAdminOfNewCodOrder(Order $order): void
    {
        $chatId = config('services.telegram.admin_chat_id');
        if (! filled($chatId)) {
            Log::warning('Telegram admin COD notify skipped: TELEGRAM_ADMIN_CHAT_ID empty');

            return;
        }

        $order->loadMissing(['user', 'items', 'shipment']);

        $sent = $this->sendMessage(
            (string) $chatId,
            $this->formatAdminOrderCardHtml($order, 'cod'),
            $this->buildAdminOrderKeyboard($order),
            'HTML'
        );

        if (! $sent) {
            Log::warning('Telegram admin COD notify failed to send', [
                'chat_id' => $chatId,
                'order_id' => $order->id,
            ]);
        }
    }

    public function buildReceiptUrl(Order $order): string
    {
        return $this->receiptTokens->buildReceiptUrl($order);
    }

    /**
     * @return array{inline_keyboard: list<list<array<string, string>>>}|null
     */
    public function buildOrderActionKeyboard(Order $order, ?Shipment $shipment = null, string $locale = TelegramBotTexts::LOCALE_EN): ?array
    {
        $order->loadMissing('shipment');
        $shipment ??= $order->shipment;
        $rows = [];

        $actionRow = [];
        $actionRow[] = [
            'text' => TelegramBotTexts::get($locale, 'btn_invoice'),
            'callback_data' => 'invoice_'.$order->id,
        ];

        if ($order->payment_status !== 'paid') {
            $actionRow[] = [
                'text' => $order->payment_method === 'cod'
                    ? TelegramBotTexts::get($locale, 'btn_payment_qr_cod')
                    : TelegramBotTexts::get($locale, 'btn_payment_qr'),
                'callback_data' => 'payqr_'.$order->id,
            ];
        }

        if ($actionRow !== []) {
            $rows[] = $actionRow;
        }

        if ($shipment && filled($shipment->external_tracking_url)) {
            $provider = $shipment->provider ?: TelegramBotTexts::get($locale, 'courier_fallback');
            $rows[] = [[
                'text' => TelegramBotTexts::get($locale, 'btn_track_on', ['provider' => $provider]),
                'url' => $shipment->external_tracking_url,
            ]];
        }

        return $rows !== [] ? ['inline_keyboard' => $rows] : null;
    }

    public function resolveLocaleForChatId(int $chatId): string
    {
        $user = TelegramUser::where('chat_id', $chatId)->latest('id')->first();

        return TelegramBotTexts::resolveLocale($user);
    }

    public function setPreferredLocale(TelegramUser $telegramUser, string $locale): TelegramUser
    {
        $locale = in_array($locale, [TelegramBotTexts::LOCALE_KM, TelegramBotTexts::LOCALE_EN], true)
            ? $locale
            : TelegramBotTexts::LOCALE_EN;

        $telegramUser->update(['preferred_locale' => $locale]);

        return $telegramUser->fresh();
    }

    /**
     * @return array{inline_keyboard: list<list<array<string, string>>>}
     */
    public function buildLanguageMenuKeyboard(): array
    {
        return [
            'inline_keyboard' => [
                [
                    ['text' => '🇰🇭 ខ្មែរ', 'callback_data' => 'lang_km'],
                    ['text' => '🇬🇧 English', 'callback_data' => 'lang_en'],
                ],
            ],
        ];
    }

    public function sendLanguageMenu(int $chatId, ?TelegramUser $telegramUser = null): void
    {
        $locale = TelegramBotTexts::resolveLocale($telegramUser);
        $this->sendMessage(
            (string) $chatId,
            TelegramBotTexts::get($locale, 'language_prompt'),
            $this->buildLanguageMenuKeyboard()
        );
    }

    public function sendInvoiceToChat(int $chatId, Order $order, ?string $locale = null): bool
    {
        $locale ??= $this->resolveLocaleForChatId($chatId);

        try {
            $order->loadMissing('shipment');
            $message = $this->formatInvoiceTelegramMessage($order, $locale);
            // Keep action buttons after viewing invoice (admin = full status keyboard).
            $keyboard = $this->keyboardForChat($chatId, $order);

            return $this->sendMessage((string) $chatId, $message, $keyboard, 'HTML');
        } catch (\Throwable $e) {
            Log::error('Telegram invoice failed', [
                'order_id' => $order->id,
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
            $this->sendMessage(
                (string) $chatId,
                TelegramBotTexts::get($locale, 'invoice_failed'),
                $this->keyboardForChat($chatId, $order)
            );

            return false;
        }
    }

    /**
     * @return array{inline_keyboard: list<list<array<string, string>>>}|null
     */
    public function keyboardForChat(int $chatId, Order $order): ?array
    {
        $order->loadMissing('shipment');

        if ($this->isAdminChatId($chatId)) {
            return $this->buildAdminOrderKeyboard($order);
        }

        return $this->buildOrderActionKeyboard($order, $order->shipment, $this->resolveLocaleForChatId($chatId));
    }

    private function formatInvoiceTelegramMessage(Order $order, string $locale): string
    {
        $order->loadMissing(['user', 'items.product', 'shipment']);
        $invoice = $this->invoices->buildOrderInvoiceData($order, InvoiceService::AUDIENCE_CUSTOMER);
        $customer = $invoice['customer'] ?? [];
        $e = fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $lines = [
            '🧾 <b>'.htmlspecialchars(TelegramBotTexts::get($locale, 'invoice_title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</b>',
            '',
            htmlspecialchars(TelegramBotTexts::get($locale, 'invoice_number'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').': <code>'.$e((string) ($invoice['invoice_number'] ?? '')).'</code>',
            htmlspecialchars(TelegramBotTexts::get($locale, 'invoice_date'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').': '.$e((string) ($invoice['invoice_date'] ?? '')),
            htmlspecialchars(TelegramBotTexts::get($locale, 'invoice_status'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').': <b>'.$e((string) ($invoice['payment_status'] ?? '')).'</b>',
            '',
            '<b>'.htmlspecialchars(TelegramBotTexts::get($locale, 'customer'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</b>',
            $e((string) ($customer['name'] ?? TelegramBotTexts::get($locale, 'guest'))),
        ];

        $phone = trim((string) ($customer['phone'] ?? ''));
        if ($phone !== '' && $phone !== '-') {
            $lines[] = $e($phone);
        }

        $address = trim((string) ($customer['full_address'] ?? ''));
        if ($address !== '' && $address !== '-') {
            $lines[] = $e($address);
        }

        $lines[] = '';
        $lines[] = '<b>'.htmlspecialchars(TelegramBotTexts::get($locale, 'items'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</b>';

        $items = $invoice['items'] ?? [];
        $maxItems = 12;
        $qtyLabel = TelegramBotTexts::get($locale, 'qty_label');
        foreach (array_slice($items, 0, $maxItems) as $item) {
            $name = $e((string) ($item['product_name'] ?? 'Item'));
            $sku = $e((string) ($item['sku'] ?? '-'));
            $qty = (int) ($item['quantity'] ?? 0);
            $total = number_format((float) ($item['grand_total'] ?? 0), 2);
            $lines[] = '• '.$name;
            $lines[] = '  <code>'.$sku.'</code> · '.$qtyLabel.' '.$qty.' — $'.$total;
        }

        $remaining = count($items) - $maxItems;
        if ($remaining > 0) {
            $lines[] = '<i>'.htmlspecialchars(
                TelegramBotTexts::get($locale, 'more_items', ['count' => $remaining, 'plural' => '']),
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            ).'</i>';
        }

        $lines[] = '';
        $lines[] = htmlspecialchars(TelegramBotTexts::get($locale, 'subtotal'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').': $'.number_format((float) ($invoice['subtotal'] ?? 0), 2);

        if ((float) ($invoice['discount'] ?? 0) > 0) {
            $lines[] = htmlspecialchars(TelegramBotTexts::get($locale, 'discount'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').': −$'.number_format((float) $invoice['discount'], 2);
        }

        $lines[] = htmlspecialchars(TelegramBotTexts::get($locale, 'shipping'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').': $'.number_format((float) ($invoice['shipping_fee'] ?? 0), 2);
        $lines[] = '<b>'.htmlspecialchars(TelegramBotTexts::get($locale, 'grand_total'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').': $'.number_format((float) ($invoice['grand_total'] ?? 0), 2).'</b>';

        $shipment = $order->shipment;
        $provider = $shipment?->provider;
        if ($provider && strtolower((string) $provider) !== 'internal') {
            $lines[] = '';
            $lines[] = htmlspecialchars(TelegramBotTexts::get($locale, 'courier'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').': '.$e((string) $provider);
        }

        if (! empty($invoice['tracking_code'])) {
            $lines[] = htmlspecialchars(TelegramBotTexts::get($locale, 'tracking_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').': <code>'.$e((string) $invoice['tracking_code']).'</code>';
        }

        if (! empty($invoice['is_cod']) && ($invoice['payment_status'] ?? '') !== 'PAID') {
            $lines[] = '';
            $lines[] = '<i>'.htmlspecialchars(TelegramBotTexts::get($locale, 'payment_cod'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</i>';
        }

        $message = implode("\n", $lines);
        if (strlen($message) > 4000) {
            $message = substr($message, 0, 3990)."\n…";
        }

        return $message;
    }

    public function canChatAccessOrder(int $chatId, Order $order, ?TelegramUser $telegramUser = null): bool
    {
        if ($this->isAdminChatId($chatId)) {
            return true;
        }

        if (TelegramOrderSubscription::where('order_id', $order->id)->where('chat_id', $chatId)->exists()) {
            return true;
        }

        if ($telegramUser?->user_id && (int) $telegramUser->user_id === (int) $order->user_id) {
            return true;
        }

        return false;
    }

    public function isAdminChatId(int $chatId): bool
    {
        $configured = trim((string) config('services.telegram.admin_chat_id', ''));
        if ($configured === '') {
            return false;
        }

        // Group/supergroup IDs are negative (e.g. -5438165888); compare as strings of ints.
        return (string) $chatId === (string) (int) $configured;
    }

    /**
     * @return array{ok: bool, message: string, customer_notified: bool}
     */
    public function updateShipmentStatusFromAdminChat(Order $order, string $status): array
    {
        $status = strtolower(trim($status));
        $allowed = ['pending', 'processing', 'shipped', 'in_transit', 'delivered', 'failed', 'returned'];
        if (! in_array($status, $allowed, true)) {
            return ['ok' => false, 'message' => 'Invalid status.', 'customer_notified' => false];
        }

        $order->loadMissing(['shipment', 'user']);
        $shipment = $order->shipment;
        if (! $shipment) {
            return ['ok' => false, 'message' => 'Shipment not found for this order.', 'customer_notified' => false];
        }

        $shippedAt = $shipment->shipped_at;
        $deliveredAt = $shipment->delivered_at;
        if (in_array($status, ['shipped', 'in_transit', 'delivered'], true) && ! $shippedAt) {
            $shippedAt = now();
        }
        if ($status === 'delivered' && ! $deliveredAt) {
            $deliveredAt = now();
        }

        $shipment->update([
            'status' => $status,
            'shipped_at' => $shippedAt,
            'delivered_at' => $deliveredAt,
        ]);

        ShipmentTrackingEvent::create([
            'shipment_id' => $shipment->id,
            'status' => $status,
            'note' => 'Status updated from Telegram admin bot.',
            'updated_by' => null,
            'event_time' => now(),
        ]);

        // Sync order status into system (web/app) immediately.
        $orderStatus = match ($status) {
            'processing' => 'processing',
            'shipped', 'in_transit' => 'shipped',
            'failed' => 'failed',
            'returned' => 'returned',
            'delivered' => null, // ShipmentObserver sets completed
            default => null,
        };

        if ($orderStatus !== null && (string) $order->status !== $orderStatus) {
            $order->update(['status' => $orderStatus]);
        }

        $customerNotified = $this->resolveOrderChatIds($order->fresh(['user']))->isNotEmpty();

        $statusKm = match ($status) {
            'processing' => 'កំពុងរៀបចំ',
            'shipped' => 'ដឹកចេញ',
            'in_transit' => 'កំពុងដឹក',
            'delivered' => 'ដឹកដល់',
            'failed' => 'បរាជ័យ',
            'returned' => 'ត្រឡប់',
            default => $status,
        };

        return [
            'ok' => true,
            'message' => "✅ {$statusKm} / ".str_replace('_', ' ', $status)
                ."\nOrder: ".($order->order_number ?: '#'.$order->id)
                ."\n".($customerNotified
                    ? '📣 Customer Telegram: notified'
                    : '⚠️ Customer Telegram: not linked — alert skipped'),
            'customer_notified' => $customerNotified,
        ];
    }

    public function sendPaymentQrToChat(int $chatId, Order $order, ?string $locale = null): bool
    {
        $locale ??= $this->resolveLocaleForChatId($chatId);

        if ($order->payment_status === 'paid') {
            $this->sendMessage(
                (string) $chatId,
                TelegramBotTexts::get($locale, 'already_paid'),
                $this->keyboardForChat($chatId, $order)
            );

            return false;
        }

        try {
            $payment = $this->resolvePaymentQrForOrder($order);
            $qrString = (string) ($payment['qr_string'] ?? '');
            if ($qrString === '') {
                $this->sendMessage(
                    (string) $chatId,
                    TelegramBotTexts::get($locale, 'payment_qr_failed'),
                    $this->keyboardForChat($chatId, $order)
                );

                return false;
            }

            $amount = $payment['amount'] ?? null;
            $currency = (string) ($payment['currency'] ?? 'KHR');
            $amountLabel = $currency === 'KHR'
                ? number_format((float) $amount).' ៛'
                : '$'.number_format((float) $amount, 2);

            $label = $order->order_number ?: '#'.$order->id;
            $codNote = $order->payment_method === 'cod'
                ? TelegramBotTexts::get($locale, 'payment_qr_cod_note')
                : TelegramBotTexts::get($locale, 'payment_qr_scan_note');

            $caption = TelegramBotTexts::get($locale, 'payment_qr_title')."\n\n"
                .TelegramBotTexts::get($locale, 'order_label').": `{$label}`\n"
                .TelegramBotTexts::get($locale, 'amount_label').": *{$amountLabel}*"
                .$codNote;

            $sent = $this->sendPhoto(
                (string) $chatId,
                $this->buildQrPngBytes($qrString),
                $caption,
                $this->keyboardForChat($chatId, $order)
            );

            // Ensure action buttons remain even if photo markup fails.
            if ($sent && $this->isAdminChatId($chatId)) {
                $this->sendMessage(
                    (string) $chatId,
                    "⬆️ KHQR ខាងលើ · បន្តចុចប៊ូតុងខាងក្រោមបាន\n⬆️ QR above · use buttons below to continue",
                    $this->buildAdminOrderKeyboard($order),
                    null
                );
            }

            return $sent;
        } catch (\Throwable $e) {
            Log::error('Telegram payment QR failed', [
                'order_id' => $order->id,
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
            $this->sendMessage(
                (string) $chatId,
                TelegramBotTexts::get($locale, 'payment_qr_failed_gen'),
                $this->keyboardForChat($chatId, $order)
            );

            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvePaymentQrForOrder(Order $order): array
    {
        if ($order->payment_method === 'cod') {
            $result = $this->codCollection->createCodKhqr($order);

            return $result['payment'];
        }

        if ($order->payment_method !== 'bakong_khqr') {
            throw new \RuntimeException('Online payment is not available for this order.');
        }

        $existing = Payment::where('order_id', $order->id)
            ->khqrProvider()
            ->where('status', 'pending')
            ->where(function ($query) {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->latest('id')
            ->first();

        if ($existing && $existing->qr_string) {
            return [
                'qr_string' => $existing->qr_string,
                'amount' => $existing->amount,
                'currency' => $existing->currency,
                'status' => $existing->status,
            ];
        }

        return DB::transaction(function () use ($order, $existing) {
            Payment::where('order_id', $order->id)
                ->khqrProvider()
                ->where('status', 'pending')
                ->whereNotNull('expires_at')
                ->where('expires_at', '<=', now())
                ->update(['status' => 'expired']);

            $payment = $existing ?: Payment::create([
                'order_id' => $order->id,
                'provider' => 'rielpay',
                'amount' => $order->total,
                'currency' => config('services.rielpay.currency', 'KHR'),
                'method' => 'bakong_khqr',
                'status' => 'pending',
            ]);

            $result = $this->rielpayPayments->generate($order, $payment);
            $payment->update([
                'provider' => 'rielpay',
                'bill_number' => $result['bill_number'],
                'md5' => $result['md5'],
                'khqr_payload' => $result['payload'],
                'qr_string' => $result['qr_string'],
                'expires_at' => $result['expires_at'],
                'raw_response' => $result['payload'],
            ]);

            $fresh = $payment->fresh();

            return [
                'qr_string' => $fresh->qr_string,
                'amount' => $fresh->amount,
                'currency' => $fresh->currency,
                'status' => $fresh->status,
            ];
        });
    }

    private function buildQrPngBytes(string $qrString): string
    {
        $writer = new PngWriter();
        $qrCode = QrCode::create($qrString)
            ->setEncoding(new Encoding('UTF-8'))
            ->setSize(480)
            ->setMargin(12)
            ->setForegroundColor(new Color(0, 0, 0))
            ->setBackgroundColor(new Color(255, 255, 255));

        return $writer->write($qrCode)->getString();
    }

    public function notifyOrderShipped(Shipment $shipment): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $shipment->loadMissing('order.user');
        $order = $shipment->order;
        if (! $order) {
            return;
        }

        $label = $order->order_number ?: '#'.$order->id;
        $provider = $shipment->provider ?: TelegramBotTexts::get(TelegramBotTexts::LOCALE_EN, 'courier_fallback');
        $trackingCode = $shipment->tracking_code ?: '—';

        $this->notifyOrderRecipients($order, function (string $locale) use ($order, $shipment, $label, $provider, $trackingCode) {
            $message = TelegramBotTexts::get($locale, 'order_shipped', [
                'label' => $label,
                'provider' => $shipment->provider ?: TelegramBotTexts::get($locale, 'courier_fallback'),
                'tracking' => $trackingCode,
            ]);

            return [$message, $this->buildOrderActionKeyboard($order, $shipment, $locale)];
        });
        $this->createInAppNotification(
            $order,
            'order_shipped',
            'Order shipped',
            "Your order was handed to {$provider}. Tracking: {$trackingCode}"
        );
    }

    public function notifyOrderDelivered(Shipment $shipment): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $shipment->loadMissing('order.user');
        $order = $shipment->order;
        if (! $order) {
            return;
        }

        $this->notifyOrderDeliveredForOrder($order);
    }

    public function notifyOrderDeliveredForOrder(Order $order): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $order->loadMissing('user');
        $label = $order->order_number ?: '#'.$order->id;
        $codPending = $order->payment_method === 'cod' && $order->payment_status !== 'paid';

        $this->createInAppNotification(
            $order,
            'order_delivered',
            'Order delivered',
            'Your order has been delivered. Thank you for shopping with us!'
        );

        $this->notifyOrderRecipients($order, function (string $locale) use ($order, $label, $codPending) {
            $message = TelegramBotTexts::get($locale, 'order_delivered', [
                'label' => $label,
                'cod_note' => $codPending
                    ? TelegramBotTexts::get($locale, 'order_delivered_cod_note')
                    : '',
            ]);

            return [$message, $this->buildOrderActionKeyboard($order, null, $locale)];
        });
        $this->notifyAdminOrderDelivered($order);
    }

    public function notifyAdminOrderDelivered(Order $order): void
    {
        $chatId = config('services.telegram.admin_chat_id');
        if (! filled($chatId)) {
            return;
        }

        $order->loadMissing(['user', 'items.product', 'shipment']);

        $this->sendMessage(
            (string) $chatId,
            $this->formatAdminOrderCardHtml($order, 'completed'),
            $this->buildAdminOrderKeyboard($order),
            'HTML'
        );
    }

    public function notifyAdminOfNewOrder(Order $order): void
    {
        $chatId = config('services.telegram.admin_chat_id');
        if (! filled($chatId)) {
            return;
        }

        $order->loadMissing(['user', 'items.product', 'shipment']);

        $this->sendMessage(
            (string) $chatId,
            $this->formatAdminOrderCardHtml($order, 'paid'),
            $this->buildAdminOrderKeyboard($order),
            'HTML'
        );
    }

    public function upsertTelegramUser(array $from, int $chatId): TelegramUser
    {
        return TelegramUser::updateOrCreate(
            ['telegram_user_id' => (int) $from['id']],
            [
                'chat_id' => $chatId,
                'username' => $from['username'] ?? null,
                'first_name' => $from['first_name'] ?? null,
                'last_name' => $from['last_name'] ?? null,
                'language_code' => $from['language_code'] ?? null,
                'last_interacted_at' => now(),
            ]
        );
    }

    public function linkTelegramToUser(TelegramUser $telegramUser, int $userId): void
    {
        $telegramUser->update(['user_id' => $userId]);
    }

    public function subscribeChatToOrder(TelegramUser $telegramUser, Order $order): void
    {
        TelegramOrderSubscription::firstOrCreate(
            [
                'order_id' => $order->id,
                'chat_id' => $telegramUser->chat_id,
            ],
            ['telegram_user_id' => $telegramUser->id]
        );
    }

    public function isUserConnected(int $userId): bool
    {
        return TelegramUser::where('user_id', $userId)->exists();
    }

    public function sendOrderStatusReply(int $chatId, Order $order, ?string $locale = null): void
    {
        $locale ??= $this->resolveLocaleForChatId($chatId);
        $order->loadMissing(['user', 'items.product', 'shipment']);
        $label = $order->order_number ?: '#'.$order->id;
        $status = ucfirst(str_replace('_', ' ', (string) $order->status));
        $shipment = $order->shipment;
        $e = fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $lines = [
            '<b>'.htmlspecialchars(TelegramBotTexts::get($locale, 'order_status_title'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</b>',
            '',
            htmlspecialchars(TelegramBotTexts::get($locale, 'order_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').': <code>'.$e($label).'</code>',
            htmlspecialchars(TelegramBotTexts::get($locale, 'status_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').': '.$e($status),
        ];

        if ($shipment) {
            $lines[] = htmlspecialchars(TelegramBotTexts::get($locale, 'shipment_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                .': '.$e(ucfirst(str_replace('_', ' ', (string) $shipment->status)));
            if ($shipment->tracking_code) {
                $lines[] = htmlspecialchars(TelegramBotTexts::get($locale, 'tracking_label'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                    .': <code>'.$e((string) $shipment->tracking_code).'</code>';
            }
        }

        $paymentLabel = $this->formatPaymentMethodLabel($order);
        $lines[] = htmlspecialchars(TelegramBotTexts::get($locale, 'invoice_status'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            .': <b>'.$e($order->payment_status === 'paid' ? 'Paid' : 'Pending').'</b>'
            .' · '.$e($paymentLabel);

        $lines[] = '';
        $lines[] = '<b>'.htmlspecialchars(TelegramBotTexts::get($locale, 'items'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</b>';
        $qtyLabel = TelegramBotTexts::get($locale, 'qty_label');
        $maxItems = 5;
        foreach ($order->items->take($maxItems) as $item) {
            $name = $e((string) ($item->name ?: $item->product?->name ?: 'Item'));
            $qty = (int) ($item->qty ?? 0);
            $lines[] = '• '.$name.' — '.$qtyLabel.' '.$qty;
        }
        $remaining = $order->items->count() - $maxItems;
        if ($remaining > 0) {
            $lines[] = '<i>'.$e(TelegramBotTexts::get($locale, 'more_items', ['count' => $remaining, 'plural' => ''])).'</i>';
        }

        $lines[] = '';
        $lines[] = '<b>'.htmlspecialchars(TelegramBotTexts::get($locale, 'grand_total'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            .': $'.number_format((float) $order->total, 2).'</b>';

        $replyMarkup = $this->keyboardForChat($chatId, $order);

        $this->sendMessage((string) $chatId, implode("\n", $lines), $replyMarkup, 'HTML');
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $payload = ['callback_query_id' => $callbackQueryId];
        if ($text !== null) {
            $payload['text'] = $text;
        }

        try {
            Http::timeout(10)->post(
                'https://api.telegram.org/bot'.config('services.telegram.bot_token').'/answerCallbackQuery',
                $payload
            );
        } catch (\Throwable $e) {
            Log::warning('Telegram answerCallbackQuery failed', ['error' => $e->getMessage()]);
        }
    }

    public function sendPhoto(string $chatId, string $pngBytes, ?string $caption = null, ?array $replyMarkup = null): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        try {
            $multipart = [
                ['name' => 'chat_id', 'contents' => $chatId],
                ['name' => 'photo', 'contents' => $pngBytes, 'filename' => 'payment-qr.png'],
            ];

            if ($caption !== null) {
                $multipart[] = ['name' => 'caption', 'contents' => $caption];
                $multipart[] = ['name' => 'parse_mode', 'contents' => 'Markdown'];
            }

            if ($replyMarkup !== null) {
                $multipart[] = [
                    'name' => 'reply_markup',
                    'contents' => json_encode($replyMarkup, JSON_UNESCAPED_UNICODE),
                ];
            }

            $response = Http::timeout(20)
                ->asMultipart()
                ->post('https://api.telegram.org/bot'.config('services.telegram.bot_token').'/sendPhoto', $multipart);

            if (! $response->successful()) {
                Log::warning('Telegram sendPhoto failed', [
                    'chat_id' => $chatId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                // Retry caption without Markdown if parse failed, keep buttons.
                if ($caption !== null && $replyMarkup !== null) {
                    $retryParts = [
                        ['name' => 'chat_id', 'contents' => $chatId],
                        ['name' => 'photo', 'contents' => $pngBytes, 'filename' => 'payment-qr.png'],
                        ['name' => 'caption', 'contents' => preg_replace('/[*_`]/', '', $caption) ?? $caption],
                        ['name' => 'reply_markup', 'contents' => json_encode($replyMarkup, JSON_UNESCAPED_UNICODE)],
                    ];
                    $retry = Http::timeout(20)
                        ->asMultipart()
                        ->post('https://api.telegram.org/bot'.config('services.telegram.bot_token').'/sendPhoto', $retryParts);

                    return $retry->successful();
                }

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Telegram sendPhoto exception', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function sendMessage(string $chatId, string $text, ?array $replyMarkup = null, ?string $parseMode = 'Markdown'): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'disable_web_page_preview' => true,
        ];

        if ($parseMode !== null) {
            $payload['parse_mode'] = $parseMode;
        }

        // Telegram Bot API expects reply_markup as a JSON *string* in form-urlencoded bodies.
        if ($replyMarkup !== null) {
            $payload['reply_markup'] = json_encode($replyMarkup, JSON_UNESCAPED_UNICODE);
        }

        $endpoint = 'https://api.telegram.org/bot'.config('services.telegram.bot_token').'/sendMessage';

        try {
            // Use asForm() — Laravel Http defaults to JSON, which breaks reply_markup when double-encoded.
            $response = Http::asForm()->timeout(15)->post($endpoint, $payload);

            if ($response->successful()) {
                return true;
            }

            Log::warning('Telegram sendMessage failed', [
                'chat_id' => $chatId,
                'status' => $response->status(),
                'body' => $response->body(),
            ]);

            // Retry without parse mode — Markdown/HTML often breaks on addresses / phone numbers.
            if ($parseMode !== null) {
                unset($payload['parse_mode']);
                $retry = Http::asForm()->timeout(15)->post($endpoint, $payload);

                if ($retry->successful()) {
                    return true;
                }

                Log::warning('Telegram sendMessage retry (no parse_mode) failed', [
                    'chat_id' => $chatId,
                    'status' => $retry->status(),
                    'body' => $retry->body(),
                ]);
            }

            return false;
        } catch (\Throwable $e) {
            Log::error('Telegram sendMessage exception', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * Send a test admin alert with action buttons (for verifying TELEGRAM_ADMIN_CHAT_ID).
     */
    public function sendAdminTestMessage(): array
    {
        $chatId = trim((string) config('services.telegram.admin_chat_id', ''));
        if ($chatId === '') {
            return ['ok' => false, 'message' => 'TELEGRAM_ADMIN_CHAT_ID is empty'];
        }

        if (! $this->isEnabled()) {
            return ['ok' => false, 'message' => 'TELEGRAM_BOT_TOKEN is not configured'];
        }

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '🧾 Invoice (test)', 'callback_data' => 'invoice_0'],
                    ['text' => '✅ Admin OK', 'callback_data' => 'lang_menu'],
                ],
                [
                    ['text' => '⏳ Processing', 'callback_data' => 'adminst_0_processing'],
                    ['text' => '📦 Shipped', 'callback_data' => 'adminst_0_shipped'],
                ],
            ],
        ];

        $message = "🧪 <b>Fitandsleek admin test</b>\n\n"
            .'Chat ID: <code>'.htmlspecialchars($chatId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')."</code>\n"
            ."If you see buttons below, admin Telegram is working.";

        $sent = $this->sendMessage($chatId, $message, $keyboard, 'HTML');

        return [
            'ok' => $sent,
            'message' => $sent
                ? 'Test message sent to '.$chatId
                : 'Failed to send test message to '.$chatId.' — check bot is in group and can post',
            'chat_id' => $chatId,
        ];
    }

    /**
     * @return Collection<int, int>
     */
    private function resolveOrderChatIds(Order $order): Collection
    {
        $chatIds = collect();

        if ($order->user_id) {
            $chatIds = $chatIds->merge(
                TelegramUser::where('user_id', $order->user_id)->pluck('chat_id')
            );
        }

        $chatIds = $chatIds->merge(
            TelegramOrderSubscription::where('order_id', $order->id)->pluck('chat_id')
        );

        return $chatIds->filter()->unique()->values();
    }

    /**
     * @param  \Closure(string $locale): array{0: string, 1: ?array}  $buildForLocale
     */
    private function notifyOrderRecipients(Order $order, \Closure $buildForLocale): void
    {
        foreach ($this->resolveOrderChatIds($order) as $chatId) {
            $locale = $this->resolveLocaleForChatId((int) $chatId);
            [$message, $replyMarkup] = $buildForLocale($locale);
            $this->sendMessage((string) $chatId, $message, $replyMarkup);
        }
    }

    private function createInAppNotification(Order $order, string $type, string $title, string $message): void
    {
        if (! $order->user_id) {
            return;
        }

        Notification::create([
            'user_id' => $order->user_id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => [
                'order_id' => $order->id,
                'order_number' => $order->order_number,
            ],
            'is_read' => false,
        ]);
    }

    private function resolveOrderPhone(Order $order): string
    {
        $address = is_array($order->shipping_address) ? $order->shipping_address : [];
        foreach (['receiver_phone', 'phone', 'mobile', 'receiverPhone'] as $key) {
            $phone = trim((string) ($address[$key] ?? ''));
            if ($phone !== '') {
                return $phone;
            }
        }

        $userPhone = trim((string) ($order->user?->phone ?? ''));

        return $userPhone !== '' ? $userPhone : '—';
    }

    private function formatAddress(Order $order): string
    {
        $address = $order->shipping_address;
        if (! is_array($address)) {
            return '—';
        }

        $formatted = trim((string) ($address['formatted_address'] ?? ''));
        if ($formatted !== '') {
            return $formatted;
        }

        $parts = array_filter([
            $address['receiver_name'] ?? null,
            filled($address['house_no'] ?? null) ? 'ផ្ទះលេខ '.$address['house_no'] : null,
            filled($address['street_no'] ?? null) ? 'ផ្លូវ '.$address['street_no'] : null,
            $address['street'] ?? null,
            filled($address['sangkat'] ?? null) ? 'សង្កាត់/ឃុំ '.$address['sangkat'] : null,
            filled($address['khan'] ?? null) ? 'ខណ្ឌ/ស្រុក '.$address['khan'] : null,
            $address['province'] ?? null,
            $address['landmark'] ?? null,
            $address['line1'] ?? $address['address'] ?? null,
            $address['line2'] ?? null,
            $address['district'] ?? null,
            $address['city'] ?? null,
            $address['state'] ?? null,
            $address['full_address'] ?? null,
            $address['country'] ?? null,
        ], fn ($part) => filled($part));

        return $parts !== [] ? implode(', ', $parts) : '—';
    }

    private function formatAdminNewOrderTelegramMessage(Order $order): string
    {
        $invoice = $this->invoices->buildOrderInvoiceData($order, InvoiceService::AUDIENCE_SHOP);
        $customer = $invoice['customer'] ?? [];
        $e = fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $label = $order->order_number ?: '#'.$order->id;
        $paymentLabel = $this->formatPaymentMethodLabel($order);
        $paidLabel = ($invoice['payment_status'] ?? '') === 'PAID' ? 'Paid' : 'Pending';
        $orderTime = $order->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '';

        $lines = [
            '🔔 <b>New paid order</b>',
            '',
            'Order: <code>'.$e($label).'</code>',
            'Date: '.$e($orderTime),
            'Payment: <b>'.$e($paidLabel).'</b> · '.$e($paymentLabel),
            '',
            '<b>Customer</b>',
            $e((string) ($customer['name'] ?? 'Guest')),
        ];

        $phone = trim((string) ($customer['phone'] ?? ''));
        if ($phone !== '' && $phone !== '-') {
            $lines[] = '📞 '.$e($phone);
        }

        $fullAddress = trim((string) ($customer['full_address'] ?? ''));
        if ($fullAddress !== '' && $fullAddress !== '-') {
            $lines[] = '📍 '.$e($fullAddress);
        }

        $note = trim((string) (is_array($order->shipping_address) ? ($order->shipping_address['landmark'] ?? '') : ''));
        if ($note !== '' && ! str_contains($fullAddress, $note)) {
            $lines[] = 'Note: '.$e($note);
        }

        $lines[] = '';
        $lines[] = '<b>Items</b>';

        $items = $invoice['items'] ?? [];
        $maxItems = 8;
        foreach (array_slice($items, 0, $maxItems) as $item) {
            $name = $e((string) ($item['product_name'] ?? 'Item'));
            $sku = $e((string) ($item['sku'] ?? '-'));
            $qty = (int) ($item['quantity'] ?? 0);
            $lineTotal = number_format((float) ($item['grand_total'] ?? 0), 2);
            $lines[] = '• '.$name;
            $lines[] = '  <code>'.$sku.'</code> · qty '.$qty.' — $'.$lineTotal;
        }

        $remaining = count($items) - $maxItems;
        if ($remaining > 0) {
            $lines[] = '<i>+'.$remaining.' more item(s)</i>';
        }

        $lines[] = '';
        $lines[] = 'Subtotal: $'.number_format((float) ($invoice['subtotal'] ?? 0), 2);

        if ((float) ($invoice['discount'] ?? 0) > 0) {
            $lines[] = 'Discount: −$'.number_format((float) $invoice['discount'], 2);
        }

        $lines[] = 'Shipping: $'.number_format((float) ($invoice['shipping_fee'] ?? 0), 2);
        $lines[] = '<b>Total: $'.number_format((float) ($invoice['grand_total'] ?? 0), 2).'</b>';

        if ($order->payment_method === 'cod' && $order->payment_status !== 'paid') {
            $lines[] = '';
            $lines[] = '<i>COD — collect $'.number_format((float) $order->total, 2).' on delivery</i>';
        }

        $message = implode("\n", $lines);
        if (strlen($message) > 4000) {
            $message = substr($message, 0, 3990)."\n…";
        }

        return $message;
    }

    /**
     * @return array{inline_keyboard: list<list<array<string, string>>>}|null
     */
    public function buildAdminOrderKeyboardPublic(Order $order): ?array
    {
        return $this->buildAdminOrderKeyboard($order);
    }

    private function formatAdminOrderCardHtml(Order $order, string $kind = 'paid'): string
    {
        $order->loadMissing(['user', 'items.product', 'shipment']);
        $invoice = $this->invoices->buildOrderInvoiceData($order, InvoiceService::AUDIENCE_SHOP);
        $customer = $invoice['customer'] ?? [];
        $e = fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $label = $order->order_number ?: '#'.$order->id;
        $paymentLabel = $this->formatPaymentMethodLabel($order);
        $paidLabel = ($invoice['payment_status'] ?? '') === 'PAID' ? 'Paid / បានបង់' : 'Pending / មិនទាន់បង់';
        $orderTime = $order->created_at?->timezone(config('app.timezone'))->format('Y-m-d H:i') ?? '';
        $shipment = $order->shipment;
        $shipStatus = $shipment
            ? ucfirst(str_replace('_', ' ', (string) $shipment->status))
            : '—';

        $title = match ($kind) {
            'cod' => '🔔 <b>ការបញ្ជាទិញ COD ថ្មី</b>\n<i>New COD order</i>',
            'completed' => '✅ <b>ដឹកដល់ / បញ្ចប់</b>\n<i>Order completed</i>',
            default => '🔔 <b>ការបញ្ជាទិញបានបង់</b>\n<i>New paid order</i>',
        };

        $lines = [
            $title,
            '',
            '🧾 Order: <code>'.$e($label).'</code>',
            '📅 Date: '.$e($orderTime),
            '💳 Payment: <b>'.$e($paidLabel).'</b> · '.$e($paymentLabel),
            '📦 Shipment: <b>'.$e($shipStatus).'</b>',
            '',
            '<b>អតិថិជន / Customer</b>',
            '👤 '.$e((string) ($customer['name'] ?? $order->user?->name ?? 'Guest')),
        ];

        $phone = trim((string) ($customer['phone'] ?? $this->resolveOrderPhone($order)));
        if ($phone !== '' && $phone !== '-') {
            $lines[] = '📞 '.$e($phone);
        }

        $fullAddress = trim((string) ($customer['full_address'] ?? $this->formatAddress($order)));
        if ($fullAddress !== '' && $fullAddress !== '-') {
            $lines[] = '📍 '.$e($fullAddress);
        }

        $lines[] = '';
        $lines[] = '<b>ទំនិញ / Items</b>';
        $items = $invoice['items'] ?? [];
        $maxItems = 8;
        foreach (array_slice($items, 0, $maxItems) as $item) {
            $name = $e((string) ($item['product_name'] ?? 'Item'));
            $sku = $e((string) ($item['sku'] ?? '-'));
            $qty = (int) ($item['quantity'] ?? 0);
            $lineTotal = number_format((float) ($item['grand_total'] ?? 0), 2);
            $lines[] = '• '.$name;
            $lines[] = '  <code>'.$sku.'</code> · ចំនួន '.$qty.' — $'.$lineTotal;
        }
        $remaining = count($items) - $maxItems;
        if ($remaining > 0) {
            $lines[] = '<i>+'.$remaining.' more</i>';
        }

        $lines[] = '';
        $lines[] = 'Subtotal: $'.number_format((float) ($invoice['subtotal'] ?? 0), 2);
        if ((float) ($invoice['discount'] ?? 0) > 0) {
            $lines[] = 'Discount: −$'.number_format((float) $invoice['discount'], 2);
        }
        $lines[] = 'Shipping: $'.number_format((float) ($invoice['shipping_fee'] ?? 0), 2);
        $lines[] = '<b>សរុប / Total: $'.number_format((float) ($invoice['grand_total'] ?? $order->total), 2).'</b>';

        if ($shipment?->tracking_code) {
            $lines[] = 'Tracking: <code>'.$e((string) $shipment->tracking_code).'</code>';
        }
        if ($shipment?->provider && strtolower((string) $shipment->provider) !== 'internal') {
            $lines[] = 'Courier: '.$e((string) $shipment->provider);
        }

        if ($order->payment_method === 'cod' && $order->payment_status !== 'paid') {
            $lines[] = '';
            $lines[] = '<i>COD — ប្រមូល $'.number_format((float) $order->total, 2).' ពេលដឹកដល់</i>';
        }

        if ($kind === 'completed') {
            $lines[] = '';
            $lines[] = '<i>Marked as delivered/completed.</i>';
        }

        $message = implode("\n", $lines);
        if (strlen($message) > 4000) {
            $message = substr($message, 0, 3990)."\n…";
        }

        return $message;
    }

    /**
     * @return array{inline_keyboard: list<list<array<string, string>>>}|null
     */
    private function buildAdminOrderKeyboard(Order $order): ?array
    {
        $order->loadMissing('shipment');
        $shipment = $order->shipment;
        $rows = [];

        // Short bilingual labels (2 per row) to avoid Telegram truncation.
        $actionRow = [
            ['text' => '🧾 វិក័យបត្រ', 'callback_data' => 'invoice_'.$order->id],
        ];
        if ($order->payment_status !== 'paid') {
            $actionRow[] = [
                'text' => $order->payment_method === 'cod' ? '💳 KHQR COD' : '💳 KHQR',
                'callback_data' => 'payqr_'.$order->id,
            ];
        }
        $rows[] = $actionRow;

        if ($shipment && filled($shipment->external_tracking_url)) {
            $provider = $shipment->provider && strtolower((string) $shipment->provider) !== 'internal'
                ? (string) $shipment->provider
                : 'Courier';
            $rows[] = [[
                'text' => '🔗 Track '.$provider,
                'url' => $shipment->external_tracking_url,
            ]];
        }

        $rows[] = [
            ['text' => '⏳ រៀបចំ', 'callback_data' => 'adminst_'.$order->id.'_processing'],
            ['text' => '📦 ដឹកចេញ', 'callback_data' => 'adminst_'.$order->id.'_shipped'],
        ];
        $rows[] = [
            ['text' => '🚚 កំពុងដឹក', 'callback_data' => 'adminst_'.$order->id.'_in_transit'],
            ['text' => '✅ ដឹកដល់', 'callback_data' => 'adminst_'.$order->id.'_delivered'],
        ];
        $rows[] = [
            ['text' => '⚠️ បរាជ័យ', 'callback_data' => 'adminst_'.$order->id.'_failed'],
            ['text' => '↩️ ត្រឡប់', 'callback_data' => 'adminst_'.$order->id.'_returned'],
        ];

        return ['inline_keyboard' => $rows];
    }

    private function formatPaymentMethodLabel(Order $order): string
    {
        return match ($order->payment_method) {
            'cod' => 'COD',
            'bakong_khqr' => 'KHQR',
            default => ucfirst(str_replace('_', ' ', (string) ($order->payment_method ?: 'online'))),
        };
    }
}
