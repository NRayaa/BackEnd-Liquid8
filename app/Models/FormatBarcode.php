<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class FormatBarcode extends Model
{
    use HasFactory;
    protected $guarded = ['id'];
    protected $appends = ['username'];

    public function getUsernameAttribute()
    {
        return $this->users()->first()?->username ?? null; 
    }


    public function users()
    {
        return $this->hasMany(User::class, 'format_barcode_id');
    }
}
