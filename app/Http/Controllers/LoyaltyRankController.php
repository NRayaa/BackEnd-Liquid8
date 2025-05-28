<?php

namespace App\Http\Controllers;

use App\Models\LoyaltyRank;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use App\Http\Resources\ResponseResource;
use Illuminate\Support\Facades\Validator;

class LoyaltyRankController extends Controller
{

    public function index(Request $request)
    {
        $query = $request->query('q');
        if ($query) {
            $rank = LoyaltyRank::where('rank', 'like', '%' . $query . '%')->latest();
        } else {
            $rank = LoyaltyRank::latest();
        }
        $rank = $rank->paginate(33);
        return new ResponseResource(true, "Berhasil mengambil list rank", $rank);
    }


    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $validator = Validator::make($request->all(), [
                'rank' => 'required|string|max:255|unique:loyalty_ranks,rank',
                'min_transactions' => 'required|integer',
                'min_amount_transaction' => 'required|numeric',
                'percentage_discount' => 'required|numeric|max:100',
                'expired_weeks' => 'required|integer',
            ]);
            if ($validator->fails()) {
                return (new ResponseResource(
                    false,
                    "Validasi gagal",
                    $validator->errors()
                ))->response()->setStatusCode(422);
            }
            $rank = LoyaltyRank::create([
                'rank' => $request->rank,
                'min_transactions' => $request->min_transactions,
                'min_amount_transaction' => $request->min_amount_transaction,
                'percentage_discount' => $request->percentage_discount,
                'expired_weeks' => $request->expired_weeks,
            ]);
            return new ResponseResource(
                true,
                "Berhasil menyimpan data rank",
                $rank
            );
        } catch (\Exception $e) {
            Log::error('Gagal menyimpan data loyalty rank', [
                'error' => $e->getMessage(),
                'input' => $request->all()
            ]);
            return (new ResponseResource(
                false,
                $e->getMessage(),
                null
            ))->response()->setStatusCode(500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(LoyaltyRank $loyaltyRank)
    {
        return new ResponseResource(true, "Berhasil mengambil data rank", $loyaltyRank);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(LoyaltyRank $loyaltyRank)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, LoyaltyRank $loyaltyRank)
    {
        try {
            $validator = Validator::make($request->all(), [
                'rank' => 'required|string|max:255',
                'min_transactions' => 'required|integer|min:0',
                'min_amount_transaction' => 'required|numeric|min:0',
                'percentage_discount' => 'required|numeric|min:0|max:100',
                'expired_weeks' => 'required|integer|min:1',
            ]);
            if ($validator->fails()) {
                return (new ResponseResource(false, "Validasi gagal", $validator->errors()))->response()->setStatusCode(422);
            }
            $loyaltyRank->update([
                'rank' => $request->rank,
                'min_transactions' => $request->min_transactions,
                'min_amount_transaction' => $request->min_amount_transaction,
                'percentage_discount' => $request->percentage_discount,
                'expired_weeks' => $request->expired_weeks,
            ]);
            return new ResponseResource(true, "Berhasil memperbarui data rank", $loyaltyRank);
        } catch (\Exception $e) {
            return (new ResponseResource(false, "Gagal memperbarui data rank", null))->response()->setStatusCode(500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(LoyaltyRank $loyaltyRank)
    {
        try {
            $loyaltyRank->delete();
            return new ResponseResource(true, "Berhasil menghapus data rank", []);
        } catch (\Exception $e) {
            return (new ResponseResource(false, "Gagal menghapus data rank", []))->response()->setStatusCode(500);
        }
    }
}
