<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class New_product extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public function promos()
    {
        return $this->hasMany(Promo::class);
    }

    protected $appends = ['days_since_created'];

    public function getDaysSinceCreatedAttribute()
    {
        return Carbon::parse($this->new_date_in_product)->diffInDays(Carbon::now()) . ' Hari';
    }
}
