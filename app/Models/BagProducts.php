<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BagProducts extends Model
{
    use HasFactory;

    protected $guarded = ['id'];
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function bulkyDocument()
    {
        return $this->belongsTo(BulkyDocument::class);
    }

    public function bulkySales()
    {
        return $this->hasMany(BulkySale::class, 'bag_product_id');
    }

}
