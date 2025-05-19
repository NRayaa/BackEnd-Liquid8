<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SaleDocument extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $appends = ['grand_total'];

    public function getGrandTotalAttribute()
    {
        return $this->cardbox_total_price + $this->total_price_document_sale;
    }

    public function sales()
    {
        return $this->hasMany(Sale::class, 'code_document_sale', 'code_document_sale');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function buyer()
    {
        return $this->belongsTo(Buyer::class, 'buyer_id_document_sale');
    }
}
