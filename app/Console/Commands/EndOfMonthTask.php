<?php

namespace App\Console\Commands;

use App\Http\Controllers\ArchiveStorageController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

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
        $archiveStorage = new ArchiveStorageController;
        $archiveStorage->store();
        Log::info("Cron job archive storage report Berhasil di jalankan" . date('Y-m-d H:i:s'));
    }
}
