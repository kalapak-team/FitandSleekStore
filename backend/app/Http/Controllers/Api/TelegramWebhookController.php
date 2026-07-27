<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\TelegramUser;
use App\Services\TelegramService;
use App\Support\TelegramBotTexts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function __construct(private TelegramService $telegram)
    {
    }

    public function handle(Request $request, ?string $secret = null): JsonResponse
    {
        if (! $this->validWebhookSecret($request, $secret)) {
            Log::warning('Telegram webhook rejected (secret mismatch)', [
                'has_header_secret' => $request->hasHeader('X-Telegram-Bot-Api-Secret-Token'),
            ]);

            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (! $this->telegram->isEnabled()) {
            return response()->json(['ok' => true]);
        }

        $update = $request->all();

        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            try {
                $this->handleCallbackQuery($update['callback_query']);
            } catch (\Throwable $e) {
                Log::error('Telegram callback handler failed', [
                    'error' => $e->getMessage(),
                ]);
            }

            return response()->json(['ok' => true]);
        }

        $message = $update['message'] ?? null;

        if (! is_array($message)) {
            return response()->json(['ok' => true]);
        }

        $chat = $message['chat'] ?? null;
        $from = $message['from'] ?? null;
        $text = $this->normalizeCommandText(trim((string) ($message['text'] ?? '')));

        if (! is_array($chat) || ! is_array($from) || $text === '') {
            return response()->json(['ok' => true]);
        }

        try {
            $telegramUser = $this->telegram->upsertTelegramUser($from, (int) $chat['id']);
            $chatId = (int) $chat['id'];

            if (str_starts_with($text, '/start')) {
                $this->handleStart($telegramUser, $text, $chatId);
            } elseif (str_starts_with($text, '/track')) {
                $this->handleTrack($telegramUser, $text, $chatId);
            } elseif (str_starts_with($text, '/orders')) {
                $this->handleOrders($telegramUser, $chatId);
            } elseif (str_starts_with($text, '/language') || str_starts_with($text, '/lang')) {
                $this->telegram->sendLanguageMenu($chatId, $telegramUser);
            } elseif (str_starts_with($text, '/help')) {
                $this->sendHelp($telegramUser, $chatId);
            }
        } catch (\Throwable $e) {
            Log::error('Telegram webhook handler failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    private function handleStart(TelegramUser $telegramUser, string $text, int $chatId): void
    {
        $locale = TelegramBotTexts::resolveLocale($telegramUser);
        $parts = preg_split('/\s+/', $text, 2);
        $payload = isset($parts[1]) ? trim($parts[1]) : '';

        if ($payload === '') {
            $this->sendWelcome($telegramUser, $chatId);

            return;
        }

        if (str_starts_with($payload, 'order_')) {
            $orderKey = substr($payload, 6);
            $order = Order::findByPublicKey($orderKey)?->load('shipment');

            if (! $order) {
                $this->telegram->sendMessage(
                    (string) $chatId,
                    TelegramBotTexts::get($locale, 'order_not_found', ['key' => $orderKey])
                );

                return;
            }

            $this->telegram->subscribeChatToOrder($telegramUser, $order);
            $label = $order->order_number ?: '#'.$order->id;
            $this->telegram->sendMessage(
                (string) $chatId,
                TelegramBotTexts::get($locale, 'order_subscribed', ['label' => $label])
            );
            $this->telegram->sendInvoiceToChat($chatId, $order, $locale);

            return;
        }

        if (str_starts_with($payload, 'link_')) {
            $segments = explode('_', $payload);
            if (count($segments) !== 3) {
                $this->telegram->sendMessage(
                    (string) $chatId,
                    TelegramBotTexts::get($locale, 'invalid_account_link')
                );

                return;
            }

            $userId = (int) $segments[1];
            $signature = $segments[2];

            if (! $this->telegram->verifyAccountLinkSignature($userId, $signature)) {
                $this->telegram->sendMessage(
                    (string) $chatId,
                    TelegramBotTexts::get($locale, 'account_link_invalid')
                );

                return;
            }

            $this->telegram->linkTelegramToUser($telegramUser, $userId);
            $this->telegram->sendMessage(
                (string) $chatId,
                TelegramBotTexts::get($locale, 'account_linked')
            );

            return;
        }

        $this->sendWelcome($telegramUser, $chatId);
    }

    private function handleTrack(TelegramUser $telegramUser, string $text, int $chatId): void
    {
        $locale = TelegramBotTexts::resolveLocale($telegramUser);
        $parts = preg_split('/\s+/', $text, 2);
        $orderKey = isset($parts[1]) ? trim($parts[1]) : '';

        if ($orderKey === '') {
            $this->telegram->sendMessage(
                (string) $chatId,
                TelegramBotTexts::get($locale, 'track_usage')
            );

            return;
        }

        $order = Order::findByPublicKey($orderKey)?->load('shipment');

        if (! $order) {
            $this->telegram->sendMessage(
                (string) $chatId,
                TelegramBotTexts::get($locale, 'order_not_found', ['key' => $orderKey])
            );

            return;
        }

        if ($telegramUser->user_id && (int) $telegramUser->user_id !== (int) $order->user_id) {
            $this->telegram->sendMessage(
                (string) $chatId,
                TelegramBotTexts::get($locale, 'order_not_yours')
            );

            return;
        }

        $this->telegram->subscribeChatToOrder($telegramUser, $order);
        $this->telegram->sendOrderStatusReply($chatId, $order, $locale);
    }

    private function handleOrders(TelegramUser $telegramUser, int $chatId): void
    {
        $locale = TelegramBotTexts::resolveLocale($telegramUser);

        if (! $telegramUser->user_id) {
            $this->telegram->sendMessage(
                (string) $chatId,
                TelegramBotTexts::get($locale, 'link_account_first')
            );

            return;
        }

        $orders = Order::with('shipment')
            ->where('user_id', $telegramUser->user_id)
            ->orderByDesc('id')
            ->limit(5)
            ->get();

        if ($orders->isEmpty()) {
            $this->telegram->sendMessage((string) $chatId, TelegramBotTexts::get($locale, 'no_orders'));

            return;
        }

        $lines = [TelegramBotTexts::get($locale, 'recent_orders_title'), ''];
        foreach ($orders as $order) {
            $label = $order->order_number ?: '#'.$order->id;
            $status = ucfirst(str_replace('_', ' ', (string) $order->status));
            $lines[] = "• `{$label}` — {$status}";
        }
        $lines[] = '';
        $lines[] = TelegramBotTexts::get($locale, 'track_hint');

        $this->telegram->sendMessage((string) $chatId, implode("\n", $lines));
    }

    private function sendWelcome(TelegramUser $telegramUser, int $chatId): void
    {
        $locale = TelegramBotTexts::resolveLocale($telegramUser);
        $message = TelegramBotTexts::get($locale, 'welcome');
        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => TelegramBotTexts::get($locale, 'btn_language'), 'callback_data' => 'lang_menu'],
                ],
            ],
        ];

        $sent = $this->telegram->sendMessage((string) $chatId, $message, $keyboard);
        if (! $sent) {
            $plain = preg_replace('/[*_`]/', '', $message);
            $this->telegram->sendMessage((string) $chatId, $plain, $keyboard, null);
        }
    }

    private function validWebhookSecret(Request $request, ?string $pathSecret): bool
    {
        $expected = (string) config('services.telegram.webhook_secret');
        if ($expected === '') {
            return false;
        }

        if ($pathSecret !== null && hash_equals($expected, $pathSecret)) {
            return true;
        }

        $header = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        return $header !== '' && hash_equals($expected, $header);
    }

    private function normalizeCommandText(string $text): string
    {
        if ($text === '') {
            return '';
        }

        if (preg_match('#^(/\w+)(@\S+)?(\s+.*)?$#', $text, $matches)) {
            return $matches[1].($matches[3] ?? '');
        }

        return $text;
    }

    private function sendHelp(TelegramUser $telegramUser, int $chatId): void
    {
        $locale = TelegramBotTexts::resolveLocale($telegramUser);
        $this->telegram->sendMessage((string) $chatId, TelegramBotTexts::get($locale, 'help'));
    }

    private function handleCallbackQuery(array $callback): void
    {
        $callbackId = (string) ($callback['id'] ?? '');
        $data = (string) ($callback['data'] ?? '');
        $from = $callback['from'] ?? null;
        $message = $callback['message'] ?? null;
        $chat = is_array($message) ? ($message['chat'] ?? null) : null;

        if ($callbackId === '' || ! is_array($from) || ! is_array($chat)) {
            return;
        }

        $chatId = (int) $chat['id'];
        $telegramUser = $this->telegram->upsertTelegramUser($from, $chatId);

        // Dismiss Telegram button spinner immediately (must happen within a few seconds).
        $this->telegram->answerCallbackQuery($callbackId);

        if ($data === 'lang_menu') {
            $this->telegram->sendLanguageMenu($chatId, $telegramUser);

            return;
        }

        if ($data === 'lang_km' || $data === 'lang_en') {
            $locale = $data === 'lang_km' ? TelegramBotTexts::LOCALE_KM : TelegramBotTexts::LOCALE_EN;
            $telegramUser = $this->telegram->setPreferredLocale($telegramUser, $locale);
            $this->telegram->sendMessage(
                (string) $chatId,
                TelegramBotTexts::get($locale, $locale === TelegramBotTexts::LOCALE_KM ? 'language_set_km' : 'language_set_en')
            );

            return;
        }

        if (str_starts_with($data, 'payqr_')) {
            $this->handlePaymentQrCallback($callbackId, $data, $chatId, $telegramUser);

            return;
        }

        if (str_starts_with($data, 'invoice_')) {
            $this->handleInvoiceCallback($callbackId, $data, $chatId, $telegramUser);

            return;
        }
    }

    private function handlePaymentQrCallback(string $callbackId, string $data, int $chatId, TelegramUser $telegramUser): void
    {
        $locale = TelegramBotTexts::resolveLocale($telegramUser);
        $orderId = (int) substr($data, 6);
        if ($orderId <= 0) {
            $this->telegram->sendMessage((string) $chatId, TelegramBotTexts::get($locale, 'invalid_order'));

            return;
        }

        $order = Order::find($orderId);
        if (! $order) {
            $this->telegram->sendMessage((string) $chatId, TelegramBotTexts::get($locale, 'order_not_found_short'));

            return;
        }

        if (! $this->telegram->canChatAccessOrder($chatId, $order, $telegramUser)) {
            $this->telegram->sendMessage((string) $chatId, TelegramBotTexts::get($locale, 'not_subscribed'));

            return;
        }

        $this->telegram->sendPaymentQrToChat($chatId, $order, $locale);
    }

    private function handleInvoiceCallback(string $callbackId, string $data, int $chatId, TelegramUser $telegramUser): void
    {
        $locale = TelegramBotTexts::resolveLocale($telegramUser);
        $orderId = (int) substr($data, 8);
        if ($orderId <= 0) {
            $this->telegram->sendMessage((string) $chatId, TelegramBotTexts::get($locale, 'invalid_order'));

            return;
        }

        $order = Order::find($orderId);
        if (! $order) {
            $this->telegram->sendMessage((string) $chatId, TelegramBotTexts::get($locale, 'order_not_found_short'));

            return;
        }

        if (! $this->telegram->canChatAccessOrder($chatId, $order, $telegramUser)) {
            $this->telegram->sendMessage((string) $chatId, TelegramBotTexts::get($locale, 'not_subscribed'));

            return;
        }

        $this->telegram->sendInvoiceToChat($chatId, $order, $locale);
    }
}
