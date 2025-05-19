<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('batch:processRemaining')->everyTenMinutes();

        $schedule->command('cron:expiredProduct')->dailyAt('21:00');
        $schedule->command('cron:slowMovingProduct')->dailyAt('23:00');

        // Jadwalkan command untuk dijalankan pada pukul 23:59 pada hari terakhir bulan
        $schedule->command('end-of-month:task')->when(function () {
            return now()->isLastOfMonth();
        })->dailyAt('23:59');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');

        require base_path('routes/console.php');
    }
}
