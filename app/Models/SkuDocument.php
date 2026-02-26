<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SkuDocument extends Model
{
    protected $guarded = ['id'];

    public function user_scan_webs()
    {
        return $this->hasMany(UserScanWeb::class);
    }
}
