<?php

namespace App\Exports;

use Illuminate\Http\Request;
use App\Exports\ProductSheet;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class ProductByColor implements WithMultipleSheets
{
    use Exportable;

    protected $query;

    public function __construct(Request $request)
    {
        $this->query = $request->input('q'); // Ambil query input
    }

    public function sheets(): array
    {
        $sheets = [];

        // Ambil semua tag_product unik
        $tags = \App\Models\New_product::whereNotNull('new_tag_product') // Pastikan tidak null
            ->where('new_category_product', null)
            ->where(function ($query) {
                $query->whereRaw("JSON_UNQUOTE(JSON_EXTRACT(new_quality, '$.lolos')) = 'lolos'")
                    ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(JSON_UNQUOTE(new_quality), '$.lolos')) = 'lolos'");
            })
            ->where('new_status_product', 'display')
            ->distinct()
            ->pluck('new_tag_product'); // Ambil hanya kolom new_tag_product

        // Iterasi untuk membuat sheet
        foreach ($tags as $tag) {
            $sheets[] = new ProductSheet($tag);
        }

        return $sheets;
    }
}
