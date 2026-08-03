<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Client for the Fitandsleek Gradio chat Space on Hugging Face.
 *
 * Space: https://huggingface.co/spaces/Kalapak/fitandsleekAIchat
 * Host:  https://kalapak-fitandsleekaichat.hf.space
 *
 * Gradio 5 API: POST /gradio_api/call/chat  data=["message", null]
 * then GET /gradio_api/call/chat/{event_id} (SSE) until event: complete.
 */
class HuggingFaceSpaceChatService
{
    public function isConfigured(): bool
    {
        return $this->baseUrl() !== '';
    }

    public function baseUrl(): string
    {
        return rtrim(trim((string) config('services.hf_chat.base_url', '')), '/');
    }

    public function timeout(): int
    {
        return max(30, (int) config('services.hf_chat.timeout', 180));
    }

    public function spaceId(): string
    {
        return trim((string) config('services.hf_chat.space_id', 'Kalapak/fitandsleekAIchat'));
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{reply: string, model: string, provider: string, raw: array<string, mixed>}
     */
    public function chat(array $messages, array $options = []): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                'Hugging Face chat Space is not configured. Set HF_CHAT_BASE_URL in backend .env.'
            );
        }

        $prompt = $this->messagesToPrompt($messages);
        if ($prompt === '') {
            throw new RuntimeException('Empty chat message.');
        }

        $eventId = $this->enqueue($prompt);
        $payload = $this->waitForResult($eventId);
        $reply = $this->extractReply($payload);

        if ($reply === '') {
            throw new RuntimeException('Hugging Face Space returned an empty reply.');
        }

        return [
            'reply' => $reply,
            'model' => $this->spaceId(),
            'provider' => 'huggingface',
            'raw' => is_array($payload) ? $payload : [],
        ];
    }

    public function ping(): bool
    {
        if ($this->baseUrl() === '') {
            return false;
        }

        try {
            $response = Http::timeout(15)->get($this->baseUrl().'/gradio_api/info');

            return $response->successful()
                && is_array($response->json('named_endpoints./chat'));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Flatten OpenAI-style messages into one Gradio ChatInterface prompt.
     * The Space API only accepts a single message string (+ unused history slot).
     *
     * @param  list<array{role: string, content: string}>  $messages
     */
    private function messagesToPrompt(array $messages): string
    {
        $systemParts = [];
        $turns = [];

        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? '');
            $content = trim((string) ($message['content'] ?? ''));
            if ($content === '') {
                continue;
            }

            if ($role === 'system') {
                $systemParts[] = $content;
                continue;
            }

            $label = $role === 'assistant' ? 'Assistant' : 'User';
            $turns[] = $label.': '.$content;
        }

        if ($turns === []) {
            return '';
        }

        $prompt = '';
        if ($systemParts !== []) {
            $prompt .= "[Context — follow strictly]\n"
                .implode("\n\n", $systemParts)
                ."\n\n";
        }

        // Single turn: send only the user text (Space already has FAQ/RAG/store logic).
        if (count($turns) === 1 && str_starts_with($turns[0], 'User: ')) {
            $userOnly = substr($turns[0], strlen('User: '));

            return $prompt !== ''
                ? $prompt."[Question]\n".$userOnly
                : $userOnly;
        }

        $prompt .= "[Conversation]\n".implode("\n", $turns)."\nAssistant:";

        return $prompt;
    }

    private function enqueue(string $message): string
    {
        $token = trim((string) config('services.hf_chat.token', ''));

        try {
            $request = Http::timeout(30)
                ->acceptJson()
                ->asJson();

            if ($token !== '') {
                $request = $request->withToken($token);
            }

            $response = $request
                ->post($this->baseUrl().'/gradio_api/call/chat', [
                    // Second slot is Gradio ChatInterface history (unused across our HTTP calls).
                    'data' => [$message, null],
                ])
                ->throw();
        } catch (ConnectionException $e) {
            throw new RuntimeException(
                'Hugging Face Space is not reachable at '.$this->baseUrl()
                .'. Check that the Space is Running: https://huggingface.co/spaces/'.$this->spaceId(),
                previous: $e,
            );
        } catch (RequestException $e) {
            $body = (string) ($e->response?->body() ?? $e->getMessage());
            throw new RuntimeException(
                'Hugging Face Space queue error: '.Str::limit($body, 240),
                previous: $e,
            );
        }

        $eventId = trim((string) ($response->json('event_id') ?? ''));
        if ($eventId === '') {
            throw new RuntimeException('Hugging Face Space did not return an event_id.');
        }

        return $eventId;
    }

    /**
     * @return list<mixed>|array<string, mixed>
     */
    private function waitForResult(string $eventId): array
    {
        $token = trim((string) config('services.hf_chat.token', ''));
        $deadline = microtime(true) + $this->timeout();
        $url = $this->baseUrl().'/gradio_api/call/chat/'.$eventId;

        while (microtime(true) < $deadline) {
            try {
                $request = Http::timeout(min(60, max(10, (int) ($deadline - microtime(true)))))
                    ->withHeaders(['Accept' => 'text/event-stream']);

                if ($token !== '') {
                    $request = $request->withToken($token);
                }

                $response = $request->get($url);
            } catch (ConnectionException $e) {
                throw new RuntimeException(
                    'Lost connection while waiting for Hugging Face Space reply.',
                    previous: $e,
                );
            }

            if ($response->failed()) {
                throw new RuntimeException(
                    'Hugging Face Space poll failed HTTP '.$response->status()
                );
            }

            $parsed = $this->parseSse($response->body());
            if (($parsed['event'] ?? '') === 'complete') {
                $data = $parsed['data'] ?? null;
                if (is_array($data)) {
                    return $data;
                }

                throw new RuntimeException('Hugging Face Space completed with an unexpected payload.');
            }

            if (($parsed['event'] ?? '') === 'error') {
                throw new RuntimeException(
                    'Hugging Face Space error while generating a reply. Open the Space UI and retry: '
                    .'https://huggingface.co/spaces/'.$this->spaceId()
                );
            }

            usleep(400_000);
        }

        throw new RuntimeException(
            'Hugging Face Space timed out after '.$this->timeout().'s. The Space may be cold-starting — retry in a minute.'
        );
    }

    /**
     * @return array{event?: string, data?: mixed}
     */
    private function parseSse(string $body): array
    {
        $event = null;
        $dataLines = [];

        foreach (preg_split("/\r\n|\n|\r/", $body) ?: [] as $line) {
            if (str_starts_with($line, 'event:')) {
                $event = trim(substr($line, strlen('event:')));
                continue;
            }
            if (str_starts_with($line, 'data:')) {
                $dataLines[] = substr($line, strlen('data:'));
            }
        }

        if ($dataLines === []) {
            return ['event' => $event];
        }

        $raw = trim(implode("\n", $dataLines));
        if ($raw === '' || strtolower($raw) === 'null') {
            return ['event' => $event, 'data' => null];
        }

        $decoded = json_decode($raw, true);

        return [
            'event' => $event,
            'data' => json_last_error() === JSON_ERROR_NONE ? $decoded : $raw,
        ];
    }

    /**
     * @param  list<mixed>|array<string, mixed>  $payload
     */
    private function extractReply(array $payload): string
    {
        // Gradio ChatInterface returns [reply_string, history_or_null]
        if (array_is_list($payload) && isset($payload[0]) && is_string($payload[0])) {
            return trim($payload[0]);
        }

        if (isset($payload['reply']) && is_string($payload['reply'])) {
            return trim($payload['reply']);
        }

        return '';
    }
}
