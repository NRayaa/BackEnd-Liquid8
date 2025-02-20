<?php

namespace App\Imports;

use App\Models\BulkySale;
use App\Models\New_product;
use App\Models\StagingProduct;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class BulkySaleImport implements ToCollection, WithHeadingRow, WithValidation
{

    private $bulkyDocumentId;

    private $totalFoundBarcode = 0;
    private $dataNoutFoundBarcode = [];
    private $duplicateBarcodes = [];

    public function __construct($bulkyDocumentId)
    {
        $this->bulkyDocumentId = $bulkyDocumentId;
    }

    public function collection(Collection $rows)
    {
        $bulkySaleData = [];
        $barcodeToDelete = [];

        foreach ($rows as $row) {
            $barcode = $row['barcode'] ?? $row['barcode_product'];

            // Cek apakah barcode sudah diproses sebelumnya
            if (in_array($barcode, $barcodeToDelete)) {
                // Jika barcode sudah ada, tandai sebagai duplikat
                $this->duplicateBarcodes[] = $barcode;
                continue; // Lewatkan iterasi ini dan tidak memproses barcode duplikat lagi
            }

            $product = New_product::where('new_barcode_product', $barcode)->first() ?? StagingProduct::where('new_barcode_product', $barcode)->first();

            if ($product) {
                $bulkySaleData[] = [
                    'bulky_document_id' => $this->bulkyDocumentId,
                    'barcode_bulky_sale' => $product->new_barcode_product,
                    'product_category_bulky_sale' => $product->new_category_product,
                    'name_product_bulky_sale' => $product->new_name_product,
                    'old_price_bulky_sale' => $product->old_price_product,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                $this->totalFoundBarcode++;
                $barcodeToDelete[] = $product->new_barcode_product;
            } else {
                $this->dataNoutFoundBarcode[] = $barcode;
            }
        }

        if (!empty($bulkySaleData)) {
            BulkySale::insert($bulkySaleData);
        }

        if (!empty($barcodeToDelete)) {
            New_product::whereIn('new_barcode_product', $barcodeToDelete)->delete();
            StagingProduct::whereIn('new_barcode_product', $barcodeToDelete)->delete();
        }
    }

    public function getTotalFoundBarcode(): int
    {
        return $this->totalFoundBarcode;
    }

    public function getTotalNotFoundBarcode(): int
    {
        return count($this->dataNoutFoundBarcode);
    }

    public function getDataNotFoundBarcode(): array
    {
        return $this->dataNoutFoundBarcode;
    }

    public function getDataDuplicateBarcode(): array
    {
        return $this->duplicateBarcodes;
    }

    public function rules(): array
    {
        return [
            'barcode' => 'required_without_all:*.barcode_product,*.barkode',
        ];
    }

    public function customValidationMessages()
    {
        return [
            'barcode.required_without_all' => 'Harus ada kolom: Barcode / Barcode Product !',
        ];
    }
}
