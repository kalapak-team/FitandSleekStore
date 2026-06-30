<?php

namespace App\Http\Controllers\Api\Storefront;

use App\Http\Controllers\Controller;
use App\Services\HomepageBootstrapService;
use App\Services\StorefrontCacheService;
use Illuminate\Http\Request;

class HomepageBootstrapController extends Controller
{
    public function __construct(
        private HomepageBootstrapService $bootstrap,
    ) {
    }

    public function __invoke(Request $request)
    {
        $segment = 'guest';

        $payload = StorefrontCacheService::remember(
            'homepage-bootstrap',
            StorefrontCacheService::TTL_BOOTSTRAP,
            $segment,
            fn (): array => $this->bootstrap->build($segment)
        );

        return response()->json($payload);
    }
}
