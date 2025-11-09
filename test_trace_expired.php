<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\LoyaltyService;
use App\Models\Buyer;

// Cari buyer ETI HERAWATI dari gambar
$buyer = Buyer::where('name_buyer', 'LIKE', '%ETI HERAWATI%')->first();

if (!$buyer) {
    echo "Buyer ETI HERAWATI tidak ditemukan!\n";
    echo "Mencoba dengan buyer_id=1 (Miss Darlene Botsford)...\n\n";
    $buyerId = 1;
} else {
    $buyerId = $buyer->id;
    echo "Buyer ditemukan: {$buyer->name_buyer} (ID: {$buyerId})\n\n";
}

// Trace expired history untuk buyer ini
$result = LoyaltyService::traceExpiredHistory($buyerId);

echo "=== TRACE EXPIRED HISTORY ===\n";
echo "Buyer ID: {$result['buyer_id']}\n";
echo "Total Transactions: {$result['total_transactions']}\n";
echo "\n";

if (empty($result['transactions'])) {
    echo "Tidak ada transaksi yang ditemukan.\n";
} else {
    echo str_repeat("=", 120) . "\n";
    foreach ($result['transactions'] as $trans) {
        echo "Trans #{$trans['transaction_number']} - {$trans['transaction_date']}\n";
        echo "  Sale Document ID: {$trans['sale_document_id']}\n";
        echo "  Code: {$trans['code_document_sale']}\n";
        echo "  Total Display: Rp " . number_format($trans['total_display'], 0, ',', '.') . "\n";
        echo "  Count: {$trans['count_before']} → {$trans['count_after']}\n";
        
        if ($trans['expired_status'] === 'EXPIRED') {
            echo "  ⚠️  STATUS: {$trans['expired_status']} ⚠️\n";
            echo "  Reason: {$trans['expired_reason']}\n";
        } else {
            echo "  ✅ STATUS: {$trans['expired_status']}\n";
        }
        
        echo "  Rank After: {$trans['rank_after']} (expired_weeks: {$trans['expired_weeks']})\n";
        
        if ($trans['expire_date_before']) {
            echo "  Expire Date Before: {$trans['expire_date_before']}\n";
        }
        
        if ($trans['expire_date_action'] !== 'NONE') {
            echo "  Expire Date Action: {$trans['expire_date_action']}";
            if (isset($trans['expire_date_calculation'])) {
                echo " ({$trans['expire_date_calculation']})";
            }
            echo "\n";
        }
        
        if ($trans['expire_date_after']) {
            echo "  Expire Date After: {$trans['expire_date_after']}\n";
        }
        
        echo str_repeat("-", 120) . "\n";
    }
}

echo "\n=== SUMMARY ===\n";
echo "Total Expired Events: {$result['summary']['total_expired_events']}\n";
echo "Final Count: {$result['summary']['final_count']}\n";
echo "Final Rank: {$result['summary']['final_rank']}\n";
if ($result['summary']['final_expire_date']) {
    echo "Final Expire Date: {$result['summary']['final_expire_date']}\n";
} else {
    echo "Final Expire Date: NULL\n";
}
