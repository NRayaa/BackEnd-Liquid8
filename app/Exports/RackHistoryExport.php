<?php

namespace App\Exports;

use App\Models\RackHistory;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Border;

class RackHistoryExport implements FromArray, ShouldAutoSize, WithStyles
{
    protected $source;
    protected $boldRows = [];
    protected $tableRanges = [];

    public function __construct($source)
    {
        $this->source = $source;
    }

    public function array(): array
    {
        $latestHistoryIds = RackHistory::select(DB::raw('MAX(id) as id'))
            ->groupBy('barcode');

        $validInsertions = RackHistory::with(['user:id,name', 'rack:id,name'])
            ->whereIn('id', $latestHistoryIds)
            ->where('action', 'IN')
            ->where('source', $this->source)
            ->select(
                'rack_id',
                'user_id',
                DB::raw('COUNT(*) as total_inserted')
            )
            ->groupBy('rack_id', 'user_id')
            ->get();

        $formattedData = [];
        foreach ($validInsertions as $row) {
            $rackName = $row->rack ? $row->rack->name : 'Rak Telah Dihapus';
            $userName = $row->user ? $row->user->name : 'Unknown';

            if (!isset($formattedData[$rackName])) {
                $formattedData[$rackName] = [
                    'rack_name'     => $rackName,
                    'total_in_rack' => 0,
                    'users'         => []
                ];
            }

            $formattedData[$rackName]['users'][] = [
                'user_name'      => $userName,
                'total_inserted' => $row->total_inserted
            ];
            $formattedData[$rackName]['total_in_rack'] += $row->total_inserted;
        }

        $exportArray = [];
        $currentRowIndex = 1;

        foreach ($formattedData as $rack) {
            $exportArray[] = ['Nama Rack : ' . $rack['rack_name'], ''];
            $this->boldRows[] = $currentRowIndex;
            $currentRowIndex++;

            $tableStartRow = $currentRowIndex;

            $exportArray[] = ['Nama', 'Total Scan'];
            $this->boldRows[] = $currentRowIndex;
            $currentRowIndex++;

            foreach ($rack['users'] as $user) {
                $exportArray[] = [$user['user_name'], $user['total_inserted']];
                $currentRowIndex++;
            }

            $exportArray[] = ['TOTAL', $rack['total_in_rack']];
            $this->boldRows[] = $currentRowIndex;

            $tableEndRow = $currentRowIndex;

            $this->tableRanges[] = 'A' . $tableStartRow . ':B' . $tableEndRow;

            $currentRowIndex++; 

            $exportArray[] = ['', ''];
            $currentRowIndex++;
        }

        return $exportArray;
    }

    public function styles(Worksheet $sheet)
    {
        $styles = [];

        foreach ($this->boldRows as $rowNumber) {
            $styles[$rowNumber] = ['font' => ['bold' => true]];
        }

        $borderStyle = [
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['argb' => '00000000'], 
                ],
            ],
        ];

        foreach ($this->tableRanges as $range) {
            $sheet->getStyle($range)->applyFromArray($borderStyle);
        }

        return $styles;
    }
}
