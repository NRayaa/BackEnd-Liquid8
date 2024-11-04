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
          
            'hexa_code_color' => 'required|unique:color_tags,hexa_code_color',
            'name_color' => 'required|unique:color_tags,name_color',
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
    public function show(ColorTag2 $color_tag)
    {
        return new ResponseResource(true, "data tag warna", $color_tag);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(ColorTag2 $color_tag)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ColorTag2 $color_tag)
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
        $color_tag->update($request->all());
        return new ResponseResource(true, "berhasil mengedit tag warna", $color_tag);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ColorTag2 $color_tag)
    {
        $color_tag->delete();
        return new ResponseResource(true, "berhasil menghapus tag warna", $color_tag);

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
