<?php

namespace App\Exports;

use App\Models\Rack;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class RackDataExport implements FromQuery, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    protected $source;

    public function __construct($source = null)
    {
        $this->source = $source;
    }

    public function query()
    {
        $query = Rack::query()->with(['userSo', 'userDisplay']);

        if ($this->source) {
            $query->where('source', $this->source);
        }

        $excludedStatuses = ['dump', 'migrate', 'scrap_qcd', 'sale', 'repair'];

        $query->withCount(['stagingProducts as valid_staging_count' => function ($q) use ($excludedStatuses) {
            $q->whereNotIn('new_status_product', $excludedStatuses);
        }]);
        $query->withSum(['stagingProducts as valid_staging_old_price' => function ($q) use ($excludedStatuses) {
            $q->whereNotIn('new_status_product', $excludedStatuses);
        }], 'old_price_product');
        $query->withSum(['stagingProducts as valid_staging_new_price' => function ($q) use ($excludedStatuses) {
            $q->whereNotIn('new_status_product', $excludedStatuses);
        }], 'new_price_product');


        $query->withCount(['newProducts as valid_new_count' => function ($q) use ($excludedStatuses) {
            $q->whereNotIn('new_status_product', $excludedStatuses);
        }]);
        $query->withSum(['newProducts as valid_new_old_price' => function ($q) use ($excludedStatuses) {
            $q->whereNotIn('new_status_product', $excludedStatuses);
        }], 'old_price_product');
        $query->withSum(['newProducts as valid_new_new_price' => function ($q) use ($excludedStatuses) {
            $q->whereNotIn('new_status_product', $excludedStatuses);
        }], 'new_price_product');


        $query->withCount(['bundles as valid_bundle_count' => function ($q) {
            $q->where('product_status', '!=', 'sale');
        }]);
        $query->withSum(['bundles as valid_bundle_old_price' => function ($q) {
            $q->where('product_status', '!=', 'sale');
        }], 'total_price_bundle');
        $query->withSum(['bundles as valid_bundle_new_price' => function ($q) {
            $q->where('product_status', '!=', 'sale');
        }], 'total_price_custom_bundle');

        return $query->latest();
    }

    public function headings(): array
    {
        return [
            'Nama Rak',
            'Barcode',
            'Kategori',
            'Status',
            'Source',
            'Total Data',
            'Total Old Price',
            'Total New Price',
            'Waktu Buat Rak',
            'Waktu SO',
            'Waktu To Display',
            'User SO',
            'User Display',
        ];
    }

    public function map($rack): array
    {

        $kategori = strtoupper(trim($rack->name));
        if (strpos($kategori, '-') !== false) {
            $kategori = substr($kategori, strpos($kategori, '-') + 1);
        }
        $kategori = preg_replace('/\s+\d+$/', '', $kategori);

        $status = ucfirst($rack->source);
        if ($rack->source === 'staging' && !is_null($rack->moved_to_display_at)) {
            $status = 'To Display';
        }

        $realTotalData = 0;
        $realTotalOldPrice = 0;
        $realTotalNewPrice = 0;

        if ($rack->source === 'staging') {
            $realTotalData = ($rack->valid_staging_count ?? 0) + ($rack->valid_new_count ?? 0);
            $realTotalOldPrice = ($rack->valid_staging_old_price ?? 0) + ($rack->valid_new_old_price ?? 0);
            $realTotalNewPrice = ($rack->valid_staging_new_price ?? 0) + ($rack->valid_new_new_price ?? 0);
        } else {
            $realTotalData = ($rack->valid_new_count ?? 0) + ($rack->valid_bundle_count ?? 0);
            $realTotalOldPrice = ($rack->valid_new_old_price ?? 0) + ($rack->valid_bundle_old_price ?? 0);
            $realTotalNewPrice = ($rack->valid_new_new_price ?? 0) + ($rack->valid_bundle_new_price ?? 0);
        }

        return [
            $rack->name,
            $rack->barcode,
            $kategori,
            $status,
            ucfirst($rack->source),
            $realTotalData,
            $realTotalOldPrice,
            $realTotalNewPrice,
            $rack->created_at ? $rack->created_at->format('Y-m-d H:i:s') : '-',
            $rack->so_at ? Carbon::parse($rack->so_at)->format('Y-m-d H:i:s') : '-',
            $rack->moved_to_display_at ? Carbon::parse($rack->moved_to_display_at)->format('Y-m-d H:i:s') : '-',
            $rack->userSo ? $rack->userSo->name : '-',
            $rack->userDisplay ? $rack->userDisplay->name : '-',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
