<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BklScannedProduct extends Model
{
    protected $guarded = ['id'];

    public function newProduct()
    {
        return $this->belongsTo(New_product::class, 'new_product_id');
    }

    public function bundle()
    {
        return $this->belongsTo(Bundle::class, 'bundle_id');
    }

    public function bklDocument()
    {
        return $this->belongsTo(BklDocument::class, 'bkl_document_id');
    }
}
