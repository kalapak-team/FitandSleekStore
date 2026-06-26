<?php

namespace App\Support;

use Illuminate\Support\Facades\Http;

final class RielpayDiagnostics
{
    /**
     * @return array<string, mixed>
     */
    public static function summary(): array
    {
        $apiUrl = rtrim((string) config('services.rielpay.api_url'), '/');
        $hasKey = trim((string) config('services.rielpay.api_key')) !== '';
        $issues = self::issues($apiUrl, $hasKey);
        $probe = self::probe($apiUrl);

        return [
            'api_url' => $apiUrl,
            'currency' => config('services.rielpay.currency', 'KHR'),
            'api_key_configured' => $hasKey,
            'configured' => $issues === [] && ($probe['ok'] ?? false),
            'issues' => $issues,
            'probe' => $probe,
        ];
    }

    /**
     * @return list<string>
     */
    public static function issues(?string $apiUrl = null, ?bool $hasKey = null): array
    {
        $apiUrl = $apiUrl ?? rtrim((string) config('services.rielpay.api_url'), '/');
        $hasKey = $hasKey ?? trim((string) config('services.rielpay.api_key')) !== '';
        $issues = [];

        if ($apiUrl === '') {
            $issues[] = 'RIELPAY_API_URL is missing on the server.';
        }

        if (! $hasKey) {
            $issues[] = 'RIELPAY_API_KEY is missing on the server.';
        }

        return $issues;
    }

    /**
     * @return array{ok: bool, status?: int, body?: string, error?: string}
     */
    public static function probe(?string $apiUrl = null): array
    {
        $apiUrl = $apiUrl ?? rtrim((string) config('services.rielpay.api_url'), '/');
        if ($apiUrl === '') {
            return ['ok' => false, 'error' => 'RIELPAY_API_URL is not configured.'];
        }

        try {
            $response = Http::timeout(10)->get($apiUrl.'/up');
            $body = trim((string) $response->body());

            if ($response->successful() && str_contains(strtolower($body), 'up')) {
                return [
                    'ok' => true,
                    'status' => $response->status(),
                    'body' => $body,
                ];
            }

            return [
                'ok' => false,
                'status' => $response->status(),
                'body' => $body !== '' ? $body : null,
                'error' => self::explainProbeFailure($response->status(), $body),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => 'Could not reach Rielpay gateway: '.$e->getMessage(),
            ];
        }
    }

    private static function explainProbeFailure(int $status, string $body): string
    {
        if ($status === 530 || str_contains($body, '1033')) {
            return 'Rielpay gateway is offline (Cloudflare 530). Start api-rielpay.fitandsleek.online or fix its Cloudflare tunnel.';
        }

        if ($status === 401 || $status === 403) {
            return 'Rielpay gateway responded but is not healthy. Check the Rielpay server deployment.';
        }

        return 'Rielpay gateway health check failed (HTTP '.$status.').';
    }
}
