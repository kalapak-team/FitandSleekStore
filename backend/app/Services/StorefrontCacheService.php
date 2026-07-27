<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;

class StorefrontCacheService
{
    public const TTL_HOMEPAGE = 600;

    public const TTL_BANNERS = 300;

    public const TTL_CATEGORIES = 600;

    public const TTL_COLLECTIONS = 600;

    public const TTL_PRODUCTS = 180;

    public const TTL_DISCOUNTS = 180;

    public const TTL_RECOMMENDATIONS = 180;

    public const TTL_BOOTSTRAP = 600;

    private const VERSION_PREFIX = 'storefront:v1:ver:';

    public static function remember(string $domain, int $ttlSeconds, string $suffix, callable $callback): mixed
    {
        $key = self::key($domain, $suffix);
        $hit = Cache::get($key);
        if ($hit !== null) {
            return $hit;
        }

        $lockKey = 'lock:'.md5($key);
        $lock = Cache::lock($lockKey, 120);

        try {
            $lock->block(90);

            $hit = Cache::get($key);
            if ($hit !== null) {
                return $hit;
            }

            return Cache::remember($key, $ttlSeconds, $callback);
        } finally {
            optional($lock)->release();
        }
    }

    public static function key(string $domain, string $suffix = ''): string
    {
        $version = (int) Cache::get(self::VERSION_PREFIX.$domain, 1);
        $suffix = $suffix !== '' ? ':'.$suffix : '';

        return "storefront:v1:{$domain}:v{$version}{$suffix}";
    }

    public static function bump(string $domain): void
    {
        $verKey = self::VERSION_PREFIX.$domain;

        if (! Cache::has($verKey)) {
            Cache::forever($verKey, 2);

            return;
        }

        Cache::increment($verKey);
    }

    public static function bumpProducts(): void
    {
        self::bump('products');
        self::bump('discounts');
        self::bump('recommendations');
        self::bump('homepage-bootstrap');
    }

    public static function bumpHomepageBootstrap(): void
    {
        self::bump('homepage-bootstrap');
    }

    public static function requestHash(array $params): string
    {
        ksort($params);

        return md5(json_encode($params));
    }

}
