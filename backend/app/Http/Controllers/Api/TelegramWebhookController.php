<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\TelegramUser;
use App\Services\DatabaseBackupService;
use App\Services\TelegramBotAuthService;
use App\Services\TelegramService;
use App\Support\TelegramBotTexts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramWebhookController extends Controller
{
    public function __construct(
        private TelegramService $telegram,
        private TelegramBotAuthService $botAuth,
    ) {
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
            } elseif (str_starts_with($text, '/chatid')) {
                $this->handleChatId($chat, $chatId);
            } elseif (str_starts_with($text, '/backup')) {
                $this->handleBackup($telegramUser, $chatId);
            }
        } catch (\Throwable $e) {
            Log::error('Telegram webhook handler failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * @param  array<string, mixed>  $chat
     */
    private function handleChatId(array $chat, int $chatId): void
    {
        $e = fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $title = trim((string) ($chat['title'] ?? $chat['username'] ?? $chat['first_name'] ?? ''));
        $type = trim((string) ($chat['type'] ?? 'unknown'));

        $lines = [
            '🆔 <b>Chat ID</b>',
            '',
            '<code>'.$e((string) $chatId).'</code>',
            'Type: '.$e($type),
        ];

        if ($title !== '') {
            $lines[] = 'Name: '.$e($title);
        }

        $this->telegram->sendMessage((string) $chatId, implode("\n", $lines), null, 'HTML');
    }

    private function handleBackup(TelegramUser $telegramUser, int $chatId): void
    {
        $locale = TelegramBotTexts::resolveLocale($telegramUser);

        if (! $this->isAllowedBackupChat($chatId)) {
            $this->telegram->sendMessage(
                (string) $chatId,
                '⛔ Backup command is only available in the backup / admin group.',
                null,
                null
            );

            return;
        }

        if (! $this->botAuth->resolveSuperAdminActor($telegramUser)) {
            $this->telegram->sendMessage(
                (string) $chatId,
                TelegramBotTexts::get($locale, 'backup_superadmin_only'),
                null,
                null
            );

            return;
        }

        $this->telegram->sendMessage(
            (string) $chatId,
            '⏳ Starting database backup… please wait.',
            null,
            null
        );

        dispatch(function () {
            try {
                // Manual Telegram /backup always dumps, even if data is unchanged.
                app(DatabaseBackupService::class)->run(force: true);
            } catch (\Throwable $e) {
                Log::error('Telegram /backup failed', ['error' => $e->getMessage()]);
            }
        })->afterResponse();
    }

    private function isAllowedBackupChat(int $chatId): bool
    {
        if ($this->telegram->isAdminChatId($chatId)) {
            return true;
        }

        $backupChat = trim((string) config('backup.telegram_chat_id'));
        if ($backupChat === '') {
            return false;
        }

        return (string) $chatId === (string) (int) $backupChat;
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

        if (preg_match('/^(staff|driver)_/i', $payload)) {
            $this->handleStaffOrDriverLink($telegramUser, $payload, $chatId, $locale);

            return;
        }

        if (str_starts_with($payload, 'job_')) {
            $this->handleDriverJobStart($telegramUser, substr($payload, 4), $chatId, $locale);

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

    private function handleStaffOrDriverLink(
        TelegramUser $telegramUser,
        string $payload,
        int $chatId,
        string $locale
    ): void {
        $result = $this->botAuth->redeemStartPayload($payload, $telegramUser);
        $isDriver = str_starts_with(strtolower($payload), 'driver_');

        if (! $result['ok'] || ! $result['user']) {
            $reason = match ($result['message']) {
                'expired' => 'expired',
                'used' => 'already used',
                'inactive' => 'account inactive',
                'telegram_busy' => 'this Telegram is linked to another staff/driver',
                'role_mismatch' => 'role mismatch',
                default => 'invalid or not found',
            };
            $key = $isDriver ? 'driver_link_failed' : 'staff_link_failed';
            $this->telegram->sendMessage(
                (string) $chatId,
                TelegramBotTexts::get($locale, $key, ['reason' => $reason])
            );

            return;
        }

        $isLinkedDriver = $result['user']->isDriver();
        $key = $isLinkedDriver ? 'driver_linked' : 'staff_linked';
        $this->telegram->sendMessage(
            (string) $chatId,
            TelegramBotTexts::get($locale, $key, ['name' => $result['user']->name ?: ('#'.$result['user']->id)]),
            $isLinkedDriver ? null : $this->telegram->buildAdminGroupInviteKeyboard($locale)
        );
    }

    private function handleDriverJobStart(
        TelegramUser $telegramUser,
        string $orderKey,
        int $chatId,
        string $locale
    ): void {
        $order = Order::findByPublicKey($orderKey)?->load(['shipment', 'user', 'items']);
        if (! $order) {
            $this->telegram->sendMessage(
                (string) $chatId,
                TelegramBotTexts::get($locale, 'order_not_found', ['key' => $orderKey])
            );

            return;
        }

        // External courier: no shop account required — admin must unlock after pickup confirm.
        if (! $this->botAuth->courierCanOpenJob($order)) {
            $this->telegram->sendMessage(
                (string) $chatId,
                TelegramBotTexts::get($locale, 'courier_job_locked')
            );

            return;
        }

        $this->telegram->sendDriverJobCard($chatId, $order, $locale);
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
            $linked = $this->botAuth->linkedUser($telegramUser);
            if (! $linked || (! $linked->isAdmin() && ! $linked->isDriver())) {
                $this->telegram->sendMessage(
                    (string) $chatId,
                    TelegramBotTexts::get($locale, 'order_not_yours')
                );

                return;
            }
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

        if (! str_starts_with($data, 'adminst_') && ! str_starts_with($data, 'drivest_')) {
            $this->telegram->answerCallbackQuery($callbackId);
        }

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

        if (str_starts_with($data, 'adminst_')) {
            $this->handleAdminStatusCallback($callbackId, $data, $chatId, $telegramUser);

            return;
        }

        if (str_starts_with($data, 'drivest_')) {
            $this->handleDriverStatusCallback($callbackId, $data, $chatId, $telegramUser);
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

        $driver = $this->botAuth->resolveDriverActor($telegramUser);
        $staff = $this->botAuth->resolveStaffActor($telegramUser);
        $allowed = $this->telegram->canChatAccessOrder($chatId, $order, $telegramUser)
            || $this->botAuth->courierCanOpenJob($order)
            || ($driver && $this->botAuth->driverCanOpenJob($driver, $order))
            || ($staff && $this->botAuth->staffCanManageOrders($staff));

        if (! $allowed) {
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
            $staff = $this->botAuth->resolveStaffActor($telegramUser);
            if (! $staff || ! $this->botAuth->staffCanManageOrders($staff)) {
                $this->telegram->sendMessage((string) $chatId, TelegramBotTexts::get($locale, 'not_subscribed'));

                return;
            }
        }

        $this->telegram->sendInvoiceToChat($chatId, $order, $locale);
    }

    private function handleAdminStatusCallback(string $callbackId, string $data, int $chatId, TelegramUser $telegramUser): void
    {
        $locale = TelegramBotTexts::resolveLocale($telegramUser);

        $staff = $this->botAuth->resolveStaffActor($telegramUser);
        $allowed = $staff && $this->botAuth->staffCanManageOrders($staff);

        if (! $allowed || ! $this->telegram->isAdminChatId($chatId)) {
            $this->telegram->answerCallbackQuery($callbackId, 'Staff link required');
            $this->telegram->sendMessage((string) $chatId, TelegramBotTexts::get($locale, 'staff_action_denied'));

            return;
        }

        $parts = explode('_', $data);
        if (count($parts) < 3) {
            $this->telegram->answerCallbackQuery($callbackId, 'Invalid');
            $this->telegram->sendMessage((string) $chatId, TelegramBotTexts::get($locale, 'invalid_order'));

            return;
        }

        $orderId = (int) ($parts[1] ?? 0);
        $status = implode('_', array_slice($parts, 2));
        if ($orderId <= 0 || $status === '') {
            $this->telegram->answerCallbackQuery($callbackId, 'Invalid');
            $this->telegram->sendMessage((string) $chatId, TelegramBotTexts::get($locale, 'invalid_order'));

            return;
        }

        $order = Order::with('shipment')->find($orderId);
        if (! $order) {
            $this->telegram->answerCallbackQuery($callbackId, 'Not found');
            $this->telegram->sendMessage((string) $chatId, TelegramBotTexts::get($locale, 'order_not_found_short'));

            return;
        }

        $result = $this->telegram->updateShipmentStatusFromAdminChat($order, $status, $staff);
        $toast = $result['ok']
            ? ('OK · '.str_replace('_', ' ', $status))
            : ('Fail · '.($result['message'] ?? 'error'));
        $this->telegram->answerCallbackQuery($callbackId, mb_substr($toast, 0, 180));

        $this->telegram->sendMessage(
            (string) $chatId,
            ($result['ok'] ? '' : '❌ ').$result['message'],
            $this->telegram->keyboardForChat($chatId, $order->fresh(['shipment', 'user', 'items.product'])),
            null
        );
    }

    private function handleDriverStatusCallback(string $callbackId, string $data, int $chatId, TelegramUser $telegramUser): void
    {
        $locale = TelegramBotTexts::resolveLocale($telegramUser);

        $parts = explode('_', $data);
        if (count($parts) < 3) {
            $this->telegram->answerCallbackQuery($callbackId, 'Invalid');

            return;
        }

        $orderId = (int) ($parts[1] ?? 0);
        $action = implode('_', array_slice($parts, 2));
        $order = Order::with('shipment')->find($orderId);

        if ($orderId <= 0 || ! $order) {
            $this->telegram->answerCallbackQuery($callbackId, 'Not found');
            $this->telegram->sendMessage((string) $chatId, TelegramBotTexts::get($locale, 'order_not_found_short'));

            return;
        }

        if (! $this->botAuth->courierCanOpenJob($order)) {
            $this->telegram->answerCallbackQuery($callbackId, 'Locked');
            $this->telegram->sendMessage((string) $chatId, TelegramBotTexts::get($locale, 'courier_job_locked'));

            return;
        }

        if ($action === 'complete') {
            if ($order->payment_method === 'cod' && ! $order->isPaymentSuccessful()) {
                $this->telegram->answerCallbackQuery($callbackId, 'Pay first');
                $this->telegram->sendMessage(
                    (string) $chatId,
                    TelegramBotTexts::get($locale, 'driver_complete_need_pay'),
                    $this->telegram->buildDriverOrderKeyboard($order)
                );

                return;
            }
            $action = 'delivered';
        }

        $allowedStatuses = ['shipped', 'in_transit', 'delivered'];
        if (! in_array($action, $allowedStatuses, true)) {
            $this->telegram->answerCallbackQuery($callbackId, 'Not allowed');

            return;
        }

        $courierLabel = trim(implode(' ', array_filter([
            $telegramUser->first_name,
            $telegramUser->last_name,
            $telegramUser->username ? '@'.$telegramUser->username : null,
        ]))) ?: ('tg:'.$telegramUser->telegram_user_id);

        $result = $this->telegram->updateShipmentStatusFromAdminChat(
            $order,
            $action,
            null,
            'External courier via Telegram ('.$courierLabel.')'
        );

        if ($result['ok'] && $action === 'delivered') {
            // Close the job QR after successful delivery.
            if ($order->shipment) {
                $order->shipment->update([
                    'courier_job_unlocked_at' => null,
                    'courier_job_unlocked_by' => null,
                ]);
            }
        }

        $toast = $result['ok']
            ? ('OK · '.str_replace('_', ' ', $action))
            : ('Fail · '.($result['message'] ?? 'error'));
        $this->telegram->answerCallbackQuery($callbackId, mb_substr($toast, 0, 180));

        $fresh = $order->fresh(['shipment', 'user', 'items']);
        $keyboard = null;
        $extra = '';
        if ($fresh && $this->botAuth->courierCanOpenJob($fresh)) {
            $keyboard = $this->telegram->buildDriverOrderKeyboard($fresh);
        } elseif ($result['ok'] && $action === 'delivered') {
            $extra = "\n\n".TelegramBotTexts::get($locale, 'courier_job_done_locked');
        }

        $this->telegram->sendMessage(
            (string) $chatId,
            ($result['ok'] ? '' : '❌ ').$result['message'].$extra,
            $keyboard,
            null
        );
    }
}
