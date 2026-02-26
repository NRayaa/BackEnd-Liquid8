<?php

namespace App\Http\Resources;

use App\Models\LoyaltyRank;
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
        $currentTransaction = $this->buyerLoyalty->transaction_count ?? 0;
        if ($currentTransaction <= 1) {
            $nextRank = LoyaltyRank::where('min_transactions', $currentTransaction)
                ->first();
        } else {
            $nextRank = LoyaltyRank::where('min_transactions', '>', $currentTransaction)
                ->orderBy('min_transactions', 'asc')
                ->first();
        }

        return [
            'id' => $this->id,
            'name_buyer' => $this->name_buyer,
            'phone_buyer' => $this->phone_buyer,
            'address_buyer' => $this->address_buyer,
            'type_buyer' => $this->type_buyer,
            'amount_transaction_buyer' => $this->amount_transaction_buyer,
            'amount_purchase_buyer' => $this->amount_purchase_buyer,
            'avg_purchase_buyer' => $this->avg_purchase_buyer,
            'email' => $this->email,
            'point_buyer' => $this->point_buyer,
            'monthly_point' => (int) ($this->monthly_point ?? 0),
            'monthly_rank_position' => $this->calculated_monthly_rank ?? '-',
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'rank' => optional(optional($this->buyerLoyalty)->rank)->rank ?? null,
            'next_rank' => $nextRank ? $nextRank->rank : null,
            'transaction_next' => $nextRank ? max(1, $nextRank->min_transactions - $currentTransaction) : 0,
            'percentage_discount' => optional(optional($this->buyerLoyalty)->rank)->percentage_discount ?? 0,
            'current_transaction' => $currentTransaction,

        ];
    }
}
