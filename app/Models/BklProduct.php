<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BklProduct extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $casts = [
        'new_quality' => 'array',
        'actual_new_quality' => 'array',
        'new_date_in_product' => 'date',
    ];
}
