<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Log;
use App\Models\ProductApprove;
use Exception;

class ProcessRemainingBatch extends Command
{
    protected $signature = 'batch:processRemaining';
    protected $description = 'Process remaining batch data in Redis if count is less than batch size';

    public function handle()
    {
        $startTime = now();
        $cronjobLogger = Log::channel('cronjob');
        
        $cronjobLogger->info('=== ProcessRemainingBatch Started ===', [
            'command' => $this->signature,
            'start_time' => $startTime->toDateTimeString(),
            'memory_usage' => memory_get_usage(true),
        ]);

        try {
            $batchSize = 7;
            $redisKey = 'product_batch'; 

            // Log initial state
            $totalItems = Redis::llen($redisKey);
            $cronjobLogger->info('Redis state check', [
                'redis_key' => $redisKey,
                'total_items_in_queue' => $totalItems,
                'batch_size' => $batchSize,
            ]);

            $batchData = Redis::lrange($redisKey, 0, $batchSize - 1);

            if (!empty($batchData)) {
                $processedCount = 0;
                $errorCount = 0;

                foreach ($batchData as $data) {
                    try {
                        $inputData = json_decode($data, true);

                        if ($inputData) {
                            ProductApprove::create($inputData);
                            $processedCount++;
                            
                            $cronjobLogger->debug('Product processed successfully', [
                                'product_data' => $inputData,
                                'processed_count' => $processedCount,
                            ]);
                        } else {
                            $errorCount++;
                            $cronjobLogger->warning('Invalid JSON data in Redis', [
                                'raw_data' => $data,
                                'error_count' => $errorCount,
                            ]);
                        }
                    } catch (Exception $e) {
                        $errorCount++;
                        $cronjobLogger->error('Error processing single item', [
                            'raw_data' => $data,
                            'error' => $e->getMessage(),
                            'error_count' => $errorCount,
                        ]);
                    }
                }

                // Remove processed items from Redis
                Redis::ltrim($redisKey, $batchSize, -1);
                
                $cronjobLogger->info('Batch processing completed', [
                    'total_processed' => $processedCount,
                    'errors' => $errorCount,
                    'remaining_items' => Redis::llen($redisKey),
                ]);
            } else {
                $cronjobLogger->info('No data to process', [
                    'redis_key' => $redisKey,
                    'message' => 'Redis queue is empty',
                ]);
            }

            $endTime = now();
            $executionTime = $endTime->diffInSeconds($startTime);
            
            $cronjobLogger->info('=== ProcessRemainingBatch Completed Successfully ===', [
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
            
            $cronjobLogger->error('=== ProcessRemainingBatch Failed ===', [
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