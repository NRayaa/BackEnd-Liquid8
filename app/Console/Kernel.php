<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;
use Illuminate\Support\Facades\Log;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->command('batch:processRemaining')
            ->everyTenMinutes()
            ->onSuccess(function () {
                Log::channel('cronjob')->info('Scheduled command executed successfully', [
                    'command' => 'batch:processRemaining',
                    'schedule' => 'every 10 minutes',
                    'status' => 'success',
                    'timestamp' => now()->toDateTimeString(),
                ]);
            })
            ->onFailure(function () {
                Log::channel('cronjob')->error('Scheduled command failed', [
                    'command' => 'batch:processRemaining',
                    'schedule' => 'every 10 minutes',
                    'status' => 'failed',
                    'timestamp' => now()->toDateTimeString(),
                ]);
            });

        $schedule->command('cron:expiredProduct')
            ->daily()
            ->onSuccess(function () {
                Log::channel('cronjob')->info('Scheduled command executed successfully', [
                    'command' => 'cron:expiredProduct',
                    'schedule' => 'daily',
                    'status' => 'success',
                    'timestamp' => now()->toDateTimeString(),
                ]);
            })
            ->onFailure(function () {
                Log::channel('cronjob')->error('Scheduled command failed', [
                    'command' => 'cron:expiredProduct',
                    'schedule' => 'daily',
                    'status' => 'failed',
                    'timestamp' => now()->toDateTimeString(),
                ]);
            });

        // expireBuyerLoyalty dijalankan setelah expiredProduct selesai
        $schedule->command('cron:expireBuyerLoyalty')
            ->daily()
            ->onSuccess(function () {
                Log::channel('cronjob')->info('Scheduled command executed successfully', [
                    'command' => 'cron:expireBuyerLoyalty',
                    'schedule' => 'daily',
                    'status' => 'success',
                    'timestamp' => now()->toDateTimeString(),
                ]);
            })
            ->onFailure(function () {
                Log::channel('cronjob')->error('Scheduled command failed', [
                    'command' => 'cron:expireBuyerLoyalty',
                    'schedule' => 'hourly',
                    'status' => 'failed',
                    'timestamp' => now()->toDateTimeString(),
                ]);
            });

        // 3. slowMovingProduct dijalankan setelah expireBuyerLoyalty selesai
        $schedule->command('cron:slowMovingProduct')
            ->daily()
            ->onSuccess(function () {
                Log::channel('cronjob')->info('Scheduled command executed successfully', [
                    'command' => 'cron:slowMovingProduct',
                    'schedule' => 'daily',
                    'status' => 'success',
                    'timestamp' => now()->toDateTimeString(),
                ]);
            })
            ->onFailure(function () {
                Log::channel('cronjob')->error('Scheduled command failed', [
                    'command' => 'cron:slowMovingProduct',
                    'schedule' => 'daily',
                    'status' => 'failed',
                    'timestamp' => now()->toDateTimeString(),
                ]);
            });

        // Jadwalkan command untuk dijalankan pada pukul 23:59 pada hari terakhir bulan
        $schedule->command('end-of-month:task')
            ->when(function () {
                return now()->isLastOfMonth();
            })
            ->dailyAt('23:50')
            ->onSuccess(function () {
                Log::channel('cronjob')->info('Scheduled command executed successfully', [
                    'command' => 'end-of-month:task',
                    'schedule' => 'last day of month at 23:50',
                    'status' => 'success',
                    'timestamp' => now()->toDateTimeString(),
                ]);
            })
            ->onFailure(function () {
                Log::channel('cronjob')->error('Scheduled command failed', [
                    'command' => 'end-of-month:task',
                    'schedule' => 'last day of month at 23:50',
                    'status' => 'failed',
                    'timestamp' => now()->toDateTimeString(),
                ]);
            });

        //sekarang kita jalankan disini setiap jam 21.00 wib Carbon::now(asia/jakarta)
        $schedule->command('cron:summaryDailyReport')
            ->dailyAt('21:00')
            ->onSuccess(function () {
                Log::channel('cronjob')->info('Scheduled command executed successfully', [
                    'command' => 'cron:summaryDailyReport',
                    'schedule' => 'daily at 21:00',
                    'status' => 'success',
                    'timestamp' => now()->toDateTimeString(),
                ]);
            })
            ->onFailure(function () {
                Log::channel('cronjob')->error('Scheduled command failed', [
                    'command' => 'cron:summaryDailyReport',
                    'schedule' => 'daily at 21:00',
                    'status' => 'failed',
                    'timestamp' => now()->toDateTimeString(),
                ]);
            });
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
