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

            if ($buyerLoyalty->transaction_count == 0) {
                $buyerLoyalty->update([
                    'transaction_count' => 1,
                    'last_upgrade_date' => Carbon::now('Asia/Jakarta'),
                ]);
                return $buyerLoyalty->rank->percentage_discount;
            }

            if ($currentTransaction == 2) {
                $buyerLoyalty->update([
                    'loyalty_rank_id' => $lowerRank->id,
                    'transaction_count' => $buyerLoyalty->transaction_count + 1,
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
                    'expire_date' => Carbon::parse($buyerLoyalty->expire_date)->addWeeks($lowerRank->expired_weeks)->endOfDay(),
                    'updated_at' => Carbon::now('Asia/Jakarta'),
                ]);
                return $lowerRank->percentage_discount;
            } else {

                $buyerLoyalty->update([
                    'loyalty_rank_id' => $lowerRank->id,
                    'transaction_count' => $buyerLoyalty->transaction_count + 1,
                    'last_upgrade_date' => Carbon::now('Asia/Jakarta'),
                    'expire_date' => Carbon::parse($buyerLoyalty->expire_date)->addWeeks($lowerRank->expired_weeks)->endOfDay(),
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

    /**
     * Get current and next rank for a buyer based on their transaction history
     * Simulates expired_weeks accumulation from each transaction since June 2025
     * 
     * @param int $buyer_id
     * @param string|null $current_transaction_date Optional: untuk mendapatkan rank pada tanggal tertentu
     * @return array ['current_rank' => LoyaltyRank, 'next_rank' => LoyaltyRank|null, 'transaction_count' => int, 'expire_date' => Carbon|null]
     */
    public static function getCurrentRankInfo($buyer_id, $current_transaction_date = null)
    {
        // Ambil semua transaksi buyer yang valid sejak Juni 2025
        $query = \App\Models\SaleDocument::where('buyer_id_document_sale', $buyer_id)
            ->where('status_document_sale', 'selesai')
            ->where('total_display_document_sale', '>=', 5000000)
            ->where('created_at', '>=', '2025-06-01');

        // Jika ada tanggal transaksi spesifik, filter sampai tanggal tersebut
        if ($current_transaction_date) {
            $query->where('created_at', '<=', $current_transaction_date);
        }

        $transactions = $query->orderBy('created_at', 'asc')->get(['created_at']);
        $validTransactionCount = $transactions->count();

        // Load all ranks untuk referensi
        $allRanks = LoyaltyRank::orderBy('min_transactions', 'asc')->get();

        // Jika tidak ada transaksi valid
        if ($validTransactionCount == 0) {
            $newBuyerRank = $allRanks->where('min_transactions', 0)->first();
            return [
                'current_rank' => $newBuyerRank,
                'next_rank' => $allRanks->where('min_transactions', '>', 0)->first(),
                'transaction_count' => 0,
                'expire_date' => null,
            ];
        }

        // Simulasi akumulasi expired_weeks dengan check expired
        $simulatedExpireDate = null;
        $currentTransactionCount = 0;
        $lastResetIndex = -1; // Track index transaksi terakhir yang jadi reset point

        foreach ($transactions as $index => $transaction) {
            $transactionDate = Carbon::parse($transaction->created_at);

            // Check apakah transaksi ini melewati expire_date (EXPIRED)
            if ($simulatedExpireDate !== null && $transactionDate->gt($simulatedExpireDate)) {
                // EXPIRED! Reset count ke 1 (kembali ke New Buyer, tidak ada expired)
                $currentTransactionCount = 1;
                $lastResetIndex = $index; // Simpan index transaksi yang jadi reset point

                // Get New Buyer rank (min_transactions=0)
                $rankForTransaction = $allRanks->where('min_transactions', 0)->first();

                // New Buyer tidak punya expired, set null
                $simulatedExpireDate = null;
            } else {
                // Tidak expired, lanjutkan normal
                $currentTransactionCount++;

                // Tentukan rank berdasarkan transaction count saat ini
                $rankForTransaction = $allRanks->where('min_transactions', '<=', $currentTransactionCount)
                    ->sortByDesc('min_transactions')
                    ->first();

                if (!$rankForTransaction) {
                    // Fallback ke New Buyer jika tidak ada rank yang cocok
                    $rankForTransaction = $allRanks->where('min_transactions', 0)->first();
                }

                // Akumulasi expired_weeks (hanya untuk count >= 2, karena count 1 = New Buyer tanpa expired)
                if ($currentTransactionCount >= 2 && $rankForTransaction && $rankForTransaction->expired_weeks > 0) {
                    if ($simulatedExpireDate === null) {
                        // Transaksi ke-2: mulai hitung expired dari tanggal transaksi pertama SETELAH reset
                        // Jika ada reset, pakai transaksi di lastResetIndex, kalau tidak pakai transaksi paling awal
                        $firstTransactionIndex = ($lastResetIndex >= 0) ? $lastResetIndex : 0;
                        $firstTransactionDate = Carbon::parse($transactions[$firstTransactionIndex]->created_at);
                        $simulatedExpireDate = $firstTransactionDate->copy()->addWeeks($rankForTransaction->expired_weeks)->endOfDay();
                    } else {
                        // Transaksi berikutnya: tambahkan expired_weeks ke expire_date yang ada
                        $simulatedExpireDate->addWeeks($rankForTransaction->expired_weeks);
                    }
                }
            }
        }

        // Tentukan current rank berdasarkan transaction count SEBELUM transaksi ini (count-1)
        // Karena transaksi ini menggunakan rank sebelumnya, bukan rank hasil upgrade
        $effectiveCount = max(0, $currentTransactionCount - 1);
        $currentRank = $allRanks->where('min_transactions', '<=', $effectiveCount)
            ->sortByDesc('min_transactions')
            ->first();

        if (!$currentRank) {
            // Fallback ke New Buyer
            $currentRank = $allRanks->where('min_transactions', 0)->first();
        }

        // Cari next rank berdasarkan effectiveCount (rank yang sedang dipakai)
        $nextRank = $allRanks->where('min_transactions', '>', $effectiveCount)
            ->sortBy('min_transactions')
            ->first();

        $expireDate = $simulatedExpireDate;

        return [
            'current_rank' => $currentRank,
            'next_rank' => $nextRank,
            'transaction_count' => $currentTransactionCount, // Gunakan currentTransactionCount setelah expired check
            'expire_date' => $expireDate,
        ];
    }
}
