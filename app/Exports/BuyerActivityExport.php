<?php

namespace App\Exports;

use App\Models\Buyer;
use App\Models\BuyerLoyalty;
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

class BuyerActivityExport implements WithMultipleSheets
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
            new BuyerSummarySheet($this->month, $this->year),
            new ActiveBuyerSheet($this->month, $this->year),
            new InactiveBuyerSheet($this->month, $this->year),
        ];
    }
}

/**
 * SHEET 1: SUMMARY (Rekapitulasi Angka)
 * Persis seperti output JSON getBuyerSummary
 */
class BuyerSummarySheet implements FromCollection, WithHeadings, WithStyles, WithTitle, ShouldAutoSize
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
        return 'Summary Dashboard';
    }

    public function collection()
    {

        $totalMasterBuyer = Buyer::count();

        $activeBuyerCount = SaleDocument::where('status_document_sale', 'selesai')
            ->whereMonth('created_at', $this->month)
            ->whereYear('created_at', $this->year)
            ->distinct('buyer_id_document_sale')
            ->count('buyer_id_document_sale');


        $inactiveBuyerCount = $totalMasterBuyer - $activeBuyerCount;


        $retentionRate = ($totalMasterBuyer > 0)
            ? round(($activeBuyerCount / $totalMasterBuyer) * 100, 1) . '%'
            : '0%';

        return collect([
            ['KETERANGAN', 'NILAI'],
            ['Periode Laporan', "{$this->month}-{$this->year}"],
            ['Total Registered Buyer', $totalMasterBuyer],
            ['Active Buyer (Monthly)', $activeBuyerCount],
            ['Inactive Buyer (Monthly)', $inactiveBuyerCount],
            ['Shopper Retention Rate', $retentionRate],
        ]);
    }

    public function headings(): array
    {
        return ['LAPORAN SUMMARY BUYER ACTIVITY'];
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
        $sheet->mergeCells('A1:B1');

        $sheet->getStyle('A2:B2')->getFont()->setBold(true);
        $sheet->getStyle('A2:B2')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF4472C4');
        $sheet->getStyle('A2:B2')->getFont()->getColor()->setARGB('FFFFFFFF');
    }
}

/**
 * SHEET 2: ACTIVE BUYERS
 * Daftar Buyer yang melakukan transaksi bulan ini
 */
class ActiveBuyerSheet implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithTitle
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
        return 'Active Buyers';
    }

    public function collection()
    {


        return SaleDocument::with('buyer')
            ->where('status_document_sale', 'selesai')
            ->whereMonth('created_at', $this->month)
            ->whereYear('created_at', $this->year)
            ->selectRaw('
                buyer_id_document_sale, 
                count(*) as total_trx_count, 
                sum(total_price_document_sale) as total_spent, 
                sum(buyer_point_document_sale) as total_point,
                max(created_at) as last_trx_date
            ')
            ->groupBy('buyer_id_document_sale')
            ->orderByDesc('total_spent')
            ->get();
    }

    public function headings(): array
    {
        return [
            'ID BUYER',
            'NAMA BUYER',
            'NO TELEPON',
            'JUMLAH TRANSAKSI',
            'TOTAL BELANJA (NET)',
            'TRANSAKSI TERAKHIR'
        ];
    }

    public function map($row): array
    {
        $buyer = $row->buyer;
        return [
            $row->buyer_id_document_sale,
            $buyer ? $buyer->name_buyer : 'Unknown',
            $buyer ? $buyer->phone_buyer : '-',
            $row->total_trx_count,
            'Rp ' . number_format($row->total_spent, 0, ',', '.'),
            \Carbon\Carbon::parse($row->last_trx_date)->format('d-m-Y H:i')
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1:G1')->getFont()->setBold(true);
        $sheet->getStyle('A1:G1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF548235');
        $sheet->getStyle('A1:G1')->getFont()->getColor()->setARGB('FFFFFFFF');
    }
}

/**
 * SHEET 3: INACTIVE BUYERS
 * Daftar Buyer yang TIDAK belanja bulan ini
 */
class InactiveBuyerSheet implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithTitle
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
        return 'Inactive Buyers';
    }

    public function collection()
    {

        $activeBuyerIds = SaleDocument::where('status_document_sale', 'selesai')
            ->whereMonth('created_at', $this->month)
            ->whereYear('created_at', $this->year)
            ->distinct()
            ->pluck('buyer_id_document_sale')
            ->toArray();


        return Buyer::whereNotIn('id', $activeBuyerIds)
            ->orderBy('name_buyer', 'asc')
            ->get();
    }

    public function headings(): array
    {
        return [
            'ID BUYER',
            'NAMA BUYER',
            'NO TELEPON',
            'ALAMAT',
            'STATUS'
        ];
    }

    public function map($buyer): array
    {
        return [
            $buyer->id,
            $buyer->name_buyer,
            $buyer->phone_buyer,
            $buyer->address_buyer,
            'Tidak Belanja Bulan Ini'
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('A1:E1')->getFont()->setBold(true);
        $sheet->getStyle('A1:E1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFC00000');
        $sheet->getStyle('A1:E1')->getFont()->getColor()->setARGB('FFFFFFFF');
    }
}
