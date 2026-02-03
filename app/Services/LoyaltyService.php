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
            // ['start' => '2026-02-01', 'end' => '2026-02-14'],
        ];
    }

    public static function checkAndExtendGracePeriod($expireDate)
    {
        if ($expireDate === null) return null;

        $periods = self::getClosurePeriods();

        foreach ($periods as $period) {
            $start = Carbon::parse($period['start']);
            $end = Carbon::parse($period['end']);

            // Jika tanggal expired jatuh di antara tanggal tutup
            if ($expireDate->between($start, $end)) {
                // Hitung sisa hari dari mulai tutup sampai tanggal expired asli
                $daysRemaining = $start->diffInDays($expireDate, false);
                if ($daysRemaining < 0) $daysRemaining = 0;

                // Geser expired ke (Tanggal Buka + 1 Hari + Sisa Hari)
                return $end->copy()->addDays(1 + $daysRemaining)->endOfDay();
            }
        }

        return $expireDate;
    }

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

            // Helper untuk hitung expired
            $calculateExpiry = function ($weeks) {
                $date = Carbon::now('Asia/Jakarta')->addWeeks($weeks)->endOfDay();
                // Terapkan grace period check saat save
                return self::checkAndExtendGracePeriod($date);
            };

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

            while ($currentTransactionCount >= 2 && $simulatedExpireDate !== null && $transactionDate->gt($simulatedExpireDate)) {

                $extendedDate = self::checkAndExtendGracePeriod($simulatedExpireDate);

                // Jika tanggal berubah (berarti kena grace period)
                if ($extendedDate->ne($simulatedExpireDate)) {
                    $simulatedExpireDate = $extendedDate;

                    // Cek lagi apakah setelah diperpanjang masih expired atau sudah selamat
                    if (!$transactionDate->gt($simulatedExpireDate)) {
                        break;
                    }
                }

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
                    // Cek extension lagi untuk tanggal expired hasil downgrade
                    $newExpire = $simulatedExpireDate->copy()->addWeeks($activeRankForExpired->expired_weeks);
                    $simulatedExpireDate = self::checkAndExtendGracePeriod($newExpire);
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
                    // Cek grace period saat set expire date
                    $simulatedExpireDate = self::checkAndExtendGracePeriod($calcDate);
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
            'transaction_count' => $currentTransactionCount,
            'expire_date' => $expireDate,
        ];
    }

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

        // Ambil periode libur
        $closurePeriods = self::getClosurePeriods();

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

                $originalExpireDate = $simulatedExpireDate->copy();

                // check periode tutup
                foreach ($closurePeriods as $period) {
                    $storeClosureStart = Carbon::parse($period['start']);
                    $storeClosureEnd = Carbon::parse($period['end']);

                    if ($simulatedExpireDate->between($storeClosureStart, $storeClosureEnd)) {
                        $sisaHari = $storeClosureStart->diffInDays($simulatedExpireDate, false);
                        if ($sisaHari < 0) $sisaHari = 0;

                        // Extend tanggal
                        $simulatedExpireDate = $storeClosureEnd->copy()->addDays(1 + $sisaHari)->endOfDay();

                        $transactionDetail['grace_period_applied'] = true;
                        $transactionDetail['grace_period_name'] = "SO Period " . $storeClosureStart->format('M Y');
                        $transactionDetail['original_expire_date'] = $originalExpireDate->format('d M Y H:i:s');
                        $transactionDetail['sisa_hari'] = $sisaHari;
                        $transactionDetail['extended_expire_date'] = $simulatedExpireDate->format('d M Y H:i:s');

                        // Break karena asumsi 1 tanggal expired hanya kena 1 periode libur
                        break;
                    }
                }

                if ($transactionDate->gt($simulatedExpireDate)) {
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
                    if (isset($transactionDetail['grace_period_applied'])) {
                        $transactionDetail['expired_status'] = 'VALID (Grace Period)';
                    }
                }
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

                    // set expire date baru
                    $simulatedExpireDate = self::checkAndExtendGracePeriod($calcDate);

                    $transactionDetail['expire_date_action'] = 'UPDATE';
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
