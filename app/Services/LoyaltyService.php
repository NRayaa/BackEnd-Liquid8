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
                    'expire_date' => Carbon::now('Asia/Jakarta')->addWeeks($lowerRank->expired_weeks)->endOfDay(),
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
                    'expire_date' => Carbon::now('Asia/Jakarta')->addWeeks($lowerRank->expired_weeks)->endOfDay(),
                    'updated_at' => Carbon::now('Asia/Jakarta'),
                ]);
                return $lowerRank->percentage_discount;
            } else {

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
        }
    }

    //loyalti otomatis, bisa digunakan ketika semua data buyer udah benar
    public static function processLoyalty2($buyer_id, $totalDisplayPrice)
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

            // Cek apakah buyer sudah expired berdasarkan expire_date yang ada
            $now = Carbon::now('Asia/Jakarta');
            $isCurrentlyExpired = false;
            
            if ($buyerLoyalty->expire_date && $now->gt(Carbon::parse($buyerLoyalty->expire_date))) {
                // Check grace period untuk expired
                $expireDate = Carbon::parse($buyerLoyalty->expire_date);
                $storeClosureStart = Carbon::parse('2025-07-11');
                $storeClosureEnd = Carbon::parse('2025-09-19');
                
                if ($expireDate->between($storeClosureStart, $storeClosureEnd)) {
                    // Ada grace period, extend expire date
                    $sisaHari = $storeClosureStart->diffInDays($expireDate, false);
                    if ($sisaHari < 0) $sisaHari = 0;
                    $extendedExpireDate = $storeClosureEnd->copy()->addDays(1 + $sisaHari)->endOfDay();
                    
                    // Re-check dengan extended date
                    if ($now->gt($extendedExpireDate)) {
                        $isCurrentlyExpired = true;
                    }
                } else {
                    $isCurrentlyExpired = true;
                }
            }

            // Jika expired, reset ke New Buyer
            if ($isCurrentlyExpired) {
                $newBuyerRank = LoyaltyRank::where('min_transactions', 0)->first();
                $buyerLoyalty->update([
                    'loyalty_rank_id' => $newBuyerRank->id,
                    'transaction_count' => 1, // Reset ke 1 (transaksi ini jadi transaksi pertama)
                    'last_upgrade_date' => Carbon::now('Asia/Jakarta'),
                    'expire_date' => null, // New Buyer tidak ada expire
                ]);

                BuyerLoyaltyHistory::create([
                    'buyer_id' => $buyer_id,
                    'previous_rank' => $buyerLoyalty->rank ? $buyerLoyalty->rank->rank : 'Unknown',
                    'current_rank' => $newBuyerRank->rank,
                    'note' => 'Expired and reset to ' . $newBuyerRank->rank,
                    'created_at' => Carbon::now('Asia/Jakarta'),
                    'updated_at' => Carbon::now('Asia/Jakarta'),
                ]);
                
                return $newBuyerRank->percentage_discount;
            }

            // Normal processing (tidak expired)
            $newTransactionCount = $buyerLoyalty->transaction_count + 1;
            
            // Tentukan rank berdasarkan transaction count baru
            $newRank = LoyaltyRank::where('min_transactions', '<=', $newTransactionCount)
                ->orderBy('min_transactions', 'desc')
                ->first();

            if (!$newRank) {
                $newRank = LoyaltyRank::where('min_transactions', 0)->first();
            }

            // Update expire_date menggunakan rank yang AKTIF saat transaksi (bukan rank hasil upgrade)
            $expireDateNew = null;
            if ($newTransactionCount >= 2) {
                // Dapatkan rank yang sedang aktif SEBELUM transaksi ini (rank saat belanja)
                $effectiveCount = max(0, $newTransactionCount - 1);
                $activeRankForExpired = LoyaltyRank::where('min_transactions', '<=', $effectiveCount)
                    ->orderBy('min_transactions', 'desc')
                    ->first();
                
                if ($activeRankForExpired && $activeRankForExpired->expired_weeks > 0) {
                    // SET expire_date dari tanggal transaksi + expired_weeks rank yang AKTIF
                    $expireDateNew = Carbon::now('Asia/Jakarta')->addWeeks($activeRankForExpired->expired_weeks)->endOfDay();
                }
            }

            // Update buyer loyalty
            $oldRankName = $buyerLoyalty->rank ? $buyerLoyalty->rank->rank : 'Unknown';
            
            $buyerLoyalty->update([
                'loyalty_rank_id' => $newRank->id,
                'transaction_count' => $newTransactionCount,
                'last_upgrade_date' => Carbon::now('Asia/Jakarta'),
                'expire_date' => $expireDateNew,
            ]);

            // Log history jika rank berubah
            if ($oldRankName !== $newRank->rank) {
                BuyerLoyaltyHistory::create([
                    'buyer_id' => $buyer_id,
                    'previous_rank' => $oldRankName,
                    'current_rank' => $newRank->rank,
                    'note' => 'Rank upgraded from ' . $oldRankName . ' to ' . $newRank->rank,
                    'created_at' => Carbon::now('Asia/Jakarta'),
                    'updated_at' => Carbon::now('Asia/Jakarta'),
                ]);
            }

            return $newRank->percentage_discount;
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
            // HANYA check expired jika currentTransactionCount >= 2 (bukan transaksi ke-2)
            // Karena transaksi ke-2 hanya SET expire, tidak CHECK expired
            $isExpired = false;
            if ($currentTransactionCount >= 2 && $simulatedExpireDate !== null && $transactionDate->gt($simulatedExpireDate)) {
                // Check apakah ada grace period (toko tutup)
                $storeClosureStart = Carbon::parse('2025-07-11');
                $storeClosureEnd = Carbon::parse('2025-09-19');
                
                // Jika expire_date jatuh dalam periode toko tutup, hitung sisa hari dan extend
                if ($simulatedExpireDate->between($storeClosureStart, $storeClosureEnd)) {
                    // Hitung sisa hari dari mulai tutup toko sampai expire_date
                    $sisaHari = $storeClosureStart->diffInDays($simulatedExpireDate, false);
                    if ($sisaHari < 0) $sisaHari = 0; // Jika expire_date sebelum tutup, tidak ada sisa
                    
                    // Extend expire_date = tanggal buka + sisa hari
                    $simulatedExpireDate = $storeClosureEnd->copy()->addDays(1 + $sisaHari)->endOfDay();
                }
                
                // Re-check expired setelah extension
                if ($transactionDate->gt($simulatedExpireDate)) {
                    // EXPIRED! Turun 1 tingkat ke rank di bawahnya
                    
                    // Dapatkan rank AKTIF sebelum expired (rank yang sedang dipakai)
                    $effectiveCountBeforeExpired = max(0, $currentTransactionCount - 1);
                    $currentActiveRank = $allRanks->where('min_transactions', '<=', $effectiveCountBeforeExpired)
                        ->sortByDesc('min_transactions')
                        ->first();
                    
                    // Cari rank di BAWAH rank aktif saat ini
                    $downgradedRank = null;
                    if ($currentActiveRank) {
                        $downgradedRank = $allRanks->where('min_transactions', '<', $currentActiveRank->min_transactions)
                            ->sortByDesc('min_transactions')
                            ->first();
                    }
                    
                    // Jika tidak ada rank di bawahnya, fallback ke New Buyer
                    if (!$downgradedRank) {
                        $downgradedRank = $allRanks->where('min_transactions', 0)->first();
                    }
                    
                    // Set count = min_transactions rank baru + 1 (transaksi ini adalah transaksi pertama di rank baru)
                    $currentTransactionCount = $downgradedRank->min_transactions + 1;
                    $simulatedExpireDate = null;
                    $lastResetIndex = $index; // Simpan index transaksi yang jadi reset point
                    $isExpired = true;
                    // JANGAN increment lagi karena sudah di-set sesuai rank baru
                } else {
                    // Tidak expired (karena grace period), increment normal
                    $currentTransactionCount++;
                }
            } else {
                // Process transaksi ini (tidak expired, increment normal)
                $currentTransactionCount++;
            }

            // Tentukan rank berdasarkan transaction count saat ini
            // Jika expired, turun ke rank downgrade (bukan New Buyer)
            if ($isExpired) {
                // Rank sudah di-set saat downgrade, gunakan currentTransactionCount untuk tentukan rank
                $rankForTransaction = $allRanks->where('min_transactions', '<=', $currentTransactionCount)
                    ->sortByDesc('min_transactions')
                    ->first();
            } else if ($currentTransactionCount == 1) {
                $rankForTransaction = $allRanks->where('min_transactions', 0)->first();
            } else {
                $rankForTransaction = $allRanks->where('min_transactions', '<=', $currentTransactionCount)
                    ->sortByDesc('min_transactions')
                    ->first();
            }

            if (!$rankForTransaction) {
                // Fallback ke New Buyer jika tidak ada rank yang cocok
                $rankForTransaction = $allRanks->where('min_transactions', 0)->first();
            }

            // Update expired_weeks menggunakan rank yang AKTIF saat transaksi (bukan rank hasil upgrade)
            if ($currentTransactionCount >= 2) {
                // Dapatkan rank yang sedang aktif SEBELUM transaksi ini (rank saat belanja)
                $effectiveCountForExpired = max(0, $currentTransactionCount - 1);
                $activeRankForExpired = $allRanks->where('min_transactions', '<=', $effectiveCountForExpired)
                    ->sortByDesc('min_transactions')
                    ->first();
                
                if ($activeRankForExpired && $activeRankForExpired->expired_weeks > 0) {
                    // UPDATE expire_date dari tanggal transaksi + expired_weeks rank yang AKTIF
                    $simulatedExpireDate = $transactionDate->copy()->addWeeks($activeRankForExpired->expired_weeks)->endOfDay();
                }
            }
        }

        // Tentukan current rank berdasarkan rank dari transaksi sebelumnya
        // Bukan berdasarkan min_transactions, tapi rank yang aktif saat transaksi terakhir
        $currentRank = null;
        $effectiveCount = 0; // Inisialisasi default
        
        if ($validTransactionCount > 0) {
            // Ambil rank dari simulasi transaksi terakhir
            $lastTransactionCount = $currentTransactionCount;
            if ($lastTransactionCount == 1) {
                $currentRank = $allRanks->where('min_transactions', 0)->first();
                $effectiveCount = 0; // New Buyer = effective count 0
            } else {
                // Untuk transaksi dengan count > 1, ambil rank berdasarkan count sebelumnya
                $effectiveCount = max(0, $lastTransactionCount - 1);
                $currentRank = $allRanks->where('min_transactions', '<=', $effectiveCount)
                    ->sortByDesc('min_transactions')
                    ->first();
            }
        }

        if (!$currentRank) {
            // Fallback ke New Buyer
            $currentRank = $allRanks->where('min_transactions', 0)->first();
            $effectiveCount = 0; // New Buyer = effective count 0
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

    /**
     * Trace detail expired untuk semua transaksi buyer
     * Menampilkan setiap transaksi dengan status expired, count, dan expire_date
     * 
     * @param int $buyer_id
     * @param int|null $sale_document_id Optional: untuk trace sampai transaksi tertentu
     * @return array Detail setiap transaksi dengan status expired
     */
    public static function traceExpiredHistory($buyer_id, $sale_document_id = null)
    {
        // Ambil semua transaksi buyer yang valid
        $query = \App\Models\SaleDocument::where('buyer_id_document_sale', $buyer_id)
            ->where('status_document_sale', 'selesai')
            ->where('total_display_document_sale', '>=', 5000000)
            ->where('created_at', '>=', '2025-06-01');

        // Jika ada sale_document_id spesifik, filter sampai transaksi tersebut
        if ($sale_document_id) {
            $specificDocument = \App\Models\SaleDocument::find($sale_document_id);
            if ($specificDocument) {
                $query->where('created_at', '<=', $specificDocument->created_at);
            }
        }

        $transactions = $query->orderBy('created_at', 'asc')->get();

        // Load all ranks
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

        // Jika tidak ada transaksi
        if ($transactions->isEmpty()) {
            $result['summary']['final_rank'] = 'New Buyer';
            return $result;
        }

        // Simulasi dengan tracking expired
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

            // CEK EXPIRED (hanya dari transaksi #3 onwards, check SEBELUM increment)
            $isExpired = false;
            if ($currentTransactionCount >= 2 && $simulatedExpireDate !== null && $transactionDate->gt($simulatedExpireDate)) {
                // Check apakah ada grace period (toko tutup)
                $storeClosureStart = Carbon::parse('2025-07-11');
                $storeClosureEnd = Carbon::parse('2025-09-19');
                $originalExpireDate = $simulatedExpireDate->copy();
                
                // Jika expire_date jatuh dalam periode toko tutup, hitung sisa hari dan extend
                if ($simulatedExpireDate->between($storeClosureStart, $storeClosureEnd)) {
                    // Hitung sisa hari dari mulai tutup toko sampai expire_date
                    $sisaHari = $storeClosureStart->diffInDays($simulatedExpireDate, false);
                    if ($sisaHari < 0) $sisaHari = 0; // Jika expire_date sebelum tutup, tidak ada sisa
                    
                    // Extend expire_date = tanggal buka + sisa hari
                    $simulatedExpireDate = $storeClosureEnd->copy()->addDays(1 + $sisaHari)->endOfDay();
                    $transactionDetail['grace_period_applied'] = true;
                    $transactionDetail['original_expire_date'] = $originalExpireDate->format('d M Y H:i:s');
                    $transactionDetail['sisa_hari'] = $sisaHari;
                    $transactionDetail['extended_expire_date'] = $simulatedExpireDate->format('d M Y H:i:s');
                }
                
                // Re-check expired setelah extension
                if ($transactionDate->gt($simulatedExpireDate)) {
                    $expiredCount++;
                    $transactionDetail['expired_status'] = 'EXPIRED';
                    $transactionDetail['expired_reason'] = "Transaction date ({$transactionDate->format('d M Y')}) > Expire date ({$simulatedExpireDate->format('d M Y')})";
                    
                    // Dapatkan rank AKTIF sebelum expired (rank yang sedang dipakai)
                    $effectiveCountBeforeExpired = max(0, $currentTransactionCount - 1);
                    $currentActiveRank = $allRanks->where('min_transactions', '<=', $effectiveCountBeforeExpired)
                        ->sortByDesc('min_transactions')
                        ->first();
                    
                    // Cari rank di BAWAH rank aktif saat ini
                    $downgradedRank = null;
                    if ($currentActiveRank) {
                        $downgradedRank = $allRanks->where('min_transactions', '<', $currentActiveRank->min_transactions)
                            ->sortByDesc('min_transactions')
                            ->first();
                    }
                    
                    // Jika tidak ada rank di bawahnya, fallback ke New Buyer
                    if (!$downgradedRank) {
                        $downgradedRank = $allRanks->where('min_transactions', 0)->first();
                    }
                    
                    // Set count = min_transactions rank baru + 1 (transaksi ini adalah transaksi pertama di rank baru)
                    $currentTransactionCount = $downgradedRank->min_transactions + 1;
                    $simulatedExpireDate = null;
                    $isExpired = true;
                    
                    // Log informasi downgrade
                    $transactionDetail['downgraded_from_rank'] = $currentActiveRank ? $currentActiveRank->rank : 'Unknown';
                    $transactionDetail['downgraded_to_rank'] = $downgradedRank->rank;
                    $transactionDetail['downgraded_from_min_transactions'] = $currentActiveRank ? $currentActiveRank->min_transactions : 0;
                    $transactionDetail['downgraded_to_min_transactions'] = $downgradedRank->min_transactions;
                    
                    // JANGAN increment lagi karena sudah di-set sesuai rank baru
                } else {
                    // Tidak expired (mungkin karena grace period), increment normal
                    $currentTransactionCount++;
                    if (isset($transactionDetail['grace_period_applied'])) {
                        $transactionDetail['expired_status'] = 'VALID (Grace Period)';
                    }
                }
            } else {
                // Tidak expired, increment normal
                $currentTransactionCount++;
            }

            $transactionDetail['count_after'] = $currentTransactionCount;

            // Tentukan rank setelah transaksi ini
            // Jika expired, turun ke rank downgrade (bukan New Buyer)
            if ($isExpired) {
                // Rank sudah di-set saat downgrade, gunakan count_after untuk tentukan rank
                $rankAfter = $allRanks->where('min_transactions', '<=', $currentTransactionCount)
                    ->sortByDesc('min_transactions')
                    ->first();
            } else if ($currentTransactionCount == 1) {
                $rankAfter = $allRanks->where('min_transactions', 0)->first();
            } else {
                $rankAfter = $allRanks->where('min_transactions', '<=', $currentTransactionCount)
                    ->sortByDesc('min_transactions')
                    ->first();
            }

            if (!$rankAfter) {
                $rankAfter = $allRanks->where('min_transactions', 0)->first();
            }

            $transactionDetail['rank_after'] = $rankAfter->rank;
            $transactionDetail['expired_weeks'] = $rankAfter->expired_weeks;

            // Update expire_date menggunakan rank yang AKTIF saat transaksi
            if ($currentTransactionCount >= 2) {
                // Dapatkan rank yang sedang aktif SEBELUM transaksi ini (rank saat belanja)
                $effectiveCountForExpired = max(0, $currentTransactionCount - 1);
                $activeRankForExpired = $allRanks->where('min_transactions', '<=', $effectiveCountForExpired)
                    ->sortByDesc('min_transactions')
                    ->first();
                
                if ($activeRankForExpired && $activeRankForExpired->expired_weeks > 0) {
                    // UPDATE expire_date dari tanggal transaksi + expired_weeks rank yang AKTIF
                    $simulatedExpireDate = $transactionDate->copy()->addWeeks($activeRankForExpired->expired_weeks)->endOfDay();
                    $transactionDetail['expire_date_action'] = 'UPDATE';
                    $transactionDetail['active_rank_for_expired'] = $activeRankForExpired->rank;
                    $transactionDetail['expire_date_calculation'] = "{$transactionDate->format('d M Y')} + {$activeRankForExpired->expired_weeks} weeks (from {$activeRankForExpired->rank})";
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

        // Summary - gunakan effective count (count - 1) untuk rank
        $effectiveCount = max(0, $currentTransactionCount - 1);
        $finalRank = $allRanks->where('min_transactions', '<=', $effectiveCount)
            ->sortByDesc('min_transactions')
            ->first();

        if (!$finalRank) {
            $finalRank = $allRanks->where('min_transactions', 0)->first();
        }

        $result['summary']['total_expired_events'] = $expiredCount;
        $result['summary']['final_count'] = $currentTransactionCount;
        $result['summary']['final_rank'] = $finalRank->rank;
        $result['summary']['final_expire_date'] = $simulatedExpireDate ? $simulatedExpireDate->format('d M Y H:i:s') : null;

        return $result;
    }


}
