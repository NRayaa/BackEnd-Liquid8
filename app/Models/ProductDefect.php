<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductDefect extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function riwayatCheck(){
        return $this->belongsTo(RiwayatCheck::class, 'code_document', 'code_document');
    }
}
