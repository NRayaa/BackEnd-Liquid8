<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Bundle extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function product_bundles()
    {
        return $this->hasMany(Product_Bundle::class);
    }

    public function rack()
    {
        return $this->belongsTo(Rack::class, 'rack_id', 'id');
    }
}
