<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BuyerLoyalty extends Model
{
    use HasFactory;
    protected $guarded = ['id'];
    
    public function rank()
    {
        return $this->belongsTo(LoyaltyRank::class, 'loyalty_rank_id', 'id');
    }
    
    public function buyer()
    {
        return $this->belongsTo(Buyer::class);
    }
}
