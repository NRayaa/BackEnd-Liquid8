<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SummarySoColor extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function soColors()
    {
        return $this->hasMany(SoColor::class, 'summary_so_color_id');
    }
}
