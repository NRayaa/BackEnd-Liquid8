<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\NewProductController;
use App\Http\Controllers\StagingProductController;
use Exception;

class expireProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cron:expiredProduct';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Menguhbah status new_product menjadi expired';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $startTime = now();
        $cronjobLogger = Log::channel('cronjob');
        
        $cronjobLogger->info('=== ExpireProducts Started ===', [
            'command' => $this->signature,
            'start_time' => $startTime->toDateTimeString(),
            'memory_usage' => memory_get_usage(true),
        ]);

        try {
            $expiredProductInventory = new NewProductController;
            $expiredProductStaging = new StagingProductController;
            
            $cronjobLogger->info('Processing expired products from inventory', [
                'controller' => 'NewProductController',
                'method' => 'expireProducts',
            ]);
            
            $expiredProductInventory->expireProducts();
            
            $cronjobLogger->info('Processing expired products from staging', [
                'controller' => 'StagingProductController', 
                'method' => 'expireProductStaging',
            ]);
            
            $expiredProductStaging->expireProductStaging();
            
            $cronjobLogger->info('Product expiration process completed successfully');

            $endTime = now();
            $executionTime = $endTime->diffInSeconds($startTime);
            
            $cronjobLogger->info('=== ExpireProducts Completed Successfully ===', [
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
            
            $cronjobLogger->error('=== ExpireProducts Failed ===', [
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
