<?php

namespace App\Providers;

use App\Services\Payment\PaymentProviderInterface;
use App\Services\Payment\Providers\SandboxProvider;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\Schema;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(PaymentProviderInterface::class, function () {
            return new SandboxProvider();
        });
    }

    public function boot(): void
    {
        Schema::defaultStringLength(191);
    }
}