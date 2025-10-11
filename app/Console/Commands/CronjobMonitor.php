<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;

class CronjobMonitor extends Command
{
    protected $signature = 'cronjob:monitor {--days=7 : Number of days to check}';
    protected $description = 'Monitor cronjob execution status from logs';

    public function handle()
    {
        $days = $this->option('days');
        $this->info("=== Cronjob Monitor Report (Last {$days} days) ===");
        
        $logPath = storage_path('logs');
        $cronjobLogs = [];
        
        // Collect cronjob log files from the last X days
        for ($i = 0; $i < $days; $i++) {
            $date = Carbon::now()->subDays($i)->format('Y-m-d');
            $logFile = "{$logPath}/cronjob-{$date}.log";
            
            if (file_exists($logFile)) {
                $cronjobLogs[] = $logFile;
            }
        }
        
        if (empty($cronjobLogs)) {
            $this->warn('No cronjob logs found for the specified period.');
            return;
        }
        
        $commands = [
            'batch:processRemaining',
            'cron:expiredProduct', 
            'cron:expireBuyerLoyalty',
            'cron:slowMovingProduct',
            'end-of-month:task'
        ];
        
        foreach ($commands as $command) {
            $this->info("\n--- {$command} ---");
            $this->analyzeCommand($cronjobLogs, $command);
        }
        
        // Show recent errors
        $this->info("\n=== Recent Errors ===");
        $this->showRecentErrors($cronjobLogs);
    }
    
    private function analyzeCommand($logFiles, $command)
    {
        $executions = [];
        $errors = [];
        
        foreach ($logFiles as $logFile) {
            $content = file_get_contents($logFile);
            $lines = explode("\n", $content);
            
            foreach ($lines as $line) {
                if (strpos($line, $command) !== false) {
                    if (strpos($line, 'Started') !== false) {
                        preg_match('/\[(.*?)\]/', $line, $matches);
                        if (isset($matches[1])) {
                            $executions[] = $matches[1];
                        }
                    }
                    
                    if (strpos($line, 'ERROR') !== false || strpos($line, 'Failed') !== false) {
                        $errors[] = $line;
                    }
                }
            }
        }
        
        $this->line("Total executions: " . count($executions));
        $this->line("Errors: " . count($errors));
        
        if (!empty($executions)) {
            $this->line("Last execution: " . end($executions));
        }
        
        if (!empty($errors)) {
            $this->error("Recent errors found! Check logs for details.");
        } else {
            $this->info("No errors detected.");
        }
    }
    
    private function showRecentErrors($logFiles)
    {
        $errorCount = 0;
        
        foreach ($logFiles as $logFile) {
            $content = file_get_contents($logFile);
            $lines = explode("\n", $content);
            
            foreach ($lines as $line) {
                if (strpos($line, 'ERROR') !== false || strpos($line, 'Failed') !== false) {
                    $this->error(substr($line, 0, 150) . '...');
                    $errorCount++;
                    
                    if ($errorCount >= 5) break 2; // Show max 5 errors
                }
            }
        }
        
        if ($errorCount === 0) {
            $this->info("No errors found in recent logs.");
        }
    }
}
