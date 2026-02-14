<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\LoyaltyRank;
use App\Models\BuyerLoyalty;
use Illuminate\Http\Request;
use App\Models\BuyerLoyaltyHistory;
use Illuminate\Support\Facades\Log;
use App\Http\Resources\ResponseResource;
use App\Models\SaleDocument;
use App\Models\Buyer;

class BuyerLoyaltyController extends Controller
{
    /**
     * Konfigurasi Periode Libur Toko
     */
    public static function getClosurePeriods()
    {
        return [
            ['start' => '2025-07-11', 'end' => '2025-09-19'],
            ['start' => '2026-02-01', 'end' => '2026-02-11'],
        ];
    }

    /**
     * Cek dan perpanjang expired date jika terkena masa libur (Logika Overlap)
     */
    public static function checkAndExtendGracePeriod($expireDate, $transactionDate = null)
    {
        if ($expireDate === null) return null;

        $periods = self::getClosurePeriods();
        $finalDate = $expireDate->copy();

        // Normalisasi Tanggal Transaksi
        $trxDate = $transactionDate ? Carbon::parse($transactionDate)->startOfDay() : null;

        foreach ($periods as $period) {
            $start = Carbon::parse($period['start'])->startOfDay();
            $end = Carbon::parse($period['end'])->endOfDay();

            if ($trxDate && $end->lt($trxDate)) {
                continue;
            }

            if ($start->gt($expireDate)) {
                continue;
            }

            $duration = $start->diffInDays($end) + 1;
            $finalDate->addDays($duration);
        }

        return $finalDate;
    }

    /**
     * CRON JOB: Menurunkan rank buyer yang sudah expired.
     */
    public function expireBuyerLoyalty()
    {
        Log::channel('buyer_loyalty')->info('=== START: Expire Buyer Loyalty Process ===', [
            'timestamp' => Carbon::now('Asia/Jakarta')->toDateTimeString()
        ]);

        $buyerLoyalties = BuyerLoyalty::whereNotNull('expire_date')
            ->where('expire_date', '<', Carbon::now('Asia/Jakarta'))
            ->get();
        $processed = 0;

        foreach ($buyerLoyalties as $buyerLoyalty) {
            try {
                $currentRankName = $buyerLoyalty->rank ? $buyerLoyalty->rank->rank : 'Unknown';
            } catch (\Exception $e) {
                $currentRankName = 'Unknown';
            }

            // Cari Rank paling dasar (New Buyer, min_transactions 0)
            $newBuyerRank = LoyaltyRank::where('min_transactions', 0)->first();
            if (!$newBuyerRank) {
                $newBuyerRank = LoyaltyRank::orderBy('min_transactions', 'asc')->first();
            }

            if ($newBuyerRank) {
                try {
                    // Reset total ke New Buyer (Count 0)
                    $buyerLoyalty->update([
                        'loyalty_rank_id' => $newBuyerRank->id,
                        'transaction_count' => 0,
                        'last_upgrade_date' => Carbon::now('Asia/Jakarta'),
                        'expire_date' => null,
                    ]);

                    BuyerLoyaltyHistory::create([
                        'buyer_id' => $buyerLoyalty->buyer_id,
                        'previous_rank' => $currentRankName,
                        'current_rank' => $newBuyerRank->rank,
                        'note' => 'Rank expired, downgraded from ' . $currentRankName . ' to ' . $newBuyerRank->rank . ' (reset to 0 transactions)',
                        'created_at' => Carbon::now('Asia/Jakarta'),
                        'updated_at' => Carbon::now('Asia/Jakarta'),
                    ]);

                    $processed++;
                } catch (\Exception $e) {
                    Log::channel('buyer_loyalty')->error('Error updating buyer loyalty', [
                        'buyer_id' => $buyerLoyalty->buyer_id,
                        'error' => $e->getMessage()
                    ]);
                }
            }
        }

        Log::channel('buyer_loyalty')->info('=== END: Expire Buyer Loyalty Process ===', [
            'total_processed' => $processed,
        ]);

        return new ResponseResource(true, "Berhasil memproses $processed buyer loyalty yang expired", [
            'processed_count' => $processed,
            'total_expired' => $buyerLoyalties->count(),
        ]);
    }

