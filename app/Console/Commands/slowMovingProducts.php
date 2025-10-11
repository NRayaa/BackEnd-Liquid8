<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\NewProductController;
use App\Http\Controllers\StagingProductController;
use Exception;

class slowMovingProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cron:slowMovingProduct';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Menguhbah status new_product menjadi slow_moving';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $startTime = now();
        $cronjobLogger = Log::channel('cronjob');
        
        $cronjobLogger->info('=== SlowMovingProducts Started ===', [
            'command' => $this->signature,
            'start_time' => $startTime->toDateTimeString(),
            'memory_usage' => memory_get_usage(true),
        ]);

        try {
            $expiredProductStaging = new StagingProductController;
            $expiredProductInventory = new NewProductController;
            
            $cronjobLogger->info('Processing slow moving products from inventory', [
                'controller' => 'NewProductController',
                'method' => 'slowMovingProducts',
            ]);
            
            $expiredProductInventory->slowMovingProducts();
            
            $cronjobLogger->info('Processing slow moving products from staging', [
                'controller' => 'StagingProductController',
                'method' => 'slowMovingProductStaging',
            ]);
            
            $expiredProductStaging->slowMovingProductStaging();
            
            $cronjobLogger->info('Slow moving products process completed successfully');

            $endTime = now();
            $executionTime = $endTime->diffInSeconds($startTime);
            
            $cronjobLogger->info('=== SlowMovingProducts Completed Successfully ===', [
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
            
            $cronjobLogger->error('=== SlowMovingProducts Failed ===', [
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
