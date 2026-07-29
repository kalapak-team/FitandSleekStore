<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

class DatabaseBackupService
{
    public function __construct(private TelegramService $telegram)
    {
    }

    /**
     * @return array{
     *     ok: bool,
     *     message: string,
     *     filename?: string,
     *     bytes?: int,
     *     r2_path?: string|null,
     *     telegram_sent?: bool
     * }
     */
    public function run(): array
    {
        if (! config('backup.enabled')) {
            return ['ok' => false, 'message' => 'Backups are disabled (BACKUP_ENABLED=false).'];
        }

        $key = trim((string) config('backup.encryption_key'));
        if ($key === '') {
            return ['ok' => false, 'message' => 'BACKUP_ENCRYPTION_KEY is not set.'];
        }

        $chatId = trim((string) config('backup.telegram_chat_id'));
        if ($chatId === '') {
            return ['ok' => false, 'message' => 'BACKUP_TELEGRAM_CHAT_ID (or TELEGRAM_ADMIN_CHAT_ID) is not set.'];
        }

        if (config('database.default') !== 'pgsql') {
            return ['ok' => false, 'message' => 'Only PostgreSQL backups are supported.'];
        }

        $workDir = storage_path('app/private/backups/tmp');
        if (! is_dir($workDir) && ! mkdir($workDir, 0750, true) && ! is_dir($workDir)) {
            return ['ok' => false, 'message' => 'Could not create backup temp directory.'];
        }

        $stamp = now(config('backup.timezone'))->format('Y-m-d_His');
        $prefix = (string) config('backup.filename_prefix', 'fitandsleek');
        $baseName = "{$prefix}-{$stamp}";
        $sqlPath = "{$workDir}/{$baseName}.sql";
        $gzPath = "{$sqlPath}.gz";
        $encPath = "{$gzPath}.enc";
        $filename = basename($encPath);

        try {
            $this->dumpDatabase($sqlPath);
            $this->gzipFile($sqlPath, $gzPath);
            @unlink($sqlPath);

            $this->encryptFile($gzPath, $encPath, $key);
            @unlink($gzPath);

            $bytes = (int) filesize($encPath);
            $r2Path = $this->uploadToDisk($encPath, $filename);
            $telegramSent = $this->deliverToTelegram($chatId, $encPath, $filename, $bytes, $r2Path);
            $this->pruneRemoteBackups();

            $sizeLabel = $this->humanBytes($bytes);
            $message = "✅ Database backup completed\n"
                ."📅 {$stamp}\n"
                ."📦 {$filename}\n"
                ."💾 {$sizeLabel}\n"
                .'🔒 Encrypted AES-256';

            if ($r2Path) {
                $message .= "\n🗄 R2: {$r2Path}";
            }
            if (! $telegramSent) {
                $message .= "\n⚠️ Telegram file not sent (see logs) — R2 path above if present.";
            }

            $this->telegram->sendMessage($chatId, $message, null, null);

            return [
                'ok' => true,
                'message' => $message,
                'filename' => $filename,
                'bytes' => $bytes,
                'r2_path' => $r2Path,
                'telegram_sent' => $telegramSent,
            ];
        } catch (Throwable $e) {
            Log::error('Database backup failed', ['error' => $e->getMessage()]);

            $fail = "❌ Database backup FAILED\n🛠 ".$e->getMessage();
            try {
                $this->telegram->sendMessage($chatId, $fail, null, null);
            } catch (Throwable) {
                // ignore notify failure
            }

            return ['ok' => false, 'message' => $fail];
        } finally {
            foreach ([$sqlPath, $gzPath, $encPath] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }

    private function dumpDatabase(string $sqlPath): void
    {
        $db = config('database.connections.pgsql');
        $host = (string) ($db['host'] ?? '');
        $port = (string) ($db['port'] ?? '5432');
        $database = (string) ($db['database'] ?? '');
        $username = (string) ($db['username'] ?? '');
        $password = (string) ($db['password'] ?? '');
        $sslmode = (string) ($db['sslmode'] ?? 'prefer');

        if ($host === '' || $database === '' || $username === '') {
            throw new RuntimeException('PostgreSQL connection settings are incomplete.');
        }

        $pgDump = $this->resolveBinary('pg_dump');
        $env = array_merge($_ENV, [
            'PGPASSWORD' => $password,
            'PGSSLMODE' => $sslmode,
        ]);

        $result = Process::env($env)
            ->timeout(600)
            ->run([
                $pgDump,
                '-h', $host,
                '-p', $port,
                '-U', $username,
                '-d', $database,
                '--no-owner',
                '--no-acl',
                '-F', 'p',
                '-f', $sqlPath,
            ]);

        if (! $result->successful() || ! is_file($sqlPath) || filesize($sqlPath) < 1) {
            throw new RuntimeException(trim($result->errorOutput() ?: $result->output()) ?: 'pg_dump failed.');
        }
    }

    private function gzipFile(string $sqlPath, string $gzPath): void
    {
        $in = fopen($sqlPath, 'rb');
        if ($in === false) {
            throw new RuntimeException('Could not read SQL dump for compression.');
        }

        $out = gzopen($gzPath, 'wb9');
        if ($out === false) {
            fclose($in);
            throw new RuntimeException('Could not open gzip target.');
        }

        try {
            while (! feof($in)) {
                $chunk = fread($in, 1024 * 1024);
                if ($chunk === false) {
                    throw new RuntimeException('Failed reading SQL dump during gzip.');
                }
                if ($chunk !== '' && gzwrite($out, $chunk) === false) {
                    throw new RuntimeException('Failed writing gzipped backup.');
                }
            }
        } finally {
            fclose($in);
            gzclose($out);
        }

        if (! is_file($gzPath) || filesize($gzPath) < 1) {
            throw new RuntimeException('Gzip produced an empty file.');
        }
    }

    private function encryptFile(string $inputPath, string $outputPath, string $password): void
    {
        $openssl = $this->resolveBinary('openssl');

        $result = Process::env(['BACKUP_ENCRYPTION_KEY' => $password])
            ->timeout(300)
            ->run([
                $openssl, 'enc',
                '-aes-256-cbc',
                '-pbkdf2',
                '-iter', '100000',
                '-salt',
                '-in', $inputPath,
                '-out', $outputPath,
                '-pass', 'env:BACKUP_ENCRYPTION_KEY',
            ]);

        if (! $result->successful() || ! is_file($outputPath) || filesize($outputPath) < 1) {
            throw new RuntimeException(trim($result->errorOutput()) ?: 'openssl encrypt failed.');
        }
    }

    private function uploadToDisk(string $localPath, string $filename): ?string
    {
        $diskName = trim((string) config('backup.disk'));
        if ($diskName === '' || $diskName === 'null' || $diskName === 'false') {
            return null;
        }

        $diskConfig = config("filesystems.disks.{$diskName}");
        if (! is_array($diskConfig)) {
            Log::warning('Backup disk not defined; skipping object storage.', ['disk' => $diskName]);

            return null;
        }

        if (blank($diskConfig['key'] ?? null) || blank($diskConfig['secret'] ?? null) || blank($diskConfig['bucket'] ?? null) || blank($diskConfig['endpoint'] ?? null)) {
            Log::info('Backup disk credentials incomplete; Telegram-only backup.');

            return null;
        }

        $prefix = trim((string) config('backup.path_prefix'), '/');
        $datePath = now(config('backup.timezone'))->format('Y/m/d');
        $remotePath = ($prefix !== '' ? $prefix.'/' : '')."{$datePath}/{$filename}";

        Storage::disk($diskName)->put($remotePath, fopen($localPath, 'r'), [
            'visibility' => 'private',
            'ContentType' => 'application/octet-stream',
        ]);

        return $remotePath;
    }

    private function deliverToTelegram(string $chatId, string $localPath, string $filename, int $bytes, ?string $r2Path): bool
    {
        $max = (int) config('backup.telegram_max_bytes');
        if ($bytes > $max) {
            $this->telegram->sendMessage(
                $chatId,
                "⚠️ Backup file is larger than Telegram limit ({$this->humanBytes($bytes)}).\n"
                .'File kept on object storage only'
                .($r2Path ? ": {$r2Path}" : ' (configure BACKUP_DISK / R2).'),
                null,
                null
            );

            return false;
        }

        $caption = "📦 {$filename}\n💾 {$this->humanBytes($bytes)}\n🔒 Encrypted database backup";

        return $this->telegram->sendDocument($chatId, $localPath, $filename, $caption);
    }

    private function pruneRemoteBackups(): void
    {
        $diskName = trim((string) config('backup.disk'));
        if ($diskName === '' || $diskName === 'null' || $diskName === 'false') {
            return;
        }

        $diskConfig = config("filesystems.disks.{$diskName}");
        if (! is_array($diskConfig) || blank($diskConfig['bucket'] ?? null) || blank($diskConfig['key'] ?? null)) {
            return;
        }

        $days = (int) config('backup.retention_days', 14);
        $cutoff = now(config('backup.timezone'))->subDays($days)->getTimestamp();
        $prefix = trim((string) config('backup.path_prefix'), '/');
        $prefix = $prefix !== '' ? $prefix.'/' : '';

        try {
            $disk = Storage::disk($diskName);
            foreach ($disk->allFiles($prefix === '' ? '' : rtrim($prefix, '/')) as $path) {
                if (! str_ends_with($path, '.sql.gz.enc')) {
                    continue;
                }
                $lastModified = $disk->lastModified($path);
                if ($lastModified !== false && $lastModified < $cutoff) {
                    $disk->delete($path);
                }
            }
        } catch (Throwable $e) {
            Log::warning('Backup prune failed', ['error' => $e->getMessage()]);
        }
    }

    private function resolveBinary(string $name): string
    {
        // Prefer a client at least as new as Neon (PG 18+). Homebrew / PGDG paths first.
        $candidates = [
            "/opt/homebrew/opt/postgresql@18/bin/{$name}",
            "/usr/local/opt/postgresql@18/bin/{$name}",
            "/usr/lib/postgresql/18/bin/{$name}",
            "/opt/homebrew/opt/postgresql@17/bin/{$name}",
            "/usr/lib/postgresql/17/bin/{$name}",
            "/opt/homebrew/opt/postgresql@16/bin/{$name}",
            "/usr/local/opt/postgresql@16/bin/{$name}",
            "/usr/lib/postgresql/16/bin/{$name}",
            "/usr/bin/{$name}",
        ];
        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        $result = Process::run(['which', $name]);
        $path = trim($result->output());
        if ($result->successful() && $path !== '' && is_executable($path)) {
            return $path;
        }

        throw new RuntimeException("Required binary not found: {$name}");
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) max(0, $bytes);
        $i = 0;
        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return ($i === 0 ? (string) (int) $value : number_format($value, 1)).' '.$units[$i];
    }
}
