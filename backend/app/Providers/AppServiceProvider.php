<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Route;
use App\Models\User;
use App\Models\Order;
use App\Models\Product;
use App\Models\Banner;
use App\Models\Category;
use App\Models\Collection;
use App\Models\Setting;
use App\Models\Shipment;
use App\Observers\OrderObserver;
use App\Observers\ProductObserver;
use App\Observers\ShipmentObserver;
use App\Observers\StorefrontCatalogCacheObserver;
use Illuminate\Auth\Notifications\ResetPassword;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Route model binding for customer parameter
        Route::model('customer', User::class);

        ResetPassword::createUrlUsing(function (User $user, string $token): string {
            $frontendUrl = rtrim(config('app.frontend_url'), '/');

            return $frontendUrl.'/reset-password?token='.$token.'&email='.urlencode($user->email);
        });

        Order::observe(OrderObserver::class);
        Product::observe(ProductObserver::class);
        Shipment::observe(ShipmentObserver::class);

        $catalogCacheObserver = StorefrontCatalogCacheObserver::class;
        Banner::observe($catalogCacheObserver);
        Category::observe($catalogCacheObserver);
        Collection::observe($catalogCacheObserver);
        Setting::observe($catalogCacheObserver);
    }
}

