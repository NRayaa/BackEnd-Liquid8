<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class RiwayatCheck extends Model
{
    use HasFactory;
    protected $guarded = ['id'];

    public function user(){
        return $this->belongsTo(User::class);
    }

    public function notifications(){
        return $this->hasMany(Notification::class);
    }

    public function productDefect(){
        return $this->hasMany(productDefect::class);
    }
}
