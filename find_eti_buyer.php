<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Buyer;

// Cari buyer ETI HERAWATI
$buyer = Buyer::where('name_buyer', 'LIKE', '%ETI HERAWATI%')->first();

if ($buyer) {
    echo "Buyer ditemukan!\n";
    echo "ID: {$buyer->id}\n";
    echo "Name: {$buyer->name_buyer}\n";
    echo "Phone: {$buyer->phone_buyer}\n";
    echo "\n";
    
    // Cek transaksi buyer ini
    $transactions = \App\Models\SaleDocument::where('buyer_id_document_sale', $buyer->id)
        ->where('status_document_sale', 'selesai')
        ->where('total_display_document_sale', '>=', 5000000)
        ->where('created_at', '>=', '2025-06-01')
        ->orderBy('created_at', 'asc')
        ->get(['id', 'code_document_sale', 'created_at', 'total_display_document_sale']);
    
    echo "Total transaksi valid: {$transactions->count()}\n\n";
    
    if ($transactions->count() > 0) {
        echo "Detail transaksi:\n";
        foreach ($transactions as $index => $trans) {
            $transNumber = $index + 1;
            echo "{$transNumber}. {$trans->code_document_sale} - {$trans->created_at->format('d M Y')} - Rp " . number_format($trans->total_display_document_sale, 0, ',', '.') . "\n";
        }
    }
} else {
    echo "Buyer ETI HERAWATI tidak ditemukan.\n";
    echo "\nMencoba mencari buyer dengan nama mirip...\n";
    
    $buyers = Buyer::where('name_buyer', 'LIKE', '%ETI%')
        ->orWhere('name_buyer', 'LIKE', '%HERAWATI%')
        ->limit(10)
        ->get(['id', 'name_buyer']);
    
    if ($buyers->count() > 0) {
        echo "Buyer dengan nama mirip:\n";
        foreach ($buyers as $b) {
            echo "- ID: {$b->id}, Name: {$b->name_buyer}\n";
        }
    } else {
        echo "Tidak ada buyer dengan nama mirip.\n";
    }
}
