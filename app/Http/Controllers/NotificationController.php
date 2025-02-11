<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Repair;
use App\Models\New_product;
use App\Models\Notification;
use App\Models\RiwayatCheck;
use Illuminate\Http\Request;
use App\Models\ProductApprove;
use Illuminate\Support\Facades\DB;
use App\Http\Resources\ResponseResource;
use App\Models\Document;
use App\Models\StagingApprove;
use App\Models\StagingProduct;
use Illuminate\Support\Facades\Validator;
use Carbon\Carbon;


class NotificationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $notifications = Notification::latest()->paginate(100);

        return new ResponseResource(true, "list notification", $notifications);
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
    public function show(Notification $notification)
    {
        if (!$notification) {
            return new ResponseResource(false, "id notification tidak terdaftar", null);
        }
        return new ResponseResource(true, "detail notification", $notification);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Notification $notification)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Notification $notification)
    {
        $user = User::find(auth()->id());

        if (!$user) {
            $resource = new ResponseResource(false, "User tidak dikenali", null);
            return $resource->response()->setStatusCode(422);
        }

        $validator = Validator::make($request->all(), [
            'notification_name' => 'required',
            'status' => 'required|in:pending,done'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }


        $notification->update([
            'notification_name' => $request->notification_name,
            'status' => $request->status
        ]);
        return new ResponseResource(true, "berhasil update", $notification);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Notification $notification)
    {
        try {
            $notification->delete();
            return new ResponseResource(true, "berhasil di hapus", null);
        } catch (\Exception $e) {
            return response()->json(["error" => $e], 402);
        }
    }

    public function approveTransaction($notificationId)
    {
        set_time_limit(300);
        ini_set('memory_limit', '512M');

        $user = User::with('role')->find(auth()->id());

        DB::beginTransaction();

        try {
            if ($user && ($user->role && ($user->role->role_name == 'Spv' ||  $user->role->role_name == 'Admin'))) {
                $notification = Notification::find($notificationId);

                if (!$notification) {
                    return response()->json(['error' => 'Transaksi tidak ditemukan'], 404);
                }

                if ($notification->status == 'staging' || $notification->status == 'done') {
                    return response()->json(['message' => 'Transaksi sudah disetujui sebelumnya'], 422);
                }

                $notification->update([
                    'notification_name' => 'Approved',
                    'status' => 'done',
                ]);

                if ($notification->riwayat_check_id !== null) {
                    $riwayatCheck = RiwayatCheck::find($notification->riwayat_check_id);
                    $document = Document::where('code_document', $riwayatCheck->code_document)->first();
                    $document->update([
                        'status_document' => 'done'
                    ]);

                    if ($riwayatCheck) {
                        $riwayatCheck->update(['status_approve' => 'done']);

                        $productApprovesTags = ProductApprove::where('code_document', $riwayatCheck->code_document)
                            ->whereNotNull('new_tag_product')
                            ->get();

                        $productApprovesCategories = ProductApprove::where('code_document', $riwayatCheck->code_document)
                            ->whereNull('new_tag_product')
                            ->get();

                        // Fungsi untuk insert dan hapus data
                        $this->processProductApproves($productApprovesTags, New_product::class, 100);
                        $this->processProductApproves($productApprovesCategories, StagingProduct::class, 200);

                        // Menangani RepairCheck jika ada
                        $repairCheck = Repair::where('user_id', $notification->user_id)->first();

                        if ($repairCheck) {
                            $repairCheck->update(['status_approve' => 'done']);

                            $repairCheck->repair_products()->chunkById(200, function ($productFilter) {
                                foreach ($productFilter as $product) {
                                    New_product::create([
                                        'code_document' => $product->code_document,
                                        'old_barcode_product' => $product->old_barcode_product,
                                        'new_barcode_product' => $product->new_barcode_product,
                                        'new_name_product' => $product->new_name_product,
                                        'new_quantity_product' => $product->new_quantity_product,
                                        'new_price_product' => $product->new_price_product,
                                        'new_date_in_product' => $product->new_date_in_product,
                                        'new_status_product' => 'display',
                                        'new_quality' => $product->new_quality,
                                        'new_category_product' => $product->new_category_product,
                                        'new_tag_product' => $product->new_tag_product,
                                        'new_discount' => $product->new_discount,
                                        'display_price' => $product->display_price,
                                        'created_at' => now(),
                                        'updated_at' => now(),
                                    ]);

                                    $product->delete();
                                }
                            });

                            // Setelah semua produk terkait dihapus, hapus repairCheck
                            $repairCheck->delete();
                        }
                    }
                }

                DB::commit();
                return new ResponseResource(true, 'Transaksi berhasil diapprove', $notification);
            } else {
                $response = new ResponseResource(false, "User tidak diizinkan atau role tidak valid", null);
                return $response->response()->setStatusCode(403);
            }
        } catch (\Exception $e) {
            DB::rollBack();
            return new ResponseResource(false, "Gagal mengapprove transaksi", $e->getMessage());
        }
    }

    private function processProductApproves($productApproves, $modelClass, $chunkSize)
    {
        $productApproves->chunk($chunkSize)->each(function ($chunk) use ($modelClass) {
            $dataToInsert = [];

            foreach ($chunk as $productApprove) {
                $dataToInsert[] = [
                    'code_document' => $productApprove->code_document,
                    'old_barcode_product' => $productApprove->old_barcode_product,
                    'new_barcode_product' => $productApprove->new_barcode_product,
                    'new_name_product' => $productApprove->new_name_product,
                    'new_quantity_product' => $productApprove->new_quantity_product,
                    'new_price_product' => $productApprove->new_price_product,
                    'old_price_product' => $productApprove->old_price_product,
                    'new_date_in_product' => Carbon::now('Asia/Jakarta')->toDateString(),
                    'new_status_product' => $productApprove->new_status_product,
                    'new_quality' => $productApprove->new_quality,
                    'new_category_product' => $productApprove->new_category_product,
                    'new_tag_product' => $productApprove->new_tag_product,
                    'new_discount' => $productApprove->new_discount,
                    'display_price' => $productApprove->display_price,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // Insert ke model yang ditentukan
            $modelClass::insert($dataToInsert);

            // Hapus data setelah berhasil insert
            ProductApprove::destroy($chunk->pluck('id'));
        });
    }

    public function getNotificationByRole(Request $request)
    {
        $userId = auth()->id();
        $query = $request->input('q');
        $page = $request->input('page', 1);
        $perPage = 33;

        // Buat query dasar
        $notifQuery = Notification::query()
            ->latest('notifications.created_at');
        if (!in_array($userId, [1, 2, 5, 8])) {
            $notifQuery->whereNot('status', 'sale');
        }


        // Filter pencarian jika ada
        if ($query) {
            $notifQuery->where('notifications.status', 'LIKE', '%{$query}%');
        }

        // Lakukan pagination pada query
        $notifications = $notifQuery->paginate($perPage);

        return new ResponseResource(true, "Notifications", $notifications);
    }

    public function notifWidget(Request $request)
    {
        $userId = auth()->id();
        $query = $request->input('q');

        $notifQuery = Notification::query()->latest()->limit(5);

        if(!in_array($userId, [1,2,5,8])){
            $notifQuery->whereNot('status', 'sale');
        }
        // Jika ada query pencarian
        if ($query) {
            $notifQuery->where('status', 'LIKE', '%' . $query . '%');
        }

        // Ambil hasil query
        $notifications = $notifQuery->get();


        // Kembalikan hasil dalam format ResponseResource
        return new ResponseResource(true, "Notifications", $notifications);
    }
}
