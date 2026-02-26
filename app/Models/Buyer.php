<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class Buyer extends Model
{
    use HasFactory;

    protected $guarded = ['id'];


    public function buyerPoint()
    {
        return $this->hasMany(BuyerPoint::class);
    }

    public function buyerLoyalty()
    {
        return $this->hasOne(BuyerLoyalty::class);
    }

    public function buyerLoyaltyHistories()
    {
        return $this->hasMany(BuyerLoyaltyHistory::class);
    }

    public function bulkyDocuments()
    {
        return $this->hasMany(BulkyDocument::class, 'buyer_id');
    }

    public function sales()
    {
        return $this->hasMany(SaleDocument::class, 'buyer_id_document_sale', 'id');
    }
}
