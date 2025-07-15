<?php 
namespace App\Exports;

use App\Models\Category;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class TemplateBulkingCategory implements WithMultipleSheets
{
    /**
     * @return array
     */
    public function sheets(): array
    {
        return [
            new EmptyTemplateSheet(),
            new CategorySheet(),
        ];
    }
}

class EmptyTemplateSheet implements FromCollection, WithHeadings, WithTitle
{
    public function collection()
    {
        // Return a collection with one empty row
        return collect([
            ['', '', '', '', '', '', '', ''], // Empty row corresponding to the headers
        ]);
    }

    public function headings(): array
    {
        return [
            'Barcode',
            'Description',
            'Category',
            'Qty',
            'Unit Price',
            'Bast',
            'Discount',
            'Price After Discount',
        ];
    }

    public function title(): string
    {
        return 'Template'; // Title for the first sheet
    }
}

class CategorySheet implements FromCollection, WithHeadings, WithTitle
{
    public function collection()
    {
        // Fetch data for the Category sheet from the model
        return Category::select('id', 'name_category', 'discount_category')->get();
    }

    public function headings(): array
    {
        return [
            'Id',
            'Category Name',
            'Discount Category',
        ];
    }

    public function title(): string
    {
        return 'Categories'; // Title for the second sheet
    }
}
?>