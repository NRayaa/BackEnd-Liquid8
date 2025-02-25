<?php

namespace App\Models;

use App\Models\PaletImage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Palet extends Model
{
    use HasFactory;
    protected $guarded = ['id'];

    public function paletProducts()
    {
        return $this->hasMany(PaletProduct::class);
    }
    public function paletImages()
    {
        return $this->hasMany(PaletImage::class, 'palet_id');
    }
    public function paletBrands()
    {
        return $this->hasMany(PaletBrand::class, 'palet_id');
    }
    public function category()
    {
        return $this->belongsTo(Category::class, 'category_id');
    }
    public function product_status()
    {
        return $this->belongsTo(ProductStatus::class, 'product_status_id');
    }
    public function destination()
    {
        return $this->belongsTo(Destination::class, 'destination_id');
    }
    public function product_condition()
    {
        return $this->belongsTo(ProductCondition::class, 'product_condition_id');
    }
    public function category_palet(){
        return $this->belongsTo(CategoryPalet::class, 'category_palet_id');
    }


}
