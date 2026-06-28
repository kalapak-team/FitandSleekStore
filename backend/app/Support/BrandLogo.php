<?php

namespace App\Support;

use Illuminate\Support\Str;

/** Resolves the storefront logo for emails, PDFs, and Blade views. */
final class BrandLogo
{
    public static function url(): string
    {
        $logoUrl = (string) config('app.logo_url', '/logo.png');

        if (! Str::startsWith($logoUrl, ['http://', 'https://'])) {
            return self::dataUriFromPath($logoUrl) ?? asset($logoUrl);
        }

        $appHost = parse_url((string) config('app.url', ''), PHP_URL_HOST);
        $logoHost = parse_url($logoUrl, PHP_URL_HOST);
        $logoPath = (string) parse_url($logoUrl, PHP_URL_PATH);

        if (! empty($appHost) && ! empty($logoHost) && strcasecmp($appHost, $logoHost) === 0) {
            return self::dataUriFromPath($logoPath) ?? $logoUrl;
        }

        return $logoUrl;
    }

    /** Absolute logo URL for email HTML — never inline base64 (Gmail clips >102KB). */
    public static function emailUrl(): string
    {
        return self::emailAssetUrl((string) config('app.logo_url', '/logo.png'));
    }

    /** Circular brand mark for email headers (linked URL, not base64). */
    public static function emailCircleUrl(): ?string
    {
        $logoUrl = (string) config('app.logo_circle_url', '/logo-circle.png');

        if ($logoUrl === '') {
            return null;
        }

        if (! Str::startsWith($logoUrl, ['http://', 'https://'])) {
            $fullPath = public_path(ltrim($logoUrl, '/'));
            if (! is_file($fullPath)) {
                return null;
            }
        }

        return self::emailAssetUrl($logoUrl);
    }

    private static function emailAssetUrl(string $logoUrl): string
    {
        if (Str::startsWith($logoUrl, ['http://', 'https://'])) {
            return $logoUrl;
        }

        return asset(ltrim($logoUrl, '/'));
    }

    public static function dataUriFromPath(string $path): ?string
    {
        $relativePath = ltrim($path, '/');

        if ($relativePath === '') {
            return null;
        }

        $fullPath = public_path($relativePath);

        if (! is_file($fullPath) || ! is_readable($fullPath)) {
            return null;
        }

        $mimeType = mime_content_type($fullPath) ?: 'image/png';
        $contents = file_get_contents($fullPath);

        if ($contents === false) {
            return null;
        }

        return 'data:'.$mimeType.';base64,'.base64_encode($contents);
    }
}
