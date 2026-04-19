<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Migrate extends Model
{
    protected $guarded = ['id'];
    use HasFactory;

    public function migrateDocument()
    {
        return $this->belongsTo(MigrateDocument::class, 'code_document_migrate', 'code_document_migrate');
    }

    public function colorRack()
    {
        return $this->belongsTo(ColorRack::class, 'color_rack_id');
    }
}
