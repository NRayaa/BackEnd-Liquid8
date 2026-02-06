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
    public static function getClosurePeriods()
    {
        return [
            ['start' => '2025-07-11', 'end' => '2025-09-19'],
            ['start' => '2026-02-01', 'end' => '2026-02-04'],
        ];
    }

    public static function checkAndExtendGracePeriod($expireDate, $transactionDate = null)
    {
        if ($expireDate === null) return null;

        $periods = self::getClosurePeriods();
        
        $finalDate = $expireDate->copy();

        foreach ($periods as $period) {
            $start = Carbon::parse($period['start']);
            $end = Carbon::parse($period['end']);

            if ($transactionDate && $transactionDate->gt($end)) {
                continue;
            }

            // Jika expired >= start libur, tambah durasi full
            if ($expireDate->gte($start)) {
                // Hitung durasi (inclusive start & end, jadi +1)
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

            // Transaksi LIVE: anggap transaction date adalah SEKARANG
            $now = Carbon::now('Asia/Jakarta');

            // Helper kalkulasi expired
            $calculateExpiry = function ($weeks) use ($now) {
                $date = $now->copy()->addWeeks($weeks)->endOfDay();
                // Pass $now sebagai transaction date
                return self::checkAndExtendGracePeriod($date, $now);
            };

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

            if ($buyerLoyalty->transaction_count == 0) {
                $buyerLoyalty->update([
                    'transaction_count' => 1,
                    'expire_date' => $calculateExpiry($lowerRank->expired_weeks),
                    'last_upgrade_date' => Carbon::now('Asia/Jakarta'),
                ]);
                return $buyerLoyalty->rank->percentage_discount;
            }
            if ($currentTransaction == 2) {
                $buyerLoyalty->update([
                    'loyalty_rank_id' => $lowerRank->id,
                    'transaction_count' => $buyerLoyalty->transaction_count + 1,
                    'last_upgrade_date' => Carbon::now('Asia/Jakarta'),
                    'expire_date' => $calculateExpiry($lowerRank->expired_weeks),
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
                    'expire_date' => $calculateExpiry($lowerRank->expired_weeks),
                    'updated_at' => Carbon::now('Asia/Jakarta'),
                ]);
                return $lowerRank->percentage_discount;
            } else {
                $buyerLoyalty->update([
                    'loyalty_rank_id' => $lowerRank->id,
                    'transaction_count' => $buyerLoyalty->transaction_count + 1,
                    'last_upgrade_date' => Carbon::now('Asia/Jakarta'),
                    'expire_date' => $calculateExpiry($lowerRank->expired_weeks),
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

    public static function getCurrentRankInfo($buyer_id, $current_transaction_date = null)
    {
        $allRanks = LoyaltyRank::orderBy('min_transactions', 'asc')->get();
        $listBuyerIdSpecial = [496];

        if (in_array($buyer_id, $listBuyerIdSpecial)) {
            $buyerLoyalty = BuyerLoyalty::where('buyer_id', $buyer_id)->first();
            if (!$buyerLoyalty) {
                $defaultRank = $allRanks->where('min_transactions', 0)->first();
                return [
                    'current_rank' => $defaultRank,
                    'next_rank' => $allRanks->where('min_transactions', '>', 0)->first(),
                    'transaction_count' => 0,
                    'expire_date' => null,
                    'discount_percent' => $defaultRank->discount ?? 0,
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

        $query = \App\Models\SaleDocument::where('buyer_id_document_sale', $buyer_id)
            ->where('status_document_sale', 'selesai')
            ->where('total_display_document_sale', '>=', 5000000)
            ->where('created_at', '>=', '2025-06-01');

        if ($current_transaction_date) {
            $query->where('created_at', '<=', $current_transaction_date);
        }

        $transactions = $query->orderBy('created_at', 'asc')->get(['created_at']);

        if ($transactions->isEmpty()) {
            $newBuyerRank = $allRanks->where('min_transactions', 0)->first();
            return [
                'current_rank' => $newBuyerRank,
                'next_rank' => $allRanks->where('min_transactions', '>', 0)->first(),
                'transaction_count' => 0,
                'expire_date' => null,
                'discount_percent' => $newBuyerRank->discount ?? 0,
            ];
        }

        $simulatedExpireDate = null;
        $currentTransactionCount = 0;

        foreach ($transactions as $transaction) {
            $transactionDate = Carbon::parse($transaction->created_at);

            // WHILE LOOP: Hanya murni cek apakah expired sebelum transaksi ini
            while ($currentTransactionCount >= 2 && $simulatedExpireDate !== null && $transactionDate->gt($simulatedExpireDate)) {

                $currentAchievedRank = $allRanks->where('min_transactions', '<=', $currentTransactionCount)
                    ->sortByDesc('min_transactions')->first();

                $downgradedRank = null;
                if ($currentAchievedRank) {
                    $downgradedRank = $allRanks->where('min_transactions', '<', $currentAchievedRank->min_transactions)
                        ->sortByDesc('min_transactions')->first();
                }

                if (!$downgradedRank) {
                    $downgradedRank = $allRanks->where('min_transactions', 0)->first();
                }

                if ($currentAchievedRank && $downgradedRank->id == $currentAchievedRank->id) {
                    $currentTransactionCount = $downgradedRank->min_transactions;
                    $simulatedExpireDate = null;
                    break;
                }

                $currentTransactionCount = $downgradedRank->min_transactions;

                $activeRankForExpired = $allRanks->where('min_transactions', '<=', $currentTransactionCount)
                    ->sortByDesc('min_transactions')->first();

                if ($activeRankForExpired && $activeRankForExpired->expired_weeks > 0) {
                    $rawExpire = $simulatedExpireDate->copy()->addWeeks($activeRankForExpired->expired_weeks)->endOfDay();
                    
                    // PASS Transaction Date ke Helper
                    $simulatedExpireDate = self::checkAndExtendGracePeriod($rawExpire, $transactionDate);
                } else {
                    $simulatedExpireDate = null;
                }
            }

            $currentTransactionCount++;

            if ($currentTransactionCount >= 2) {
                $effectiveCountForExpired = max(0, $currentTransactionCount - 1);
                $activeRank = $allRanks->where('min_transactions', '<=', $effectiveCountForExpired)
                    ->sortByDesc('min_transactions')->first();

                if ($activeRank && $activeRank->expired_weeks > 0) {
                    $calcDate = $transactionDate->copy()->addWeeks($activeRank->expired_weeks)->endOfDay();
                    
                    // PASS Transaction Date ke Helper
                    $simulatedExpireDate = self::checkAndExtendGracePeriod($calcDate, $transactionDate);
                } else {
                    $simulatedExpireDate = null;
                }
            }
        }

        $finalRank = $allRanks->where('min_transactions', '<=', $currentTransactionCount)
            ->sortByDesc('min_transactions')->first();

        if (!$finalRank) {
            $finalRank = $allRanks->where('min_transactions', 0)->first();
        }

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

    // (Function debugInfo bisa disesuaikan sama seperti getCurrentRankInfo jika diperlukan)

    public static function traceExpiredHistory($buyer_id, $sale_document_id = null)
    {
        $listBuyerIdSpecial = [496];
        $query = \App\Models\SaleDocument::where('buyer_id_document_sale', $buyer_id)
            ->where('status_document_sale', 'selesai')
            ->where('total_display_document_sale', '>=', 5000000)
            ->where('created_at', '>=', '2025-06-01');

        if ($sale_document_id) {
            $specificDocument = \App\Models\SaleDocument::find($sale_document_id);
            if ($specificDocument) {
                $query->where('created_at', '<=', $specificDocument->created_at);
            }
        }

        $transactions = $query->orderBy('created_at', 'asc')->get();
        $allRanks = LoyaltyRank::orderBy('min_transactions', 'asc')->get();

        $result = [
            'buyer_id' => $buyer_id,
            'total_transactions' => $transactions->count(),
            'transactions' => [],
            'summary' => [
                'total_expired_events' => 0,
                'final_count' => 0,
                'final_rank' => null,
                'final_expire_date' => null,
            ]
        ];

        if ($transactions->isEmpty()) {
            $result['summary']['final_rank'] = 'New Buyer';
            return $result;
        }

        $simulatedExpireDate = null;
        $currentTransactionCount = 0;
        $expiredCount = 0;

        foreach ($transactions as $index => $transaction) {
            $transactionDate = Carbon::parse($transaction->created_at);
            $transNumber = $index + 1;

            $transactionDetail = [
                'transaction_number' => $transNumber,
                'sale_document_id' => $transaction->id,
                'code_document_sale' => $transaction->code_document_sale,
                'transaction_date' => $transactionDate->format('d M Y H:i:s'),
                'total_display' => $transaction->total_display_document_sale,
                'count_before' => $currentTransactionCount,
                'expired_status' => 'VALID',
                'expire_date_before' => $simulatedExpireDate ? $simulatedExpireDate->format('d M Y H:i:s') : null,
            ];

            $isExpired = false;
            if ($currentTransactionCount >= 2 && $simulatedExpireDate !== null && $transactionDate->gt($simulatedExpireDate)) {
                $expiredCount++;
                $transactionDetail['expired_status'] = 'EXPIRED';
                $transactionDetail['expired_reason'] = "Transaction date ({$transactionDate->format('d M Y')}) > Expire date ({$simulatedExpireDate->format('d M Y')})";

                $currentAchievedRank = $allRanks->where('min_transactions', '<=', $currentTransactionCount)
                    ->sortByDesc('min_transactions')->first();

                $downgradedRank = null;
                if ($currentAchievedRank) {
                    $downgradedRank = $allRanks->where('min_transactions', '<', $currentAchievedRank->min_transactions)
                        ->sortByDesc('min_transactions')->first();
                }

                if (!$downgradedRank) {
                    $downgradedRank = $allRanks->where('min_transactions', 0)->first();
                }

                $currentTransactionCount = $downgradedRank->min_transactions + 1;
                $simulatedExpireDate = null;
                $isExpired = true;

                $transactionDetail['downgraded_from_rank'] = $currentAchievedRank ? $currentAchievedRank->rank : 'Unknown';
                $transactionDetail['downgraded_to_rank'] = $downgradedRank->rank;
            } else {
                $currentTransactionCount++;
            }

            $transactionDetail['count_after'] = $currentTransactionCount;

            if ($isExpired) {
                $rankAfter = $allRanks->where('min_transactions', '<=', $currentTransactionCount)
                    ->sortByDesc('min_transactions')->first();
            } else if ($currentTransactionCount == 1) {
                $rankAfter = $allRanks->where('min_transactions', 0)->first();
            } else {
                $rankAfter = $allRanks->where('min_transactions', '<=', $currentTransactionCount)
                    ->sortByDesc('min_transactions')->first();
            }

            if (!$rankAfter) {
                $rankAfter = $allRanks->where('min_transactions', 0)->first();
            }

            $transactionDetail['rank_after'] = $rankAfter->rank;

            if ($currentTransactionCount >= 2) {
                $effectiveCountForExpired = max(0, $currentTransactionCount - 1);
                $activeRankForExpired = $allRanks->where('min_transactions', '<=', $effectiveCountForExpired)
                    ->sortByDesc('min_transactions')->first();

                if ($activeRankForExpired && $activeRankForExpired->expired_weeks > 0) {
                    $calcDate = $transactionDate->copy()->addWeeks($activeRankForExpired->expired_weeks)->endOfDay();

                    // PASS Transaction Date ke Helper
                    $simulatedExpireDate = self::checkAndExtendGracePeriod($calcDate, $transactionDate);

                    $transactionDetail['expire_date_action'] = 'UPDATE';
                    // Cek visual apakah tanggal berubah
                    if ($simulatedExpireDate->ne($calcDate)) {
                        $transactionDetail['grace_period_applied'] = true;
                        $transactionDetail['original_expire_date'] = $calcDate->format('d M Y H:i:s');
                    }
                    $transactionDetail['expire_date_after'] = $simulatedExpireDate->format('d M Y H:i:s');
                } else {
                    $transactionDetail['expire_date_action'] = 'NONE';
                    $transactionDetail['expire_date_after'] = $simulatedExpireDate ? $simulatedExpireDate->format('d M Y H:i:s') : null;
                }
            } else {
                $transactionDetail['expire_date_action'] = 'NONE';
                $transactionDetail['expire_date_after'] = $simulatedExpireDate ? $simulatedExpireDate->format('d M Y H:i:s') : null;
            }

            $result['transactions'][] = $transactionDetail;
        }

        $effectiveCount = max(0, $currentTransactionCount - 1);
        $finalRank = $allRanks->where('min_transactions', '<=', $effectiveCount)
            ->sortByDesc('min_transactions')->first();

        if (!$finalRank) {
            $finalRank = $allRanks->where('min_transactions', 0)->first();
        }

        $result['summary']['total_expired_events'] = $expiredCount;
        $result['summary']['final_count'] = $currentTransactionCount;
        $result['summary']['final_rank'] = $finalRank->rank;
        $result['summary']['final_expire_date'] = $simulatedExpireDate ? $simulatedExpireDate->format('d M Y H:i:s') : null;

        if (in_array($buyer_id, $listBuyerIdSpecial)) {
            $result['is_special_buyer'] = true;
            $buyerLoyalty = BuyerLoyalty::where('buyer_id', $buyer_id)->first();
            if ($buyerLoyalty) {
                // ... (Logic special buyer sama) ...
                $currentRank = $buyerLoyalty->rank;
                $nextRank = $allRanks->where('min_transactions', '>', $currentRank->min_transactions)->sortBy('min_transactions')->first();
                $result['summary_special'] = [
                     'note' => 'Buyer special tidak terkena expired, data diambil dari buyer_loyalties table',
                     'total_expired_events' => 0,
                     'final_count' => $buyerLoyalty->transaction_count,
                     'final_rank' => $currentRank->rank,
                     'final_expire_date' => $buyerLoyalty->expire_date ? Carbon::parse($buyerLoyalty->expire_date)->format('d M Y H:i:s') : null,
                     'next_rank' => $nextRank ? $nextRank->rank : null,
                     'last_upgrade_date' => $buyerLoyalty->last_upgrade_date ? Carbon::parse($buyerLoyalty->last_upgrade_date)->format('d M Y H:i:s') : null,
                ];
            } else {
                $result['summary_special'] = ['note' => 'No data'];
            }
        }

        return $result;
    }
}