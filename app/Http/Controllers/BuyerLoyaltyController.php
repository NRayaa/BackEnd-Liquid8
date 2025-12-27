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

            // Safe access untuk rank relationship
            try {
                $currentRankName = $buyerLoyalty->rank ? $buyerLoyalty->rank->rank : 'Unknown';
            } catch (\Exception $e) {
                Log::channel('buyer_loyalty')->error('Error accessing rank relationship', [
                    'buyer_id' => $buyerLoyalty->buyer_id,
                    'error' => $e->getMessage()
                ]);
                $currentRankName = 'Unknown';
                continue; // Skip this buyer if we can't get rank info
            }

            // Downgrade ke New Buyer (reset transaction_count ke 0, expire_date ke null)
            $newBuyerRank = LoyaltyRank::where('min_transactions', 0)->first();

            if ($newBuyerRank) {
                try {
                    $updateResult = $buyerLoyalty->update([
                        'loyalty_rank_id' => $newBuyerRank->id,
                        'transaction_count' => 0, // Reset ke 0
                        'last_upgrade_date' => Carbon::now('Asia/Jakarta'),
                        'expire_date' => null, // New Buyer tidak punya expired
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

    //gunakan sekali saja, sisanya pakai function expireBuyerLoyalty untuk donwgrade rank
    public function recalculateBuyerLoyalty()
    {
        // ... (Query Buyer sama seperti sebelumnya) ...
        $buyerIds = \App\Models\SaleDocument::where('created_at', '>=', '2025-06-01')
            ->where('status_document_sale', 'selesai')
            ->where('total_display_document_sale', '>=', 5000000)
            ->distinct()
            ->pluck('buyer_id_document_sale');

        $processed = 0;
        $allRanks = LoyaltyRank::orderBy('min_transactions', 'asc')->get();

        // Config Toko Tutup
        $storeClosureStart = Carbon::parse('2025-07-11');
        $storeClosureEnd = Carbon::parse('2025-09-19');

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

            // Variables Initialization
            $currentTransactionCount = 0;
            $simulatedExpireDate = null;
            $currentRank = $allRanks->where('min_transactions', 0)->first();

            foreach ($transactions as $index => $transaction) {
                $transactionDate = Carbon::parse($transaction->created_at);

                // Simpan Rank SEBELUM transaksi diproses (untuk perhitungan expired nanti)
                $rankBeforeTransaction = $currentRank;

                // --- 1. CEK EXPIRED ---
                if ($currentTransactionCount >= 2 && $simulatedExpireDate !== null && $transactionDate->gt($simulatedExpireDate)) {
                    // Cek Grace Period
                    if ($simulatedExpireDate->between($storeClosureStart, $storeClosureEnd)) {
                        $daysRemaining = $storeClosureStart->diffInDays($simulatedExpireDate, false);
                        if ($daysRemaining < 0) $daysRemaining = 0;
                        $simulatedExpireDate = $storeClosureEnd->copy()->addDays(1 + $daysRemaining)->endOfDay();
                    }

                    // Cek Ulang setelah Grace Period
                    if ($transactionDate->gt($simulatedExpireDate)) {
                        // EXPIRED CONFIRMED
                        // Downgrade Logic
                        $downgradedRank = $allRanks->where('min_transactions', '<', $currentRank->min_transactions)
                            ->sortByDesc('min_transactions')
                            ->first();

                        if (!$downgradedRank) {
                            $downgradedRank = $allRanks->where('min_transactions', 0)->first();
                        }

                        // Reset variabel state
                        $currentTransactionCount = $downgradedRank->min_transactions;
                        $currentRank = $downgradedRank;
                        $rankBeforeTransaction = $downgradedRank; // Update rank before juga karena sudah reset
                        $simulatedExpireDate = null;
                    }
                }

                // --- 2. INCREMENT & UPDATE RANK ---
                $currentTransactionCount++;

                $newRank = $allRanks->where('min_transactions', '<=', $currentTransactionCount)
                    ->sortByDesc('min_transactions')
                    ->first();

                if (!$newRank) $newRank = $allRanks->where('min_transactions', 0)->first();

                $currentRank = $newRank;

                // --- 3. SET EXPIRED DATE BERIKUTNYA ---
                if ($currentTransactionCount >= 2) {
                    // LOGIC FIX: Gunakan Rank SEBELUM transaksi (rankBeforeTransaction)
                    // Kecuali jika Rank Sebelum transaksi adalah New Buyer (weeks = 0), maka pakai Rank Baru

                    $rankForCalculation = $rankBeforeTransaction;
                    if ($rankForCalculation->expired_weeks <= 0) {
                        $rankForCalculation = $newRank;
                    }

                    if ($rankForCalculation->expired_weeks > 0) {
                        $simulatedExpireDate = $transactionDate->copy()->addWeeks($rankForCalculation->expired_weeks)->endOfDay();
                    }
                } else {
                    $simulatedExpireDate = null;
                }
            }

            $now = Carbon::now('Asia/Jakarta');
            if ($simulatedExpireDate !== null && $now->gt($simulatedExpireDate)) {
                $downgradedRank = $allRanks->where('min_transactions', '<', $currentRank->min_transactions)
                    ->sortByDesc('min_transactions')
                    ->first();
                if (!$downgradedRank) $downgradedRank = $allRanks->where('min_transactions', 0)->first();

                $currentTransactionCount = $downgradedRank->min_transactions;
                if ($currentTransactionCount == 0 && $downgradedRank->min_transactions == 0) $currentTransactionCount = 0; // Hard reset to 0 logic

                $currentRank = $downgradedRank;
                $simulatedExpireDate = null;
            }

            $buyerLoyalty = BuyerLoyalty::where('buyer_id', $buyer->id)->first();
            $dataToUpdate = [
                'loyalty_rank_id' => $currentRank->id,
                'transaction_count' => $currentTransactionCount,
                'last_upgrade_date' => Carbon::now('Asia/Jakarta'),
                'expire_date' => $simulatedExpireDate,
            ];

            if ($buyerLoyalty) {
                $oldRankName = $buyerLoyalty->rank ? $buyerLoyalty->rank->rank : 'Unknown';
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
     * Trace expired history untuk buyer tertentu
     * Menampilkan detail setiap transaksi dengan status expired
     * 
     * @param Request $request (buyer_id required, sale_document_id optional)
     * @return ResponseResource
     */
    public function traceExpired(Request $request)
    {
        $request->validate([
            'buyer_id' => 'required|integer',
            'sale_document_id' => 'nullable|integer',
        ]);

        $buyerId = $request->input('buyer_id');
        $saleDocumentId = $request->input('sale_document_id');

        // Cek apakah buyer ada
        $buyer = \App\Models\Buyer::find($buyerId);
        if (!$buyer) {
            return (new ResponseResource(false, "Buyer dengan ID {$buyerId} tidak ditemukan!", null))
                ->response()
                ->setStatusCode(404);
        }

        // Gunakan service untuk trace expired history
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

        // Cek apakah buyer ada
        $buyer = \App\Models\Buyer::find($buyerId);

        // Gunakan service untuk mendapatkan info rank
        $infoResult = \App\Services\LoyaltyService::getCurrentRankInfo($buyerId, $currentTransactionDate);

        return new ResponseResource(true, "Info transaction untuk buyer {$buyer->name_buyer}", $infoResult);
    }
}
