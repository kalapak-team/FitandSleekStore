<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\AdminDashboardContextService;
use App\Services\AiConversationStore;
use App\Services\ChatFaqContextService;
use App\Services\OllamaChatService;
use Illuminate\Http\Request;
use RuntimeException;

class AdminAiChatController extends Controller
{
    public function __construct(
        private readonly AdminDashboardContextService $dashboardContext,
        private readonly OllamaChatService $ollama,
        private readonly AiConversationStore $conversations,
        private readonly ChatFaqContextService $faqContext,
    ) {
    }

    public function chat(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:4000',
            'conversation_id' => 'nullable|string|max:128',
        ]);

        if (! $this->ollama->isConfigured()) {
            return response()->json([
                'error' => 'ai_not_configured',
                'message' => 'Admin AI is not configured. Set OLLAMA_BASE_URL and OLLAMA_MODEL in your backend .env file, then start Ollama.',
            ], 503);
        }

        $conversation = $this->conversations->loadOrCreate(
            $validated['conversation_id'] ?? null,
            'admin',
        );
        $isFollowUp = ($validated['conversation_id'] ?? '') !== '';

        $system = $this->buildSystemPrompt($validated['message'], $isFollowUp);
        $history = $conversation['messages'];
        $messages = array_merge(
            [['role' => 'system', 'content' => $system]],
            $history,
            [['role' => 'user', 'content' => $validated['message']]],
        );

        try {
            $result = $this->ollama->chat($messages, [
                'temperature' => 0.35,
                'num_predict' => $isFollowUp ? 320 : 512,
            ]);
        } catch (RuntimeException $e) {
            report($e);

            $unreachable = str_contains(strtolower($e->getMessage()), 'not reachable')
                || str_contains(strtolower($e->getMessage()), 'not configured');

            return response()->json([
                'error' => $unreachable ? 'ai_unreachable' : 'ai_upstream_error',
                'message' => $e->getMessage(),
            ], $unreachable ? 503 : 502);
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'error' => 'ai_error',
                'message' => 'Could not reach the AI service. Please try again shortly.',
            ], 500);
        }

        $reply = $this->substituteUnresolvedPlaceholders(
            $result['reply'],
            array_merge(
                $this->dashboardContext->toDifyInputs(),
                $this->dashboardContext->enrichInputsForMessage($validated['message']),
            ),
        );

        $tables = $this->dashboardContext->buildRichTablesForMessage($validated['message']);
        $reply = $this->polishReplyForRichTables($reply, $tables);

        $history[] = ['role' => 'user', 'content' => $validated['message']];
        $history[] = ['role' => 'assistant', 'content' => $reply];
        $this->conversations->save('admin', $conversation['id'], $history);

        return response()->json([
            'reply' => $reply,
            'tables' => $tables,
            'conversation_id' => $conversation['id'],
            'message_id' => null,
            'provider' => 'ollama',
            'model' => $result['model'],
        ]);
    }

    private function buildSystemPrompt(string $message, bool $isFollowUp): string
    {
        $style = $isFollowUp
            ? "Reply in 1–2 short sentences only. Answer ONLY what was asked. Use the admin's language (Khmer or English). "
                . "NEVER use markdown tables (| pipe syntax) or ### headings — the app renders rich tables automatically."
            : "Give a brief professional answer (1–2 sentences). Match the admin's language (Khmer or English). "
                . "NEVER use markdown tables (| pipe syntax) or ### headings — product/inventory data appears as UI tables automatically.";

        $facts = $this->dashboardContext->toCompactQueryContext($message);
        $enrichment = $this->dashboardContext->enrichInputsForMessage($message);
        $extra = '';
        foreach (['user_lookup_text', 'order_lookup_text', 'product_lookup_text'] as $key) {
            if (! empty($enrichment[$key])) {
                $extra .= "\n" . $enrichment[$key];
            }
        }

        $faq = $this->faqContext->toPromptBlock($message, 3);

        return $style
            . "\n\n[Live Fit & Sleek Admin Console data — authoritative source; never invent data]\n"
            . $facts
            . ($extra !== '' ? "\n\n[Lookups for this question]\n" . trim($extra) : '')
            . ($faq !== '' ? "\n\n" . $faq : '');
    }

    /** If the model echoes unreplaced {{total_users}}, fill from live inputs. */
    private function substituteUnresolvedPlaceholders(string $reply, array $inputs): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-z0-9_]+)\s*\}\}/i',
            function (array $m) use ($inputs) {
                $key = $m[1];
                if (isset($inputs[$key]) && is_scalar($inputs[$key])) {
                    return (string) $inputs[$key];
                }

                return $m[0];
            },
            $reply,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $tables
     */
    private function polishReplyForRichTables(string $reply, array $tables): string
    {
        if ($tables === []) {
            return $reply;
        }

        $clean = preg_replace('/^\|.+\|\s*\r?\n\|[-:\s|]+\|\s*\r?\n(?:\|.+\|\s*\r?\n?)+/m', '', $reply) ?? $reply;
        $clean = preg_replace('/^#{1,6}\s+.+$/m', '', $clean) ?? $clean;
        $clean = preg_replace("/\n{3,}/", "\n\n", trim($clean));

        if ($clean === '') {
            foreach ($tables as $table) {
                if (($table['variant'] ?? '') === 'discount') {
                    return 'ខាងក្រោមជាផលិតផលដែលលក់យឺត — គួរពិចារណាបញ្ចុះតម្លៃ៖';
                }
            }

            return 'នេះជាទិន្នន័យពី database៖';
        }

        $paragraphs = preg_split('/\n\s*\n/', $clean) ?: [$clean];
        $first = trim((string) ($paragraphs[0] ?? $clean));

        if (strlen($first) > 220) {
            $sentences = preg_split('/(?<=[.!?។])\s+/u', $first) ?: [$first];

            return trim(implode(' ', array_slice($sentences, 0, 2)));
        }

        return $first;
    }
}
