<?php

namespace App\Console\Commands;

use App\Services\OllamaChatService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class OllamaDiagnoseCommand extends Command
{
    protected $signature = 'ollama:diagnose {--ping-chat : Also send a tiny chat request}';

    protected $description = 'Check Ollama reachability and configured model for Fitandsleek chat AI';

    public function handle(OllamaChatService $ollama): int
    {
        $base = $ollama->baseUrl();
        $model = $ollama->model();

        $this->info('OLLAMA_BASE_URL = ' . ($base !== '' ? $base : '(empty)'));
        $this->info('OLLAMA_MODEL    = ' . ($model !== '' ? $model : '(empty)'));

        if (! $ollama->isConfigured()) {
            $this->error('Not configured. Add OLLAMA_BASE_URL and OLLAMA_MODEL to backend/.env');

            return self::FAILURE;
        }

        if (! $ollama->ping()) {
            $this->error('Cannot reach Ollama at ' . $base);
            $this->line('→ Install from https://ollama.com and ensure the app is running.');
            $this->line('→ Then: ollama pull ' . $model);

            return self::FAILURE;
        }

        $this->info('✓ Ollama is reachable');

        try {
            $tags = Http::timeout(10)->get($base . '/api/tags')->json('models') ?? [];
            $names = collect($tags)->pluck('name')->filter()->values()->all();
            $this->line('Installed models: ' . ($names === [] ? '(none)' : implode(', ', $names)));

            $hasModel = collect($names)->contains(function ($name) use ($model) {
                return $name === $model
                    || str_starts_with((string) $name, $model)
                    || str_starts_with($model, (string) $name);
            });

            if (! $hasModel) {
                $this->warn('Configured model not found locally.');
                $this->line('→ Run: ollama pull ' . $model);

                return self::FAILURE;
            }

            $this->info('✓ Model "' . $model . '" is available');
        } catch (\Throwable $e) {
            $this->warn('Could not list models: ' . $e->getMessage());
        }

        if ($this->option('ping-chat')) {
            $this->line('Sending test chat…');
            try {
                $result = $ollama->chat([
                    ['role' => 'system', 'content' => 'Reply with exactly: OK'],
                    ['role' => 'user', 'content' => 'ping'],
                ], ['num_predict' => 16, 'temperature' => 0]);
                $this->info('✓ Chat reply: ' . $result['reply']);
            } catch (\Throwable $e) {
                $this->error('Chat failed: ' . $e->getMessage());

                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->info('Admin + storefront chat are ready to use Ollama.');

        return self::SUCCESS;
    }
}
