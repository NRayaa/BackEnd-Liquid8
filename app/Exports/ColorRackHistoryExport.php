<?php

namespace App\Exports;

use App\Models\ColorRackHistory;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Carbon\Carbon;

class ColorRackHistoryExport implements FromCollection, WithHeadings, WithMapping
{
    protected $date;

    public function __construct($date = null)
    {
        $this->date = $date ? Carbon::parse($date) : Carbon::today();
    }

    public function collection()
    {
        return ColorRackHistory::with(['user', 'colorRack'])
            ->whereDate('created_at', $this->date)
            ->latest()
            ->get();
    }

    public function headings(): array
    {
        return [
            'Tanggal & Waktu',
            'Nama Rak',
            'Barcode Item',
            'Nama Item',
            'Aksi (Masuk/Keluar)',
            'Operator'
        ];
    }

    public function map($log): array
    {
        return [
            $log->created_at->format('Y-m-d H:i:s'),
            $log->colorRack ? $log->colorRack->name : 'Rak Dihapus',
            $log->barcode,
            $log->product_name,
            $log->action === 'IN' ? 'Barang Masuk (IN)' : 'Barang Keluar (OUT)',
            $log->user ? $log->user->name : 'Sistem'
        ];
    }
}
