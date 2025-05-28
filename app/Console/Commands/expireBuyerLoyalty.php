<?php

namespace App\Console\Commands;

use App\Http\Controllers\BuyerLoyaltyController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use App\Http\Controllers\NewProductController;
use App\Http\Controllers\StagingProductController;

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
       $expiredBuyerLoyalty = new BuyerLoyaltyController;
       $expiredBuyerLoyalty->expireBuyerLoyalty(); 
        Log::info("berhasil jalan, expired buyer loyalty" . date('Y-m-d H:i:s'));
    }
}
