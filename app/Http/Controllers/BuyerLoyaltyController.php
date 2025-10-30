<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use App\Models\LoyaltyRank;
use App\Models\BuyerLoyalty;
use Illuminate\Http\Request;
use App\Models\BuyerLoyaltyHistory;
use Illuminate\Support\Facades\Log;
use App\Http\Resources\ResponseResource;

class BuyerLoyaltyController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(BuyerLoyalty $buyerLoyalty)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(BuyerLoyalty $buyerLoyalty)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, BuyerLoyalty $buyerLoyalty)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(BuyerLoyalty $buyerLoyalty)
    {
        //
    }

    public function expireBuyerLoyalty()
    {
        // Log::channel('buyer_loyalty')->info('=== START: Expire Buyer Loyalty Process ===', [
        //     'timestamp' => Carbon::now('Asia/Jakarta')->toDateTimeString()
        // ]);

        $buyerLoyalties = BuyerLoyalty::whereNotNull('expire_date')
            ->where('expire_date', '<', Carbon::now('Asia/Jakarta'))
            ->get();
        $processed = 0;

        // Log::channel('buyer_loyalty')->info('Found expired buyer loyalties', [
        //     'total_expired' => $buyerLoyalties->count(),
        //     'current_time' => Carbon::now('Asia/Jakarta')->toDateTimeString()
        // ]);

        foreach ($buyerLoyalties as $buyerLoyalty) {

            $currentTransaction = $buyerLoyalty->transaction_count;

            // Safe access untuk rank relationship
            try {
                $currentRankName = $buyerLoyalty->rank ? $buyerLoyalty->rank->rank : 'Unknown';
            } catch (\Exception $e) {
                // Log::channel('buyer_loyalty')->error('Error accessing rank relationship', [
                //     'buyer_id' => $buyerLoyalty->buyer_id,
                //     'error' => $e->getMessage()
                // ]);
                $currentRankName = 'Unknown';
                continue; // Skip this buyer if we can't get rank info
            }


            $lowerRank = LoyaltyRank::where('min_transactions', '<', $currentTransaction)
                ->orderBy('min_transactions', 'desc')
                ->first();

            if ($lowerRank) {
                $newExpireDate = Carbon::now('Asia/Jakarta')->addWeeks($lowerRank->expired_weeks)->endOfDay();

                try {
                    $updateResult = $buyerLoyalty->update([
                        'loyalty_rank_id' => $lowerRank->id,
                        'transaction_count' => $lowerRank->min_transactions,
                        'last_upgrade_date' => Carbon::now('Asia/Jakarta'),
                        'expire_date' => $newExpireDate,
                    ]);

                    $historyResult = BuyerLoyaltyHistory::create([
                        'buyer_id' => $buyerLoyalty->buyer_id,
                        'previous_rank' => $currentRankName,
                        'current_rank' => $lowerRank->rank,
                        'note' => 'Rank expired, downgraded from ' . $currentRankName . ' to ' . $lowerRank->rank,
                        'created_at' => Carbon::now('Asia/Jakarta'),
                        'updated_at' => Carbon::now('Asia/Jakarta'),
                    ]);


                    $processed++;
                } catch (\Exception $e) {
                    Log::error('Error updating buyer loyalty', [
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

        // Ambil semua buyer_id yang punya transaksi >= 5jt sejak 1 Oktober 2025 dari SaleDocument
        $buyerTransactions = \App\Models\SaleDocument::where('created_at', '>=', '2025-10-01')
            ->where('total_display_document_sale', '>=', 5000000)
            ->selectRaw('buyer_id_document_sale, COUNT(*) as transaction_count')
            ->groupBy('buyer_id_document_sale')
            ->get();

        $processed = 0;

        foreach ($buyerTransactions as $buyerTransaction) {
            $buyerId = $buyerTransaction->buyer_id_document_sale;
            $validTransactionCount = $buyerTransaction->transaction_count;

            // Ambil data buyer
            $buyer = \App\Models\Buyer::find($buyerId);
            if (!$buyer) {
                continue; // Skip jika buyer tidak ditemukan
            }

            // Cari rank yang sesuai berdasarkan jumlah transaksi
            if ($validTransactionCount == 1) {
                // Jika hanya 1 transaksi, tetap masuk New Buyer
                $appropriateRank = LoyaltyRank::where('min_transactions', 0)->first();
            } else {
                // Jika 2+ transaksi, baru masuk ke rank yang sesuai
                $appropriateRank = LoyaltyRank::where('min_transactions', '<=', $validTransactionCount)
                    ->orderBy('min_transactions', 'desc')
                    ->first();

                if (!$appropriateRank) {
                    // Fallback ke New Buyer jika tidak ada rank yang cocok
                    $appropriateRank = LoyaltyRank::where('min_transactions', 0)->first();
                }
            }

            // Hitung expire_date akumulatif berdasarkan setiap transaksi
            $totalWeeksToAdd = 0;
            $startDate = Carbon::parse('2025-10-01');

            // Get semua ranks untuk referensi
            $allRanks = LoyaltyRank::orderBy('min_transactions', 'asc')->get();

            for ($transaction = 1; $transaction <= $validTransactionCount; $transaction++) {
                // Tentukan rank yang berlaku untuk transaksi ke-n
                $rankForTransaction = $allRanks->where('min_transactions', '<=', $transaction)
                    ->sortByDesc('min_transactions')
                    ->first();

                if ($rankForTransaction && $rankForTransaction->expired_weeks > 0) {
                    $totalWeeksToAdd += $rankForTransaction->expired_weeks;
                }
            }

            // Calculate expire_date
            $expireDate = $totalWeeksToAdd > 0 ? $startDate->copy()->addWeeks($totalWeeksToAdd)->endOfDay() : null;

            // Update atau create BuyerLoyalty
            $buyerLoyalty = BuyerLoyalty::where('buyer_id', $buyer->id)->first();

            if ($buyerLoyalty) {
                $oldRankName = $buyerLoyalty->rank ? $buyerLoyalty->rank->rank : 'Unknown';

                // Update existing
                $buyerLoyalty->update([
                    'loyalty_rank_id' => $appropriateRank->id,
                    'transaction_count' => $validTransactionCount,
                    'last_upgrade_date' => Carbon::now('Asia/Jakarta'),
                    'expire_date' => $expireDate,
                ]);

                // Log history jika rank berubah
                if ($oldRankName !== $appropriateRank->rank) {
                    BuyerLoyaltyHistory::create([
                        'buyer_id' => $buyer->id,
                        'previous_rank' => $oldRankName,
                        'current_rank' => $appropriateRank->rank,
                        'note' => "Rank recalculated from {$oldRankName} to {$appropriateRank->rank} based on {$validTransactionCount} transactions",
                        'created_at' => Carbon::now('Asia/Jakarta'),
                        'updated_at' => Carbon::now('Asia/Jakarta'),
                    ]);
                }
            } else {
                // Create new
                BuyerLoyalty::create([
                    'buyer_id' => $buyer->id,
                    'loyalty_rank_id' => $appropriateRank->id,
                    'transaction_count' => $validTransactionCount,
                    'last_upgrade_date' => Carbon::now('Asia/Jakarta'),
                    'expire_date' => $expireDate,
                ]);

                BuyerLoyaltyHistory::create([
                    'buyer_id' => $buyer->id,
                    'previous_rank' => null,
                    'current_rank' => $appropriateRank->rank,
                    'note' => "Initial rank assignment: {$appropriateRank->rank} based on {$validTransactionCount} transactions",
                    'created_at' => Carbon::now('Asia/Jakarta'),
                    'updated_at' => Carbon::now('Asia/Jakarta'),
                ]);
            }

            $processed++;

        
        }


        return new ResponseResource(true, "Berhasil recalculate {$processed} buyer loyalty", [
            'processed_count' => $processed,
            'total_buyers' => $buyerTransactions->count(),
        ]);
    }
}
