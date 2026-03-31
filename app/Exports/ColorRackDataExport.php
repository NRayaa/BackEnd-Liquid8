<?php

namespace App\Exports;

use App\Models\ColorRack;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Carbon\Carbon;

class ColorRackDataExport implements FromCollection, WithHeadings, WithMapping
{
    public function collection()
    {
        return ColorRack::with([
                'colorRackProducts.newProduct', 
                'colorRackProducts.bundle', 
                'userSo', 
                'userMigrate'
            ])
            ->withCount('colorRackProducts')
            ->get();
    }

    public function headings(): array
    {
        return [
            'Nama Rak',
            'Barcode',
            'Status',
            'Total Data',
            'Total Old Price',
            'Total New Price',
            'Waktu Buat Rak',
            'Waktu SO',
            'Waktu To Migrate',
            'User SO',
            'User Migrate',
        ];
    }

    public function map($rack): array
    {
        return [
            $rack->name,
            $rack->barcode,
            strtoupper($rack->status),
            $rack->color_rack_products_count,
            $rack->total_old_price,
            $rack->total_new_price,
            $rack->created_at ? $rack->created_at->format('Y-m-d H:i:s') : '-',
            $rack->so_at ? $rack->so_at->format('Y-m-d H:i:s') : ($rack->is_so ? 'Sudah SO' : 'Belum SO'),
            $rack->move_to_migrate_at ? Carbon::parse($rack->move_to_migrate_at)->format('Y-m-d H:i:s') : '-',
            $rack->userSo ? $rack->userSo->name : '-',
            $rack->userMigrate ? $rack->userMigrate->name : '-',
        ];
    }
}
