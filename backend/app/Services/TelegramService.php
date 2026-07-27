<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shipment;
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
            $message = $this->formatInvoiceTelegramMessage($order, $locale);
            $keyboard = $this->buildOrderActionKeyboard($order, $order->shipment, $locale);

            return $this->sendMessage((string) $chatId, $message, $keyboard, 'HTML');
        } catch (\Throwable $e) {
            Log::error('Telegram invoice failed', [
                'order_id' => $order->id,
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
            $this->sendMessage((string) $chatId, TelegramBotTexts::get($locale, 'invoice_failed'));

            return false;
        }
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
        if (TelegramOrderSubscription::where('order_id', $order->id)->where('chat_id', $chatId)->exists()) {
            return true;
        }

        if ($telegramUser?->user_id && (int) $telegramUser->user_id === (int) $order->user_id) {
            return true;
        }

        return false;
    }

    public function sendPaymentQrToChat(int $chatId, Order $order, ?string $locale = null): bool
    {
        $locale ??= $this->resolveLocaleForChatId($chatId);

        if ($order->payment_status === 'paid') {
            $this->sendMessage((string) $chatId, TelegramBotTexts::get($locale, 'already_paid'));

            return false;
        }

        try {
            $payment = $this->resolvePaymentQrForOrder($order);
            $qrString = (string) ($payment['qr_string'] ?? '');
            if ($qrString === '') {
                $this->sendMessage((string) $chatId, TelegramBotTexts::get($locale, 'payment_qr_failed'));

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

            return $this->sendPhoto((string) $chatId, $this->buildQrPngBytes($qrString), $caption);
        } catch (\Throwable $e) {
            Log::error('Telegram payment QR failed', [
                'order_id' => $order->id,
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
            $this->sendMessage((string) $chatId, TelegramBotTexts::get($locale, 'payment_qr_failed_gen'));

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

        $order->loadMissing('user');
        $label = $order->order_number ?: '#'.$order->id;
        $customer = $order->user?->name ?: 'Guest';
        $phone = $this->resolveOrderPhone($order);
        $total = number_format((float) $order->total, 2);
        $address = $this->formatAddress($order);

        $message = "✅ *Order completed*\n\n"
            ."Order: `{$label}`\n"
            ."Customer: {$customer}\n"
            ."Phone: {$phone}\n"
            ."Total: \${$total}\n"
            ."Address: {$address}\n\n"
            ."Marked as delivered/completed.";

        $this->sendMessage((string) $chatId, $message, $this->buildAdminOrderKeyboard($order));
    }

    public function notifyAdminOfNewOrder(Order $order): void
    {
        $chatId = config('services.telegram.admin_chat_id');
        if (! filled($chatId)) {
            return;
        }

        $order->loadMissing(['user', 'items.product', 'shipment']);

        $message = $this->formatAdminNewOrderTelegramMessage($order);

        $this->sendMessage(
            (string) $chatId,
            $message,
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

        $replyMarkup = $this->buildOrderActionKeyboard($order, $shipment, $locale);

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

    public function sendPhoto(string $chatId, string $pngBytes, ?string $caption = null): bool
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

            $response = Http::timeout(20)
                ->asMultipart()
                ->post('https://api.telegram.org/bot'.config('services.telegram.bot_token').'/sendPhoto', $multipart);

            if (! $response->successful()) {
                Log::warning('Telegram sendPhoto failed', [
                    'chat_id' => $chatId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

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

        if ($replyMarkup !== null) {
            $payload['reply_markup'] = json_encode($replyMarkup);
        }

        try {
            $response = Http::timeout(10)->post(
                'https://api.telegram.org/bot'.config('services.telegram.bot_token').'/sendMessage',
                $payload
            );

            if (! $response->successful()) {
                Log::warning('Telegram sendMessage failed', [
                    'chat_id' => $chatId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return false;
            }

            return true;
        } catch (\Throwable $e) {
            Log::error('Telegram sendMessage exception', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
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
    private function buildAdminOrderKeyboard(Order $order): ?array
    {
        $base = rtrim((string) config('app.frontend_url'), '/');
        $rows = [];

        if ($base !== '') {
            $rows[] = [
                [
                    'text' => 'Open in Admin',
                    'url' => $base.'/admin/orders',
                ],
                [
                    'text' => 'Invoice',
                    'url' => $base.'/admin/orders/'.$order->id.'/invoice',
                ],
            ];
        }

        $address = is_array($order->shipping_address) ? $order->shipping_address : [];
        $lat = $address['latitude'] ?? null;
        $lng = $address['longitude'] ?? null;
        if (is_numeric($lat) && is_numeric($lng)) {
            $rows[] = [[
                'text' => 'Map',
                'url' => 'https://www.google.com/maps/search/?api=1&query='.$lat.','.$lng,
            ]];
        } elseif ($base === '') {
            return null;
        }

        return $rows !== [] ? ['inline_keyboard' => $rows] : null;
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
