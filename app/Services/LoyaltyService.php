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
        $storeClosureStart = Carbon::parse('2025-07-11');
        $storeClosureEnd = Carbon::parse('2025-09-19');

        if ($totalDisplayPrice >= 5000000) {
            $buyerLoyalty = BuyerLoyalty::where('buyer_id', $buyer_id)->first();

            // Helper untuk hitung expired + grace period
            $calculateExpireDate = function ($rank) use ($storeClosureStart, $storeClosureEnd) {
                if ($rank->expired_weeks <= 0) return null;

                $date = Carbon::now('Asia/Jakarta')->addWeeks($rank->expired_weeks)->endOfDay();

                // Cek Grace Period
                if ($date->between($storeClosureStart, $storeClosureEnd)) {
                    $sisaHari = $storeClosureStart->diffInDays($date, false);
                    if ($sisaHari < 0) $sisaHari = 0;
                    $date = $storeClosureEnd->copy()->addDays(1 + $sisaHari)->endOfDay();
                }
                return $date;
            };

            // --- SECTION NEW BUYER ---
            if (!$buyerLoyalty) {
                $rankBuyer = LoyaltyRank::where('min_transactions', '<=', 1)
                    ->orderBy('min_transactions', 'desc')
                    ->first();

                if (!$rankBuyer) {
                    $rankBuyer = LoyaltyRank::orderBy('min_transactions', 'asc')->first();
                }

                $expireDate = $calculateExpireDate($rankBuyer);

                $buyerLoyalty = BuyerLoyalty::create([
                    'buyer_id' => $buyer_id,
                    'loyalty_rank_id' => $rankBuyer->id,
                    'transaction_count' => 1,
                    'last_upgrade_date' => Carbon::now('Asia/Jakarta'),
                    'expire_date' => $expireDate,
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

            // --- SECTION EXISTING BUYER ---
            $currentTransaction = $buyerLoyalty->transaction_count + 1;
            $lowerRank = LoyaltyRank::where('min_transactions', '<=', $currentTransaction)
                ->orderBy('min_transactions', 'desc')
                ->first();

            if ($buyerLoyalty->transaction_count == 0) {
                $buyerLoyalty->update([
                    'transaction_count' => 1,
                    'expire_date' => $calculateExpireDate($lowerRank),
                    'last_upgrade_date' => Carbon::now('Asia/Jakarta'),
                ]);
                return $buyerLoyalty->rank->percentage_discount;
            }

            if ($currentTransaction == 2) {
                $buyerLoyalty->update([
                    'loyalty_rank_id' => $lowerRank->id,
                    'transaction_count' => $buyerLoyalty->transaction_count + 1,
                    'last_upgrade_date' => Carbon::now('Asia/Jakarta'),
                    'expire_date' => $calculateExpireDate($lowerRank),
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
                    'expire_date' => $calculateExpireDate($lowerRank),
                    'updated_at' => Carbon::now('Asia/Jakarta'),
                ]);
                return $lowerRank->percentage_discount;
            } else {
                $buyerLoyalty->update([
                    'loyalty_rank_id' => $lowerRank->id,
                    'transaction_count' => $buyerLoyalty->transaction_count + 1,
                    'last_upgrade_date' => Carbon::now('Asia/Jakarta'),
                    'expire_date' => $calculateExpireDate($lowerRank),
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
        // 1. Load Data Rank
        $allRanks = LoyaltyRank::orderBy('min_transactions', 'asc')->get();

        // 2. Handle Special Buyer (Bypass)
        $listBuyerIdSpecial = [496];
        if (in_array($buyer_id, $listBuyerIdSpecial)) {
            $buyerLoyalty = BuyerLoyalty::where('buyer_id', $buyer_id)->first();

            // Handle jika data loyalty tidak ditemukan
            if (!$buyerLoyalty) {
                $defaultRank = $allRanks->where('min_transactions', 0)->first();
                return [
                    'current_rank' => $defaultRank,
                    'next_rank' => $allRanks->where('min_transactions', '>', 0)->first(),
                    'transaction_count' => 0,
                    'expire_date' => null,
                    'discount_percent' => $defaultRank->discount ?? 0, // Default discount
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

        $storeClosureStart = Carbon::parse('2025-07-11');
        $storeClosureEnd = Carbon::parse('2025-09-19');

        foreach ($transactions as $transaction) {
            $transactionDate = Carbon::parse($transaction->created_at);

            // --- LOGIC EXPIRED (WHILE LOOP) ---
            while ($currentTransactionCount >= 1 && $simulatedExpireDate !== null && $transactionDate->gt($simulatedExpireDate)) {

                // Cek Grace Period
                if ($simulatedExpireDate->between($storeClosureStart, $storeClosureEnd)) {
                    $sisaHari = $storeClosureStart->diffInDays($simulatedExpireDate, false);
                    if ($sisaHari < 0) $sisaHari = 0;

                    $extendedExpireDate = $storeClosureEnd->copy()->addDays(1 + $sisaHari)->endOfDay();
                    $simulatedExpireDate = $extendedExpireDate;

                    // Jika selamat setelah grace period, break loop
                    if (!$transactionDate->gt($simulatedExpireDate)) {
                        break;
                    }
                }

                // Proses Downgrade
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

                // Safety Break (Mencegah infinite loop jika sudah di dasar)
                if ($currentAchievedRank && $downgradedRank->id == $currentAchievedRank->id) {
                    $currentTransactionCount = $downgradedRank->min_transactions; // Reset ke dasar
                    $simulatedExpireDate = null;
                    break;
                }

                $currentTransactionCount = $downgradedRank->min_transactions;

                $activeRankForExpired = $allRanks->where('min_transactions', '<=', $currentTransactionCount)
                    ->sortByDesc('min_transactions')->first();

                if ($activeRankForExpired && $activeRankForExpired->expired_weeks > 0) {
                    // Akumulasi dari tanggal expired lama
                    $simulatedExpireDate = $simulatedExpireDate->copy()->addWeeks($activeRankForExpired->expired_weeks);
                } else {
                    $simulatedExpireDate = null;
                }
            }

            // Increment Transaksi (Berlaku untuk transaksi normal maupun transaksi yang menyebabkan reset)
            $currentTransactionCount++;

            // Set Expire Date Normal untuk transaksi berikutnya
            if ($currentTransactionCount >= 1) {
                $activeRank = $allRanks->where('min_transactions', '<=', $currentTransactionCount)
                    ->sortByDesc('min_transactions')->first();

                if ($activeRank && $activeRank->expired_weeks > 0) {
                    $simulatedExpireDate = $transactionDate->copy()->addWeeks($activeRank->expired_weeks)->endOfDay();
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

        $discountValue = $finalRank->discount ?? 0;

        return [
            'current_rank' => $finalRank,
            'next_rank' => $nextRank,
            'transaction_count' => $currentTransactionCount, // Count sudah fix
            'expire_date' => $simulatedExpireDate,
            'discount_percent' => $discountValue, // Output class discount
        ];
    }

    public static function debugInfo($buyer_id, $current_transaction_date = null)
    {
        // List buyer special yang tidak terkena expired
        $listBuyerIdSpecial = [7];

        // Check apakah buyer ini special
        if (in_array($buyer_id, $listBuyerIdSpecial)) {
            // Buyer special: ambil data dari buyer_loyalties
            $buyerLoyalty = BuyerLoyalty::where('buyer_id', $buyer_id)->first();

            if (!$buyerLoyalty) {
                $allRanks = LoyaltyRank::orderBy('min_transactions', 'asc')->get();
                $newBuyerRank = $allRanks->where('min_transactions', 0)->first();
                return [
                    'current_rank' => $newBuyerRank,
                    'next_rank' => $allRanks->where('min_transactions', '>', 0)->first(),
                    'transaction_count' => 0,
                    'expire_date' => null,
                ];
            }

            // Load rank info
            $currentRank = $buyerLoyalty->rank;
            $allRanks = LoyaltyRank::orderBy('min_transactions', 'asc')->get();
            $nextRank = $allRanks->where('min_transactions', '>', $currentRank->min_transactions)
                ->sortBy('min_transactions')
                ->first();

            return [
                'current_rank' => $currentRank,
                'next_rank' => $nextRank,
                'transaction_count' => $buyerLoyalty->transaction_count,
                'expire_date' => $buyerLoyalty->expire_date ? Carbon::parse($buyerLoyalty->expire_date) : null,
            ];
        }

        // Buyer normal: proses seperti biasa
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
            if ($currentTransactionCount >= 1 && $simulatedExpireDate !== null && $transactionDate->gt($simulatedExpireDate)) {
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

                    // Dapatkan rank yang SUDAH ACHIEVED (rank setelah transaksi terakhir selesai)
                    // currentTransactionCount = jumlah transaksi yang sudah selesai = rank yang sudah dicapai
                    $currentAchievedRank = $allRanks->where('min_transactions', '<=', $currentTransactionCount)
                        ->sortByDesc('min_transactions')
                        ->first();

                    // Cari rank di BAWAH rank yang sudah achieved
                    $downgradedRank = null;
                    if ($currentAchievedRank) {
                        $downgradedRank = $allRanks->where('min_transactions', '<', $currentAchievedRank->min_transactions)
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
            if ($currentTransactionCount >= 1) {
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

        // Fallback jika tidak ada rank
        $lowestRank = $allRanks->first();

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

        // Config Store Closure
        $storeClosureStart = Carbon::parse('2025-07-11');
        $storeClosureEnd = Carbon::parse('2025-09-19');

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

            // LOGIC EXPIRED CHECKER (Strict Mode)
            // PERBAIKAN: Check expired dimulai sejak count >= 1 (Transaksi pertama sudah punya expired)

            $isExpiredInThisTransaction = false;
            $rankBeforeLoop = $allRanks->where('min_transactions', '<=', $currentTransactionCount)
                ->sortByDesc('min_transactions')->first();
            if (!$rankBeforeLoop) $rankBeforeLoop = $lowestRank;

            while ($currentTransactionCount >= 1 && $simulatedExpireDate !== null && $transactionDate->gt($simulatedExpireDate)) {

                // 1. Cek Grace Period
                $isGracePeriod = false;
                if ($simulatedExpireDate->between($storeClosureStart, $storeClosureEnd)) {
                    $sisaHari = $storeClosureStart->diffInDays($simulatedExpireDate, false);
                    if ($sisaHari < 0) $sisaHari = 0;

                    $originalExpireDate = $simulatedExpireDate->copy();
                    $simulatedExpireDate = $storeClosureEnd->copy()->addDays(1 + $sisaHari)->endOfDay();

                    // Log grace period info
                    if (!$isExpiredInThisTransaction) {
                        $transactionDetail['grace_period_applied'] = true;
                        $transactionDetail['original_expire_date_before_grace'] = $originalExpireDate->format('d M Y H:i:s');
                        $transactionDetail['extended_expire_date'] = $simulatedExpireDate->format('d M Y H:i:s');
                    }

                    // Jika selamat, break loop
                    if (!$transactionDate->gt($simulatedExpireDate)) {
                        break;
                    }
                }

                // 2. Logic Downgrade
                $isExpiredInThisTransaction = true;
                $expiredCount++;

                $currentAchievedRank = $allRanks->where('min_transactions', '<=', $currentTransactionCount)
                    ->sortByDesc('min_transactions')->first();

                $downgradedRank = null;
                if ($currentAchievedRank) {
                    $downgradedRank = $allRanks->where('min_transactions', '<', $currentAchievedRank->min_transactions)
                        ->sortByDesc('min_transactions')->first();
                }
                if (!$downgradedRank) $downgradedRank = $lowestRank;

                // Safety break (Prevent Infinite Loop)
                if ($currentAchievedRank && $downgradedRank->id == $currentAchievedRank->id) {
                    $currentTransactionCount = $downgradedRank->min_transactions;
                    $simulatedExpireDate = null;
                    break;
                }

                // Apply Downgrade
                $transactionDetail['downgraded_from_rank'] = $currentAchievedRank->rank ?? 'Unknown';
                $transactionDetail['downgraded_to_rank'] = $downgradedRank->rank;

                $currentTransactionCount = $downgradedRank->min_transactions;

                // 3. Next Expire Check (Chasing)
                if ($downgradedRank->expired_weeks > 0) {
                    $simulatedExpireDate = $simulatedExpireDate->copy()->addWeeks($downgradedRank->expired_weeks)->endOfDay();
                } else {
                    $simulatedExpireDate = null;
                }
            }

            if ($isExpiredInThisTransaction) {
                $transactionDetail['expired_status'] = 'EXPIRED';
                $transactionDetail['expired_reason'] = "Transaction date > Expire date";
            }

            // INCREMENT NORMAL
            $currentTransactionCount++;
            $transactionDetail['count_after'] = $currentTransactionCount;

            // Determine Rank After
            $rankAfter = $allRanks->where('min_transactions', '<=', $currentTransactionCount)
                ->sortByDesc('min_transactions')->first();
            if (!$rankAfter) $rankAfter = $lowestRank;

            $transactionDetail['rank_after'] = $rankAfter->rank;
            $transactionDetail['expired_weeks'] = $rankAfter->expired_weeks;

            // UPDATE EXPIRE DATE (FUTURE)
            // Perbaikan: Hitung expired date baru jika count >= 1
            if ($currentTransactionCount >= 1) {
                $rankForExpire = $rankAfter; // Expired date mengikuti rank baru (Upgrade logic)

                if ($rankForExpire->expired_weeks > 0) {
                    $simulatedExpireDate = $transactionDate->copy()->addWeeks($rankForExpire->expired_weeks)->endOfDay();

                    // Grace period check for future date
                    if ($simulatedExpireDate->between($storeClosureStart, $storeClosureEnd)) {
                        $sisaHari = $storeClosureStart->diffInDays($simulatedExpireDate, false);
                        if ($sisaHari < 0) $sisaHari = 0;
                        $simulatedExpireDate = $storeClosureEnd->copy()->addDays(1 + $sisaHari)->endOfDay();
                    }

                    $transactionDetail['expire_date_action'] = 'UPDATE';
                    $transactionDetail['active_rank_for_expired'] = $rankForExpire->rank;
                    $transactionDetail['expire_date_calculation'] = "{$transactionDate->format('d M Y')} + {$rankForExpire->expired_weeks} weeks";
                    $transactionDetail['expire_date_after'] = $simulatedExpireDate->format('d M Y H:i:s');
                } else {
                    $simulatedExpireDate = null;
                    $transactionDetail['expire_date_action'] = 'NONE';
                    $transactionDetail['expire_date_after'] = null;
                }
            } else {
                $transactionDetail['expire_date_action'] = 'NONE';
                $transactionDetail['expire_date_after'] = null;
            }

            $result['transactions'][] = $transactionDetail;
        }

        // Summary
        $finalRank = $allRanks->where('min_transactions', '<=', $currentTransactionCount)
            ->sortByDesc('min_transactions')->first();
        if (!$finalRank) $finalRank = $lowestRank;

        $result['summary']['total_expired_events'] = $expiredCount;
        $result['summary']['final_count'] = $currentTransactionCount;
        $result['summary']['final_rank'] = $finalRank->rank;
        $result['summary']['final_expire_date'] = $simulatedExpireDate ? $simulatedExpireDate->format('d M Y H:i:s') : null;

        // Check apakah buyer ini special, jika ya tambahkan summary_special
        if (in_array($buyer_id, $listBuyerIdSpecial)) {
            $result['is_special_buyer'] = true;

            // Ambil data dari buyer_loyalties untuk summary special
            $buyerLoyalty = BuyerLoyalty::where('buyer_id', $buyer_id)->first();

            if ($buyerLoyalty) {
                $currentRank = $buyerLoyalty->rank;
                $allRanks = LoyaltyRank::orderBy('min_transactions', 'asc')->get();
                $nextRank = $allRanks->where('min_transactions', '>', $currentRank->min_transactions)
                    ->sortBy('min_transactions')
                    ->first();

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
                $result['summary_special'] = [
                    'note' => 'Buyer special tidak terkena expired, tapi belum ada data di buyer_loyalties table',
                    'total_expired_events' => 0,
                    'final_count' => 0,
                    'final_rank' => 'New Buyer',
                    'final_expire_date' => null,
                    'next_rank' => null,
                    'last_upgrade_date' => null,
                ];
            }
        }

        return $result;
    }
}