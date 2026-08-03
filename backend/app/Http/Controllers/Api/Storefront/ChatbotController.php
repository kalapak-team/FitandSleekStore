<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use App\Services\ChatAiService;
use App\Services\ChatFaqContextService;
use App\Services\StorefrontChatContextService;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;

class ChatbotController extends Controller
{
    public function __construct(
        private readonly StorefrontChatContextService $storeContext,
        private readonly ChatAiService $chatAi,
        private readonly ChatFaqContextService $faqContext,
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
                'error' => 'AI chatbot is not configured. Set CHAT_AI_PROVIDER=huggingface and HF_CHAT_BASE_URL (or OLLAMA_*).',
            ], 503);
        }

        $user = $this->resolveUser($request);
        $provider = $this->chatAi->provider();

        // Hugging Face Space already has FAQ / RAG / store logic — keep Laravel context light.
        // Ollama still gets the full live storefront snapshot.
        if ($provider === 'ollama') {
            $context = $this->storeContext->toPromptContext($validated['message'], $user);
            $faq = $this->faqContext->toPromptBlock($validated['message'], 4);
            $systemText = 'You are Fit & Sleek customer support assistant. Be friendly, professional, and concise (1–3 sentences). '
                . 'Help with products, orders, shipping, returns, sizing, discounts, and store navigation. '
                . 'If the user writes in Khmer, respond in Khmer. Otherwise respond in English. '
                . 'Do NOT use markdown tables — product cards appear in the chat UI.';

            if ($context) {
                $systemText .= "\n\n[Live store database — use ONLY this data]\n" . $context;
            }
            if ($faq !== '') {
                $systemText .= "\n\n" . $faq;
            }
        } else {
            $systemText = 'You are Fit & Sleek customer support on the Fitandsleek website. '
                . 'Be friendly and concise. Match the customer language (Khmer or English). '
                . 'Do NOT use markdown tables — product cards appear in the chat UI.';

            if ($user !== null) {
                $systemText .= "\nSigned-in customer id: {$user->id}; email: {$user->email}.";
            }
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
            $result = $this->chatAi->chat($messages, [
                'temperature' => 0.55,
                'num_predict' => 512,
            ]);
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
            'provider' => $result['provider'] ?? $provider,
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
