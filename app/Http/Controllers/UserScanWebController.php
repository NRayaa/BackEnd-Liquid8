<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\Document;
use App\Models\SkuDocument;
use App\Models\UserScanWeb;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;


class UserScanWebController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $query = $request->input('q');
        $page = $request->input('page', 1);

        $userScanQuery = UserScanWeb::latest();
        if (!empty($query)) {
            $userScanQuery->where(function ($queryBuilder) use ($query) {
                $queryBuilder->where('username', 'LIKE', '%' . $query . '%')
                    ->orWhere('base_document', 'LIKE', '%' . $query . '%')
                    ->orWhere('code_document', 'LIKE', '%' . $query . '%')

                ;
            });
            $page = 1;
        }
        // Paginasi data
        $userScans = $userScanQuery->paginate(33, ['*'], 'page', $page);

        // Sembunyikan relasi 'user' dan 'document' dari setiap item
        $userScans->getCollection()->each->makeHidden(['user', 'document']);

        return new ResponseResource(true, "list user scan", $userScans);
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
    public function show($code_document) {}


    /**
     * Show the form for editing the specified resource.
     */
    public function edit(UserScanWeb $userScanWeb)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, UserScanWeb $userScanWeb)
    {
        $validator = Validator::make($request->all(), [
            'total_scans' => 'required|integer|min:0'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $userScanWeb->update(
            [
                'total_scans' => $request->input('total_scans')
            ]
        );

        return new ResponseResource(true, "berhasil di edit", $userScanWeb);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(UserScanWeb $userScanWeb)
    {
        if ($userScanWeb->delete()) {
            return new ResponseResource(true, "User scan successfully deleted", null);
        }

        return new ResponseResource(false, "Failed to delete user scan", null);
    }

    public function total_user_scans(Request $request)
    {
        $users = UserScanWeb::groupBy('user_id')->select('user_id')
            ->selectRaw('SUM(total_scans) as total_scans')->get()
            ->map(function ($user) {
                return [
                    'total_scans'  => $user->total_scans,
                    'username' => $user->username
                ];
            });
        return new ResponseResource(true, "List total per user scans", $users);
    }

    public function detail_user_scan($code_document)
    {
        $document = Document::where('code_document', $code_document)->first();

        if (!$document) {
            $document = SkuDocument::where('code_document', $code_document)->first();
        }

        if (!$document) {
            return (new ResponseResource(false, "code_document tidak ada", $document))->setStatusCode(404);
        }
        
        $userFIleScan = UserScanWeb::where('document_id', $document->id)->get();

        $countUser = $userFIleScan->unique('user_id')->count();

        $totalScanAll = $userFIleScan->sum('total_scans');

        $totalScanToday = $userFIleScan->where('scan_date', Carbon::now('Asia/Jakarta')->toDateString())->sum('total_scans');

        $userFIleScan->each->makeHidden(['user', 'document']);

        // Kembalikan data dalam format resource
        return new ResponseResource(true, "Detail Data", [
            'summary' => [
                'count_user' => $countUser,
                'total_scans_all' => $totalScanAll,
                'total_scans_today' => $totalScanToday,
            ],
            'data' => $userFIleScan,
        ]);
    }
}
