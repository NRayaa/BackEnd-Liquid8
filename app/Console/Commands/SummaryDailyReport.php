<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\NewProductController;
use App\Http\Controllers\StagingProductController;
use App\Http\Controllers\SummaryController;
use Exception;

class SummaryDailyReport extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cron:summaryDailyReport';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'summary daily report cronjob';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $startTime = now();
        $cronjobLogger = Log::channel('cronjob');
        
        $cronjobLogger->info('=== Summary Daily Report Started ===', [
            'command' => $this->signature,
            'start_time' => $startTime->toDateTimeString(),
            'memory_usage' => memory_get_usage(true),
        ]);

        try {
            $summaryDailyReport = new SummaryController;

            $cronjobLogger->info('Processing summary inbound', [
                'controller' => 'SummaryController',
                'method' => 'summaryInbound',
            ]);
            
            $summaryDailyReport->summaryInbound(request());
            
            $cronjobLogger->info('Processing summary outbound', [
                'controller' => 'SummaryController', 
                'method' => 'summaryOutbound',
            ]);
            
            $summaryDailyReport->summaryOutbound(request());
            
            $cronjobLogger->info('Summary daily report process completed successfully');

            $endTime = now();
            $executionTime = $endTime->diffInSeconds($startTime);
            
            $cronjobLogger->info('=== Summary Daily Report Completed Successfully ===', [
                'command' => $this->signature,
                'start_time' => $startTime->toDateTimeString(),
                'end_time' => $endTime->toDateTimeString(),
                'execution_time_seconds' => $executionTime,
                'final_memory_usage' => memory_get_usage(true),
                'peak_memory_usage' => memory_get_peak_usage(true),
            ]);

            return Command::SUCCESS;

        } catch (Exception $e) {
            $endTime = now();
            $executionTime = $endTime->diffInSeconds($startTime);
            
            $cronjobLogger->error('=== Summary Daily Report Failed ===', [
                'command' => $this->signature,
                'start_time' => $startTime->toDateTimeString(),
                'end_time' => $endTime->toDateTimeString(),
                'execution_time_seconds' => $executionTime,
                'error_message' => $e->getMessage(),
                'error_trace' => $e->getTraceAsString(),
                'memory_usage' => memory_get_usage(true),
            ]);

            return Command::FAILURE;
        }
    }
}
