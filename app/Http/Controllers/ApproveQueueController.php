<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Models\User;
use App\Models\New_product;
use App\Models\ApproveQueue;
use App\Models\SaleDocument;
use App\Models\Notification;
use Illuminate\Http\Request;
use App\Models\StagingProduct;
use App\Http\Resources\ResponseResource;
use Illuminate\Support\Facades\DB;

class ApproveQueueController extends Controller
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
    public function show(ApproveQueue $approveQueue)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(ApproveQueue $approveQueue)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, ApproveQueue $approveQueue)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(ApproveQueue $approveQueue)
    {
        //
    }

    public function approve(Request $request)
    {
        DB::beginTransaction();
        set_time_limit(300);
        ini_set('memory_limit', '512M');
        try {
            $user = auth()->user()->email;
            $approveQueue = ApproveQueue::find($request->id);
            $barcode = '';

            if (!$approveQueue) {
                return (new ResponseResource(false, "Approve queue not found", null))->response()->setStatusCode(404);
            }
            if($approveQueue->status == '0'){                
                return (new ResponseResource(false, "Sudah di approve", null))->response()->setStatusCode(404);
            }

            if ($approveQueue->type == 'inventory') {
                $newProduct = New_product::find($approveQueue->product_id);
                $newProduct->update([
                    'old_price_product' => $approveQueue->old_price_product,
                    'new_name_product' => $approveQueue->new_name_product,
                    'new_quantity_product' => $approveQueue->new_quantity_product,
                    'new_price_product' => $approveQueue->new_price_product,
                    'new_discount' => $approveQueue->new_discount,
                    'new_tag_product' => $approveQueue->new_tag_product,
                    'new_category_product' => $approveQueue->new_category_product,
                    'display_price' => $approveQueue->new_price_product,
                ]);
                $barcode = $newProduct->new_barcode_product;
                $newProduct->save();
            }

            if ($approveQueue->type == 'staging') {
                $stagingProduct = StagingProduct::find($approveQueue->product_id);
                $stagingProduct->update([
                    'old_price_product' => $approveQueue->old_price_product,
                    'new_name_product' => $approveQueue->new_name_product,
                    'new_quantity_product' => $approveQueue->new_quantity_product,
                    'new_price_product' => $approveQueue->new_price_product,
                    'new_discount' => $approveQueue->new_discount,
                    'new_tag_product' => $approveQueue->new_tag_product,
                    'new_category_product' => $approveQueue->new_category_product,
                    'display_price' => $approveQueue->new_price_product,
                ]);
                $barcode = $stagingProduct->new_barcode_product;
                $stagingProduct->save();
            }

            $notification = Notification::where('user_id', $approveQueue->user_id)
                ->where('status', $approveQueue->type)->where('external_id', $approveQueue->product_id)
                ->first();
            $approveQueue->update(['status' => '0']);
            $notification->delete();
            logUserAction($request, $request->user(), "storage/product/category", "menghapus product->" . $user);

            DB::commit();
            return new ResponseResource(true, "Approved successfully", $barcode);
        } catch (\Exception $e) {
            DB::rollBack();
            return (new ResponseResource(false, "Failed to approve", null))->response()->setStatusCode(500);
        }
    }

    public function reject(Request $request)
    {
        $approveQueue = ApproveQueue::find($request->id);
        if (!$approveQueue) {
            return (new ResponseResource(false, "Approve queue not found", null))->response()->setStatusCode(404);
        }

        $approveQueue->delete();
        $notification = Notification::where('user_id', $approveQueue->user_id)
            ->where('status', $approveQueue->type)->where('external_id', $approveQueue->product_id)
            ->first();
        $notification->delete();
        return new ResponseResource(true, "Approved successfully", null);
    }

    public function get_approve_spv($status, $external_id)
    {
        if ($status == 'inventory') {
            $dataOld = New_product::where('id', $external_id)->first();
            $dataNew = ApproveQueue::where('product_id', $dataOld->id)
                ->where('type', 'inventory')->whereNot('status', '0')
                ->first();

            if (!$dataOld || !$dataNew) {
                return (new ResponseResource(false, "data not found", null))->response()->setStatusCode(404);
            }

            $dataNewCustom = [
                'user' => $dataNew->user->username,
                'product_id' => $dataNew->product_id,
                'barcode' => $dataOld->new_barcode_product,
                'type' => $dataNew->type,
                'code_document' => $dataNew->code_document,
                'old_price_product' => $dataNew->old_price_product,
                'new_name_product' => $dataNew->new_name_product,
                'new_quantity_product' => $dataNew->new_quantity_product,
                'new_price_product' => $dataNew->new_price_product,
                'new_discount' => $dataNew->new_discount,
                'new_tag_product' => $dataNew->new_tag_product,
                'new_category_product' => $dataNew->new_category_product,
                'created_at' => $dataNew->created_at,
                'updated_at' => $dataNew->updated_at,
                'deleted_at' => $dataNew->deleted_at,
            ];

            return new ResponseResource(
                true,
                "approved edit product",
                [
                    'id' => $dataNew->id,
                    'dataOld' => $dataOld,
                    'dataNew' => $dataNewCustom,
                ]
            );
        }
        if ($status == 'sale') {
            $check_approved = SaleDocument::where('id', $external_id)
                ->where('approved', '1')
                ->exists();

            if (!$check_approved) {
                return (new ResponseResource(false, "Document is not approved", null))->response()->setStatusCode(404);
            }

            $data = SaleDocument::select(
                'id',
                'code_document_sale',
                'new_discount_sale',
                'total_product_document_sale',
                'total_price_document_sale',
                'total_display_document_sale',
                'voucher',
                'approved',
                'created_at'
            )->where('id', $external_id)
                ->with([
                    'sales' => function ($query) {
                        $query->whereColumn('product_price_sale', '<', 'display_price')
                            ->select(
                                'id',
                                'code_document_sale',
                                'product_name_sale',
                                'product_old_price_sale',
                                'product_category_sale',
                                'product_barcode_sale',
                                'product_price_sale',
                                'product_qty_sale',
                                'total_discount_sale',
                                'created_at',
                                'new_discount_sale',
                                'display_price',
                                'code_document',
                                'approved'
                            );
                    }
                ])->first();
            return new ResponseResource(true, "approved invoice discount", $data);
        }
        if ($status == 'staging') {
            $dataOld = StagingProduct::where('id', $external_id)->first();
            $dataNew = ApproveQueue::where('product_id', $dataOld->id)->whereNot('status', '0')
                ->where('type', 'staging')
                ->first();
            if (!$dataOld || !$dataNew) {
                return (new ResponseResource(false, "data not found", null))->response()->setStatusCode(404);
            }
            $dataNewCustom = [
                'user' => $dataNew->user->username,
                'product_id' => $dataNew->product_id,
                'barcode' => $dataOld->new_barcode_product,
                'type' => $dataNew->type,
                'code_document' => $dataNew->code_document,
                'old_price_product' => $dataNew->old_price_product,
                'new_name_product' => $dataNew->new_name_product,
                'new_quantity_product' => $dataNew->new_quantity_product,
                'new_price_product' => $dataNew->new_price_product,
                'new_discount' => $dataNew->new_discount,
                'new_tag_product' => $dataNew->new_tag_product,
                'new_category_product' => $dataNew->new_category_product,
                'created_at' => $dataNew->created_at,
                'updated_at' => $dataNew->updated_at,
                'deleted_at' => $dataNew->deleted_at,
            ];
            return new ResponseResource(
                true,
                "approved edit product",
                [
                    'dataOld' => $dataOld,
                    'dataNew' => $dataNewCustom,
                ]
            );
        }
    }
}
