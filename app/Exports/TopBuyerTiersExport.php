<?php

namespace App\Exports;

use Carbon\Carbon;
use App\Models\Buyer;
use App\Models\LoyaltyRank;
use App\Models\SaleDocument;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * KELAS UTAMA: Menangani Multi-Sheet (Januari - Desember)
 */
class TopBuyerTiersExport implements WithMultipleSheets
{
    protected $year;

    public function __construct($year)
    {
        $this->year = $year;
    }

    public function sheets(): array
    {
        $sheets = [];

        for ($month = 1; $month <= 12; $month++) {
            $sheets[] = new MonthlyBuyerTierSheet($month, $this->year);
        }
        return $sheets;
    }
}

/**
 * KELAS INTERNAL: Menangani Logic Perhitungan per Bulan
 */
class MonthlyBuyerTierSheet implements FromCollection, WithHeadings, WithMapping, ShouldAutoSize, WithStyles, WithTitle
{
    protected $month;
    protected $year;


    protected $ranks;
    protected $lowestRank;
    protected $storeClosureStart;
    protected $storeClosureEnd;

    public function __construct($month, $year)
    {
        $this->month = $month;
        $this->year = $year;


        $this->ranks = LoyaltyRank::orderBy('min_transactions', 'asc')->get();
        $this->lowestRank = $this->ranks->first();


        $this->storeClosureStart = Carbon::parse('2025-07-11');
        $this->storeClosureEnd = Carbon::parse('2025-09-19');
    }

    public function title(): string
    {
        return Carbon::createFromDate($this->year, $this->month, 1)
            ->locale('id')
            ->translatedFormat('F');
    }

    /**
     * Logic Simulasi Rank
     */
    private function getBuyerRankAtEndOfMonth($transactions)
    {
        $currentTransactionCount = 0;
        $simulatedExpireDate = null;
        $currentRank = $this->lowestRank;

        foreach ($transactions as $transaction) {
            $transactionDate = Carbon::parse($transaction->created_at);


            while ($currentTransactionCount >= 2 && $simulatedExpireDate !== null && $transactionDate->gt($simulatedExpireDate)) {
                if ($simulatedExpireDate->between($this->storeClosureStart, $this->storeClosureEnd)) {
                    $daysRemaining = $this->storeClosureStart->diffInDays($simulatedExpireDate, false);
                    if ($daysRemaining < 0) $daysRemaining = 0;
                    $simulatedExpireDate = $this->storeClosureEnd->copy()->addDays(1 + $daysRemaining)->endOfDay();
                    if (!$transactionDate->gt($simulatedExpireDate)) break;
                }

                $downgradedRank = $this->ranks->where('min_transactions', '<', $currentRank->min_transactions)
                    ->sortByDesc('min_transactions')->first();

                if (!$downgradedRank) $downgradedRank = $this->lowestRank;

                if ($currentRank->id == $downgradedRank->id) {
                    $currentTransactionCount = $downgradedRank->min_transactions;
                    $currentRank = $downgradedRank;
                    $simulatedExpireDate = null;
                    break;
                }

                $currentRank = $downgradedRank;
                $currentTransactionCount = $downgradedRank->min_transactions;

                if ($downgradedRank->expired_weeks > 0) {
                    $simulatedExpireDate = $simulatedExpireDate->copy()->addWeeks($downgradedRank->expired_weeks)->endOfDay();
                } else {
                    $simulatedExpireDate = null;
                }
            }


            $rankBeforeTransaction = $currentRank;
            $currentTransactionCount++;

            if ($currentTransactionCount == 1) {
                $newRank = $this->lowestRank;
            } else {
                $newRank = $this->ranks->where('min_transactions', '<=', $currentTransactionCount)
                    ->sortByDesc('min_transactions')->first();
                if (!$newRank) $newRank = $this->lowestRank;
            }


            if ($currentTransactionCount >= 2) {
                $rankForCalculation = $rankBeforeTransaction;
                if ($rankForCalculation->expired_weeks <= 0) $rankForCalculation = $newRank;

                if ($rankForCalculation->expired_weeks > 0) {
                    $simulatedExpireDate = $transactionDate->copy()->addWeeks($rankForCalculation->expired_weeks)->endOfDay();

                    if ($simulatedExpireDate->between($this->storeClosureStart, $this->storeClosureEnd)) {
                        $daysRemaining = $this->storeClosureStart->diffInDays($simulatedExpireDate, false);
                        if ($daysRemaining < 0) $daysRemaining = 0;
                        $simulatedExpireDate = $this->storeClosureEnd->copy()->addDays(1 + $daysRemaining)->endOfDay();
                    }
                } else {
                    $simulatedExpireDate = null;
                }
            } else {
                $simulatedExpireDate = null;
            }

            $currentRank = $newRank;
        }

        return $currentRank;
    }

