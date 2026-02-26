<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Destination extends Model
{
    use HasFactory, SoftDeletes;
    protected $guarded = ['id'];

    protected $casts = [
        'is_olsera_integrated' => 'boolean',
        'olsera_secret_key' => 'encrypted',
        'olsera_token_expires_at' => 'datetime',
    ];

    protected $hidden = [
        'olsera_app_id',
        'olsera_secret_key',
        'olsera_access_token',
        'olsera_refresh_token',
        'olsera_token_expires_at',
    ];

    public function palets()
    {
        return $this->hasMany(Palet::class, 'destination_id');
    }

    public function isTokenExpired()
    {
        if (!$this->olsera_token_expires_at) return true;
        return now()->greaterThanOrEqualTo($this->olsera_token_expires_at->subMinutes(1));
    }

    public function destination()
    {
        return $this->belongsTo(Destination::class);
    }
}
