<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackupService;
use Illuminate\Console\Command;

class BackupDatabaseCommand extends Command
{
    protected $signature = 'backup:database';

    protected $description = 'Dump, encrypt, and store a PostgreSQL backup (R2 + Telegram)';

    public function handle(DatabaseBackupService $backups): int
    {
        $this->info('Starting database backup…');

        $result = $backups->run();

        if ($result['ok']) {
            $this->info($result['message']);

            return self::SUCCESS;
        }

        $this->error($result['message']);

        return self::FAILURE;
    }
}
