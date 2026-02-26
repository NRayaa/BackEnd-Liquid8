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
        return $this->morphedByMany(New_product::class, 'productable', 'scrap_document_items')
            ->withTimestamps();
    }

    public function stagingProducts()
    {
        return $this->morphedByMany(StagingProduct::class, 'productable', 'scrap_document_items')
            ->withTimestamps();
    }

    public function migrateBulkyProducts()
    {
        return $this->morphedByMany(MigrateBulkyProduct::class, 'productable', 'scrap_document_items')
            ->withTimestamps();
    }
}
