<?php

namespace App\Exports;

use Carbon\Carbon;
use Illuminate\Http\Request;
use App\Models\ArchiveStorage;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use Maatwebsite\Excel\Concerns\WithCustomStartCell;

class ArchiveStorageExport implements WithEvents, WithStyles, ShouldAutoSize, WithTitle, WithCustomStartCell
{
    use Exportable;

    protected $year;
    protected $month;
    protected $indonesianMonths = [
        'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
        'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
    ];
    protected $types = ['type1', 'type2', 'color'];
    protected $displayNames = [
        'type1' => 'Inventories',
        'type2' => 'Stagings',
        'color' => 'Colors'
    ];

    public function __construct($year, $month = null)
    {
        $this->year = $year;
        $this->month = $month;
    }

    /**
     * @return string
     */
    public function title(): string
    {
        return 'Storage Report';
    }

    /**
     * @return string
     */
    public function startCell(): string
    {
        return 'A1';
    }

    
    public function styles(Worksheet $sheet)
    {
        // Judul utama
        $sheet->mergeCells('A1:M1');
        $sheet->setCellValue('A1', 'STORAGE REPORT - ' . $this->year);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $sheet->mergeCells('A4:C4');
        $sheet->setCellValue('A4', 'TOTAL ITEM');
        $sheet->getStyle('A4')->getFont()->setBold(true);

        $sheet->setCellValue('A5', 'Storage Type');
        $sheet->getStyle('A5')->getFont()->setBold(true);

        $col = 'B';
        $months = $this->month
            ? [Carbon::createFromFormat('m', $this->month)->translatedFormat('F')]
            : $this->indonesianMonths;

        foreach ($months as $month) {
            $sheet->setCellValue($col . '5', $month);
            $sheet->getStyle($col . '5')->getFont()->setBold(true);
            $col++;
        }

        $row = 6;
        foreach ($this->types as $type) {
            $sheet->setCellValue('A' . $row, $this->displayNames[$type]);
            $row++;
        }

        $sheet->mergeCells('A9:C9');
        $sheet->setCellValue('A9', 'TOTAL VALUE');
        $sheet->getStyle('A9')->getFont()->setBold(true);

        $sheet->setCellValue('A10', 'Storage Type');
        $sheet->getStyle('A10')->getFont()->setBold(true);

        $col = 'B';
        $months = $this->month
            ? [Carbon::createFromFormat('m', $this->month)->translatedFormat('F')]
            : $this->indonesianMonths;

        foreach ($months as $month) {
            $sheet->setCellValue($col . '10', $month);
            $sheet->getStyle($col . '10')->getFont()->setBold(true);
            $col++;
        }

        $row = 11;
        foreach ($this->types as $type) {
            $sheet->setCellValue('A' . $row, $this->displayNames[$type]);
            $row++;
        }

        $yellowFill = [
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => [
                    'rgb' => 'FFFF00',
                ],
            ]
        ];

        $sheet->getStyle('A5:M5')->applyFromArray($yellowFill);
        $sheet->getStyle('A10:M10')->applyFromArray($yellowFill);
        $sheet->getStyle('A5:A8')->applyFromArray($yellowFill);
        $sheet->getStyle('A10:A13')->applyFromArray($yellowFill);

        $borderStyle = [
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
        ];
        $sheet->getStyle('A1:M13')->applyFromArray($borderStyle);

        $alignmentCenter = [
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ]
        ];
        $sheet->getStyle('A1:M1')->applyFromArray($alignmentCenter);
        $sheet->getStyle('A4:M5')->applyFromArray($alignmentCenter);
        $sheet->getStyle('A9:M10')->applyFromArray($alignmentCenter);

        return [];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet;
                $this->populateData($sheet);
            }
        ];
    }

   
    protected function populateData($sheet)
    {
        $months = $this->month
            ? [Carbon::createFromFormat('m', $this->month)->translatedFormat('F')]
            : $this->indonesianMonths; 

        $row = 6;
        foreach ($this->types as $type) {
            $col = 'B'; 

            foreach ($months as $month) {
                $englishMonth = $this->convertToEnglishMonth($month);

                if ($type == 'color') {
                    $totalItem = ArchiveStorage::where('year', $this->year)
                        ->where('month', $englishMonth)
                        ->where('type', 'color')
                        ->sum('total_color');
                } else {
                    $totalItem = ArchiveStorage::where('year', $this->year)
                        ->where('month', $englishMonth)
                        ->where(function ($query) use ($type) {
                            if ($type == 'type1') {
                                $query->where('type', 'type1')->orWhereNull('type');
                            } else {
                                $query->where('type', $type);
                            }
                        })
                        ->sum('total_category');
                }

                $sheet->setCellValue($col . $row, $totalItem > 0 ? $totalItem : 0);
                
                $col++;
            }
            $row++; 
        }

        $row = 11;
        foreach ($this->types as $type) {
            $col = 'B';
            
            foreach ($months as $month) {
                $englishMonth = $this->convertToEnglishMonth($month);

                $totalValue = ArchiveStorage::where('year', $this->year)
                    ->where('month', $englishMonth)
                    ->where(function ($query) use ($type) {
                        if ($type == 'type1') {
                            $query->where('type', 'type1')->orWhereNull('type');
                        } else {
                            $query->where('type', $type);
                        }
                    })
                    ->sum('value_product');

                $sheet->setCellValue($col . $row, $totalValue > 0 ? $totalValue : 0);
                
                $col++;
            }
            $row++; 
        }
    }

    protected function convertToEnglishMonth($indonesianMonth)
    {
        $months = [
            'Januari' => 'January',
            'Februari' => 'February',
            'Maret' => 'March',
            'April' => 'April',
            'Mei' => 'May',
            'Juni' => 'June',
            'Juli' => 'July',
            'Agustus' => 'August',
            'September' => 'September',
            'Oktober' => 'October',
            'November' => 'November',
            'Desember' => 'December'
        ];

        return $months[$indonesianMonth] ?? $indonesianMonth;
    }
}