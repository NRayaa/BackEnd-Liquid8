<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Rack extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function stagingProducts()
    {
        return $this->hasMany(StagingProduct::class, 'rack_id');
    }

    public function newProducts()
    {
        return $this->hasMany(New_product::class, 'rack_id');
    }
}
