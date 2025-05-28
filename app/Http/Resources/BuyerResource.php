<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BuyerResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name_buyer' => $this->name_buyer,
            'phone_buyer' => $this->phone_buyer,
            'address_buyer' => $this->address_buyer,
            'type_buyer' => $this->type_buyer,
            'amount_transaction_buyer' => $this->amount_transaction_buyer,
            'amount_purchase_buyer' => $this->amount_purchase_buyer,
            'avg_purchase_buyer' => $this->avg_purchase_buyer,
            'point_buyer' => $this->point_buyer,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'rank' => optional(optional($this->buyerLoyalty)->rank)->rank ?? null,
            'percentage_discount' => optional(optional($this->buyerLoyalty)->rank)->percentage_discount ?? 0,
        ];
    }
}
