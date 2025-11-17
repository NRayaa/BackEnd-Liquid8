<?php

namespace App\Exports;

use App\Models\SummaryInbound;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithChunkReading;

class SummaryInboundExport implements FromQuery, WithHeadings, WithMapping, WithChunkReading
{
    use Exportable;

    protected $date;

    public function __construct($date = null)
    {
        $this->date = $date;
    } 

    public function query()
    {
        $summaryInbound = SummaryInbound::latest();
        
        // Filter by date if provided
        if ($this->date) {
            $summaryInbound = $summaryInbound->where('inbound_date', $this->date);
        }
        
        return $summaryInbound;
    }

    public function headings(): array
    {
        return [
            'ID',
            'Quantity',
            'New Price Product',
            'Old Price Product',
            'Display Price',
            'Inbound Date',
            'Created At',
        ];
    }

    public function map($summary): array
    {
        return [
            $summary->id,
            $summary->qty,
            $summary->new_price_product,
            $summary->old_price_product,
            $summary->display_price,
            $summary->inbound_date,
            $summary->created_at ? $summary->created_at->format('Y-m-d H:i:s') : null,
        ];
    }

    /**
     * Chunk size per read operation
     */
    public function chunkSize(): int
    {
        return 500;
    }
}
