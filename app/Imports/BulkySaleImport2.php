<?php

namespace App\Imports;

use App\Models\BulkySale;
use App\Models\Bundle;
use App\Models\New_product;
use App\Models\StagingProduct;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithBatchInserts;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class BulkySaleImport2 implements ToCollection, WithHeadingRow, WithValidation, WithChunkReading, WithBatchInserts //, ShouldQueue
{

    private $bulkyDocumentId;
    private $discountBulky;
    private $bagProductId;

    private $totalFoundBarcode = 0;
    private $dataNoutFoundBarcode = [];
    private $duplicateBarcodes = [];

    public function __construct($bulkyDocumentId, $discountBulky, $bagProductId)
    {
        $this->discountBulky = $discountBulky;
        $this->bulkyDocumentId = $bulkyDocumentId;
        $this->bagProductId = $bagProductId;
    }

    public function collection(Collection $rows)
    {
        Log::info('Import chunk executed. Total rows: ' . $rows->count());
        DB::beginTransaction();

        try {
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

                $models = [
                    'new_product' => New_product::where('new_barcode_product', $barcode)->first(),
                    'staging_product' => StagingProduct::where('new_barcode_product', $barcode)->first(),
                    'bundle_product' => Bundle::where('barcode_bundle', $barcode)->first(),
                ];

                $product = null;

                foreach ($models as $type => $model) {
                    if (!$model) continue;

                    $status = match ($type) {
                        'new_product', 'staging_product' => $model->new_status_product,
                        'bundle_product' => $model->product_status,
                    };

                    if ($status === 'sale') {
                        // $this->duplicateBarcodes[] = $barcode;
                        break;
                    }

                    $product = match ($type) {
                        'new_product', 'staging_product' => [
                            'barcode' => $model->new_barcode_product,
                            'category' => $model->new_category_product,
                            'name' => $model->new_name_product,
                            'old_price' => $model->old_price_product,
                            'status' => $model->new_status_product,
                            'qty' => $model->new_quantity_product ?? null,
                            'code_document' => $model->code_document ?? null,
                            'old_barcode_product' => $model->old_barcode_product ?? null,
                            'new_date_in_product' => $model->new_date_in_product ?? null,
                            'display_price' => $model->display_price ?? null,
                            'created_at' => $model->created_at,
                            'actual_old_price_product' => $model->actual_old_price_product ?? $model->old_price_product,

                        ],
                        'bundle_product' => [
                            'barcode' => $model->barcode_bundle,
                            'category' => $model->category,
                            'name' => $model->name_bundle,
                            'old_price' => $model->total_price_bundle,
                            'status' => $model->product_status,
                            'qty' => $model->total_product_bundle ?? null,
                            'code_document' => $model->product_bundles->first()?->code_document ?? null,
                            'old_barcode_product' => null,
                            'new_date_in_product' => $model->created_at ?? null,
                            'display_price' =>  null,
                            'created_at' => $model->created_at ?? null,
                            'actual_old_price_product' => $model->product_bundles->sum('actual_old_price_product') ?? 0,
                        ],
                    };

                    match ($type) {
                        'new_product', 'staging_product' => $model->update(['new_status_product' => 'sale']),
                        'bundle_product' => $model->update(['product_status' => 'sale']),
                    };

                    break;
                }

                if ($product) {
                    $bulkySaleData[] = [
                        'bulky_document_id' => $this->bulkyDocumentId ?? null,
                        'bag_product_id' => $this->bagProductId ?? null,
                        'barcode_bulky_sale' => $product['barcode'],
                        'product_category_bulky_sale' => $product['category'] ?? null,
                        'name_product_bulky_sale' => $product['name'] ?? null,
                        'status_product_before' => $product['status'],
                        'old_price_bulky_sale' => $product['old_price'] ?? null,
                        'after_price_bulky_sale' => $product['old_price'] - ($product['old_price'] * $this->discountBulky / 100),
                        'qty' => $product['qty'] ?? null,
                        'code_document' => $product['code_document'] ?? null,
                        'old_barcode_product' => $product['old_barcode_product'] ?? null,
                        'new_date_in_product' => $product['new_date_in_product'] ?? null,
                        'display_price' => $product['display_price'] ?? 0,
                        'actual_created_at' => $product['created_at'] ?? null,
                        'actual_old_price_product' => $product['actual_old_price_product'] ?? null,

                    ];
                    $this->totalFoundBarcode++;
                    $barcodeToDelete[] = $product['barcode'];
                } else {
                    $this->dataNoutFoundBarcode[] = $barcode;
                }
            }

            if (!empty($bulkySaleData)) {
                BulkySale::insert($bulkySaleData);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error importing bulky sale data: ' . $e->getMessage());
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

    public function chunkSize(): int
    {
        return 500;
    }

    public function batchSize(): int
    {
        return 500;
    }
}