    public function recalculateBuyerLoyalty()
    {
        $buyerIds = SaleDocument::where('created_at', '>=', '2025-06-01')
            ->where('status_document_sale', 'selesai')
            ->where('total_display_document_sale', '>=', 5000000)
            ->distinct()
            ->pluck('buyer_id_document_sale');

        $processed = 0;
        $allRanks = LoyaltyRank::orderBy('min_transactions', 'asc')->get();
        $lowestRank = $allRanks->first(); // New Buyer (0)

        foreach ($buyerIds as $buyerId) {
            $buyer = Buyer::find($buyerId);
            if (!$buyer) continue;

            $transactions = SaleDocument::where('buyer_id_document_sale', $buyerId)
                ->where('status_document_sale', 'selesai')
                ->where('total_display_document_sale', '>=', 5000000)
                ->where('created_at', '>=', '2025-06-01')
                ->orderBy('created_at', 'asc')
                ->get(['id', 'created_at', 'total_display_document_sale']);

            if ($transactions->count() == 0) continue;

            // State Awal
            $currentTransactionCount = 0;
            $simulatedExpireDate = null;
            $currentRank = $lowestRank;
            $lastTransactionDate = null;

            foreach ($transactions as $transaction) {
                $transactionDate = Carbon::parse($transaction->created_at);
                $lastTransactionDate = $transactionDate;

                while ($currentTransactionCount > 0 && $simulatedExpireDate !== null) {
                    // Jika transaksi dilakukan SEBELUM expired, maka AMAN
                    if (!$transactionDate->gt($simulatedExpireDate)) {
                        break;
                    }

                    $downgradedRank = $allRanks->where('min_transactions', '<', $currentRank->min_transactions)
                        ->sortByDesc('min_transactions')->first();

                    if (!$downgradedRank || $downgradedRank->min_transactions == 0) {
                        // Reset ke New Buyer
                        $downgradedRank = $lowestRank;
                        $currentRank = $downgradedRank;
                        $currentTransactionCount = 0; 
                        $simulatedExpireDate = null; 
                        break; 
                    }

                    // Turun Rank
                    $currentRank = $downgradedRank;
                    $currentTransactionCount = $downgradedRank->min_transactions;

                    // Hitung Expired Baru (Hasil Downgrade)
                    if ($downgradedRank->expired_weeks > 0) {
                        $rawExpire = $simulatedExpireDate->copy()->addWeeks($downgradedRank->expired_weeks)->endOfDay();
                        $simulatedExpireDate = self::checkAndExtendGracePeriod($rawExpire, $lastTransactionDate);
                    } else {
                        $simulatedExpireDate = null;
                    }
                }

                $rankBeforeTransaction = $currentRank; // Simpan rank LAMA
                $currentTransactionCount++;

                if ($currentTransactionCount == 1) {
                    $newRank = $lowestRank;
                } else {
                    $newRank = $allRanks->where('min_transactions', '<=', $currentTransactionCount)
                        ->sortByDesc('min_transactions')->first();
                    if (!$newRank) $newRank = $lowestRank;
                }
                
                $currentRank = $newRank;

                $rankForCalculation = $rankBeforeTransaction;

                // Kecuali rank lama New Buyer, gunakan rank baru
                if ($rankForCalculation->expired_weeks <= 0) {
                    $rankForCalculation = $newRank;
                }

                // Hitung hanya jika Count >= 1 dan Rank terpilih punya expired > 0
                if ($currentTransactionCount >= 1 && $rankForCalculation->expired_weeks > 0) {
                    $rawExpire = $transactionDate->copy()->addWeeks($rankForCalculation->expired_weeks)->endOfDay();
                    $simulatedExpireDate = self::checkAndExtendGracePeriod($rawExpire, $transactionDate);
                } else {
                     if ($rankForCalculation->expired_weeks <= 0) {
                        $simulatedExpireDate = null;
                     }
                }
            }

            $now = Carbon::now('Asia/Jakarta');

            while ($currentTransactionCount > 0 && $simulatedExpireDate !== null) {
                if (!$now->gt($simulatedExpireDate)) {
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

            // --- SAVE TO DB ---
            $buyerLoyalty = BuyerLoyalty::where('buyer_id', $buyer->id)->first();
            $dataToUpdate = [
                'loyalty_rank_id' => $currentRank->id,
                'transaction_count' => $currentTransactionCount,
                'last_upgrade_date' => Carbon::now('Asia/Jakarta'),
                'expire_date' => $simulatedExpireDate,
            ];

            if ($buyerLoyalty) {
                $buyerLoyalty->update($dataToUpdate);
            } else {
                $dataToUpdate['buyer_id'] = $buyer->id;
                BuyerLoyalty::create($dataToUpdate);
            }
            $processed++;
        }

        return new ResponseResource(true, "Berhasil recalculate {$processed} buyer loyalty", ['processed' => $processed]);
    }

    /**
     * TRACE EXPIRED: API untuk debugging history.
     */
    public function traceExpired(Request $request)
    {
        $request->validate([
            'buyer_id' => 'required|integer',
            'sale_document_id' => 'nullable|integer',
        ]);
        $buyerId = $request->input('buyer_id');
        $saleDocumentId = $request->input('sale_document_id');

        $buyer = Buyer::find($buyerId);
        if (!$buyer) {
            return (new ResponseResource(false, "Buyer tidak ditemukan", null))->response()->setStatusCode(404);
        }

        $listBuyerIdSpecial = [496];
        $query = SaleDocument::where('buyer_id_document_sale', $buyerId)
            ->where('status_document_sale', 'selesai')
            ->where('total_display_document_sale', '>=', 5000000)
            ->where('created_at', '>=', '2025-06-01');

        if ($saleDocumentId) {
            $specificDocument = SaleDocument::find($saleDocumentId);
            if ($specificDocument) {
                $query->where('created_at', '<=', $specificDocument->created_at);
            }
        }

        $transactions = $query->orderBy('created_at', 'asc')->get();
        $allRanks = LoyaltyRank::orderBy('min_transactions', 'asc')->get();
        $lowestRank = $allRanks->first();

        $result = [
            'buyer_id' => $buyerId,
            'transactions' => [],
            'summary' => []
        ];

        $simulatedExpireDate = null;
        $currentTransactionCount = 0;
        $expiredCount = 0;
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

            // Loop Downgrade
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
                        $simulatedExpireDate = self::checkAndExtendGracePeriod($rawExpire, $lastTransactionDate);
                    } else {
                        $simulatedExpireDate = null;
                    }
                }
            }

