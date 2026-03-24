<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class SummaryByCategoryExport implements WithMultipleSheets
{
    protected $summaryData;

    public function __construct(array $summaryData)
    {
        $this->summaryData = $summaryData;
    }

    public function sheets(): array
    {
        return [
            new SummaryCategorySheet('Display', $this->summaryData['display']->toArray()),
            new SummaryCategorySheet('Staging', $this->summaryData['staging']->toArray()),
            new SummaryCategorySheet('Cargo', $this->summaryData['cargo']->toArray()),
            new SummaryCategorySheet('Repair', $this->summaryData['repair']->toArray()),
            new SummarySkuSheet('SKU', $this->summaryData['sku']->toArray()),
        ];
    }
}