<?php

namespace App\Console\Commands;

use App\Services\ChatAiService;
use Illuminate\Console\Command;

class ChatAiDiagnoseCommand extends Command
{
    protected $signature = 'chat-ai:diagnose {--ping-chat : Also send a tiny chat request}';

    protected $description = 'Check the configured Fitandsleek chat AI provider (Hugging Face Space or Ollama)';

    public function handle(ChatAiService $chatAi): int
    {
        $provider = $chatAi->provider();
        $this->info('CHAT_AI_PROVIDER = '.$provider);
        $this->info('Active label     = '.$chatAi->label());

        if ($provider === 'huggingface') {
            $this->line('HF_CHAT_BASE_URL = '.(string) config('services.hf_chat.base_url'));
            $this->line('HF_CHAT_SPACE_ID = '.(string) config('services.hf_chat.space_id'));
        } else {
            $this->line('OLLAMA_BASE_URL  = '.(string) config('services.ollama.base_url'));
            $this->line('OLLAMA_MODEL     = '.(string) config('services.ollama.model'));
        }

        if (! $chatAi->isConfigured()) {
            $this->error('Provider is not configured.');

            return self::FAILURE;
        }

        if (! $chatAi->ping()) {
            $this->error('Cannot reach '.$chatAi->label());
            if ($provider === 'huggingface') {
                $this->line('→ Open https://huggingface.co/spaces/'.config('services.hf_chat.space_id').' and ensure status is Running.');
            } else {
                $this->line('→ Start Ollama and pull the model.');
            }

            return self::FAILURE;
        }

        $this->info('✓ '.$chatAi->label().' is reachable');

        if ($this->option('ping-chat')) {
            $this->line('Sending test chat…');
            try {
                $result = $chatAi->chat([
                    ['role' => 'user', 'content' => 'Hello'],
                ]);
                $this->info('✓ Chat reply: '.mb_substr($result['reply'], 0, 200));
                $this->line('  provider='.$result['provider'].' model='.$result['model']);
            } catch (\Throwable $e) {
                $this->error('Chat failed: '.$e->getMessage());

                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->info('Admin + storefront chat are ready to use '.$chatAi->label().'.');

        return self::SUCCESS;
    }
}
