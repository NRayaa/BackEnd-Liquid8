<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Buyer;
use App\Models\LoyaltyRank;
use App\Models\BuyerLoyalty;
use App\Http\Resources\ResponseResource;
use App\Models\BuyerLoyaltyHistory;
use Illuminate\Support\Facades\Log;

class LoyaltyService
{
    public static function processLoyalty($buyer_id, $totalDisplayPrice)
    {
        if ($totalDisplayPrice >= 5000000) {
            $buyerLoyalty = BuyerLoyalty::where('buyer_id', $buyer_id)->first();

            if (!$buyerLoyalty) {
                $rankBuyer = LoyaltyRank::where('rank', 'New Buyer')->orWhere('rank', 'new Buyer')->orWhere('min_transactions', 0)->first();
               
                $buyerLoyalty = BuyerLoyalty::create([
                    'buyer_id' => $buyer_id,
                    'loyalty_rank_id' => $rankBuyer->id,
                    'transaction_count' => 1,
                    'last_upgrade_date' => Carbon::now('Asia/Jakarta'),
                    'expire_date' => null,
                ]);
                
                BuyerLoyaltyHistory::create([
                    'buyer_id' => $buyer_id,
                    'previous_rank' => null,
                    'current_rank' => $rankBuyer->rank,
                    'note' => 'Rank upgraded to ' . $rankBuyer->rank,
                    'created_at' => Carbon::now('Asia/Jakarta'),
                    'updated_at' => Carbon::now('Asia/Jakarta'),
                ]);
                return $rankBuyer->percentage_discount;
            }

            $currentTransaction = $buyerLoyalty->transaction_count + 1;
            $lowerRank = LoyaltyRank::where('min_transactions', '<=', $currentTransaction)
                ->orderBy('min_transactions', 'desc')
                ->first();
            
            if($currentTransaction == 2){
                 $buyerLoyalty->update([
                    'loyalty_rank_id' => $lowerRank->id,
                    'transaction_count' =>$buyerLoyalty->transaction_count + 1,
                    'last_upgrade_date' => Carbon::now('Asia/Jakarta'),
                    'expire_date' => Carbon::now('Asia/Jakarta')->addWeeks($lowerRank->expired_weeks)->endOfDay(),
                ]);
                BuyerLoyaltyHistory::create([
                    'buyer_id' => $buyer_id,
                    'previous_rank' => $buyerLoyalty->rank->rank,
                    'current_rank' => $lowerRank->rank,
                    'note' => 'Rank upgraded to ' . $lowerRank->rank,
                    'created_at' => Carbon::now('Asia/Jakarta'),
                    'updated_at' => Carbon::now('Asia/Jakarta'),
                ]);
                return $buyerLoyalty->rank->percentage_discount;
            }
    
            if ($lowerRank && $lowerRank->min_transactions < $currentTransaction) {
                $buyerLoyalty->update([
                    'transaction_count' => $buyerLoyalty->transaction_count + 1,
                ]);
                return $lowerRank->percentage_discount;
            } else {
                $buyerLoyalty->update([
                    'loyalty_rank_id' => $lowerRank->id,
                    'transaction_count' =>$buyerLoyalty->transaction_count + 1,
                    'last_upgrade_date' => Carbon::now('Asia/Jakarta'),
                    'expire_date' => Carbon::now('Asia/Jakarta')->addWeeks($lowerRank->expired_weeks)->endOfDay(),
                ]);
                BuyerLoyaltyHistory::create([
                    'buyer_id' => $buyer_id,
                    'previous_rank' => $buyerLoyalty->rank->rank,
                    'current_rank' => $lowerRank->rank,
                    'note' => 'Rank upgraded to ' . $lowerRank->rank,
                    'created_at' => Carbon::now('Asia/Jakarta'),
                    'updated_at' => Carbon::now('Asia/Jakarta'),
                ]);
                return $buyerLoyalty->rank->percentage_discount;
            }
        }
    }
}
