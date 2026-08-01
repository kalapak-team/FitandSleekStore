<?php

namespace App\Services;

use App\Models\Order;
use App\Models\TelegramLinkCode;
use App\Models\TelegramUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class TelegramBotAuthService
{
    public function __construct(private TelegramService $telegram)
    {
    }

    /**
     * Create a one-time deep-link code for staff or driver Telegram binding.
     *
     * @return array{code: string, purpose: string, deep_link: ?string, expires_at: string, bot_command: string}
     */
    public function createLinkCode(User $target, User $createdBy, int $ttlMinutes = 10): array
    {
        if (! $target->isActive()) {
            throw new RuntimeException('Cannot link an inactive user.');
        }

        $purpose = $this->purposeForUser($target);
        if ($purpose === null) {
            throw new RuntimeException('Only admin, superadmin, or driver accounts can be linked to Telegram.');
        }

        $code = $this->generateUniqueCode();

        TelegramLinkCode::query()
            ->where('user_id', $target->id)
            ->whereNull('used_at')
            ->where('expires_at', '>', now())
            ->update(['expires_at' => now()]);

        $row = TelegramLinkCode::create([
            'user_id' => $target->id,
            'code' => $code,
            'purpose' => $purpose,
            'created_by' => $createdBy->id,
            'expires_at' => now()->addMinutes(max(1, $ttlMinutes)),
        ]);

        $payload = $purpose.'_'.$code;

        return [
            'code' => $code,
            'purpose' => $purpose,
            'deep_link' => $this->telegram->buildBotDeepLink($payload),
            'bot_command' => '/start '.$payload,
            'expires_at' => $row->expires_at->toIso8601String(),
        ];
    }

    /**
     * Redeem a staff_/driver_ start payload and link the Telegram account.
     *
     * @return array{ok: bool, message: string, user: ?User}
     */
    public function redeemStartPayload(string $payload, TelegramUser $telegramUser): array
    {
        $payload = trim($payload);
        if (! preg_match('/^(staff|driver)_([A-Z0-9]{6,16})$/i', $payload, $m)) {
            return ['ok' => false, 'message' => 'invalid', 'user' => null];
        }

        $purpose = strtolower($m[1]);
        $code = strtoupper($m[2]);

        return DB::transaction(function () use ($purpose, $code, $telegramUser) {
            /** @var TelegramLinkCode|null $row */
            $row = TelegramLinkCode::query()
                ->where('code', $code)
                ->lockForUpdate()
                ->first();

            if (! $row || $row->purpose !== $purpose) {
                return ['ok' => false, 'message' => 'not_found', 'user' => null];
            }

            if ($row->isUsed()) {
                return ['ok' => false, 'message' => 'used', 'user' => null];
            }

            if ($row->isExpired()) {
                return ['ok' => false, 'message' => 'expired', 'user' => null];
            }

            $user = User::query()->find($row->user_id);
            if (! $user || ! $user->isActive()) {
                return ['ok' => false, 'message' => 'inactive', 'user' => null];
            }

            $expectedPurpose = $this->purposeForUser($user);
            if ($expectedPurpose !== $purpose) {
                return ['ok' => false, 'message' => 'role_mismatch', 'user' => null];
            }

            // One Telegram identity → one staff/driver account.
            TelegramUser::query()
                ->where('user_id', $user->id)
                ->where('id', '!=', $telegramUser->id)
                ->update(['user_id' => null]);

            if ($telegramUser->user_id && (int) $telegramUser->user_id !== (int) $user->id) {
                $existing = User::query()->find($telegramUser->user_id);
                if ($existing && ($existing->isAdmin() || $existing->isDriver())) {
                    return ['ok' => false, 'message' => 'telegram_busy', 'user' => null];
                }
            }

            $this->telegram->linkTelegramToUser($telegramUser, (int) $user->id);

            $row->update([
                'used_at' => now(),
                'used_by_telegram_user_id' => $telegramUser->telegram_user_id,
            ]);

            return ['ok' => true, 'message' => 'linked', 'user' => $user->fresh()];
        });
    }

    public function unlinkUser(User $user): void
    {
        TelegramUser::query()
            ->where('user_id', $user->id)
            ->update(['user_id' => null]);

        TelegramLinkCode::query()
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->update(['expires_at' => now()]);
    }

    public function linkedUser(TelegramUser $telegramUser): ?User
    {
        if (! $telegramUser->user_id) {
            return null;
        }

        $user = $telegramUser->relationLoaded('user')
            ? $telegramUser->user
            : User::query()->find($telegramUser->user_id);

        if (! $user || ! $user->isActive()) {
            return null;
        }

        return $user;
    }

    /** Admin / superadmin who may operate shop bot actions. */
    public function resolveStaffActor(TelegramUser $telegramUser): ?User
    {
        $user = $this->linkedUser($telegramUser);

        return ($user && $user->isAdmin()) ? $user : null;
    }

    public function resolveDriverActor(TelegramUser $telegramUser): ?User
    {
        $user = $this->linkedUser($telegramUser);

        return ($user && $user->isDriver()) ? $user : null;
    }

    public function resolveSuperAdminActor(TelegramUser $telegramUser): ?User
    {
        $user = $this->linkedUser($telegramUser);

        return ($user && $user->isSuperAdmin()) ? $user : null;
    }

    /**
     * Staff may change order status if linked + (superadmin OR orders.edit permission).
     */
    public function staffCanManageOrders(User $user): bool
    {
        if ($user->isSuperAdmin()) {
            return true;
        }

        if (! $user->isAdmin()) {
            return false;
        }

        return $user->hasAdminPermission('orders', 'edit');
    }

    public function driverCanOpenJob(User $driver, Order $order): bool
    {
        if (! $driver->isDriver() || ! $driver->isActive()) {
            return false;
        }

        $status = strtolower((string) ($order->status ?? ''));

        return ! in_array($status, ['cancelled'], true);
    }

    /**
     * External courier (no shop account): job QR works only after admin unlock.
     */
    public function courierCanOpenJob(Order $order): bool
    {
        $status = strtolower((string) ($order->status ?? ''));
        if (in_array($status, ['cancelled'], true)) {
            return false;
        }

        $order->loadMissing('shipment');
        $shipment = $order->shipment;
        if (! $shipment) {
            return false;
        }

        return $shipment->isCourierJobUnlocked();
    }

    /**
     * @return array{unlocked: bool, unlocked_at: ?string, unlocked_by: ?int, deep_link: ?string, start_payload: string}
     */
    public function courierJobStatus(Order $order): array
    {
        $order->loadMissing('shipment');
        $shipment = $order->shipment;
        $key = $order->order_number ?: (string) $order->id;

        return [
            'unlocked' => (bool) $shipment?->isCourierJobUnlocked(),
            'unlocked_at' => $shipment?->courier_job_unlocked_at?->toIso8601String(),
            'unlocked_by' => $shipment?->courier_job_unlocked_by,
            'deep_link' => $this->buildDriverJobDeepLink($order),
            'start_payload' => 'job_'.$key,
        ];
    }

    public function unlockCourierJob(Order $order, User $actor, ?string $pickedUpByName = null, ?string $pickedUpByPhone = null): Order
    {
        if (! $actor->isSuperAdmin() && ! $actor->hasAdminPermission('orders', 'edit') && ! $actor->hasAdminPermission('shipments', 'edit')) {
            throw new RuntimeException('You do not have permission to confirm courier pickup.');
        }

        $order->loadMissing('shipment');
        $shipment = $order->shipment;
        if (! $shipment) {
            $shipment = $order->shipment()->create([
                'provider' => 'External',
                'status' => 'processing',
            ]);
        }

        $updates = [
            'courier_job_unlocked_at' => now(),
            'courier_job_unlocked_by' => $actor->id,
        ];

        if (! $shipment->picked_up_at) {
            $updates['picked_up_at'] = now();
        }
        if ($pickedUpByName !== null && trim($pickedUpByName) !== '') {
            $updates['picked_up_by_name'] = trim($pickedUpByName);
        }
        if ($pickedUpByPhone !== null && trim($pickedUpByPhone) !== '') {
            $updates['picked_up_by_phone'] = trim($pickedUpByPhone);
        }

        $shipment->update($updates);

        return $order->fresh(['shipment', 'user', 'items']);
    }

    public function lockCourierJob(Order $order, User $actor): Order
    {
        if (! $actor->isSuperAdmin() && ! $actor->hasAdminPermission('orders', 'edit') && ! $actor->hasAdminPermission('shipments', 'edit')) {
            throw new RuntimeException('You do not have permission to lock courier job.');
        }

        $order->loadMissing('shipment');
        if ($order->shipment) {
            $order->shipment->update([
                'courier_job_unlocked_at' => null,
                'courier_job_unlocked_by' => null,
            ]);
        }

        return $order->fresh(['shipment', 'user', 'items']);
    }

    public function buildDriverJobDeepLink(Order $order): ?string
    {
        $key = $order->order_number ?: (string) $order->id;

        return $this->telegram->buildBotDeepLink('job_'.$key);
    }

    public function telegramLinkStatus(User $user): array
    {
        $linked = TelegramUser::query()
            ->where('user_id', $user->id)
            ->orderByDesc('last_interacted_at')
            ->first();

        return [
            'linked' => (bool) $linked,
            'telegram_username' => $linked?->username,
            'telegram_user_id' => $linked?->telegram_user_id,
            'chat_id' => $linked?->chat_id,
            'last_interacted_at' => $linked?->last_interacted_at?->toIso8601String(),
            'can_link' => $this->purposeForUser($user) !== null,
            'purpose' => $this->purposeForUser($user),
        ];
    }

    public function purposeForUser(User $user): ?string
    {
        if ($user->isDriver()) {
            return TelegramLinkCode::PURPOSE_DRIVER;
        }

        if ($user->isAdmin()) {
            return TelegramLinkCode::PURPOSE_STAFF;
        }

        return null;
    }

    private function generateUniqueCode(): string
    {
        for ($i = 0; $i < 12; $i++) {
            $code = strtoupper(Str::random(8));
            $code = preg_replace('/[^A-Z0-9]/', 'X', $code) ?: Str::upper(bin2hex(random_bytes(4)));
            if (! TelegramLinkCode::query()->where('code', $code)->exists()) {
                return $code;
            }
        }

        return strtoupper(bin2hex(random_bytes(5)));
    }
}
