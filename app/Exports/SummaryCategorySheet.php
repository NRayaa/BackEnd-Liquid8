<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class SummaryCategorySheet implements FromArray, WithHeadings, WithTitle, ShouldAutoSize
{
    protected $title;
    protected $data;

    public function __construct(string $title, array $data)
    {
        $this->title = $title;
        $this->data = $data;
    }

    public function array(): array
    {
        return $this->data;
    }

    public function headings(): array
    {
        return [
            'Kategori Produk',
            'Total Qty Bag',
            'Total Qty Product',
            'Total Old Price',
            'Total New Price'
        ];
    }

    public function title(): string
    {
        return $this->title;
    }
}