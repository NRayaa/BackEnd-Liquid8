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

    public function recalculateBuyerLoyalty()
    {
        // Ambil semua buyer_id yang punya transaksi >= 5jt sejak 1 juni 2025 dari SaleDocument
        $buyerIds = \App\Models\SaleDocument::where('created_at', '>=', '2025-06-01')
            ->where('status_document_sale', 'selesai')
            ->where('total_display_document_sale', '>=', 5000000)
            ->distinct()
            ->pluck('buyer_id_document_sale');

        $processed = 0;
        $allRanks = LoyaltyRank::orderBy('min_transactions', 'asc')->get();

        foreach ($buyerIds as $buyerId) {
            // Ambil data buyer
            $buyer = \App\Models\Buyer::find($buyerId);
            if (!$buyer) {
                continue; // Skip jika buyer tidak ditemukan
            }

            // Ambil semua transaksi buyer yang valid, diurutkan berdasarkan tanggal
            $transactions = \App\Models\SaleDocument::where('buyer_id_document_sale', $buyerId)
                ->where('status_document_sale', 'selesai')
                ->where('total_display_document_sale', '>=', 5000000)
                ->where('created_at', '>=', '2025-06-01')
                ->orderBy('created_at', 'asc')
                ->get(['id', 'created_at', 'total_display_document_sale']);

            $validTransactionCount = $transactions->count();

            if ($validTransactionCount == 0) {
                continue; // Skip jika tidak ada transaksi valid
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
                if ($currentTransactionCount >= 2 && $simulatedExpireDate !== null && $transactionDate->gt($simulatedExpireDate)) {
                    // EXPIRED! Reset count ke 0, expired null
                    $currentTransactionCount = 0;
                    $simulatedExpireDate = null;
                    $lastResetIndex = $index; // Simpan index transaksi yang jadi reset point
                    
                    Log::channel('buyer_loyalty')->info('Buyer loyalty EXPIRED and RESET', [
                        'buyer_id' => $buyerId,
                        'transaction_date' => $transactionDate->toDateTimeString(),
                        'reset_to_count' => 0,
                        'expired_cleared' => true
                    ]);
                }

                // Process transaksi ini (baik yang expired maupun normal)
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
                        // Transaksi ke-2 (atau transaksi pertama setelah reset): mulai hitung expired dari tanggal transaksi INI
                        $simulatedExpireDate = $transactionDate->copy()->addWeeks($rankForTransaction->expired_weeks)->endOfDay();
                    } else {
                        // Transaksi berikutnya: tambahkan expired_weeks ke expire_date yang ada
                        $simulatedExpireDate->addWeeks($rankForTransaction->expired_weeks);
                    }
                }
            }

            // Setelah simulasi, CHECK apakah expire_date sudah lewat dari waktu SEKARANG
            $now = Carbon::now('Asia/Jakarta');
            if ($simulatedExpireDate !== null && $now->gt($simulatedExpireDate)) {
                // Expire_date sudah lewat dari waktu sekarang, reset ke New Buyer dengan count=0
                $currentTransactionCount = 0;
                $simulatedExpireDate = null;
                
                Log::channel('buyer_loyalty')->info('Buyer loyalty EXPIRED (current time check)', [
                    'buyer_id' => $buyerId,
                    'current_time' => $now->toDateTimeString(),
                    'expire_date_was' => $simulatedExpireDate ? $simulatedExpireDate->toDateTimeString() : 'null',
                    'reset_to_count' => 0,
                    'reset_to_rank' => 'New Buyer'
                ]);
            }

            // Tentukan rank akhir berdasarkan currentTransactionCount
            // Khusus untuk count=0 atau count=1, set ke New Buyer
            if ($currentTransactionCount <= 1) {
                $finalRank = $allRanks->where('min_transactions', 0)->first();
            } else {
                $finalRank = $allRanks->where('min_transactions', '<=', $currentTransactionCount)
                    ->sortByDesc('min_transactions')
                    ->first();

                if (!$finalRank) {
                    // Fallback ke New Buyer
                    $finalRank = $allRanks->where('min_transactions', 0)->first();
                }
            }

            // Untuk count=0 atau count=1, expire_date tetap null
            $expireDate = ($currentTransactionCount <= 1) ? null : $simulatedExpireDate;

            // Update atau create BuyerLoyalty
            $buyerLoyalty = BuyerLoyalty::where('buyer_id', $buyer->id)->first();

            if ($buyerLoyalty) {
                $oldRankName = $buyerLoyalty->rank ? $buyerLoyalty->rank->rank : 'Unknown';

                // Update existing (gunakan currentTransactionCount, bukan validTransactionCount)
                $buyerLoyalty->update([
                    'loyalty_rank_id' => $finalRank->id,
                    'transaction_count' => $currentTransactionCount,
                    'last_upgrade_date' => Carbon::now('Asia/Jakarta'),
                    'expire_date' => $expireDate,
                ]);

                // Log history jika rank berubah
                if ($oldRankName !== $finalRank->rank) {
                    BuyerLoyaltyHistory::create([
                        'buyer_id' => $buyer->id,
                        'previous_rank' => $oldRankName,
                        'current_rank' => $finalRank->rank,
                        'note' => "Rank recalculated from {$oldRankName} to {$finalRank->rank} based on {$currentTransactionCount} transactions (after expired check)",
                        'created_at' => Carbon::now('Asia/Jakarta'),
                        'updated_at' => Carbon::now('Asia/Jakarta'),
                    ]);
                }
            } else {
                // Create new (gunakan currentTransactionCount, bukan validTransactionCount)
                BuyerLoyalty::create([
                    'buyer_id' => $buyer->id,
                    'loyalty_rank_id' => $finalRank->id,
                    'transaction_count' => $currentTransactionCount,
                    'last_upgrade_date' => Carbon::now('Asia/Jakarta'),
                    'expire_date' => $expireDate,
                ]);

                BuyerLoyaltyHistory::create([
                    'buyer_id' => $buyer->id,
                    'previous_rank' => null,
                    'current_rank' => $finalRank->rank,
                    'note' => "Initial rank assignment: {$finalRank->rank} based on {$currentTransactionCount} transactions (after expired check)",
                    'created_at' => Carbon::now('Asia/Jakarta'),
                    'updated_at' => Carbon::now('Asia/Jakarta'),
                ]);
            }

            $processed++;
        }

        return new ResponseResource(true, "Berhasil recalculate {$processed} buyer loyalty", [
            'processed_count' => $processed,
            'total_buyers' => $buyerIds->count(),
        ]);
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

}
