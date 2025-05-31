<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SoColor extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function summarySoColor()
    {
        return $this->belongsTo(SummarySoColor::class, 'summary_so_color_id');
    }
}
