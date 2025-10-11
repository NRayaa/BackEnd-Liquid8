<?php

namespace App\Console\Commands;

use App\Http\Controllers\ArchiveStorageController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Exception;

class EndOfMonthTask extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'end-of-month:task'; 

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Task to run at the end of each month';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $startTime = now();
        $cronjobLogger = Log::channel('cronjob');
        
        $cronjobLogger->info('=== EndOfMonthTask Started ===', [
            'command' => $this->signature,
            'start_time' => $startTime->toDateTimeString(),
            'memory_usage' => memory_get_usage(true),
            'month' => $startTime->format('Y-m'),
        ]);

        try {
            $archiveStorage = new ArchiveStorageController;
            
            $cronjobLogger->info('Processing archive storage report', [
                'controller' => 'ArchiveStorageController',
                'method' => 'store',
                'month' => $startTime->format('Y-m'),
            ]);
            
            $result = $archiveStorage->store();
            
            $cronjobLogger->info('Archive storage report process completed', [
                'result' => $result,
                'month' => $startTime->format('Y-m'),
            ]);

            $endTime = now();
            $executionTime = $endTime->diffInSeconds($startTime);
            
            $cronjobLogger->info('=== EndOfMonthTask Completed Successfully ===', [
                'command' => $this->signature,
                'start_time' => $startTime->toDateTimeString(),
                'end_time' => $endTime->toDateTimeString(),
                'execution_time_seconds' => $executionTime,
                'final_memory_usage' => memory_get_usage(true),
                'peak_memory_usage' => memory_get_peak_usage(true),
                'month' => $startTime->format('Y-m'),
            ]);

            return Command::SUCCESS;

        } catch (Exception $e) {
            $endTime = now();
            $executionTime = $endTime->diffInSeconds($startTime);
            
            $cronjobLogger->error('=== EndOfMonthTask Failed ===', [
                'command' => $this->signature,
                'start_time' => $startTime->toDateTimeString(),
                'end_time' => $endTime->toDateTimeString(),
                'execution_time_seconds' => $executionTime,
                'error_message' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString(),
                'memory_usage' => memory_get_usage(true),
                'month' => $startTime->format('Y-m'),
            ]);

            return Command::FAILURE;
        }
    }
}
