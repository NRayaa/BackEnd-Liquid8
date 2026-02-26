<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OlseraProductMapping extends Model
{
    use HasFactory;

    protected $table = 'olsera_product_mappings';
    
    protected $guarded = ['id'];

    public function setWmsIdentifierAttribute($value)
    {
        $this->attributes['wms_identifier'] = strtolower($value);
    }
}