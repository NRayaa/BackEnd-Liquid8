<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithTitle;

class StorageReportExport implements WithMultipleSheets
{
    use Exportable;

    protected $inventory;
    protected $staging;
    protected $color;
    protected $summary;

    // Konstruktor untuk menerima data produk
    public function __construct($inventory, $staging, $color, $summary)
    {
        $this->inventory = $inventory;
        $this->staging = $staging;
        $this->color = $color;
        $this->summary = $summary;
    }

    // Mengembalikan array dari sheets
    public function sheets(): array
    {
        $sheets = [
            new InventoryStagingSheet($this->inventory, 'Inventories', ["Category Name", "Total Product", "Value Product"]),
            new InventoryStagingSheet($this->staging, 'Stagings', ["Category Name", "Total Product", "Value Product"]),
            new ColorSheet($this->color, 'Colors', ["Color Name", "Total Product", "Value Product"]),
            new SummarySheet($this->summary, 'Summary', [
                "total_all_product",
                "total_all_price",
                "total_product_inventory",
                "price_inventory",
                "total_product_staging",
                "price_staging",
                "total_product_color",
                "price_color"
            ]),
        ];

        return $sheets;
    }
}

// Kelas untuk sheet Inventory dan Staging
class InventoryStagingSheet implements FromCollection, WithHeadings, WithTitle
{
    protected $data;
    protected $title;
    protected $headings;

    public function __construct($data, $title, $headings)
    {
        $this->data = $data;
        $this->title = $title;
        $this->headings = $headings;
    }

    public function collection()
    {
        return collect($this->data);
    }

    public function headings(): array
    {
        return $this->headings;
    }

    // Implementasi WithTitle untuk mengatur nama sheet
    public function title(): string
    {
        return $this->title;
    }
}

// Kelas untuk sheet Color
class ColorSheet implements FromCollection, WithHeadings, WithTitle
{
    protected $data;
    protected $title;
    protected $headings;

    public function __construct($data, $title, $headings)
    {
        $this->data = $data;
        $this->title = $title;
        $this->headings = $headings;
    }

    public function collection()
    {
        return collect($this->data);
    }

    public function headings(): array
    {
        return $this->headings;
    }

    // Implementasi WithTitle untuk mengatur nama sheet
    public function title(): string
    {
        return $this->title;
    }
}
// Kelas untuk sheet Color
class SummarySheet implements FromCollection, WithHeadings, WithTitle
{
    protected $data;
    protected $title;
    protected $headings;

    public function __construct($data, $title, $headings)
    {
        $this->data = $data;
        $this->title = $title;
        $this->headings = $headings;
    }

    public function collection()
    {
        return collect($this->data);
    }

    public function headings(): array
    {
        return $this->headings;
    }

    // Implementasi WithTitle untuk mengatur nama sheet
    public function title(): string
    {
        return $this->title;
    }
}
