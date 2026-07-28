<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class TelegramResetMenuButtonCommand extends Command
{
    protected $signature = 'telegram:reset-menu-button';

    protected $description = 'Reset Telegram persistent menu button (Shop Now, etc.)';

    public function handle(): int
    {
        $token = trim((string) config('services.telegram.bot_token'));
        if ($token === '') {
            $this->error('TELEGRAM_BOT_TOKEN is not configured.');

            return self::FAILURE;
        }

        $method = 'setChatMenuButton';
        $payload = [
            'menu_button' => ['type' => 'default'],
        ];

        $response = Http::timeout(15)->post("https://api.telegram.org/bot{$token}/{$method}", $payload);

        $this->line($response->body());
        if (! $response->successful()) {
            $this->error("Failed calling {$method}.");

            return self::FAILURE;
        }

        $this->info('Menu button reset to Telegram default.');

        return self::SUCCESS;
    }
}

