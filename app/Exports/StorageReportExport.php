<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;

class StorageReportExport implements FromCollection, WithHeadings
{
    protected $listStorageReport;

    // Konstruktor untuk menerima data produk
    public function __construct($listStorageReport)
    { 
        $this->listStorageReport = $listStorageReport;
    }

    // Mengembalikan collection data yang akan diekspor
    public function collection()
    {
        return collect($this->listStorageReport);
    }

    public function headings(): array
    {

        return ["Category Name", "Total Product", "Value Product"];
    }
}
