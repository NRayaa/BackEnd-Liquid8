<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ScrapDocument extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function newProducts()
    {
        return $this->hasMany(New_product::class, 'scrap_document_id');
    }

    public function stagingProducts()
    {
        return $this->hasMany(StagingProduct::class, 'scrap_document_id');
    }
}
