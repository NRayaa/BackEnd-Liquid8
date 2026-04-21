<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class SummarySkuSheet implements FromArray, WithHeadings, WithTitle, ShouldAutoSize
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
            'Code Document',
            'Qty Product',
            'Old Price'
        ];
    }

    public function title(): string
    {
        return $this->title;
    }
}