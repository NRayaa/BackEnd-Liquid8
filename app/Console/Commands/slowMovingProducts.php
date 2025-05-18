<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\NewProductController;
use App\Http\Controllers\StagingProductController;

class slowMovingProducts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cron:slowMovingProducts';

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
        $expiredProductStaging = new StagingProductController;
        $expiredProductInventory = new NewProductController;
        $expiredProductInventory->slowMovingProducts();
        $expiredProductStaging->slowMovingProductStaging();
        
        Log::info("Cron job Berhasil di jalankan " . date('Y-m-d H:i:s'));
    }
}
