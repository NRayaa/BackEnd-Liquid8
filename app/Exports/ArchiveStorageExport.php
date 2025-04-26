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
        'Januari',
        'Februari',
        'Maret',
        'April',
        'Mei',
        'Juni',
        'Juli',
        'Agustus',
        'September',
        'Oktober',
        'November',
        'Desember'
    ];
    protected $types = ['type1', 'type2', 'color'];
    protected $displayNames = [
        'type1' => 'Type1',
        'type2' => 'Type2',
        'color' => 'Color'
    ];

    public function __construct(Request $request)
    {
        $this->year = $request->query('year');
        $this->month = $request->query('month'); // Tetap null jika tidak dikirim
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

    /**
     * Apply styles to the sheet
     */
    public function styles(Worksheet $sheet)
    {
        // Judul utama
        $sheet->mergeCells('A1:M1');
        $sheet->setCellValue('A1', 'STORAGE REPORT - ' . $this->year);
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        // Style untuk header TOTAL ITEM
        $sheet->mergeCells('A4:C4');
        $sheet->setCellValue('A4', 'TOTAL ITEM');
        $sheet->getStyle('A4')->getFont()->setBold(true);

        // Style untuk Storage Type dan bulan-bulan di baris 5
        $sheet->setCellValue('A5', 'Storage Type');
        $sheet->getStyle('A5')->getFont()->setBold(true);

        // Isi bulan-bulan di header TOTAL ITEM
        $col = 'B';
        $months = $this->month
            ? [Carbon::createFromFormat('m', $this->month)->translatedFormat('F')]
            : $this->indonesianMonths;

        foreach ($months as $month) {
            $sheet->setCellValue($col . '5', $month);
            $sheet->getStyle($col . '5')->getFont()->setBold(true);
            $col++;
        }

        // Isi tipe storage
        $row = 6;
        foreach ($this->types as $type) {
            $sheet->setCellValue('A' . $row, $this->displayNames[$type]);
            $row++;
        }

        // Style untuk header TOTAL VALUE
        $sheet->mergeCells('A9:C9');
        $sheet->setCellValue('A9', 'TOTAL VALUE');
        $sheet->getStyle('A9')->getFont()->setBold(true);

        // Style untuk Storage Type dan bulan-bulan di baris 10
        $sheet->setCellValue('A10', 'Storage Type');
        $sheet->getStyle('A10')->getFont()->setBold(true);

        // Isi bulan-bulan di header TOTAL VALUE
        $col = 'B';
        $months = $this->month
            ? [Carbon::createFromFormat('m', $this->month)->translatedFormat('F')]
            : $this->indonesianMonths;

        foreach ($months as $month) {
            $sheet->setCellValue($col . '10', $month);
            $sheet->getStyle($col . '10')->getFont()->setBold(true);
            $col++;
        }

        // Isi tipe storage untuk TOTAL VALUE
        $row = 11;
        foreach ($this->types as $type) {
            $sheet->setCellValue('A' . $row, $this->displayNames[$type]);
            $row++;
        }

        // Warna kuning untuk header bulan
        $yellowFill = [
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => [
                    'rgb' => 'FFFF00',
                ],
            ]
        ];

        // Terapkan warna kuning ke header
        $sheet->getStyle('A5:M5')->applyFromArray($yellowFill);
        $sheet->getStyle('A10:M10')->applyFromArray($yellowFill);
        $sheet->getStyle('A5:A8')->applyFromArray($yellowFill);
        $sheet->getStyle('A10:A13')->applyFromArray($yellowFill);

        // Border untuk seluruh area
        $borderStyle = [
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
        ];
        $sheet->getStyle('A1:M13')->applyFromArray($borderStyle);

        // Set alignment tengah untuk semua header
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

    /**
     * Register events
     */
    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet;
                $this->populateData($sheet);
            }
        ];
    }

    /**
     * Populate data into worksheet
     */
    protected function populateData($sheet)
    {
        // Isi data TOTAL ITEM
        $row = 6;
        foreach ($this->types as $type) {
            $col = 'B';
            $months = $this->month
                ? [Carbon::createFromFormat('m', $this->month)->translatedFormat('F')]
                : $this->indonesianMonths; // Semua bulan jika $this->month null



            foreach ($months as $month) {
                // Konversi nama bulan Indonesia ke bahasa Inggris untuk query
                $englishMonth = $this->convertToEnglishMonth($month);

                // Query untuk total item
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

                if ($type == 'color') {
                    // Khusus untuk color, gunakan total_color
                    $totalItem = ArchiveStorage::where('year', $this->year)
                        ->where('month', $englishMonth)
                        ->where('type', 'color')
                        ->sum('total_color');
                }

                if ($totalItem > 0) {
                    $sheet->setCellValue($col . $row, $totalItem);
                }

                $col++;
            }
            $row++;
        }

        // Isi data TOTAL VALUE
        $row = 11;
        foreach ($this->types as $type) {
            $col = 'B';
            foreach ($months as $month) {
                // Konversi nama bulan Indonesia ke bahasa Inggris untuk query
                $englishMonth = $this->convertToEnglishMonth($month);

                // Query untuk total value
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

                if ($totalValue > 0) {
                    // Format sebagai angka
                    $sheet->setCellValue($col . $row, $totalValue);
                }

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
