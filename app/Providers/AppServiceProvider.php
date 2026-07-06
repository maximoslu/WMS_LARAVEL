<?php

namespace App\Providers;

use App\Services\GoodsReceipts\GoodsReceiptAiExtractorInterface;
use App\Services\GoodsReceipts\OpenAiGoodsReceiptExtractor;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(GoodsReceiptAiExtractorInterface::class, OpenAiGoodsReceiptExtractor::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer('layouts.dashboard', function ($view): void {
            $user = auth()->user();

            $view->with('layoutUnreadNotificationsCount', $user?->unreadNotifications()->count() ?? 0);
        });
    }
}
