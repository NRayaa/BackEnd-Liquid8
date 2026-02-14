<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\Buyer;
use App\Models\LoyaltyRank;
use App\Models\BuyerLoyalty;
use App\Models\BuyerLoyaltyHistory;
use App\Models\SaleDocument;
use Illuminate\Support\Facades\Log;

class LoyaltyService
{
    public static function getClosurePeriods()
    {
        return [
            ['start' => '2025-07-11', 'end' => '2025-09-19'],
            ['start' => '2026-02-01', 'end' => '2026-02-11'],
        ];
    }

    /**
     * [FIXED] Logika Perpanjangan Grace Period
     */
    public static function checkAndExtendGracePeriod($expireDate, $transactionDate = null)
    {
        if ($expireDate === null) return null;

        $periods = self::getClosurePeriods();
        $finalDate = $expireDate->copy();
        

        foreach ($periods as $period) {
            $start = Carbon::parse($period['start'])->startOfDay();
            $end = Carbon::parse($period['end'])->endOfDay();
            
            if ($expireDate->betweenIncluded($start, $end)) {
                $duration = $start->diffInDays($end) + 1;
                $finalDate->addDays($duration);
            }
        }

        return $finalDate;
    }

    public static function processLoyalty($buyer_id, $totalDisplayPrice)
    {
        if ($totalDisplayPrice >= 5000000) {
            $buyerLoyalty = BuyerLoyalty::where('buyer_id', $buyer_id)->first();
            $now = Carbon::now('Asia/Jakarta');

            if (!$buyerLoyalty) {
                $firstRank = LoyaltyRank::where('min_transactions', '<=', 1)
                    ->orderBy('min_transactions', 'desc')->first();
                if (!$firstRank) $firstRank = LoyaltyRank::orderBy('min_transactions', 'asc')->first();

                $expiryDate = null;
                if ($firstRank->expired_weeks > 0) {
                    $rawDate = $now->copy()->addWeeks($firstRank->expired_weeks)->endOfDay();
                    $expiryDate = self::checkAndExtendGracePeriod($rawDate, $now);
                }

                $buyerLoyalty = BuyerLoyalty::create([
                    'buyer_id' => $buyer_id,
                    'loyalty_rank_id' => $firstRank->id,
                    'transaction_count' => 1,
                    'last_upgrade_date' => $now,
                    'expire_date' => $expiryDate,
                ]);

                BuyerLoyaltyHistory::create([
                    'buyer_id' => $buyer_id,
                    'previous_rank' => null,
                    'current_rank' => $firstRank->rank,
                    'note' => 'First transaction. Rank set to ' . $firstRank->rank,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                return $firstRank->percentage_discount;
            }

            $oldRank = $buyerLoyalty->rank;
            $oldRankName = $oldRank ? $oldRank->rank : 'Unknown';

            $currentTransaction = $buyerLoyalty->transaction_count + 1;
            
            $newRank = LoyaltyRank::where('min_transactions', '<=', $currentTransaction)
                ->orderBy('min_transactions', 'desc')->first();
            if (!$newRank) $newRank = LoyaltyRank::orderBy('min_transactions', 'asc')->first();

            // Logic Expired: Pay as you were
            $rankForCalculation = $oldRank;
            if (!$rankForCalculation || $rankForCalculation->expired_weeks <= 0) {
                $rankForCalculation = $newRank;
            }

            $newExpireDate = null;
            // Hanya hitung jika count >= 1. Jika Count 1 (New Buyer) punya weeks=0, expire tetap null.
            if ($currentTransaction >= 1 && $rankForCalculation->expired_weeks > 0) {
                $rawDate = $now->copy()->addWeeks($rankForCalculation->expired_weeks)->endOfDay();
                $newExpireDate = self::checkAndExtendGracePeriod($rawDate, $now);
            }

            $buyerLoyalty->update([
                'loyalty_rank_id' => $newRank->id,
                'transaction_count' => $currentTransaction,
                'last_upgrade_date' => $now,
                'expire_date' => $newExpireDate,
                'updated_at' => $now,
            ]);

            if ($oldRankName !== $newRank->rank) {
                BuyerLoyaltyHistory::create([
                    'buyer_id' => $buyer_id,
                    'previous_rank' => $oldRankName,
                    'current_rank' => $newRank->rank,
                    'note' => 'Rank updated to ' . $newRank->rank,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            return $newRank->percentage_discount;
        }
        return 0;
    }

    public static function getCurrentRankInfo($buyer_id, $current_transaction_date = null)
    {
        $allRanks = LoyaltyRank::orderBy('min_transactions', 'asc')->get();
        $lowestRank = $allRanks->first(); 
        $listBuyerIdSpecial = [496]; 

        if (in_array($buyer_id, $listBuyerIdSpecial)) {
            $buyerLoyalty = BuyerLoyalty::where('buyer_id', $buyer_id)->first();
            if (!$buyerLoyalty) {
                return [
                    'current_rank' => $lowestRank,
                    'next_rank' => $allRanks->where('min_transactions', '>', 0)->first(),
                    'transaction_count' => 0,
                    'expire_date' => null,
                    'discount_percent' => $lowestRank->discount ?? 0,
                ];
            }
            $currentRank = $buyerLoyalty->rank;
            $nextRank = $allRanks->where('min_transactions', '>', $currentRank->min_transactions)
                ->sortBy('min_transactions')->first();
            return [
                'current_rank' => $currentRank,
                'next_rank' => $nextRank,
                'transaction_count' => $buyerLoyalty->transaction_count,
                'expire_date' => $buyerLoyalty->expire_date ? Carbon::parse($buyerLoyalty->expire_date) : null,
                'discount_percent' => $currentRank->discount ?? 0,
            ];
        }

        $query = SaleDocument::where('buyer_id_document_sale', $buyer_id)
            ->where('status_document_sale', 'selesai')
            ->where('total_display_document_sale', '>=', 5000000)
            ->where('created_at', '>=', '2025-06-01');

        if ($current_transaction_date) {
            $query->where('created_at', '<=', Carbon::parse($current_transaction_date)->endOfDay());
        }

        $transactions = $query->orderBy('created_at', 'asc')->get(['created_at', 'id']);

        if ($transactions->isEmpty()) {
            return [
                'current_rank' => $lowestRank,
                'next_rank' => $allRanks->where('min_transactions', '>', 0)->first(),
                'transaction_count' => 0,
                'expire_date' => null,
                'discount_percent' => $lowestRank->discount ?? 0,
            ];
        }

        $simulatedExpireDate = null;
        $currentTransactionCount = 0;
        $currentRank = $lowestRank;
        $lastTransactionDate = null;

        foreach ($transactions as $transaction) {
            $transactionDate = Carbon::parse($transaction->created_at);
            $lastTransactionDate = $transactionDate;

            // Loop Downgrade
            while ($currentTransactionCount > 0 && $simulatedExpireDate !== null) {
                if (!$transactionDate->gt($simulatedExpireDate)) {
                    break;
                }

                $downgradedRank = $allRanks->where('min_transactions', '<', $currentRank->min_transactions)
                    ->sortByDesc('min_transactions')->first();

                if (!$downgradedRank || $downgradedRank->min_transactions == 0) {
                    $downgradedRank = $lowestRank;
                    $currentRank = $downgradedRank;
                    $currentTransactionCount = 0;
                    $simulatedExpireDate = null;
                    break;
                }

                $currentRank = $downgradedRank;
                $currentTransactionCount = $downgradedRank->min_transactions;

                if ($downgradedRank->expired_weeks > 0) {
                    $rawExpire = $simulatedExpireDate->copy()->addWeeks($downgradedRank->expired_weeks)->endOfDay();
                    $simulatedExpireDate = self::checkAndExtendGracePeriod($rawExpire, $lastTransactionDate);
                } else {
                    $simulatedExpireDate = null;
                }
            }

            // Upgrade
            $rankBeforeTransaction = $currentRank;
            $currentTransactionCount++;

            // Count 1 force to New Buyer (Lowest)
            if ($currentTransactionCount == 1) {
                $newRank = $lowestRank;
            } else {
                $newRank = $allRanks->where('min_transactions', '<=', $currentTransactionCount)
                    ->sortByDesc('min_transactions')->first();
                if (!$newRank) $newRank = $lowestRank;
            }
            $currentRank = $newRank;

            // Set Expire
            $rankForCalc = $rankBeforeTransaction;
            if ($rankForCalc->expired_weeks <= 0) {
                $rankForCalc = $newRank;
            }

            if ($currentTransactionCount >= 1 && $rankForCalc->expired_weeks > 0) {
                $rawExpire = $transactionDate->copy()->addWeeks($rankForCalc->expired_weeks)->endOfDay();
                $simulatedExpireDate = self::checkAndExtendGracePeriod($rawExpire, $transactionDate);
            } else {
                $simulatedExpireDate = null;
            }
        }

        // Final Check
        $finalCheckDate = $current_transaction_date ? Carbon::parse($current_transaction_date) : Carbon::now('Asia/Jakarta');

        while ($currentTransactionCount > 0 && $simulatedExpireDate !== null) {
            if (!$finalCheckDate->gt($simulatedExpireDate)) {
                break;
            }
            $downgradedRank = $allRanks->where('min_transactions', '<', $currentRank->min_transactions)
                ->sortByDesc('min_transactions')->first();

            if (!$downgradedRank || $downgradedRank->min_transactions == 0) {
                $downgradedRank = $lowestRank;
                $currentRank = $downgradedRank;
                $currentTransactionCount = 0;
                $simulatedExpireDate = null;
                break;
            }
            $currentRank = $downgradedRank;
            $currentTransactionCount = $downgradedRank->min_transactions;

            if ($downgradedRank->expired_weeks > 0) {
                $rawExpire = $simulatedExpireDate->copy()->addWeeks($downgradedRank->expired_weeks)->endOfDay();
                $simulatedExpireDate = self::checkAndExtendGracePeriod($rawExpire, $lastTransactionDate);
            } else {
                $simulatedExpireDate = null;
            }
        }

        $finalRank = $allRanks->where('min_transactions', '<=', $currentTransactionCount)
            ->sortByDesc('min_transactions')->first();
        if (!$finalRank) $finalRank = $lowestRank;

        $nextRank = $allRanks->where('min_transactions', '>', $finalRank->min_transactions)
            ->sortBy('min_transactions')->first();

        return [
            'current_rank' => $finalRank,
            'next_rank' => $nextRank,
            'transaction_count' => $currentTransactionCount,
            'expire_date' => $simulatedExpireDate,
            'discount_percent' => $finalRank->discount ?? 0,
        ];
    }

    public static function traceExpiredHistory($buyer_id, $sale_document_id = null)
    {
        $listBuyerIdSpecial = [496];
        $query = SaleDocument::where('buyer_id_document_sale', $buyer_id)
            ->where('status_document_sale', 'selesai')
            ->where('total_display_document_sale', '>=', 5000000)
            ->where('created_at', '>=', '2025-06-01');

        if ($sale_document_id) {
            $specificDocument = SaleDocument::find($sale_document_id);
            if ($specificDocument) {
                $query->where('created_at', '<=', $specificDocument->created_at);
            }
        }

        $transactions = $query->orderBy('created_at', 'asc')->get();
        $allRanks = LoyaltyRank::orderBy('min_transactions', 'asc')->get();

        $result = [
            'buyer_id' => $buyer_id,
            'transactions' => [],
            'summary' => []
        ];

        if ($transactions->isEmpty()) {
            $result['summary']['final_rank'] = 'New Buyer';
            return $result;
        }

        $simulatedExpireDate = null;
        $currentTransactionCount = 0;
        $expiredCount = 0;
        $lowestRank = $allRanks->first();
        $currentRank = $lowestRank;
        $lastTransactionDate = null;

        foreach ($transactions as $index => $transaction) {
            $transactionDate = Carbon::parse($transaction->created_at);
            $lastTransactionDate = $transactionDate;
            $transNumber = $index + 1;

            $transactionDetail = [
                'transaction_number' => $transNumber,
                'count_before' => $currentTransactionCount,
                'expired_status' => 'VALID',
                'expire_date_before' => $simulatedExpireDate ? $simulatedExpireDate->format('d M Y H:i:s') : null,
            ];

            // 1. Loop Downgrade
            while ($currentTransactionCount > 0 && $simulatedExpireDate !== null && $transactionDate->gt($simulatedExpireDate)) {
                $expiredCount++;
                $transactionDetail['expired_status'] = 'EXPIRED';
                $transactionDetail['expired_reason'] = "Date > Expire";

                $downgradedRank = $allRanks->where('min_transactions', '<', $currentRank->min_transactions)->sortByDesc('min_transactions')->first();

                if (!$downgradedRank || $downgradedRank->min_transactions == 0) {
                    $transactionDetail['downgraded_to_rank'] = $lowestRank->rank;
                    $currentRank = $lowestRank;
                    $currentTransactionCount = 0;
                    $simulatedExpireDate = null;
                    break;
                } else {
                    $transactionDetail['downgraded_to_rank'] = $downgradedRank->rank;
                    $currentRank = $downgradedRank;
                    $currentTransactionCount = $downgradedRank->min_transactions;
                    
                    if ($downgradedRank->expired_weeks > 0) {
                        $rawExpire = $simulatedExpireDate->copy()->addWeeks($downgradedRank->expired_weeks)->endOfDay();
                        // FIXED: use fixed checkAndExtendGracePeriod
                        $simulatedExpireDate = self::checkAndExtendGracePeriod($rawExpire, $lastTransactionDate);
                    } else {
                        $simulatedExpireDate = null;
                    }
                }
            }

            // 2. Upgrade
            $rankBeforeTransaction = $currentRank;
            $currentTransactionCount++;

            if ($currentTransactionCount == 1) {
                $rankAfter = $lowestRank;
            } else {
                $rankAfter = $allRanks->where('min_transactions', '<=', $currentTransactionCount)
                    ->sortByDesc('min_transactions')->first();
                if (!$rankAfter) $rankAfter = $lowestRank;
            }
            
            $currentRank = $rankAfter;
            $transactionDetail['count_after'] = $currentTransactionCount;
            $transactionDetail['rank_after'] = $rankAfter->rank;

            // 3. Set Expire
            $rankForCalc = $rankBeforeTransaction;
            if ($rankForCalc->expired_weeks <= 0) {
                $rankForCalc = $rankAfter;
            }

            if ($currentTransactionCount >= 1 && $rankForCalc->expired_weeks > 0) {
                $calcDate = $transactionDate->copy()->addWeeks($rankForCalc->expired_weeks)->endOfDay();
                $simulatedExpireDate = self::checkAndExtendGracePeriod($calcDate, $transactionDate);
                $transactionDetail['expire_date_after'] = $simulatedExpireDate->format('d M Y H:i:s');
            } else {
                $simulatedExpireDate = null;
                $transactionDetail['expire_date_after'] = null;
            }

            $result['transactions'][] = $transactionDetail;
        }

        $finalRank = $allRanks->where('min_transactions', '<=', $currentTransactionCount)->sortByDesc('min_transactions')->first();
        if (!$finalRank) $finalRank = $lowestRank;

        $result['summary']['final_rank'] = $finalRank->rank;
        $result['summary']['final_count'] = $currentTransactionCount;
        $result['summary']['final_expire_date'] = $simulatedExpireDate ? $simulatedExpireDate->format('d M Y H:i:s') : null;

        return $result;
    }
}