    public function collection()
    {

        $summary = [];
        $sortedRanks = $this->ranks->sortByDesc('min_transactions');

        foreach ($sortedRanks as $rank) {
            $summary[$rank->rank] = [
                'rank_name' => $rank->rank,
                'total_buyers' => 0,
                'total_revenue' => 0
            ];
        }


        $monthlyRevenueMap = SaleDocument::where('status_document_sale', 'selesai')
            ->whereMonth('created_at', $this->month)
            ->whereYear('created_at', $this->year)
            ->selectRaw('buyer_id_document_sale, sum(price_after_tax) as total_spent')
            ->groupBy('buyer_id_document_sale')
            ->pluck('total_spent', 'buyer_id_document_sale')
            ->toArray();


        $startOfYear = $this->year . '-01-01';
        $buyerIdsWithHistory = SaleDocument::where('status_document_sale', 'selesai')
            ->where('total_display_document_sale', '>=', 5000000)
            ->where('created_at', '>=', $startOfYear)
            ->distinct()
            ->pluck('buyer_id_document_sale');


        $allBuyers = Buyer::whereIn('id', $buyerIdsWithHistory)->get()->keyBy('id');


        $detailData = [];
        $totalRevenueAllTiers = 0;


        foreach ($buyerIdsWithHistory as $buyerId) {
            $buyer = $allBuyers[$buyerId] ?? null;
            if (!$buyer) continue;


            $histories = SaleDocument::where('buyer_id_document_sale', $buyerId)
                ->where('status_document_sale', 'selesai')
                ->where('total_display_document_sale', '>=', 5000000)
                ->where('created_at', '>=', $startOfYear)
                ->where('created_at', '<=', Carbon::createFromDate($this->year, $this->month, 1)->endOfMonth())
                ->orderBy('created_at', 'asc')
                ->get();

            if ($histories->isEmpty()) continue;

            $rankObj = $this->getBuyerRankAtEndOfMonth($histories);
            $tierName = $rankObj->rank;

            $revenue = $monthlyRevenueMap[$buyerId] ?? 0;


            if (isset($summary[$tierName])) {
                $summary[$tierName]['total_buyers']++;
                $summary[$tierName]['total_revenue'] += $revenue;
            } else {
                $summary[$tierName] = [
                    'rank_name' => $tierName,
                    'total_buyers' => 1,
                    'total_revenue' => $revenue
                ];
            }

            $totalRevenueAllTiers += $revenue;



            $detailData[] = [
                'type' => 'detail_row',
                'name' => $buyer->name_buyer,
                'tier' => $tierName,
                'revenue' => $revenue,
                'phone' => $buyer->phone_buyer
            ];
        }


        $output = collect();


        foreach ($summary as $data) {
            $percentage = ($totalRevenueAllTiers > 0)
                ? ($data['total_revenue'] / $totalRevenueAllTiers)
                : 0;

            $output->push([
                'type' => 'summary_row',
                'col1' => $data['rank_name'],
                'col2' => $data['total_buyers'],
                'col3' => $data['total_revenue'],
                'col4' => $percentage
            ]);
        }

        $output->push(['type' => 'gap']);


        $output->push([
            'type' => 'detail_header',
            'col1' => 'NAMA BUYER',
            'col2' => 'TIER SAAT INI',
            'col3' => 'BELANJA BULAN INI (NET)',
            'col4' => 'NO TELEPON'
        ]);



        $detailCollection = collect($detailData)->sortByDesc('revenue');

        foreach ($detailCollection as $row) {
            $output->push([
                'type' => 'detail_row',
                'col1' => $row['name'],
                'col2' => $row['tier'],
                'col3' => $row['revenue'],
                'col4' => $row['phone']
            ]);
        }

        return $output;
    }

    public function headings(): array
    {
        return ['TIER / RANK', 'JUMLAH BUYER (MEMBER)', 'REVENUE KONTRIBUSI', '% KONTRIBUSI'];
    }

    public function map($row): array
    {

        if (!isset($row['type'])) return [];
        if ($row['type'] == 'gap') return [''];




        $col3 = $row['col3'];
        if (is_numeric($col3)) {
            $col3 = 'Rp ' . number_format($col3, 0, ',', '.');
        }


        $col4 = $row['col4'];
        if ($row['type'] == 'summary_row' || $row['type'] == 'summary_total') {

            $col4 = number_format($col4 * 100, 2) . '%';
        }

        return [
            $row['col1'],
            $row['col2'],
            $col3,
            $col4
        ];
    }




    public function styles(Worksheet $sheet)
    {

        $sheet->getStyle('A1:D1')->getFont()->setBold(true);
        $sheet->getStyle('A1:D1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FF4472C4');
        $sheet->getStyle('A1:D1')->getFont()->getColor()->setARGB('FFFFFFFF');







        $highestRow = $sheet->getHighestRow();
        for ($row = 1; $row <= $highestRow; $row++) {
            $valA = $sheet->getCell("A$row")->getValue();


            if ($valA === 'GRAND TOTAL') {
                $sheet->getStyle("A$row:D$row")->getFont()->setBold(true);
                $sheet->getStyle("A$row:D$row")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFD9D9D9');
            }


            if ($valA === 'NAMA BUYER') {
                $sheet->getStyle("A$row:D$row")->getFont()->setBold(true);
                $sheet->getStyle("A$row:D$row")->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)->getStartColor()->setARGB('FFED7D31');
                $sheet->getStyle("A$row:D$row")->getFont()->getColor()->setARGB('FFFFFFFF');
            }
        }


        $sheet->getColumnDimension('A')->setAutoSize(true);
        $sheet->getColumnDimension('B')->setAutoSize(true);
        $sheet->getColumnDimension('C')->setAutoSize(true);
        $sheet->getColumnDimension('D')->setAutoSize(true);
    }
}
