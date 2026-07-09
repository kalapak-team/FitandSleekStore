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
        $apiKey = trim((string) config('services.rielpay.api_key'));
        $hasKey = $apiKey !== '';
        $issues = self::issues($apiUrl, $hasKey);
        $probe = self::probe($apiUrl, $apiKey);
        $auth = self::authCheck($apiUrl, $apiKey);

        if ($hasKey && ! ($auth['valid'] ?? false)) {
            $issues[] = $auth['error'] ?? 'RIELPAY_API_KEY is set but rejected by Rielpay (401).';
        }

        return [
            'api_url' => $apiUrl,
            'currency' => config('services.rielpay.currency', 'KHR'),
            'api_key_configured' => $hasKey,
            'api_key_valid' => $auth['valid'] ?? null,
            'configured' => $issues === [] && ($probe['ok'] ?? false) && ($auth['valid'] ?? false),
            'issues' => $issues,
            'probe' => $probe,
            'auth' => $auth,
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
    public static function probe(?string $apiUrl = null, ?string $apiKey = null): array
    {
        $apiUrl = $apiUrl ?? rtrim((string) config('services.rielpay.api_url'), '/');
        if ($apiUrl === '') {
            return ['ok' => false, 'error' => 'RIELPAY_API_URL is not configured.'];
        }

        try {
            $response = Http::timeout(10)->get($apiUrl.'/up');
            $body = trim((string) $response->body());

            if ($response->successful() && str_contains(strtolower($body), 'application up')) {
                return [
                    'ok' => true,
                    'status' => $response->status(),
                    'body' => 'Application up',
                ];
            }

            return [
                'ok' => false,
                'status' => $response->status(),
                'body' => $body !== '' ? mb_substr($body, 0, 120) : null,
                'error' => self::explainProbeFailure($response->status(), $body, $apiUrl),
            ];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'error' => 'Could not reach Rielpay gateway: '.$e->getMessage(),
            ];
        }
    }

    /**
     * @return array{valid: bool, status?: int, error?: string}
     */
    public static function authCheck(?string $apiUrl = null, ?string $apiKey = null): array
    {
        $apiUrl = $apiUrl ?? rtrim((string) config('services.rielpay.api_url'), '/');
        $apiKey = $apiKey ?? trim((string) config('services.rielpay.api_key'));

        if ($apiUrl === '') {
            return ['valid' => false, 'error' => 'RIELPAY_API_URL is not configured.'];
        }

        if ($apiKey === '') {
            return ['valid' => false, 'error' => 'RIELPAY_API_KEY is missing on the server.'];
        }

        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->withHeaders([
                    'Authorization' => 'Bearer '.$apiKey,
                    'X-API-Key' => $apiKey,
                ])
                ->post($apiUrl.'/api/v1/payments/check', ['md5' => '']);

            if ($response->status() === 401) {
                $message = is_string(data_get($response->json(), 'message'))
                    ? (string) data_get($response->json(), 'message')
                    : 'Invalid or revoked API key.';

                return [
                    'valid' => false,
                    'status' => 401,
                    'error' => $message,
                ];
            }

            if (in_array($response->status(), [422, 404], true)) {
                return [
                    'valid' => true,
                    'status' => $response->status(),
                ];
            }

            return [
                'valid' => false,
                'status' => $response->status(),
                'error' => 'Unexpected Rielpay auth response (HTTP '.$response->status().').',
            ];
        } catch (\Throwable $e) {
            return [
                'valid' => false,
                'error' => 'Could not verify Rielpay API key: '.$e->getMessage(),
            ];
        }
    }

    private static function explainProbeFailure(int $status, string $body, string $apiUrl): string
    {
        if ($status === 530 || str_contains($body, '1033')) {
            return 'Rielpay gateway is offline (Cloudflare 530). Point RIELPAY_API_URL at the healthy norng-rielpay host (e.g. https://api-norng-rielpay.fitandsleek.online) or fix the tunnel route for '.$apiUrl.'.';
        }

        if ($status === 401 || $status === 403) {
            return 'Rielpay gateway responded but is not healthy. Check the Rielpay server deployment.';
        }

        return 'Rielpay gateway health check failed (HTTP '.$status.').';
    }
}
