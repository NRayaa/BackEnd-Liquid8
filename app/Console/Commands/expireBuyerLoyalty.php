<?php

namespace App\Console\Commands;

use App\Http\Controllers\BuyerLoyaltyController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\NewProductController;
use App\Http\Controllers\StagingProductController;
use Exception;

class expireBuyerLoyalty extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cron:expireBuyerLoyalty';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Menurunkan status buyer loyalty yang sudah expired';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $startTime = now();
        // $cronjobLogger = Log::channel('cronjob');
        
        // $cronjobLogger->info('=== ExpireBuyerLoyalty Started ===', [
        //     'command' => $this->signature,
        //     'start_time' => $startTime->toDateTimeString(),
        //     'memory_usage' => memory_get_usage(true),
        // ]);

        try {
            $expiredBuyerLoyalty = new BuyerLoyaltyController;
            $result = $expiredBuyerLoyalty->expireBuyerLoyalty();
            
            // $cronjobLogger->info('Buyer loyalty expiration process completed', [
            //     'result' => $result,
            //     'processed_at' => now()->toDateTimeString(),
            // ]);

            $endTime = now();
            $executionTime = $endTime->diffInSeconds($startTime);
            
            // $cronjobLogger->info('=== ExpireBuyerLoyalty Completed Successfully ===', [
            //     'command' => $this->signature,
            //     'start_time' => $startTime->toDateTimeString(),
            //     'end_time' => $endTime->toDateTimeString(),
            //     'execution_time_seconds' => $executionTime,
            //     'final_memory_usage' => memory_get_usage(true),
            //     'peak_memory_usage' => memory_get_peak_usage(true),
            // ]);

            return Command::SUCCESS;

        } catch (Exception $e) {
            $endTime = now();
            $executionTime = $endTime->diffInSeconds($startTime);
            
            // $cronjobLogger->error('=== ExpireBuyerLoyalty Failed ===', [
            //     'command' => $this->signature,
            //     'start_time' => $startTime->toDateTimeString(),
            //     'end_time' => $endTime->toDateTimeString(),
            //     'execution_time_seconds' => $executionTime,
            //     'error_message' => $e->getMessage(),
            //     'error_trace' => $e->getTraceAsString(),
            //     'memory_usage' => memory_get_usage(true),
            // ]);

            return Command::FAILURE;
        }
    }
}
