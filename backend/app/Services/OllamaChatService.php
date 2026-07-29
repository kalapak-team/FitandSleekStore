<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Thin client for a self-hosted Ollama chat model (own LLM stack).
 */
class OllamaChatService
{
    public function isConfigured(): bool
    {
        return $this->baseUrl() !== '' && $this->model() !== '';
    }

    public function baseUrl(): string
    {
        return rtrim(trim((string) config('services.ollama.base_url', '')), '/');
    }

    public function model(): string
    {
        return trim((string) config('services.ollama.model', ''));
    }

    public function timeout(): int
    {
        return max(10, (int) config('services.ollama.timeout', 120));
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{reply: string, model: string, raw: array<string, mixed>}
     */
    public function chat(array $messages, array $options = []): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException('Ollama is not configured. Set OLLAMA_BASE_URL and OLLAMA_MODEL in backend .env.');
        }

        $payload = [
            'model' => $this->model(),
            'messages' => array_values($messages),
            'stream' => false,
            'options' => array_filter([
                'temperature' => $options['temperature'] ?? (float) config('services.ollama.temperature', 0.4),
                'num_predict' => $options['num_predict'] ?? (int) config('services.ollama.num_predict', 512),
            ], fn ($v) => $v !== null),
        ];

        try {
            $response = Http::timeout($this->timeout())
                ->acceptJson()
                ->post($this->baseUrl() . '/api/chat', $payload)
                ->throw();
        } catch (ConnectionException $e) {
            throw new RuntimeException(
                'Ollama is not reachable at ' . $this->baseUrl() . '. Start Ollama and pull the model first.',
                previous: $e,
            );
        } catch (RequestException $e) {
            $body = $e->response?->json() ?? [];
            $remote = (string) ($body['error'] ?? $e->response?->body() ?? $e->getMessage());

            throw new RuntimeException(
                $this->friendlyError($remote, (int) ($e->response?->status() ?? 0)),
                previous: $e,
            );
        }

        $data = $response->json() ?? [];
        $reply = trim((string) data_get($data, 'message.content', ''));

        if ($reply === '') {
            throw new RuntimeException('Ollama returned an empty reply. Check the model name and try again.');
        }

        return [
            'reply' => $reply,
            'model' => (string) ($data['model'] ?? $this->model()),
            'raw' => is_array($data) ? $data : [],
        ];
    }

    /**
     * Quick health probe: tags endpoint (does not require a pulled chat yet).
     */
    public function ping(): bool
    {
        if ($this->baseUrl() === '') {
            return false;
        }

        try {
            $response = Http::timeout(5)->get($this->baseUrl() . '/api/tags');

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    private function friendlyError(string $raw, int $status): string
    {
        $hay = strtolower($raw);

        if ($status === 404 || str_contains($hay, 'not found') || str_contains($hay, 'model')) {
            return 'Ollama model not found. Run: ollama pull ' . $this->model();
        }

        if (strlen($raw) > 280) {
            return 'Ollama error. Check that Ollama is running and the model is pulled.';
        }

        return $raw !== '' ? $raw : 'Ollama returned an error.';
    }
}
