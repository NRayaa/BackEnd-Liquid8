<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ColorRack extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function colorRackProducts()
    {
        return $this->hasMany(ColorRackProduct::class, 'color_rack_id');
    }

    public function getTotalOldPriceAttribute()
    {
        return $this->colorRackProducts->sum(function ($item) {
            if ($item->bundle_id) {
                return $item->bundle->total_price_bundle ?? 0; 
            }
            return $item->newProduct->old_price_product ?? $item->newProduct->old_price_product ?? 0;
        });
    }

    public function getTotalNewPriceAttribute()
    {
        return $this->colorRackProducts->sum(function ($item) {
            if ($item->bundle_id) {
                return $item->bundle->total_price_custom_bundle ?? 0;
            }
            return $item->newProduct->new_price_product ?? $item->newProduct->new_price_product ?? 0; 
        });
    }
}