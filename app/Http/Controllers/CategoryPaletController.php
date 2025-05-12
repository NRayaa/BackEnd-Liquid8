<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\CategoryPalet;
use App\Http\Resources\ResponseResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class CategoryPaletController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = $request->input('q');

        $categories = CategoryPalet::query();

        if ($query) {
            $categories = $categories->where(function ($search) use ($query) {
                $search->where('name_category_palet', 'LIKE', '%' . $query . '%')
                    ->orWhere('discount_category_palet', 'LIKE', '%' . $query . '%')
                    ->orWhere('max_price_category_palet', 'LIKE', '%' . $query . '%');
            });
        }

        $categories = $categories->get();

        return new ResponseResource(true, "data category", $categories);
    }

    public function index2(Request $request)
    {
        $query = $request->input('q');

        $categories = CategoryPalet::query()
            ->select([
                'id',
                'name_category_palet as name_category',
                'discount_category_palet as category_palet',
                'max_price_category_palet as max_price_category',
            ]);

        if ($query) {
            $categories = $categories->where(function ($search) use ($query) {
                $search->where('name_category_palet', 'LIKE', '%' . $query . '%')
                    ->orWhere('discount_category_palet', 'LIKE', '%' . $query . '%')
                    ->orWhere('max_price_category_palet', 'LIKE', '%' . $query . '%');
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
            'name_category_palet' => 'required:unique:category_palets, name_category_palet',
            'discount_category_palet' => 'required',
            'max_price_category_palet' => 'required',
        ], [
            'name_category_palet.unique' => "nama category sudah ada",
        ]);

        if ($validation->fails()) {
            return response()->json(['error' => $validation->errors()], 422);
        }

        $category = CategoryPalet::create([
            'name_category_palet' => $request['name_category_palet'],
            'discount_category_palet' => $request['discount_category_palet'],
            'max_price_category_palet' => $request['max_price_category_palet'],
        ]);

        return new ResponseResource(true, "berhasil menambahkan category", $category);
    }

    /**
     * Display the specified resource.
     */
    public function show(CategoryPalet $categoryPalet)
    {
        return new ResponseResource(true, "data category", $categoryPalet);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(CategoryPalet $categoryPalet)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, CategoryPalet $category_palet)
    {
        $validation = Validator::make($request->all(), [
            'name_category_palet' => 'required',
            'discount_category_palet' => 'required',
            'max_price_category_palet' => 'required',
        ]);

        if ($validation->fails()) {
            return response()->json(['error' => $validation->errors(), 422]);
        }
        $category_palet->update($request->all());

        return new ResponseResource(true, "berhasil edit category", $category_palet);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(CategoryPalet $category_palet)
    {
        DB::beginTransaction();
        try {
            $delete = $category_palet->delete();

            if ($delete) {
                DB::commit();
                return new ResponseResource(true, "berhasil dihapus", []);
            } else {
                DB::rollBack();
                return (new ResponseResource(false, "data gagal dihapus", []))->response()->setStatusCode(500);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            return (new ResponseResource(false, "Terjadi kesalahan: " . $e->getMessage(), []))->response()->setStatusCode(500);
        }
    }
}
