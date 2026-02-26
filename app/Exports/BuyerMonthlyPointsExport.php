<?php

namespace App\Exports;

use App\Models\Buyer;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class BuyerMonthlyPointsExport implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles
{
    protected $month;
    protected $year;

    public function __construct($month, $year)
    {
        $this->month = $month;
        $this->year = $year;
    }

    public function collection()
    {
        $month = $this->month;
        $year = $this->year;

        // Closure filter tanggal (Sama persis dengan controller)
        $dateFilter = function ($query) use ($month, $year) {
            $query->whereMonth('created_at', $month)
                  ->whereYear('created_at', $year)
                  ->where('status_document_sale', 'selesai');
        };

        // Query Data (Tanpa pagination karena untuk export)
        return Buyer::query()
            ->with(['buyerLoyalty.rank'])
            ->withSum(['sales as monthly_points' => $dateFilter], 'buyer_point_document_sale')
            ->withSum(['sales as monthly_purchase' => $dateFilter], 'total_price_document_sale')
            ->withCount(['sales as monthly_transaction' => $dateFilter])
            // Filter: Hanya export yang ada aktivitas poin/belanja bulan ini (Opsional, agar data tidak kosong melompong)
            // ->having('monthly_points', '>', 0) 
            ->orderByDesc('monthly_points')
            ->get();
    }

    // Header Kolom di Excel
    public function headings(): array
    {
        return [
            'ID Buyer',
            'Name Buyer',
            'No HP',
            'Address',
            'Rank Buyer',
            'Total Poin',
            'Monthly Points',          // <-- Point Bulan Ini
            'Monthly Purchase',
            'Monthly Transaction',
        ];
    }

    // Mapping Data per Baris
    public function map($buyer): array
    {
        $rankName = '-';
        if ($buyer->buyerLoyalty && $buyer->buyerLoyalty->rank) {
            $rankName = $buyer->buyerLoyalty->rank->rank ?? $buyer->buyerLoyalty->rank->rank;
        }

        return [
            $buyer->id,
            $buyer->name_buyer,
            $buyer->phone_buyer,
            $buyer->address_buyer,
            $rankName,                // <-- List Rank
            $buyer->point_buyer,      // Lifetime Points
            (int) $buyer->monthly_points,   // Monthly Points
            (float) $buyer->monthly_purchase,
            (int) $buyer->monthly_transaction,
        ];
    }

    // Styling Header (Bold)
    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}