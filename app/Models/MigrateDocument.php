<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class MigrateDocument extends Model
{
    use HasFactory, SoftDeletes; 

    protected $guarded = ['id'];

    public function migrates()
    {
        return $this->hasMany(Migrate::class, 'code_document_migrate', 'code_document_migrate');
    }

    public function user(){
        return $this->belongsTo(User::class);
    }
    
}