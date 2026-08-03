<?php

namespace App\Console\Commands;

use App\Services\HuggingFaceSpaceChatService;
use Illuminate\Console\Command;

class ChatAiDiagnoseCommand extends Command
{
    protected $signature = 'chat-ai:diagnose {--ping-chat : Also send a tiny chat request}';

    protected $description = 'Check the Fitandsleek Hugging Face chat Space';

    public function handle(HuggingFaceSpaceChatService $chatAi): int
    {
        $this->info('HF_CHAT_BASE_URL = '.($chatAi->baseUrl() !== '' ? $chatAi->baseUrl() : '(empty)'));
        $this->info('HF_CHAT_SPACE_ID = '.$chatAi->spaceId());

        if (! $chatAi->isConfigured()) {
            $this->error('Not configured. Set HF_CHAT_BASE_URL in backend/.env');

            return self::FAILURE;
        }

        if (! $chatAi->ping()) {
            $this->error('Cannot reach Hugging Face Space at '.$chatAi->baseUrl());
            $this->line('→ Open https://huggingface.co/spaces/'.$chatAi->spaceId().' and ensure status is Running.');

            return self::FAILURE;
        }

        $this->info('✓ Hugging Face Space is reachable');

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
        $this->info('Admin + storefront chat are ready to use the Hugging Face Space.');

        return self::SUCCESS;
    }
}
