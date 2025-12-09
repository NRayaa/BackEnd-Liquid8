<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BklItem extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function document()
    {
        return $this->belongsTo(BklDocument::class, 'bkl_document_id');
    }

    public function colorTag()
    {
        return $this->belongsTo(Color_tag::class, 'color_tag_id');
    }
}
