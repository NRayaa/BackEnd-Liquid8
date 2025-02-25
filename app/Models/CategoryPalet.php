<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CategoryPalet extends Model
{
    use HasFactory;
    protected $guarded = ['id'];

    public function palets(){
        return $this->hasMany(Palet::class, 'category_palet_id');
    }
}
