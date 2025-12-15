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
                        // EXPIRED! Turun 1 tingkat dari rank yang sudah achieved
                        
                        // Dapatkan rank yang SUDAH ACHIEVED (rank setelah transaksi terakhir selesai)
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
                        
                        // Set count = min_transactions rank baru + 1
                        $currentTransactionCount = $downgradedRank->min_transactions + 1;
                        $simulatedExpireDate = null;
                        $lastResetIndex = $index;
                        $isExpired = true;
                        
                        Log::channel('buyer_loyalty')->info('Buyer loyalty EXPIRED and DOWNGRADED', [
                            'buyer_id' => $buyerId,
                            'transaction_date' => $transactionDate->toDateTimeString(),
                            'downgraded_from_rank' => $currentAchievedRank ? $currentAchievedRank->rank : 'Unknown',
                            'downgraded_to_rank' => $downgradedRank->rank,
                            'new_count' => $currentTransactionCount,
                        ]);
                        // JANGAN increment lagi karena sudah di-set sesuai rank baru
                    } else {
                        // Tidak expired (karena grace period), increment normal
                        $currentTransactionCount++;
                    }
                } else {
                    // Tidak expired, increment normal
                    $currentTransactionCount++;
                }
                
                // Tentukan rank berdasarkan transaction count saat ini
                // Jika expired, paksa ke New Buyer regardless of count
                if ($isExpired || $currentTransactionCount == 1) {
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

            // Setelah simulasi, CHECK apakah expire_date sudah lewat dari waktu SEKARANG
            $now = Carbon::now('Asia/Jakarta');
            if ($simulatedExpireDate !== null && $now->gt($simulatedExpireDate)) {
                // Expire_date sudah lewat dari waktu sekarang, turun 1 tingkat dari rank yang sudah achieved
                
                // Dapatkan rank yang SUDAH ACHIEVED saat ini
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
                
                // Set count = min_transactions rank baru + 1
                $currentTransactionCount = $downgradedRank->min_transactions + 1;
                $simulatedExpireDate = null;
                
                Log::channel('buyer_loyalty')->info('Buyer loyalty EXPIRED (current time check)', [
                    'buyer_id' => $buyerId,
                    'current_time' => $now->toDateTimeString(),
                    'downgraded_from_rank' => $currentAchievedRank ? $currentAchievedRank->rank : 'Unknown',
                    'downgraded_to_rank' => $downgradedRank->rank,
                    'new_count' => $currentTransactionCount,
                ]);
            }

            // Tentukan current rank berdasarkan count setelah semua expired check
            // Gunakan count - 1 untuk menentukan rank yang SEDANG AKTIF (rank saat ini, bukan rank target)
            $currentRank = null;
            if ($currentTransactionCount > 0) {
                $effectiveCount = max(0, $currentTransactionCount - 1);
                $currentRank = $allRanks->where('min_transactions', '<=', $effectiveCount)
                    ->sortByDesc('min_transactions')
                    ->first();
            }

            if (!$currentRank) {
                // Fallback ke New Buyer
                $currentRank = $allRanks->where('min_transactions', 0)->first();
            }

            $finalRank = $currentRank;

            $expireDate = $simulatedExpireDate;

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
