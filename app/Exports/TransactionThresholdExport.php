<?php

namespace App\Exports;

use App\Models\SaleDocument;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class TransactionThresholdExport implements WithMultipleSheets
{
    protected $month;
    protected $year;

    public function __construct($month, $year)
    {
        $this->month = $month;
        $this->year = $year;
    }

    public function sheets(): array
    {
        return [
            new ThresholdSummarySheet($this->month, $this->year),
            new BelowMinimumSheet($this->month, $this->year),
            new AboveMinimumSheet($this->month, $this->year),
        ];
    }
}

/**
 * SHEET 1: DASHBOARD ANALISA (Persentase & Revenue)
 */
class ThresholdSummarySheet implements FromCollection, WithHeadings, WithStyles, WithTitle, ShouldAutoSize
{
    protected $month;
    protected $year;

    public function __construct($month, $year)
    {
        $this->month = $month;
        $this->year = $year;
    }

    public function title(): string
    {
        return 'Analisa Threshold 5 Juta';
    }

    public function collection()
    {
        // 1. Ambil Semua Transaksi Selesai Bulan Ini
        $transactions = SaleDocument::where('status_document_sale', 'selesai')
            ->whereMonth('created_at', $this->month)
            ->whereYear('created_at', $this->year)
            ->get();

        $totalTrx = $transactions->count();
        
        if ($totalTrx == 0) {
            return collect([['DATA KOSONG', 'Tidak ada transaksi bulan ini']]);
        }

        // 2. Pisahkan Data (Gunakan Harga Display untuk Logic 5 Juta)
        $threshold = 5000000;
        
        $passTrx = $transactions->where('total_display_document_sale', '>=', $threshold);
        $failTrx = $transactions->where('total_display_document_sale', '<', $threshold);

        // Count
        $countPass = $passTrx->count();
        $countFail = $failTrx->count();

        // Percentage
        $percentPass = ($countPass / $totalTrx);
        $percentFail = ($countFail / $totalTrx);

        // Revenue (Real Money / Price After Tax)
        // Pastikan kolom price_after_tax ada, jika error ganti ke total_price_document_sale
        $revPass = $passTrx->sum('price_after_tax'); 
        $revFail = $failTrx->sum('price_after_tax');
        $totalRev = $revPass + $revFail;

        // Revenue Share
        $sharePass = ($totalRev > 0) ? ($revPass / $totalRev) : 0;
        $shareFail = ($totalRev > 0) ? ($revFail / $totalRev) : 0;

        return collect([
            ['KATEGORI', 'JUMLAH TRANSAKSI', '% DARI TOTAL TRX', 'TOTAL REVENUE (NET)', 'REVENUE SHARE'],
            
            [
                'Memenuhi Syarat (>= 5 Juta)', 
                $countPass, 
                number_format($percentPass * 100, 2) . '%', 
                $revPass, // Nanti diformat di map/style
                number_format($sharePass * 100, 2) . '%'
            ],
            
            [
                'Dibawah Minimum (< 5 Juta)', 
                $countFail, 
                number_format($percentFail * 100, 2) . '%', 
                $revFail, 
                number_format($shareFail * 100, 2) . '%'
            ],

            ['', '', '', '', ''], // Gap

            [
                'GRAND TOTAL', 
                $totalTrx, 
                '100%', 
                $totalRev, 
                '100%'
            ]
        ]);
    }

    public function headings(): array
    {
        return ["ANALISA MINIMUM PURCHASE (Periode: {$this->month}-{$this->year})"];
    }

