<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use App\Services\HuggingFaceSpaceChatService;
use App\Services\StorefrontChatContextService;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;

class ChatbotController extends Controller
{
    public function __construct(
        private readonly StorefrontChatContextService $storeContext,
        private readonly HuggingFaceSpaceChatService $chatAi,
    ) {
    }

    private function defaults(): array
    {
        return [
            'enabled' => true,
            'greeting' => 'Hi there 👋',
            'welcome' => 'How can we help you today?',
            'messenger_url' => '',
            'telegram_url' => '',
            'instagram_url' => '',
            'social_links' => [],
        ];
    }

    public function settings()
    {
        $row = Setting::where('key', 'chatbot')->first();
        $value = is_array($row?->value) ? $row->value : [];

        return response()->json([
            'data' => array_merge($this->defaults(), $value),
        ]);
    }

    public function message(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:2000',
            'history' => 'sometimes|array',
            'history.*.role' => 'required_with:history|string',
            'history.*.content' => 'required_with:history|string',
        ]);

        if (! $this->chatAi->isConfigured()) {
            return response()->json([
                'error' => 'AI chatbot is not configured. Set CHATBOT_HF_URL in backend .env.',
            ], 503);
        }

        $user = $this->resolveUser($request);

        // Space already has FAQ / RAG / store logic — keep Laravel system prompt light.
        $systemText = 'You are Fit & Sleek customer support on the Fitandsleek website. '
            . 'Be friendly and concise. Match the customer language (Khmer or English). '
            . 'Do NOT use markdown tables — product cards appear in the chat UI.';

        if ($user !== null) {
            $systemText .= "\nSigned-in customer id: {$user->id}; email: {$user->email}.";
        }

        $messages = [['role' => 'system', 'content' => $systemText]];

        foreach ($validated['history'] ?? [] as $item) {
            $role = ($item['role'] ?? '') === 'assistant' ? 'assistant' : 'user';
            $content = trim((string) ($item['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $messages[] = ['role' => $role, 'content' => $content];
        }

        $messages[] = ['role' => 'user', 'content' => $validated['message']];

        if (count($messages) > 13) {
            $messages = array_merge(
                [$messages[0]],
                array_slice($messages, -12),
            );
        }

        try {
            $result = $this->chatAi->chat($messages);
        } catch (RuntimeException $e) {
            report($e);

            return response()->json([
                'error' => $e->getMessage(),
            ], str_contains(strtolower($e->getMessage()), 'not reachable')
                || str_contains(strtolower($e->getMessage()), 'timed out')
                ? 503
                : 502);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'error' => 'Chatbot request failed.',
            ], 500);
        }

        $products = $this->storeContext->buildRichProductsForMessage($validated['message']);
        $reply = $this->polishReply(trim($result['reply']), $products);

        return response()->json([
            'reply' => $reply,
            'products' => $products,
            'provider' => $result['provider'] ?? 'huggingface',
            'model' => $result['model'],
        ]);
    }

    private function polishReply(string $reply, array $products): string
    {
        if ($products === []) {
            return trim($reply);
        }

        $clean = preg_replace('/^\|.+\|\s*\r?\n\|[-:\s|]+\|\s*\r?\n(?:\|.+\|\s*\r?\n?)+/m', '', $reply) ?? $reply;
        $clean = preg_replace('/^#{1,6}\s+.+$/m', '', $clean) ?? $clean;
        $clean = preg_replace("/\n{3,}/", "\n\n", trim($clean));

        if ($clean === '') {
            return 'Here are some picks from our store:';
        }

        if (strlen($clean) > 280) {
            $parts = preg_split('/(?<=[.!?។])\s+/u', $clean) ?: [$clean];

            return trim(implode(' ', array_slice($parts, 0, 2)));
        }

        return $clean;
    }

    private function resolveUser(Request $request): ?User
    {
        $token = $request->bearerToken();
        if (! $token) {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($token);
        $user = $accessToken?->tokenable;

        return $user instanceof User ? $user : null;
    }
}
