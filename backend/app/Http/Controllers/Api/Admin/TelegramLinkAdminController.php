<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Order;
use App\Models\User;
use App\Services\TelegramBotAuthService;
use App\Services\TelegramService;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;
use Throwable;

class TelegramLinkAdminController extends BaseAdminController
{
    public function __construct(
        private TelegramBotAuthService $auth,
        private TelegramService $telegram,
    ) {
    }

    public function status(Request $request, User $user): JsonResponse
    {
        if (! $this->canManageTelegramLink($request->user(), $user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return response()->json([
            'message' => 'Telegram link status',
            'data' => $this->auth->telegramLinkStatus($user),
        ]);
    }

    public function createCode(Request $request, User $user): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();

        if (! $this->canManageTelegramLink($viewer, $user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (! $this->telegram->isEnabled() || ! $this->telegram->botUsername()) {
            return response()->json([
                'message' => 'Telegram bot is not configured (token / bot name).',
            ], 422);
        }

        try {
            $payload = $this->auth->createLinkCode($user, $viewer);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Failed to create link code.'], 500);
        }

        return response()->json([
            'message' => 'Telegram link code created',
            'data' => $payload,
        ]);
    }

    public function unlink(Request $request, User $user): JsonResponse
    {
        if (! $this->canManageTelegramLink($request->user(), $user)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $this->auth->unlinkUser($user);

        return response()->json([
            'message' => 'Telegram unlinked',
            'data' => $this->auth->telegramLinkStatus($user),
        ]);
    }

    /**
     * Deep link + QR for drivers to open one order job in the Telegram bot.
     */
    public function driverJobQr(Request $request, Order $order): JsonResponse|Response
    {
        /** @var User $viewer */
        $viewer = $request->user();

        if (! $viewer->isSuperAdmin() && ! $viewer->hasAdminPermission('orders', 'view')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        if (! $this->telegram->isEnabled() || ! $this->telegram->botUsername()) {
            return response()->json([
                'message' => 'Telegram bot is not configured (token / bot name).',
            ], 422);
        }

        $deepLink = $this->auth->buildDriverJobDeepLink($order);
        if (! $deepLink) {
            return response()->json(['message' => 'Could not build Telegram deep link.'], 422);
        }

        if ($request->query('format') === 'json') {
            return response()->json([
                'message' => 'Driver job Telegram link',
                'data' => [
                    'deep_link' => $deepLink,
                    'order_id' => $order->id,
                    'order_number' => $order->order_number,
                    'start_payload' => 'job_'.($order->order_number ?: (string) $order->id),
                ],
            ]);
        }

        $writer = new PngWriter();
        $qrCode = QrCode::create($deepLink)
            ->setEncoding(new Encoding('UTF-8'))
            ->setSize(320)
            ->setMargin(12)
            ->setForegroundColor(new Color(0, 0, 0))
            ->setBackgroundColor(new Color(255, 255, 255));

        $result = $writer->write($qrCode);

        return response($result->getString(), 200)
            ->header('Content-Type', $result->getMimeType())
            ->header('Cache-Control', 'no-store');
    }

    private function canManageTelegramLink(?User $viewer, User $target): bool
    {
        if (! $viewer) {
            return false;
        }

        if ($this->auth->purposeForUser($target) === null) {
            return false;
        }

        if ($viewer->isSuperAdmin()) {
            return true;
        }

        // Shop admins may only manage driver Telegram links.
        return $viewer->isAdmin() && $target->isDriver();
    }
}
