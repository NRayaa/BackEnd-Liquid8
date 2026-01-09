<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NonDocument extends Model
{
    protected $guarded = ['id'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function newProducts()
    {
        return $this->morphedByMany(New_product::class, 'productable', 'non_document_items');
    }

    public function stagingProducts()
    {
        return $this->morphedByMany(StagingProduct::class, 'productable', 'non_document_items');
    }

    public function migrateBulkyProducts()
    {
        return $this->morphedByMany(MigrateBulkyProduct::class, 'productable', 'non_document_items');
    }
}