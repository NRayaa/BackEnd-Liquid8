<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class LoyaltyRank extends Model
{
    use HasFactory;
    protected $fillable = [
        'rank',
        'min_transactions',
        'min_amount_transaction',
        'percentage_discount',
        'expired_weeks',
    ];

    public function buyerLoyalty()
    {
        return $this->hasMany(BuyerLoyalty::class);
    }
    
    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($model) {
            $protectedIds = [1, 2, 3, 4, 5];

            if (in_array($model->id, $protectedIds)) {
                throw new \Exception("Data dengan ID {$model->id} tidak boleh dihapus.");
            }
        });
    }
}
