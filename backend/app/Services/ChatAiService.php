<?php

namespace App\Services;

use RuntimeException;

/**
 * Provider facade for Fitandsleek chat AI (Hugging Face Space or Ollama).
 */
class ChatAiService
{
    public function __construct(
        private readonly HuggingFaceSpaceChatService $huggingface,
        private readonly OllamaChatService $ollama,
    ) {
    }

    public function provider(): string
    {
        $configured = strtolower(trim((string) config('services.chat_ai.provider', 'huggingface')));

        return in_array($configured, ['huggingface', 'hf', 'ollama'], true)
            ? ($configured === 'hf' ? 'huggingface' : $configured)
            : 'huggingface';
    }

    public function isConfigured(): bool
    {
        return $this->provider() === 'ollama'
            ? $this->ollama->isConfigured()
            : $this->huggingface->isConfigured();
    }

    public function label(): string
    {
        return $this->provider() === 'ollama' ? 'Ollama' : 'Hugging Face Space';
    }

    /**
     * @param  list<array{role: string, content: string}>  $messages
     * @return array{reply: string, model: string, provider: string, raw?: array<string, mixed>}
     */
    public function chat(array $messages, array $options = []): array
    {
        if (! $this->isConfigured()) {
            throw new RuntimeException(
                $this->provider() === 'ollama'
                    ? 'Ollama is not configured. Set OLLAMA_BASE_URL and OLLAMA_MODEL in backend .env.'
                    : 'Hugging Face chat is not configured. Set HF_CHAT_BASE_URL in backend .env.'
            );
        }

        if ($this->provider() === 'ollama') {
            $result = $this->ollama->chat($messages, $options);

            return [
                'reply' => $result['reply'],
                'model' => $result['model'],
                'provider' => 'ollama',
                'raw' => $result['raw'] ?? [],
            ];
        }

        return $this->huggingface->chat($messages, $options);
    }

    public function ping(): bool
    {
        return $this->provider() === 'ollama'
            ? $this->ollama->ping()
            : $this->huggingface->ping();
    }
}
