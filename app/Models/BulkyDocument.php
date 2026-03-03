<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class  BulkyDocument extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    public const SALE_NOT = 'not sale';
    public const SALE_READY = 'ready';
    public const SALE = 'sale';

    public const TYPE_OFFLINE  = 'cargo offline';
    public const TYPE_ONLINE   = 'cargo online';

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($bulkyDocument) {
            $currentMonth = now()->format('m');
            $currentYear = now()->format('Y');

            $lastDocument = self::whereMonth('created_at', $currentMonth)
                ->whereYear('created_at', $currentYear)
                ->orderByDesc('id') 
                ->first();

            $lastSequence = $lastDocument ? (int) substr($lastDocument->code_document_bulky, 0, 3) : 0;
            $sequence = str_pad($lastSequence + 1, 3, '0', STR_PAD_LEFT);
            $bulkyDocument->code_document_bulky = "{$sequence}/{$currentMonth}/{$currentYear}";
        });
    }

    public function bulkySales()
    {
        return $this->hasMany(BulkySale::class);
    }

    public function bagProducts()
    {
        return $this->hasMany(BagProducts::class, 'bulky_document_id');
    }

    public function buyer(){
        return $this->belongsTo(Buyer::class, 'buyer_id');
    }

    public function user(){
        return $this->belongsTo(User::class, 'user_id');
    }
}
