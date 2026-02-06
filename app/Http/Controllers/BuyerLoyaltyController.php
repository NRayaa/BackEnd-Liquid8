<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\LoyaltyRank;
use App\Models\BuyerLoyalty;
use Illuminate\Http\Request;
use App\Models\BuyerLoyaltyHistory;
use Illuminate\Support\Facades\Log;
use App\Http\Resources\ResponseResource;
use App\Services\LoyaltyService;

class BuyerLoyaltyController extends Controller
{

    public function expireBuyerLoyalty()
    {
        Log::channel('buyer_loyalty')->info('=== START: Expire Buyer Loyalty Process ===', [
            'timestamp' => Carbon::now('Asia/Jakarta')->toDateTimeString()
        ]);

        $buyerLoyalties = BuyerLoyalty::whereNotNull('expire_date')
            ->where('expire_date', '<', Carbon::now('Asia/Jakarta'))
            ->get();
        $processed = 0;

        Log::channel('buyer_loyalty')->info('Found expired buyer loyalties', [
            'total_expired' => $buyerLoyalties->count(),
            'current_time' => Carbon::now('Asia/Jakarta')->toDateTimeString()
        ]);

        foreach ($buyerLoyalties as $buyerLoyalty) {
            try {
                $currentRankName = $buyerLoyalty->rank ? $buyerLoyalty->rank->rank : 'Unknown';
            } catch (\Exception $e) {
                Log::channel('buyer_loyalty')->error('Error accessing rank relationship', [
                    'buyer_id' => $buyerLoyalty->buyer_id,
                    'error' => $e->getMessage()
                ]);
                $currentRankName = 'Unknown';
                continue;
            }

            $newBuyerRank = LoyaltyRank::where('min_transactions', 0)->first();

            if ($newBuyerRank) {
                try {
                    $updateResult = $buyerLoyalty->update([
                        'loyalty_rank_id' => $newBuyerRank->id,
                        'transaction_count' => 0,
                        'last_upgrade_date' => Carbon::now('Asia/Jakarta'),
                        'expire_date' => null,
                    ]);

                    $historyResult = BuyerLoyaltyHistory::create([
                        'buyer_id' => $buyerLoyalty->buyer_id,
                        'previous_rank' => $currentRankName,
                        'current_rank' => $newBuyerRank->rank,
                        'note' => 'Rank expired, downgraded from ' . $currentRankName . ' to ' . $newBuyerRank->rank . ' (reset to 0 transactions)',
                        'created_at' => Carbon::now('Asia/Jakarta'),
                        'updated_at' => Carbon::now('Asia/Jakarta'),
                    ]);

                    Log::channel('buyer_loyalty')->info('Buyer downgraded to New Buyer', [
                        'buyer_id' => $buyerLoyalty->buyer_id,
                        'previous_rank' => $currentRankName,
                        'new_rank' => $newBuyerRank->rank,
                        'transaction_count_reset_to' => 0
                    ]);

                    $processed++;
                } catch (\Exception $e) {
                    Log::channel('buyer_loyalty')->error('Error updating buyer loyalty', [
                        'buyer_id' => $buyerLoyalty->buyer_id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }
        }

        Log::channel('buyer_loyalty')->info('=== END: Expire Buyer Loyalty Process ===', [
            'total_processed' => $processed,
            'total_expired_found' => $buyerLoyalties->count(),
            'success_rate' => $buyerLoyalties->count() > 0 ? ($processed / $buyerLoyalties->count()) * 100 . '%' : '0%',
        ]);

        return new ResponseResource(true, "Berhasil memproses $processed buyer loyalty yang expired", [
            'processed_count' => $processed,
            'total_expired' => $buyerLoyalties->count(),
        ]);
    }

    public function recalculateBuyerLoyalty()
    {
        $buyerIds = \App\Models\SaleDocument::where('created_at', '>=', '2025-06-01')
            ->where('status_document_sale', 'selesai')
            ->where('total_display_document_sale', '>=', 5000000)
            ->distinct()
            ->pluck('buyer_id_document_sale');

        $processed = 0;
        $allRanks = LoyaltyRank::orderBy('min_transactions', 'asc')->get();
        $lowestRank = $allRanks->first();

        foreach ($buyerIds as $buyerId) {
            $buyer = \App\Models\Buyer::find($buyerId);
            if (!$buyer) continue;

            $transactions = \App\Models\SaleDocument::where('buyer_id_document_sale', $buyerId)
                ->where('status_document_sale', 'selesai')
                ->where('total_display_document_sale', '>=', 5000000)
                ->where('created_at', '>=', '2025-06-01')
                ->orderBy('created_at', 'asc')
                ->get(['id', 'created_at', 'total_display_document_sale']);

            if ($transactions->count() == 0) continue;

            $currentTransactionCount = 0;
            $simulatedExpireDate = null;
            $currentRank = $lowestRank;

            // VARIABEL BARU: Untuk menyimpan tanggal transaksi terakhir
            $lastTransactionDate = null;

            foreach ($transactions as $index => $transaction) {
                $transactionDate = Carbon::parse($transaction->created_at);
                
                // SIMPAN TANGGAL TRANSAKSI INI
                $lastTransactionDate = $transactionDate;

                // Loop Downgrade (Cek expired sebelum transaksi ini)
                while ($currentTransactionCount >= 2 && $simulatedExpireDate !== null) {
                    
                    // Gunakan tanggal transaksi ini sebagai acuan
                    $extendedDate = LoyaltyService::checkAndExtendGracePeriod($simulatedExpireDate, $transactionDate);
                    
                    if ($extendedDate->ne($simulatedExpireDate)) {
                        $simulatedExpireDate = $extendedDate;
                    }

                    if (!$transactionDate->gt($simulatedExpireDate)) {
                        break; 
                    }
                    
                    // Proses Downgrade
                    $downgradedRank = $allRanks->where('min_transactions', '<', $currentRank->min_transactions)
                        ->sortByDesc('min_transactions')->first();

                    if (!$downgradedRank) $downgradedRank = $lowestRank;

                    if ($currentRank->id == $downgradedRank->id) {
                        $currentTransactionCount = $downgradedRank->min_transactions;
                        $currentRank = $downgradedRank;
                        $simulatedExpireDate = null;
                        break;
                    }

                    $currentRank = $downgradedRank;
                    $currentTransactionCount = $downgradedRank->min_transactions;

                    // Hitung expire dari rank baru (Extend pakai tanggal transaksi ini)
                    if ($downgradedRank->expired_weeks > 0) {
                        $rawExpire = $simulatedExpireDate->copy()->addWeeks($downgradedRank->expired_weeks)->endOfDay();
                        $simulatedExpireDate = LoyaltyService::checkAndExtendGracePeriod($rawExpire, $transactionDate);
                    } else {
                        $simulatedExpireDate = null;
                    }
                }

                $rankBeforeTransaction = $currentRank;
                $currentTransactionCount++;

                // Naik Rank
                if ($currentTransactionCount == 1) {
                    $newRank = $lowestRank;
                } else {
                    $newRank = $allRanks->where('min_transactions', '<=', $currentTransactionCount)
                        ->sortByDesc('min_transactions')->first();
                    if (!$newRank) $newRank = $lowestRank;
                }

                // Set Expired Date Baru
                if ($currentTransactionCount >= 2) {
                    $rankForCalculation = $rankBeforeTransaction;
                    if ($rankForCalculation->expired_weeks <= 0) {
                        $rankForCalculation = $newRank;
                    }

                    if ($rankForCalculation->expired_weeks > 0) {
                        $rawExpire = $transactionDate->copy()->addWeeks($rankForCalculation->expired_weeks)->endOfDay();
                        // Extend pakai tanggal transaksi ini
                        $simulatedExpireDate = LoyaltyService::checkAndExtendGracePeriod($rawExpire, $transactionDate);
                    } else {
                        $simulatedExpireDate = null;
                    }
                } else {
                    $simulatedExpireDate = null;
                }
                $currentRank = $newRank;
            }

            $now = Carbon::now('Asia/Jakarta');
            
            while ($simulatedExpireDate !== null) {
                
                $extendedDate = LoyaltyService::checkAndExtendGracePeriod($simulatedExpireDate, $lastTransactionDate);
                
                if ($extendedDate->ne($simulatedExpireDate)) {
                    $simulatedExpireDate = $extendedDate;
                }

                // Baru bandingkan hasilnya dengan $now
                if (!$now->gt($simulatedExpireDate)) {
                    break;
                }

                // Proses Downgrade
                $downgradedRank = $allRanks->where('min_transactions', '<', $currentRank->min_transactions)
                    ->sortByDesc('min_transactions')->first();

                if (!$downgradedRank) $downgradedRank = $lowestRank;

                if ($currentRank->id == $downgradedRank->id) {
                    $currentTransactionCount = $downgradedRank->min_transactions;
                    $currentRank = $downgradedRank;
                    $simulatedExpireDate = null;
                    break;
                }

                $currentRank = $downgradedRank;
                $currentTransactionCount = $downgradedRank->min_transactions;
                if ($currentTransactionCount < 0) $currentTransactionCount = 0;

                if ($downgradedRank->expired_weeks > 0) {
                    $rawExpire = $simulatedExpireDate->copy()->addWeeks($downgradedRank->expired_weeks)->endOfDay();
                    // Extend pakai lastTransactionDate
                    $simulatedExpireDate = LoyaltyService::checkAndExtendGracePeriod($rawExpire, $lastTransactionDate);
                } else {
                    $simulatedExpireDate = null;
                }
            }

            // Save ke DB
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

    public function traceExpired(Request $request)
    {
        $request->validate([
            'buyer_id' => 'required|integer',
            'sale_document_id' => 'nullable|integer',
        ]);

        $buyerId = $request->input('buyer_id');
        $saleDocumentId = $request->input('sale_document_id');

        $buyer = \App\Models\Buyer::find($buyerId);
        if (!$buyer) {
            return (new ResponseResource(false, "Buyer dengan ID {$buyerId} tidak ditemukan!", null))
                ->response()
                ->setStatusCode(404);
        }

        $traceResult = \App\Services\LoyaltyService::traceExpiredHistory($buyerId, $saleDocumentId);

        return new ResponseResource(true, "Trace expired history untuk buyer {$buyer->name_buyer}", $traceResult);
    }

    public function infoTransaction(Request $request)
    {
        $request->validate([
            'buyer_id' => 'required|integer',
            'current_transaction_date' => 'nullable|date',
        ]);

        $buyerId = $request->input('buyer_id');
        $currentTransactionDate = $request->input('current_transaction_date');

        $buyer = \App\Models\Buyer::find($buyerId);

        $infoResult = \App\Services\LoyaltyService::getCurrentRankInfo($buyerId, $currentTransactionDate);

        return new ResponseResource(true, "Info transaction untuk buyer {$buyer->name_buyer}", $infoResult);
    }
}
