<?php

namespace App\Console\Commands;

use App\Http\Controllers\NewProductController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

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
        $expiredProduct = new NewProductController;
        $expiredProduct->slowMovingProducts();
        Log::info("Cron job Berhasil di jalankan " . date('Y-m-d H:i:s'));
    }
}
