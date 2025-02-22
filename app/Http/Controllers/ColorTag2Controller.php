<?php

namespace App\Http\Controllers;

use App\Models\ColorTag2;
use Illuminate\Http\Request;
use App\Http\Resources\ResponseResource;
use Illuminate\Support\Facades\Validator;

class ColorTag2Controller extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = $request->input('q');
        $tags = ColorTag2::latest();
        if($query){
           $tags = $tags->where('name_color', 'LIKE', '%' . $query . '%');
        }

        $tags = $tags->get();

        return new ResponseResource(true, "list tag warna", $tags);
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
        $validator = Validator::make($request->all(), [
          
            'hexa_code_color' => 'required|unique:color_tag2s,hexa_code_color',
            'name_color' => 'required|unique:color_tag2s,name_color',
            'min_price_color' => 'required',
            'max_price_color' => 'required',
            'fixed_price_color' => 'required'
        ]);

        if($validator->fails()){
            return response()->json(['errors' => $validator->errors()], 422);
        };

        $colorTag = ColorTag2::create([
            'hexa_code_color' => $request->hexa_code_color,
            'name_color' => $request->name_color,
            'min_price_color' => $request->min_price_color,
            'max_price_color' => $request->max_price_color,
            'fixed_price_color' => $request->fixed_price_color

        ]);

        return new ResponseResource(true, "berhasil menambah tag warna", $colorTag);
    }

    /**
     * Display the specified resource.
     */
    public function show(ColorTag2 $color_tags2)
    {
        return new ResponseResource(true, "data tag warna", $color_tags2);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(ColorTag2 $color_tags2)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ColorTag2 $color_tags2)
    {
        $validator = Validator::make($request->all(), [
            'hexa_code_color' => 'required',
            'name_color' => 'required',
            'min_price_color' => 'required',
            'max_price_color' => 'required',
            'fixed_price_color' => 'required'
        ]);

        if($validator->fails()){
            return response()->json(['errors' => $validator->errors()], 422);
        };
        $color_tags2->update($request->all());
        return new ResponseResource(true, "berhasil mengedit tag warna", $color_tags2);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ColorTag2 $color_tags2)
    {
        try {
            $color_tags2->delete();
    
            // Kembalikan respons berhasil
            return new ResponseResource(true, "Berhasil menghapus tag warna 2", []);
        } catch (\Exception $e) {
            // Tangani error jika terjadi
            return (new ResponseResource(false, "Gagal menghapus tag warna 2: " . $e->getMessage(), []))->setStatusCode(500);
        }
    }

    public function getByNameColor2(Request $request) {
        $nameColor = $request->input('q');
        $tagColor = ColorTag2::where('name_color', $nameColor)->first();
    
        if($tagColor){
            return new ResponseResource(true, "List color tag", $tagColor);
        } else {
            return new ResponseResource(false, "Data kosong", null);
        }
    }
}
