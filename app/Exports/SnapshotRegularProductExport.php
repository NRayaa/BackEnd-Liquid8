<?php

namespace App\Exports;

use App\Models\New_product;
use App\Models\StagingProduct;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithTitle;
use Carbon\Carbon;

class SnapshotRegularProductExport implements WithMultipleSheets
{
    use Exportable;

    protected $targetDate;

    public function __construct($targetDate)
    {
        $this->targetDate = $targetDate;
    }

    public function sheets(): array
    {
        return [
            new SnapshotSheet($this->targetDate, 'in'),
            new SnapshotSheet($this->targetDate, 'out'), 
        ];
    }
}


class SnapshotSheet implements FromCollection, WithHeadings, WithMapping, WithTitle
{
    protected $targetDate;
    protected $type;

    public function __construct($targetDate, $type)
    {
        $this->targetDate = $targetDate;
        $this->type = $type;
    }

    public function collection()
    {
        $targetCarbon = Carbon::parse($this->targetDate);
        $targetDateStart = $targetCarbon->copy()->format('Y-m-d') . ' 00:00:00';
        $targetDateEnd = $targetCarbon->copy()->format('Y-m-d') . ' 23:59:59';

        $newProductsQuery = New_product::whereNotNull('new_category_product')
            ->where(function($q) {
                $q->whereNull('new_tag_product')->orWhere('new_tag_product', '');
            })
            ->where(function ($query) {
                $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(new_quality, '$.lolos')) = 'lolos'")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(new_quality), '$.lolos')) = 'lolos'");
            });

        $stagingProductsQuery = StagingProduct::where(function ($query) {
                $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(new_quality, '$.lolos')) = 'lolos'")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(new_quality), '$.lolos')) = 'lolos'");
            })
            ->where(function($q) {
                $q->whereNull('new_tag_product')->orWhere('new_tag_product', '');
            })
            ->whereNull('stage')
            ->whereNotNull('new_category_product')
            ->whereNot('new_category_product', '');

        if ($this->type === 'in') {
            $newProductsQuery->whereIn('new_status_product', ['display', 'expired', 'slow_moving', 'sale'])
                ->where(function ($query) use ($targetDateEnd) {
                    $query->where('new_date_in_product', '<=', $targetDateEnd)
                          ->orWhere(function ($sub) use ($targetDateEnd) {
                              $sub->whereNull('new_date_in_product')
                                  ->where('created_at', '<=', $targetDateEnd);
                          });
                });

            $stagingProductsQuery->whereIn('new_status_product', ['display', 'expired', 'slow_moving', 'sale'])
                ->where(function ($query) use ($targetDateEnd) {
                    $query->where('new_date_in_product', '<=', $targetDateEnd)
                          ->orWhere(function ($sub) use ($targetDateEnd) {
                              $sub->whereNull('new_date_in_product')
                                  ->where('created_at', '<=', $targetDateEnd);
                          });
                });
                
        } else {
            $newProductsQuery->whereNotNull('date_out')
                ->where('date_out', '>=', $targetDateStart);

            $stagingProductsQuery->whereNotNull('date_out')
                ->where('date_out', '>=', $targetDateStart);
        }

        return $newProductsQuery->get()->concat($stagingProductsQuery->get());
    }

    public function headings(): array
    {
        return [
            'Barcode', 
            'Nama Produk', 
            'Kategori', 
            'Tanggal Masuk', 
            'Tanggal Keluar', 
            'Tipe Keluar', 
            'Lokasi Asal'
        ];
    }

    public function map($row): array
    {
        $dateOutFormatted = $row->date_out ? Carbon::parse($row->date_out)->format('Y-m-d H:i') : '-';
        $typeOutFormatted = strtoupper(str_replace('_', ' ', $row->type_out ?? '-'));
        $location = $row instanceof StagingProduct ? 'Staging' : 'Display';

        return [
            $row->new_barcode_product,
            $row->new_name_product,
            $row->new_category_product,
            $row->new_date_in_product ?? $row->created_at->format('Y-m-d'),
            $dateOutFormatted,
            $typeOutFormatted,
            $location
        ];
    }

    public function title(): string
    {
        return $this->type === 'in' ? 'Masuk (Date In)' : 'Keluar (Date Out)';
    }
}