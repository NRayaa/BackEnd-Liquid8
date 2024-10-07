<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class FilterBkl extends Model
{
    use HasFactory;
    protected $guarded = ['id'];

    protected $appends = ['days_since_created'];

    public function getDaysSinceCreatedAttribute()
    {
        return Carbon::parse($this->created_at)->diffInDays(Carbon::now()) . ' Hari';
    }
}
