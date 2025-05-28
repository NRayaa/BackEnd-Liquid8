<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use Carbon\Carbon;
use App\Models\LoyaltyRank;
use App\Models\BuyerLoyalty;
use Illuminate\Http\Request;
use App\Models\BuyerLoyaltyHistory;

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
        $buyerLoyalties = BuyerLoyalty::where('expire_date', '<', Carbon::now('Asia/Jakarta'))->get();
        $processed = 0;

        foreach ($buyerLoyalties as $buyerLoyalty) {
            $currentTransaction = $buyerLoyalty->transaction_count;
            $currentRankName = $buyerLoyalty->rank->rank; // simpan sebelum update

            $lowerRank = LoyaltyRank::where('min_transactions', '<', $currentTransaction)
                ->orderBy('min_transactions', 'desc')
                ->first();

            if ($lowerRank) {
                $buyerLoyalty->update([
                    'loyalty_rank_id' => $lowerRank->id,
                    'transaction_count' => $lowerRank->min_transactions + 1,
                    'last_upgrade_date' => Carbon::now('Asia/Jakarta'),
                    'expire_date' => Carbon::now('Asia/Jakarta')->addWeeks($lowerRank->expired_weeks)->endOfDay(),
                ]);
                BuyerLoyaltyHistory::create([
                    'buyer_id' => $buyerLoyalty->buyer_id,
                    'previous_rank' => $currentRankName,
                    'current_rank' => $lowerRank->rank,
                    'note' => 'Rank expired, downgraded to ' . $lowerRank->rank,
                    'created_at' => Carbon::now('Asia/Jakarta'),
                    'updated_at' => Carbon::now('Asia/Jakarta'),
                ]);
                $processed++;
            }
        }

        return new ResponseResource(true, "Berhasil memproses $processed buyer loyalty yang expired", [
            'processed_count' => $processed,
        ]);
    }
}
