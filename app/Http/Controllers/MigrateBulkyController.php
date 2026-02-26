<?php

namespace App\Http\Controllers;

use App\Http\Resources\ResponseResource;
use App\Models\MigrateBulky;
use App\Models\New_product;
use App\Models\StagingProduct;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class MigrateBulkyController extends Controller
{
    public function index(Request $request)
    {
        $documents = MigrateBulky::with(['user:id,name', 'migrateBulkyProducts'])
            ->has('migrateBulkyProducts')
            ->filter($request->only(['q']))
            ->latest()
            ->paginate($request->query('per_page', 15));

        return (new ResponseResource(true, "List Data Migrate Bulky", $documents))
            ->response()->setStatusCode(200);
    }

    public function show(MigrateBulky $migrateBulky)
    {
        $migrateBulky->load(['migrateBulkyProducts' => function ($query) {
            $query->where('new_status_product', '!=', 'dump')
                ->where('new_status_product', '!=', 'scrap_qcd');
        }]);

        $migrateBulky->migrateBulkyProducts->transform(function ($product) {
            $product->source = 'migrate';
            $product->status_so = ($product->is_so === 'done') ? 'Sudah SO' : 'Belum SO';
            return $product;
        });

        return new ResponseResource(true, "Detail Migrate Bulky", $migrateBulky);
    }

    public function finishMigrateBulky()
    {
        $user = Auth::user();

        $migrateBulky = MigrateBulky::with('migrateBulkyProducts')
            ->where('user_id', $user->id)
            ->where('status_bulky', 'proses')
            ->first();

        if (!$migrateBulky) {
            return response()->json(['errors' => ['migrate_bulky' => ['Tidak ada dokumen aktif untuk diselesaikan!']]], 422);
        }

        DB::beginTransaction();
        try {
            foreach ($migrateBulky->migrateBulkyProducts as $item) {
                $deleted = StagingProduct::where('id', $item->new_product_id)
                    ->where('new_barcode_product', $item->new_barcode_product)
                    ->delete();

                if ($deleted == 0) {
                    New_product::where('id', $item->new_product_id)
                        ->where('new_barcode_product', $item->new_barcode_product)
                        ->delete();
                }
            }

            $migrateBulky->update(['status_bulky' => 'added']);

            DB::commit();

            return new ResponseResource(true, "Migrasi Selesai! Data sumber berhasil dihapus.", $migrateBulky->load('migrateBulkyProducts'));
        } catch (Exception $e) {
            DB::rollBack();
            return response()->json(['errors' => ['migrate_bulky' => ['Data gagal di migrate: ' . $e->getMessage()]]], 500);
        }
    }
}
