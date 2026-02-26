<?php

namespace App\Imports;

use App\Models\StagingProduct;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithCalculatedFormulas;
use Carbon\Carbon;

class StagingProductImport implements ToModel, WithHeadingRow, WithCalculatedFormulas
{
    private $duplicates = [];

    public function headingRow(): int
    {
        return 1;
    }

    public function getDuplicates(): array
    {
        return $this->duplicates;
    }

    public function model(array $row)
    {
        if (!isset($row['barcode_liquid']) || empty($row['barcode_liquid'])) {
            return null;
        }

        $barcode = $row['barcode_liquid'];

        $exists = StagingProduct::where('new_barcode_product', $barcode)->exists();
        
        if ($exists) {
            $this->duplicates[] = $barcode . " (Ada di Database)";
            return null; 
        }

        $qualityJson = json_encode(['lolos' => 'lolos']);
        $oldPrice = is_numeric($row['unit_price']) ? $row['unit_price'] : 0;
        $newPrice = is_numeric($row['after']) ? $row['after'] : 0;

        return new StagingProduct([
            'new_barcode_product'  => $barcode,
            'old_barcode_product'  => $barcode,
            'new_name_product'     => $row['description'],
            'new_quantity_product' => $row['qty'],
            'new_category_product' => $row['kategori'],
            'old_price_product'    => $oldPrice,
            'new_price_product'    => $newPrice,
            'display_price'        => $newPrice,
            'actual_old_price_product' => $oldPrice,
            'new_quality'        => $qualityJson,       
            'actual_new_quality' => $qualityJson,
            'type'               => 'type1',
            'new_status_product' => 'display',
            'new_discount'       => 0,
            'rack_id'         => null,
            'code_document'   => null,
            'new_tag_product' => null,
            'user_id'         => null,
            'stage'           => null,
            'is_so'           => null,
            'user_so'         => null,
            'new_date_in_product' => Carbon::now(),
        ]);
    }
}