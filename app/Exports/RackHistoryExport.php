<?php

namespace App\Exports;

use App\Models\RackHistory;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithHeadings;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class RackHistoryExport implements FromArray, ShouldAutoSize, WithStyles, WithHeadings
{
    protected $source;
    protected $date;

    public function __construct($source, $date)
    {
        $this->source = $source;
        $this->date = $date;
    }

    public function array(): array
    {
        $latestHistoryIds = RackHistory::whereDate('created_at', $this->date)
            ->select(DB::raw('MAX(id) as id'))
            ->groupBy('barcode');

        $insertions = RackHistory::with(['user:id,name', 'rack:id,name'])
            ->whereIn('id', $latestHistoryIds)
            ->where('action', 'IN')
            ->where('source', $this->source)
            ->orderBy('rack_id')
            ->latest()
            ->get();

        $exportArray = [];
        $no = 1;

        foreach ($insertions as $row) {
            $exportArray[] = [
                $no++,
                $row->created_at->format('Y-m-d H:i:s'),
                $row->rack ? $row->rack->name : 'Rak Dihapus',
                $row->user ? $row->user->name : 'Unknown',
                $row->barcode,
                $row->product_name,
            ];
        }

        return $exportArray;
    }

    public function headings(): array
    {
        return [
            'No',
            'Tanggal & Waktu Masuk',
            'Nama Rak',
            'Operator (User)',
            'Barcode',
            'Nama Produk',
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1:F1')->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['argb' => 'FFE0E0E0'] 
            ]
        ]);

        $highestRow = $sheet->getHighestRow();
        if ($highestRow > 0) {
            $sheet->getStyle('A1:F' . $highestRow)->applyFromArray([
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['argb' => '00000000'],
                    ],
                ],
            ]);
        }
    }
}