    public function styles(Worksheet $sheet)
    {
        // Header Judul
        $sheet->mergeCells('A1:E1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');

        // Header Table
        $sheet->getStyle('A2:E2')->getFont()->setBold(true);
        $sheet->getStyle('A2:E2')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF4472C4');
        $sheet->getStyle('A2:E2')->getFont()->getColor()->setARGB('FFFFFFFF');

        // Format Currency Column D (Revenue)
        $sheet->getStyle('D3:D10')->getNumberFormat()->setFormatCode('"Rp "#,##0_-');

        // Style Row Grand Total
        $sheet->getStyle('A6:E6')->getFont()->setBold(true);
        $sheet->getStyle('A6:E6')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9D9D9');
        
        // Highlight Row "Dibawah Minimum" (Warning)
        $sheet->getStyle('A4:E4')->getFont()->getColor()->setARGB('FFC00000'); // Merah Text
    }
}

/**
 * SHEET 2: DETAIL DIBAWAH MINIMUM
 */
class BelowMinimumSheet implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithTitle
{
    protected $month;
    protected $year;

    public function __construct($month, $year)
    {
        $this->month = $month;
        $this->year = $year;
    }

    public function title(): string
    {
        return 'List Dibawah 5 Juta';
    }

    public function collection()
    {
        return SaleDocument::with('buyer')
            ->where('status_document_sale', 'selesai')
            ->whereMonth('created_at', $this->month)
            ->whereYear('created_at', $this->year)
            ->where('total_display_document_sale', '<', 5000000) // Logic Filter
            ->orderBy('total_display_document_sale', 'asc') // Urutkan dari yang paling kecil
            ->get();
    }

    public function headings(): array
    {
        return [
            'NO TRANSAKSI',
            'TANGGAL',
            'NAMA BUYER',
            'HARGA DISPLAY (ACUAN)',
            'HARGA NET (BAYAR)',
            'SELISIH KE 5 JUTA'
        ];
    }

    public function map($row): array
    {
        $selisih = 5000000 - $row->total_display_document_sale;

        return [
            $row->code_document_sale,
            $row->created_at->format('d-m-Y H:i'),
            $row->buyer ? $row->buyer->name_buyer : 'Unknown',
            'Rp ' . number_format($row->total_display_document_sale, 0, ',', '.'),
            'Rp ' . number_format($row->price_after_tax, 0, ',', '.'),
            'Rp ' . number_format($selisih, 0, ',', '.') // Info berapa kurangnya
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1:F1')->getFont()->setBold(true);
        $sheet->getStyle('A1:F1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFC00000'); // Merah
        $sheet->getStyle('A1:F1')->getFont()->getColor()->setARGB('FFFFFFFF');
    }
}

/**
 * SHEET 3: DETAIL MEMENUHI SYARAT
 */
class AboveMinimumSheet implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithTitle
{
    protected $month;
    protected $year;

    public function __construct($month, $year)
    {
        $this->month = $month;
        $this->year = $year;
    }

    public function title(): string
    {
        return 'List Lolos (>= 5 Juta)';
    }

    public function collection()
    {
        return SaleDocument::with('buyer')
            ->where('status_document_sale', 'selesai')
            ->whereMonth('created_at', $this->month)
            ->whereYear('created_at', $this->year)
            ->where('total_display_document_sale', '>=', 5000000)
            ->orderBy('total_display_document_sale', 'desc')
            ->get();
    }

    public function headings(): array
    {
        return [
            'NO TRANSAKSI',
            'TANGGAL',
            'NAMA BUYER',
            'HARGA DISPLAY (ACUAN)',
            'HARGA NET (BAYAR)'
        ];
    }

    public function map($row): array
    {
        return [
            $row->code_document_sale,
            $row->created_at->format('d-m-Y H:i'),
            $row->buyer ? $row->buyer->name_buyer : 'Unknown',
            'Rp ' . number_format($row->total_display_document_sale, 0, ',', '.'),
            'Rp ' . number_format($row->price_after_tax, 0, ',', '.')
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1:E1')->getFont()->setBold(true);
        $sheet->getStyle('A1:E1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF548235'); // Hijau
        $sheet->getStyle('A1:E1')->getFont()->getColor()->setARGB('FFFFFFFF');
    }
}