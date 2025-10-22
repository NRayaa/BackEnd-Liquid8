<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\Category;
use App\Models\New_product;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class CategoryController extends Controller
{
    /**
     * Display a listing of the resource.
     */

    public function index(Request $request)
    {
        $query = $request->input('q');

        $categories = Category::query();

        if ($query) {
            $categories = $categories->where(function ($search) use ($query) {
                $search->where('name_category', 'LIKE', '%' . $query . '%')
                    ->orWhere('discount_category', 'LIKE', '%' . $query . '%')
                    ->orWhere('max_price_category', 'LIKE', '%' . $query . '%');
            });
        }

        $categories = $categories->get();

        return new ResponseResource(true, "data category", $categories);
    }

    /**
     * Show the form for creating a new resource.
     */

    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */

    public function store(Request $request)
    {
        $validation = Validator::make($request->all(), [
            'name_category' => 'required:unique:categories, name_category',
            'discount_category' => 'required',
            'max_price_category' => 'required',
        ], [
            'name_category.unique' => "nama category sudah ada",
        ]);

        if ($validation->fails()) {
            return response()->json(['error' => $validation->errors()], 422);
        }

        $category = Category::create([
            'name_category' => $request['name_category'],
            'discount_category' => $request['discount_category'],
            'max_price_category' => $request['max_price_category'],
        ]);

        return new ResponseResource(true, "berhasil menambahkan category", $category);
    }

    /**
     * Display the specified resource.
     */
    public function show(Category $category)
    {
        return new ResponseResource(true, "data category", $category);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Category $category)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Category $category)
    {
        $validation = Validator::make($request->all(), [
            'name_category' => 'required',
            'discount_category' => 'required',
            'max_price_category' => 'required',
        ]);

        if ($validation->fails()) {
            return response()->json(['error' => $validation->errors(), 422]);
        }
        $category->update($request->all());

        return new ResponseResource(true, "berhasil edit category", $category);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Category $category)
    {
        $category->delete();
        return new ResponseResource(true, "berhasil di hapus", $category);
    }

    public function exportCategory(Request $request)
    {
        // Meningkatkan batas waktu eksekusi dan memori
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        // Membuat spreadsheet baru
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        // Menentukan headers berdasarkan nama kolom di tabel new_products
        $headers = [
            'ID', 'Nama Category', 'Discount', 'Max Price Discount',
            'Created At', 'Updated At',
        ];

        // Menuliskan headers ke sheet
        $columnIndex = 1;
        foreach ($headers as $header) {
            $sheet->setCellValueByColumnAndRow($columnIndex, 1, $header);
            $columnIndex++;
        }

        // Variabel untuk melacak baris
        $rowIndex = 2;

        // Mengambil data dalam batch
        Category::chunk(1000, function ($categories) use ($sheet, &$rowIndex) {
            foreach ($categories as $category) {
                $sheet->setCellValueByColumnAndRow(1, $rowIndex, $category->id);
                $sheet->setCellValueByColumnAndRow(2, $rowIndex, $category->name_category);
                $sheet->setCellValueByColumnAndRow(3, $rowIndex, $category->discount_category);
                $sheet->setCellValueByColumnAndRow(4, $rowIndex, $category->max_price_category);
                $sheet->setCellValueByColumnAndRow(5, $rowIndex, $category->created_at);
                $sheet->setCellValueByColumnAndRow(6, $rowIndex, $category->updated_at);
                $rowIndex++;
            }
        });

        // Menyimpan file Excel
        $writer = new Xlsx($spreadsheet);
        $fileName = 'categories.xlsx';
        $publicPath = 'exports';
        $filePath = public_path($publicPath) . '/' . $fileName;

        // Membuat direktori exports jika belum ada
        if (!file_exists(public_path($publicPath))) {
            mkdir(public_path($publicPath), 0777, true);
        }

        $writer->save($filePath);

        // Mengembalikan URL untuk mengunduh file
        $downloadUrl = url($publicPath . '/' . $fileName);

        return new ResponseResource(true, "file diunduh", $downloadUrl);
    }

    //buat function untuk mengechek kolom name_category yang ada di tabel new_products yang kolomnya itu bernama new_Category_products
    //check hasil kolom new_price_product harganya sudah sesuai kah dengan discount yang ada di kolom discount_category di tabel categories jika dari perhitungan old_price_product

}
