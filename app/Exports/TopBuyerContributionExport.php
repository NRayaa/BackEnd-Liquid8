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

class TopBuyerContributionExport implements WithMultipleSheets
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
            new TopBuyerSummarySheet($this->month, $this->year),
            new AllBuyersRankedSheet($this->month, $this->year),
        ];
    }
}

/**
 * SHEET 1: HIGHLIGHT TOP 1 BUYER (BY POINTS)
 */
class TopBuyerSummarySheet implements FromCollection, WithHeadings, WithStyles, WithTitle, ShouldAutoSize
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
        return 'Highlight Top 1 (By Points)';
    }

    public function collection()
    {
        // 1. Hitung Total Revenue Toko (Untuk penyebut persentase revenue)
        $totalRevenueStore = SaleDocument::where('status_document_sale', 'selesai')
            ->whereMonth('created_at', $this->month)
            ->whereYear('created_at', $this->year)
            ->sum('price_after_tax');

        if ($totalRevenueStore == 0) {
            return collect([['DATA KOSONG', 'Tidak ada penjualan bulan ini']]);
        }

        // 2. Cari Top 1 Buyer BERDASARKAN POIN TERTINGGI
        $topBuyer = SaleDocument::with('buyer')
            ->where('status_document_sale', 'selesai')
            ->whereMonth('created_at', $this->month)
            ->whereYear('created_at', $this->year)
            ->selectRaw('
                buyer_id_document_sale, 
                sum(price_after_tax) as total_spent,
                sum(buyer_point_document_sale) as total_points,
                count(*) as trx_count
            ')
            ->groupBy('buyer_id_document_sale')
            ->orderByDesc('total_points') // <--- REVISI: Ranking by POIN
            ->first();

        if (!$topBuyer) {
            return collect([['DATA KOSONG', 'Tidak ada data buyer']]);
        }

        // 3. Kalkulasi Persentase Revenue
        // (Tetap menghitung kontribusi uang, meskipun ranking by poin)
        $contributionPercent = ($topBuyer->total_spent / $totalRevenueStore);
        
        $buyerName = $topBuyer->buyer ? $topBuyer->buyer->name_buyer : 'Unknown Buyer';
        $buyerPhone = $topBuyer->buyer ? $topBuyer->buyer->phone_buyer : '-';

        return collect([
            ['KETERANGAN', 'DETAIL'],
            ['Periode Laporan', "{$this->month}-{$this->year}"],
            ['Total Revenue Toko', 'Rp ' . number_format($totalRevenueStore, 0, ',', '.')],
            ['', ''], // Gap
            ['TOP 1 BUYER (MOST LOYAL)', $buyerName],
            ['ID Buyer', $topBuyer->buyer_id_document_sale],
            ['No Telepon', $buyerPhone],
            ['Jumlah Transaksi', $topBuyer->trx_count . ' kali'],
            ['Total Poin Bulan Ini', number_format($topBuyer->total_points, 0, ',', '.') . ' pts'], // Info Poin
            ['Total Belanja (Revenue)', 'Rp ' . number_format($topBuyer->total_spent, 0, ',', '.')],
            ['', ''], // Gap
            ['KONTRIBUSI REVENUE %', number_format($contributionPercent * 100, 2) . '%']
        ]);
    }

    public function headings(): array
    {
        return ['SUMMARY TOP BUYER (BY POINTS)'];
    }

    public function styles(Worksheet $sheet)
    {
        // Judul
        $sheet->mergeCells('A1:B1');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->getStyle('A1')->getAlignment()->setHorizontal('center');
        
        // Style Label Kiri
        $sheet->getStyle('A2:A12')->getFont()->setBold(true);
        
        // Highlight Bagian TOP 1
        $sheet->getStyle('A5:B10')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9E1F2'); // Biru Muda
        
        // Highlight Poin (Penentu Juara)
        $sheet->getStyle('A9:B9')->getFont()->getColor()->setARGB('FF006100'); // Text Hijau
        $sheet->getStyle('A9:B9')->getFont()->setBold(true);

        // Highlight Hasil Persentase
        $sheet->getStyle('A12:B12')->getFont()->setBold(true)->setSize(12);
        $sheet->getStyle('A12:B12')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFFFFF00'); // Kuning
    }
}

/**
 * SHEET 2: LEADERBOARD (RANKED BY POINTS)
 */
class AllBuyersRankedSheet implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithTitle
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
        return 'Leaderboard (By Points)';
    }

    public function collection()
    {
        // 1. Hitung Total Revenue Store (Untuk penyebut persentase)
        $this->grandTotalRevenue = SaleDocument::where('status_document_sale', 'selesai')
            ->whereMonth('created_at', $this->month)
            ->whereYear('created_at', $this->year)
            ->sum('price_after_tax');

        // 2. Ambil List Buyer Sorted by POINTS
        return SaleDocument::with('buyer')
            ->where('status_document_sale', 'selesai')
            ->whereMonth('created_at', $this->month)
            ->whereYear('created_at', $this->year)
            ->selectRaw('
                buyer_id_document_sale, 
                sum(price_after_tax) as total_spent,
                sum(buyer_point_document_sale) as total_points,
                count(*) as trx_count
            ')
            ->groupBy('buyer_id_document_sale')
            ->orderByDesc('total_points') // <--- REVISI: Ranking by POIN
            ->get();
    }

    public function headings(): array
    {
        return [
            'RANK',
            'NAMA BUYER',
            'ID BUYER',
            'JUMLAH TRX',
            'TOTAL POIN', // Kolom Baru
            'TOTAL REVENUE (NET)',
            '% KONTRIBUSI REVENUE'
        ];
    }

    private $rankCounter = 0;
    private $grandTotalRevenue = 0;

    public function map($row): array
    {
        $this->rankCounter++;
        $buyerName = $row->buyer ? $row->buyer->name_buyer : 'Unknown';
        
        $percentage = ($this->grandTotalRevenue > 0) 
            ? ($row->total_spent / $this->grandTotalRevenue) 
            : 0;

        return [
            $this->rankCounter,
            $buyerName,
            $row->buyer_id_document_sale,
            $row->trx_count,
            number_format($row->total_points, 0, ',', '.'), // Poin
            'Rp ' . number_format($row->total_spent, 0, ',', '.'),
            number_format($percentage * 100, 2) . '%'
        ];
    }

    public function styles(Worksheet $sheet)
    {
        // Header Style
        $sheet->getStyle('A1:G1')->getFont()->setBold(true);
        $sheet->getStyle('A1:G1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF4472C4'); // Biru
        $sheet->getStyle('A1:G1')->getFont()->getColor()->setARGB('FFFFFFFF');

        // Highlight Juara 1 (Row 2)
        $sheet->getStyle('A2:G2')->getFont()->setBold(true);
        $sheet->getStyle('A2:G2')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFC6EFCE'); // Hijau Muda
        $sheet->getStyle('A2:G2')->getFont()->getColor()->setARGB('FF006100'); // Text Hijau Gelap
    }
}