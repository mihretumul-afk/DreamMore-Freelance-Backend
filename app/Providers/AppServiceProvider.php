<?php

namespace App\Providers;

use App\Services\Payment\PaymentProviderInterface;
use App\Services\Payment\Providers\ChapaProvider;
use App\Services\Payment\Providers\SandboxProvider;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Sanitize APP_URL scheme to prevent boot crashes from malformed env settings
        $appUrl = config('app.url');
        if ($appUrl && !str_starts_with($appUrl, 'http://') && !str_starts_with($appUrl, 'https://')) {
            config(['app.url' => 'https://' . ltrim($appUrl, '/')]);
        }

        // Keep your original payment provider binding
        $this->app->bind(PaymentProviderInterface::class, function () {
            $provider = config('payment.default', 'sandbox');

            return match ($provider) {
                'chapa'  => new ChapaProvider(),
                default  => new SandboxProvider(),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Schema::defaultStringLength(191);

        // Force HTTPS for generated URLs and asset links in production/behind proxy
        if (config('app.env') === 'production' || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')) {
            URL::forceScheme('https');
        }
    }
}