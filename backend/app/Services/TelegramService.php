<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Shipment;
use App\Models\TelegramOrderSubscription;
use App\Models\TelegramUser;
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

        $message = "✅ *Order confirmed*\n\n"
            ."Order: `{$label}`\n"
            ."Total: \${$total}\n"
            ."Status: Preparing for shipment\n\n"
            ."You will receive updates when your order ships.";

        $replyMarkup = $this->buildOrderActionKeyboard($order);

        $this->notifyOrderRecipients($order, $message, $replyMarkup);
    }

    public function buildReceiptUrl(Order $order): string
    {
        return $this->receiptTokens->buildReceiptUrl($order);
    }

    /**
     * @return array{inline_keyboard: list<list<array<string, string>>>}|null
     */
    public function buildOrderActionKeyboard(Order $order, ?Shipment $shipment = null): ?array
    {
        $order->loadMissing('shipment');
        $shipment ??= $order->shipment;
        $rows = [];

        $actionRow = [];
        $receiptUrl = $this->buildReceiptUrl($order);
        $actionRow[] = ['text' => '🧾 វិក័យបត្រ / Invoice', 'url' => $receiptUrl];

        if ($order->payment_status !== 'paid') {
            $actionRow[] = [
                'text' => $order->payment_method === 'cod'
                    ? '💳 Scan KHQR (COD)'
                    : '💳 Payment QR',
                'callback_data' => 'payqr_'.$order->id,
            ];
        }

        if ($actionRow !== []) {
            $rows[] = $actionRow;
        }

        if ($shipment && filled($shipment->external_tracking_url)) {
            $provider = $shipment->provider ?: 'Courier';
            $rows[] = [[
                'text' => "Track on {$provider}",
                'url' => $shipment->external_tracking_url,
            ]];
        }

        return $rows !== [] ? ['inline_keyboard' => $rows] : null;
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

    public function sendPaymentQrToChat(int $chatId, Order $order): bool
    {
        if ($order->payment_status === 'paid') {
            $this->sendMessage((string) $chatId, '✅ This order is already paid.');

            return false;
        }

        try {
            $payment = $this->resolvePaymentQrForOrder($order);
            $qrString = (string) ($payment['qr_string'] ?? '');
            if ($qrString === '') {
                $this->sendMessage((string) $chatId, '❌ Could not generate payment QR. Please try again later.');

                return false;
            }

            $amount = $payment['amount'] ?? null;
            $currency = (string) ($payment['currency'] ?? 'KHR');
            $amountLabel = $currency === 'KHR'
                ? number_format((float) $amount).' ៛'
                : '$'.number_format((float) $amount, 2);

            $label = $order->order_number ?: '#'.$order->id;
            $codNote = $order->payment_method === 'cod'
                ? "\n\n_បង់ពេលដឹកដល់ — Scan with ABA / Bakong_"
                : "\n\n_Scan with your banking app to pay_";

            $caption = "💳 *Payment QR*\n\n"
                ."Order: `{$label}`\n"
                ."Amount: *{$amountLabel}*"
                .$codNote;

            return $this->sendPhoto((string) $chatId, $this->buildQrPngBytes($qrString), $caption);
        } catch (\Throwable $e) {
            Log::error('Telegram payment QR failed', [
                'order_id' => $order->id,
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
            $this->sendMessage((string) $chatId, '❌ Failed to generate payment QR. Please try again.');

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
        $provider = $shipment->provider ?: 'Courier';
        $trackingCode = $shipment->tracking_code ?: '—';

        $message = "📦 *Order shipped*\n\n"
            ."Order: `{$label}`\n"
            ."Courier: {$provider}\n"
            ."Tracking: `{$trackingCode}`";

        $replyMarkup = $this->buildOrderActionKeyboard($order, $shipment);

        $this->createInAppNotification(
            $order,
            'order_shipped',
            'Order shipped',
            "Your order was handed to {$provider}. Tracking: {$trackingCode}"
        );

        $this->notifyOrderRecipients($order, $message, $replyMarkup);
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

        $message = "🎉 *Order delivered*\n\n"
            ."Order: `{$label}`\n"
            .($order->payment_method === 'cod' && $order->payment_status !== 'paid'
                ? "Pay on delivery — tap *Payment QR* below or pay cash to the driver.\n\n"
                : '')
            ."Thank you for shopping with Fitandsleek!";

        $this->createInAppNotification(
            $order,
            'order_delivered',
            'Order delivered',
            'Your order has been delivered. Thank you for shopping with us!'
        );

        $replyMarkup = $this->buildOrderActionKeyboard($order);
        $this->notifyOrderRecipients($order, $message, $replyMarkup);
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

        $message = "✅ *Order completed*\n\n"
            ."Order: `{$label}`\n"
            ."Customer: {$customer}\n"
            ."Marked as delivered/completed.";

        $this->sendMessage((string) $chatId, $message);
    }

    public function notifyAdminOfNewOrder(Order $order): void
    {
        $chatId = config('services.telegram.admin_chat_id');
        if (! filled($chatId)) {
            return;
        }

        $order->loadMissing(['user', 'items']);
        $label = $order->order_number ?: '#'.$order->id;
        $customer = $order->user?->name ?: 'Guest';
        $phone = $this->resolveOrderPhone($order);
        $itemCount = $order->items->count();
        $total = number_format((float) $order->total, 2);
        $address = $this->formatAddress($order);

        $message = "🔔 *New paid order*\n\n"
            ."Order: `{$label}`\n"
            ."Customer: {$customer}\n"
            ."Phone: {$phone}\n"
            ."Items: {$itemCount}\n"
            ."Total: \${$total}\n"
            ."Address: {$address}";

        $this->sendMessage((string) $chatId, $message);
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

    public function sendOrderStatusReply(int $chatId, Order $order): void
    {
        $order->loadMissing('shipment');
        $label = $order->order_number ?: '#'.$order->id;
        $status = ucfirst(str_replace('_', ' ', (string) $order->status));
        $shipment = $order->shipment;

        $lines = [
            "📋 *Order status*",
            '',
            "Order: `{$label}`",
            "Status: {$status}",
        ];

        if ($shipment) {
            $lines[] = 'Shipment: '.ucfirst(str_replace('_', ' ', (string) $shipment->status));
            if ($shipment->tracking_code) {
                $lines[] = "Tracking: `{$shipment->tracking_code}`";
            }
        }

        if ($order->payment_status !== 'paid') {
            $lines[] = 'Payment: Pending';
        }

        $replyMarkup = $this->buildOrderActionKeyboard($order, $shipment);

        $this->sendMessage((string) $chatId, implode("\n", $lines), $replyMarkup);
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

    public function sendMessage(string $chatId, string $text, ?array $replyMarkup = null): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'disable_web_page_preview' => true,
        ];

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

    private function notifyOrderRecipients(Order $order, string $message, ?array $replyMarkup = null): void
    {
        foreach ($this->resolveOrderChatIds($order) as $chatId) {
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
        $address = $order->shipping_address;
        if (is_array($address)) {
            $phone = trim((string) ($address['phone'] ?? $address['receiver_phone'] ?? ''));

            return $phone !== '' ? $phone : '—';
        }

        return $order->user?->phone ?: '—';
    }

    private function formatAddress(Order $order): string
    {
        $address = $order->shipping_address;
        if (! is_array($address)) {
            return '—';
        }

        $parts = array_filter([
            $address['line1'] ?? $address['address'] ?? null,
            $address['district'] ?? null,
            $address['city'] ?? $address['province'] ?? null,
        ], fn ($part) => filled($part));

        return $parts !== [] ? implode(', ', $parts) : '—';
    }
}
