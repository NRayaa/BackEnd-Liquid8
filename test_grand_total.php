<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\SaleDocument;

// Ambil sale document terakhir
$saleDocument = SaleDocument::latest()->first();

if (!$saleDocument) {
    echo "Tidak ada sale document ditemukan.\n";
    exit;
}

echo "=== TEST GRAND TOTAL ACCESSOR ===\n\n";
echo "Sale Document ID: {$saleDocument->id}\n";
echo "Code: {$saleDocument->code_document_sale}\n\n";

echo "--- Data dari Database ---\n";
echo "total_price_document_sale: Rp " . number_format($saleDocument->total_price_document_sale, 2, ',', '.') . "\n";
echo "cardbox_total_price: Rp " . number_format($saleDocument->cardbox_total_price, 2, ',', '.') . "\n";
echo "price_after_tax: Rp " . number_format($saleDocument->price_after_tax, 2, ',', '.') . "\n";
echo "is_tax: {$saleDocument->is_tax}\n";
echo "tax: {$saleDocument->tax}%\n\n";

echo "--- Accessor ---\n";
echo "grand_total (accessor): Rp " . number_format($saleDocument->grand_total, 2, ',', '.') . "\n\n";

echo "--- Manual Calculation ---\n";
$manualGrandTotal = $saleDocument->total_price_document_sale + $saleDocument->cardbox_total_price;
echo "Manual Grand Total: Rp " . number_format($manualGrandTotal, 2, ',', '.') . "\n\n";

echo "--- Check Appends ---\n";
$array = $saleDocument->toArray();
if (isset($array['grand_total'])) {
    echo "✅ grand_total ADA di toArray()\n";
    echo "Nilai: Rp " . number_format($array['grand_total'], 2, ',', '.') . "\n";
} else {
    echo "❌ grand_total TIDAK ADA di toArray()\n";
}

echo "\n--- JSON Output ---\n";
echo json_encode([
    'id' => $saleDocument->id,
    'code_document_sale' => $saleDocument->code_document_sale,
    'total_price_document_sale' => $saleDocument->total_price_document_sale,
    'cardbox_total_price' => $saleDocument->cardbox_total_price,
    'price_after_tax' => $saleDocument->price_after_tax,
    'grand_total' => $saleDocument->grand_total,
], JSON_PRETTY_PRINT);