            // Upgrade
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

            // Set Expire
            $rankForCalc = $rankBeforeTransaction;
            if ($rankForCalc->expired_weeks <= 0) {
                $rankForCalc = $rankAfter;
            }

            if ($currentTransactionCount >= 1 && $rankForCalc->expired_weeks > 0) {
                $calcDate = $transactionDate->copy()->addWeeks($rankForCalc->expired_weeks)->endOfDay();
                $simulatedExpireDate = self::checkAndExtendGracePeriod($calcDate, $transactionDate);
                $transactionDetail['expire_date_action'] = 'UPDATE';
                $transactionDetail['expire_date_after'] = $simulatedExpireDate->format('d M Y H:i:s');
            } else {
                 if ($rankForCalc->expired_weeks <= 0) {
                    $simulatedExpireDate = null;
                 }
                 $transactionDetail['expire_date_action'] = 'NONE';
                 $transactionDetail['expire_date_after'] = null;
            }

            $result['transactions'][] = $transactionDetail;
        }

        $finalRank = $allRanks->where('min_transactions', '<=', $currentTransactionCount)->sortByDesc('min_transactions')->first();
        if (!$finalRank) $finalRank = $lowestRank;

        $result['summary']['final_rank'] = $finalRank->rank;
        $result['summary']['final_count'] = $currentTransactionCount;
        $result['summary']['final_expire_date'] = $simulatedExpireDate ? $simulatedExpireDate->format('d M Y H:i:s') : null;

        return new ResponseResource(true, "Trace expired history untuk {$buyer->name_buyer}", $result);
    }

    public function infoTransaction(Request $request)
    {
        
        $request->validate([
            'buyer_id' => 'required|integer',
            'current_transaction_date' => 'nullable|date',
        ]);
        $buyerId = $request->input('buyer_id');
        $date = $request->input('current_transaction_date');
        $buyer = Buyer::find($buyerId);

        
        $infoResult = \App\Services\LoyaltyService::getCurrentRankInfo($buyerId, $date);
        
        return new ResponseResource(true, "Info transaction untuk {$buyer->name_buyer}", $infoResult);
    }
}