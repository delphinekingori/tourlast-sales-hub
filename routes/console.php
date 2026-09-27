<?php

use Illuminate\Support\Facades\Schedule;

/*
| Keep onboardings in step with tourlast.com, and send managers a morning summary.
| On the server, run `php artisan schedule:work` (or a cron entry calling
| `php artisan schedule:run` every minute) plus a queue worker.
*/

Schedule::command('hub:sync-tourlast')
    ->skip(fn (): bool => config('tourlast.source') === 'push')
    ->cron('*/'.max(1, (int) config('tourlast.sync_every_minutes')).' * * * *')
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('hub:sync-tourlast --full')
    ->skip(fn (): bool => config('tourlast.source') === 'push')
    ->dailyAt(config('tourlast.full_sync_at'))
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('hub:generate-statements')
    ->monthlyOn(1, '06:00')
    ->timezone(config('app.timezone'))
    ->onOneServer();

Schedule::command('hub:reinstate-suspensions')
    ->dailyAt('06:00')
    ->timezone(config('app.timezone'))
    ->onOneServer();

Schedule::command('hub:process-reengagements')
    ->dailyAt('06:45')
    ->timezone(config('app.timezone'))
    ->onOneServer();

Schedule::command('hub:send-daily-alerts')
    ->dailyAt('07:00')
    ->timezone(config('app.timezone'))
    ->onOneServer();

Schedule::command('hub:send-manager-alerts')
    ->weekdays()
    ->at('07:30')
    ->timezone(config('app.timezone'))
    ->onOneServer();
