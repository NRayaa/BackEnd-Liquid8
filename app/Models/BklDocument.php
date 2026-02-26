<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BklDocument extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function items()
    {
        return $this->hasMany(BklItem::class, 'bkl_document_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
