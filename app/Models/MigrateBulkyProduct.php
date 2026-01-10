<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MigrateBulkyProduct extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

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

    public function abnormalDocuments()
    {
        return $this->morphToMany(AbnormalDocument::class, 'productable', 'abnormal_document_items');
    }

    public function migrateBulky()
    {
        return $this->belongsTo(MigrateBulky::class, 'migrate_bulky_id');
    }
}
