<?php

namespace App\Exports;

use Illuminate\Http\Request;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

// Import kelas ekspor yang diperlukan
use App\Exports\BulkyDocumentExport; // Cek apakah path dan nama sudah benar
use App\Exports\BagProductExport;    // Cek apakah path dan nama sudah benar

class MultiSheetExport implements WithMultipleSheets
{
    protected $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function sheets(): array
    {
        $sheets = [];

        // Sheet utama: BulkyDocument
        $sheets['Bulky Documents'] = new BulkyDocumentExport($this->request);

        // Sheet per BagProduct
        $bulkyDocumentId = $this->request->input('id');
        $bags = \App\Models\BagProducts::with('bulkySales')
            ->where('bulky_document_id', $bulkyDocumentId)
            ->get();
        $sheets['Bag Products'] = new BagProductExport($bags);

        return $sheets;
    }
}
