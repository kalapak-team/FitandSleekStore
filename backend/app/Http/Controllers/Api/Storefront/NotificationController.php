<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\Notification;
use App\Models\Product;
use App\Support\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class NotificationController extends Controller
{
    /**
     * Get user notifications and messages.
     */
    public function index(Request $request)
    {
        $user = Auth::user();
        $isGuest = !$user;

        // Get user notifications (only for authenticated users)
        $notifications = collect();
        $totalUnreadNotifications = 0;

        if ($user) {
            $notifications = Notification::forUser($user->id)
                ->orderBy('created_at', 'desc')
                ->get()
                ->map(function ($notification) {
                    $linkUrl = $this->resolveNotificationLink($notification);
                    $data = is_array($notification->data) ? $notification->data : [];
                    $storedMedia = is_string($data['image_url'] ?? null) ? $data['image_url'] : null;
                    $mediaUrl = $this->resolveItemMediaUrl($storedMedia, $linkUrl);

                    return [
                        'id' => 'notification-' . $notification->id,
                        'type' => $notification->type ?: 'notification',
                        'title' => $notification->title,
                        'message' => $notification->message,
                        'data' => $notification->data,
                        'link_url' => $linkUrl,
                        'media_url' => $mediaUrl,
                        'media_type' => $mediaUrl ? 'image' : null,
                        'is_read' => $notification->is_read,
                        'created_at' => $notification->created_at,
                    ];
                });

            $totalUnreadNotifications = Notification::forUser($user->id)->unread()->count();
        }

        // Get active messages for this user/guest
        $userLanguage = $request->header('Accept-Language', 'en');
        $userLanguage = in_array($userLanguage, ['en', 'km']) ? $userLanguage : 'en';

        // Determine audience: guests for unauthenticated, or based on user role
        $audience = $isGuest ? 'guests' : ($user->role === 'customer' ? 'customers' : 'guests');

        $messages = Message::active()
            ->byLanguage($userLanguage)
            ->where(function ($query) use ($audience) {
                $query->where('target_audience', 'all')
                      ->orWhere('target_audience', $audience);
            })
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($message) {
                $linkUrl = $this->normalizeStorefrontLink($message->link_url);
                $mediaUrl = $this->resolveItemMediaUrl($message->media_url, $linkUrl);

                return [
                    'id' => 'message-' . $message->id,
                    'type' => 'message',
                    'title' => $message->title,
                    'message' => $message->content,
                    'link_url' => $linkUrl,
                    'media_url' => $mediaUrl,
                    'media_type' => $mediaUrl ? ($message->media_type ?: 'image') : null,
                    'language' => $message->language,
                    'target_audience' => $message->target_audience,
                    'is_read' => false, // Messages don't have read status
                    'created_at' => $message->created_at,
                ];
            });

        $messageCount = $messages->count();

        // Combine and sort by creation date
        $allItems = collect([...$notifications, ...$messages])
            ->sortByDesc('created_at')
            ->values();

        // Paginate the combined results
        $perPage = $request->per_page ?? 20;
        $page = $request->page ?? 1;
        $paginatedItems = $allItems->forPage($page, $perPage);

        return response()->json([
            'notifications' => [
                'data' => $paginatedItems,
                'current_page' => $page,
                'per_page' => $perPage,
                'total' => $allItems->count(),
                'last_page' => ceil($allItems->count() / $perPage),
            ],
            'unread_count' => $totalUnreadNotifications + $messageCount,
        ]);
    }

    /**
     * Get unread notification count.
     */
    public function unreadCount()
    {
        $user = Auth::user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $count = Notification::forUser($user->id)->unread()->count();

        return response()->json(['unread_count' => $count]);
    }

    /**
     * Mark a notification as read.
     */
    public function markAsRead(Request $request, $id)
    {
        $user = Auth::user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $notification = Notification::forUser($user->id)->find($id);

        if (!$notification) {
            return response()->json(['error' => 'Notification not found'], 404);
        }

        $notification->markAsRead();

        return response()->json(['success' => true]);
    }

    /**
     * Mark all notifications as read.
     */
    public function markAllAsRead()
    {
        $user = Auth::user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        Notification::forUser($user->id)->unread()->update(['is_read' => true]);

        return response()->json(['success' => true]);
    }

    /**
     * Delete a notification.
     */
    public function destroy($id)
    {
        $user = Auth::user();
        
        if (!$user) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $notification = Notification::forUser($user->id)->find($id);

        if (!$notification) {
            return response()->json(['error' => 'Notification not found'], 404);
        }

        $notification->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Convert stored URLs to in-app storefront paths for the SPA router.
     */
    private function normalizeStorefrontLink(?string $link): ?string
    {
        if ($link === null || trim($link) === '') {
            return null;
        }

        $link = trim($link);

        if (str_starts_with($link, '/')) {
            return preg_replace('#^/products/#', '/p/', $link) ?: null;
        }

        $path = parse_url($link, PHP_URL_PATH);
        if (! is_string($path) || $path === '' || $path === '/') {
            return null;
        }

        $query = parse_url($link, PHP_URL_QUERY);
        $fragment = parse_url($link, PHP_URL_FRAGMENT);
        $normalized = preg_replace('#^/products/#', '/p/', $path);

        if ($query) {
            $normalized .= '?' . $query;
        }
        if ($fragment) {
            $normalized .= '#' . $fragment;
        }

        return $normalized;
    }

    /**
     * Derive an in-app link for account notifications (orders, promos, etc.).
     */
    private function resolveNotificationLink(Notification $notification): ?string
    {
        $data = is_array($notification->data) ? $notification->data : [];

        if (! empty($data['link_url']) && is_string($data['link_url'])) {
            return $this->normalizeStorefrontLink($data['link_url']);
        }

        if (! empty($data['order_id'])) {
            return '/orders/' . $data['order_id'];
        }

        return match ($notification->type) {
            'order' => '/orders',
            'promotion' => '/search',
            default => null,
        };
    }

    /**
     * Resolve a displayable image for storefront notifications (mobile + web).
     * Falls back to the linked product's current cover when stored media is missing or stale.
     */
    private function resolveItemMediaUrl(?string $storedMedia, ?string $linkUrl): ?string
    {
        $candidates = [];

        if ($storedMedia !== null && trim($storedMedia) !== '') {
            $candidates[] = Media::url($storedMedia);
        }

        $slug = $this->productSlugFromStorefrontLink($linkUrl);
        if ($slug !== null) {
            $product = Product::query()->where('slug', $slug)->first(['image_url']);
            if ($product?->image_url) {
                $candidates[] = Media::url($product->image_url);
            }
        }

        foreach ($candidates as $candidate) {
            $absolute = $this->absoluteMediaUrl($candidate);
            if ($this->isUsableNotificationMedia($absolute)) {
                return $absolute;
            }
        }

        return null;
    }

    private function productSlugFromStorefrontLink(?string $link): ?string
    {
        $normalized = $this->normalizeStorefrontLink($link);
        if ($normalized === null) {
            return null;
        }

        if (preg_match('#^/p/([^/?#]+)#', $normalized, $matches) === 1) {
            return urldecode($matches[1]);
        }

        return null;
    }

    private function absoluteMediaUrl(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $url = trim($url);

        if (Media::isExternalUrl($url)) {
            return $url;
        }

        $base = rtrim((string) config('app.url'), '/');

        return str_starts_with($url, '/') ? $base.$url : $base.'/'.ltrim($url, '/');
    }

    private function isUsableNotificationMedia(?string $url): bool
    {
        if ($url === null || trim($url) === '') {
            return false;
        }

        return ! str_contains(strtolower($url), 'placeholder.svg');
    }
}

