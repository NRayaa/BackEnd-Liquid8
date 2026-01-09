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

    public function scrapDocuments()
    {
        return $this->morphToMany(ScrapDocument::class, 'productable', 'scrap_document_items')
            ->withTimestamps();
    }

    public function damagedDocuments()
    {
        return $this->morphToMany(DamagedDocument::class, 'productable', 'damaged_document_items');
    }

    public function nonDocuments()
    {
        return $this->morphToMany(NonDocument::class, 'productable', 'non_document_items');
    }
}
