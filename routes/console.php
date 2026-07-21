<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('wms:stock-alerts:evaluate --apply')
    ->dailyAt('06:00')
    ->withoutOverlapping();

Schedule::command('wms:backups:stock-snapshots')
    ->dailyAt('02:15')
    ->withoutOverlapping();

Schedule::command('wms:backups:create --type=database')
    ->dailyAt('02:30')
    ->withoutOverlapping();

Schedule::command('wms:backups:prune --days=365 --type=stock_snapshot_daily --apply')
    ->dailyAt('03:00')
    ->withoutOverlapping();
