<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class StagingProduct extends Model
{
    use HasFactory;
    protected $guarded = ['id'];

    protected $appends = ['days_since_created'];

    public function getDaysSinceCreatedAttribute()
    {
        return Carbon::parse($this->new_date_in_product)->diffInDays(Carbon::now()) . ' Hari';
    }

    public function rack()
    {
        return $this->belongsTo(Rack::class, 'rack_id');
    }
}
