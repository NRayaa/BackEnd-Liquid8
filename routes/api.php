<?php

use App\Http\Controllers\AbnormalDocumentController;
use App\Http\Controllers\ApproveQueueController;
use App\Http\Controllers\ArchiveStorageController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BagProductsController;
use App\Http\Controllers\BarcodeDamagedController;
use App\Http\Controllers\BklController;
use App\Http\Controllers\BulkyDocumentController;
use App\Http\Controllers\BulkySaleController;
use App\Http\Controllers\BundleController;
use App\Http\Controllers\BundleQcdController;
use App\Http\Controllers\BuyerController;
use App\Http\Controllers\BuyerLoyaltyController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CategoryPaletController;
use App\Http\Controllers\CheckConnectionController;
use App\Http\Controllers\ColorTag2Controller;
use App\Http\Controllers\ColorTagController;
use App\Http\Controllers\DamagedDocumentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DestinationController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\FilterBklController;
use App\Http\Controllers\FilterProductInputController;
use App\Http\Controllers\FilterQcdController;
use App\Http\Controllers\FilterStagingController;
use App\Http\Controllers\FormatBarcodeController;
use App\Http\Controllers\GenerateController;
use App\Http\Controllers\LoyaltyRankController;
use App\Http\Controllers\MigrateBulkyController;
use App\Http\Controllers\MigrateBulkyProductController;
use App\Http\Controllers\MigrateController;
use App\Http\Controllers\MigrateDocumentController;
use App\Http\Controllers\NewProductController;
use App\Http\Controllers\NonDocumentController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\PaletBrandController;
use App\Http\Controllers\PaletController;
use App\Http\Controllers\PaletFilterController;
use App\Http\Controllers\PaletImageController;
use App\Http\Controllers\PaletProductController;
use App\Http\Controllers\PpnController;
use App\Http\Controllers\ProductApproveController;
use App\Http\Controllers\ProductBrandController;
use App\Http\Controllers\ProductBundleController;
use App\Http\Controllers\ProductConditionController;
use App\Http\Controllers\ProductFilterController;
use App\Http\Controllers\ProductInputController;
use App\Http\Controllers\ProductOldController;
use App\Http\Controllers\ProductQcdController;
use App\Http\Controllers\ProductScanController;
use App\Http\Controllers\ProductSoController;
use App\Http\Controllers\ProductStatusController;
use App\Http\Controllers\PromoController;
use App\Http\Controllers\RackController;
use App\Http\Controllers\RepairController;
use App\Http\Controllers\RepairFilterController;
use App\Http\Controllers\RepairProductController;
use App\Http\Controllers\RiwayatCheckController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SaleDocumentController;
use App\Http\Controllers\ScrapDocumentController;
use App\Http\Controllers\SkuDocumentController;
use App\Http\Controllers\SkuGenerateController;
use App\Http\Controllers\SkuProductController;
use App\Http\Controllers\SkuProductOldController;
use App\Http\Controllers\SkuScanController;
use App\Http\Controllers\StagingApproveController;
use App\Http\Controllers\StagingProductController;
use App\Http\Controllers\SummaryController;
use App\Http\Controllers\SummarySoCategoryController;
use App\Http\Controllers\SummarySoColorController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserScanWebController;
use App\Http\Controllers\VehicleTypeController;
use App\Http\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes WMS
|--------------------------------------------------------------------------
| Patokan urutan role umum: Admin, Spv, Team leader, Admin Kasir, Crew, Reparasi
*/

// ========================================================================================================
// 1. AUTH & PUBLIC ROUTES
// ========================================================================================================

Route::fallback(function () {
    return response()->json(['status' => false, 'message' => 'Not Found!'], 404);
});

// Login User
Route::post('login', [AuthController::class, 'login']);

// Utility & Testing (Non-Auth)
Route::delete('cleargenerate', [GenerateController::class, 'deleteAll']);
Route::delete('deleteAll', [GenerateController::class, 'deleteAllData']);
Route::get('updateCategoryPalet', [PaletController::class, 'updateCategoryPalet']);
Route::get('cek-ping-with-image', [CheckConnectionController::class, 'checkPingWithImage']); // Cek Koneksi
Route::post('createDummyData/{count}', [GenerateController::class, 'createDummyData']);
Route::post('downloadTemplate', [GenerateController::class, 'exportTemplaye']);
Route::get('checkBarcodeMiss', [RiwayatCheckController::class, 'compareExcelWithSystem']);

// ========================================================================================================
// 2. DASHBOARD & REPORTING
// ========================================================================================================

// Akses: Admin, Spv, Team leader, Admin Kasir
// Fitur: Dashboard Utama, Summary Transaksi, Laporan Storage, Analitik Sales
Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Team leader,Admin Kasir'])->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index']);
    Route::get('dashboard2', [DashboardController::class, 'index2']);
    Route::get('dashboard/summary-transaction', [DashboardController::class, 'summaryTransaction']);
    Route::get('dashboard/summary-sales', [DashboardController::class, 'summarySales']);

    // Storage & Sales Reports
    Route::get('dashboard/storage-report', [DashboardController::class, 'storageReport']);
    Route::get('dashboard/storage-report/export', [DashboardController::class, 'exportStorageReport']);
    Route::get('dashboard/monthly-analytic-sales', [DashboardController::class, 'monthlyAnalyticSales']);
    Route::get('dashboard/yearly-analytic-sales', [DashboardController::class, 'yearlyAnalyticSales']);
    Route::get('dashboard/analytic-slow-moving', [DashboardController::class, 'analyticSlowMoving']);

    // Exports
    Route::get('export/product-expired', [DashboardController::class, 'productExpiredExport']);
    Route::post('exportDamaged', [NewProductController::class, 'exportDamaged']);
    Route::post('exportAbnormal', [NewProductController::class, 'exportAbnormal']);
    Route::post('exportNon', [NewProductController::class, 'exportNon']);
});

Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Team leader,Kasir leader'])->group(function () {
    Route::get('dashboard/general-sales', [DashboardController::class, 'generalSale']);
    Route::get('dashboard/monthly-analytic-sales/export', [DashboardController::class, 'exportMonthlyAnalyticSales']);
    Route::get('dashboard/yearly-analytic-sales/export', [DashboardController::class, 'exportYearlyAnalyticSales']);
});

// ========================================================================================================
// 3. INBOUND (MASUK BARANG)
// ========================================================================================================

// Akses: Admin, Spv, Team leader, Kasir leader, Admin Kasir
// Fitur: Proses Inbound, Check History, Manual Inbound, Scan User
Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Team leader,Kasir leader,Admin Kasir,Crew'])->group(function () {
    // Generate Data dari Excel
    Route::post('/generate', [GenerateController::class, 'processExcelFiles']);
    Route::post('/generate/merge-headers', [GenerateController::class, 'mapAndMergeHeaders']);

    // Document & Barcode Ops
    Route::post('changeBarcodeDocument', [DocumentController::class, 'changeBarcodeDocument']);

    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('categories/{category}', [CategoryController::class, 'show']);

    // Product Approvals
    Route::resource('product-approves', ProductApproveController::class);
    Route::get('productApprovesByDoc', [ProductApproveController::class, 'searchByDocument']);
    Route::get('product-approveByDoc/{code_document}', [ProductApproveController::class, 'productsApproveByDoc'])->where('code_document', '.*');

    // Document Status
    Route::get('/documentDone', [DocumentController::class, 'documentDone']);
    Route::get('/documentInProgress', [DocumentController::class, 'documentInProgress']);

    // Riwayat Check
    Route::resource('historys', RiwayatCheckController::class)->except(['destroy']);
    Route::get('riwayat-document/code_document', [RiwayatCheckController::class, 'getByDocument']);
    Route::post('history/exportToExcel', [RiwayatCheckController::class, 'exportToExcel']);

    // Notifications
    Route::resource('notifications', NotificationController::class)->except(['destroy']);

    // User Scan Monitoring
    Route::resource('user_scan_webs', UserScanWebController::class);
    Route::get('user_scan_webs/{code_document}', [UserScanWebController::class, 'detail_user_scan'])->where('code_document', '.*');
    Route::get('total_scan_users', [UserScanWebController::class, 'total_user_scans']);

    // Bulk Upload
    Route::post('bulkUploadPalet', [PaletFilterController::class, 'bulkUploadPalet']);
});

// Akses: Admin, Spv, Team leader, Crew
// Fitur: Manifest Inbound, Scanning Barcode, Input History
Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Team leader,Crew'])->group(function () {
    // Pencarian Product Old
    Route::get('product_olds-search', [ProductOldController::class, 'searchByDocument']);
    Route::get('search_barcode_product', [ProductOldController::class, 'searchByBarcode']);

    // Send Approve / Add Product
    Route::post('product-approves', [ProductApproveController::class, 'store']);
    Route::post('addProductOld', [ProductApproveController::class, 'addProductOld']);

    // Document Management
    Route::resource('/documents', DocumentController::class)->except(['destroy']);

    // Riwayat (Check In)
    Route::get('historys', [RiwayatCheckController::class, 'index']);
    Route::post('historys', [RiwayatCheckController::class, 'store']);

    // Filter Product Status per Dokumen
    Route::get('getProductLolos/{code_document}', [ProductOldController::class, 'getProductLolos'])->where('code_document', '.*');
    Route::get('getProductDamaged/{code_document}', [ProductOldController::class, 'getProductDamaged'])->where('code_document', '.*');
    Route::get('getProductAbnormal/{code_document}', [ProductOldController::class, 'getProductAbnormal'])->where('code_document', '.*');
    Route::get('getProductNon/{code_document}', [ProductOldController::class, 'getProductNon'])->where('code_document', '.*');
    Route::get('discrepancy/{code_document}', [ProductOldController::class, 'discrepancy'])->where('code_document', '.*');
});

// Akses: Admin, Spv
// Fitur: Bulking Inbound, Tag Warna, Buyer Reports
Route::middleware(['auth:sanctum', 'check.role:Admin,Spv'])->group(function () {
    Route::post('/excelOld', [StagingProductController::class, 'processExcelFilesCategoryStaging']);
    Route::post('/bulkingInventory', [NewProductController::class, 'processExcelFilesCategory']);
    Route::post('/bulking_tag_warna', [NewProductController::class, 'processExcelFilesTagColor']);

    // Buyer Analytics
    Route::get('/top-buyers', [BuyerController::class, 'getMonthlyTopBuyers']);
    Route::get('/export-monthly-points', [BuyerController::class, 'exportBuyerMonthlyPoints']);
    Route::post('/export-buyers/action/{id}', [BuyerController::class, 'actionExportRequest']);
});

// ========================================================================================================
// 4. STAGING (PERSIAPAN BARANG)
// ========================================================================================================

// Akses: Admin, Spv, Team leader, Kasir leader, Admin Kasir
// Fitur: Manage Staging, Filter Product, Move to LPR/Migrate
Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Kasir leader,Admin Kasir,Team leader'])->group(function () {
    Route::resource('staging_products', StagingProductController::class);

    // Filter & Actions
    Route::get('staging/filter_product', [FilterStagingController::class, 'index']);
    Route::post('staging/filter_product/{id}/add', [FilterStagingController::class, 'store']);
    Route::post('staging/move_to_lpr/{id}', [StagingProductController::class, 'toLpr']);
    Route::post('staging/to-migrate/{id}', [StagingProductController::class, 'toMigrate']);
    Route::delete('staging/filter_product/destroy/{id}', [FilterStagingController::class, 'destroy']);
    Route::get('export-staging', [StagingProductController::class, 'export']);

    Route::resource('staging_approves', StagingApproveController::class);

    // Batch Process
    Route::post('batchToLpr', [StagingProductController::class, 'batchToLpr']);
    Route::delete('deleteToLprBatch', [StagingProductController::class, 'deleteToLprBatch']);
});

// Akses: Admin, Spv, Kasir leader, Admin Kasir
// Fitur: Approve Staging to Inventory, Manage PPN, Edit Product History
Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Kasir leader,Admin Kasir'])->group(function () {
    Route::post('stagingTransactionApprove', [StagingApproveController::class, 'stagingTransaction']);
    Route::resource('ppn', PpnController::class);
    Route::put('ppn-set-default/{id}', [PpnController::class, 'set_default']);
    Route::put('update_product/{table}/{id}', [StagingProductController::class, 'updateProductFromHistory']);

    // Partial Staging
    Route::post('/partial-staging/{code_document}', [StagingProductController::class, 'partial'])->where('code_document', '.*');
});

// ========================================================================================================
// 5. INVENTORY (GUDANG & STOCK)
// ========================================================================================================

// Akses: Admin, Spv, Team leader, Admin Kasir, Kasir leader
// Fitur: Slow Moving, Expired, Promo, Product Management
Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Team leader,Admin Kasir,Kasir leader'])->group(function () {
    Route::get('new_product/slow_moving', [NewProductController::class, 'slowMov']);
    Route::get('new_product/expired', [NewProductController::class, 'listProductExp']);

    // Promo
    Route::get('promo', [PromoController::class, 'index']);
    Route::get('promo/{id}', [PromoController::class, 'show']);
    Route::post('promo', [PromoController::class, 'store']);
    Route::put('promo/{promo}', [PromoController::class, 'update']);
    Route::delete('promo/destroy/{promoId}/{productId}', [PromoController::class, 'destroy']);

    // New Products (Inventory)
    Route::get('/new_products/barcode/{barcode}', [NewProductController::class, 'showProductByBarcode']);
    Route::resource('new_products', NewProductController::class)->except(['destroy']);
    Route::post('/new_products/to-damaged', [NewProductController::class, 'updateToDamaged']);
});

// Akses: Admin, Spv, Team leader, Admin Kasir, Reparasi
// Fitur: Repair, QCD (Quality Control), Dump/Scrap
Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Team leader,Admin Kasir,Reparasi'])->group(function () {
    Route::get('new_product/display-expired', [NewProductController::class, 'listProductExpDisplay']);
    Route::get('getProductRepair', [RepairController::class, 'getProductRepair']);

    // QCD (Quality Control Display)
    Route::get('qcd/filter_product', [FilterQcdController::class, 'index']);
    Route::post('qcd/filter_product/{id}/add', [FilterQcdController::class, 'store']);
    Route::delete('qcd/destroy/{id}', [FilterQcdController::class, 'destroy']);
    Route::get('bundle/qcd', [BundleQcdController::class, 'index']);
    Route::get('bundle/qcd/{bundleQcd}', [BundleQcdController::class, 'show']);
    Route::post('bundle/qcd', [ProductQcdController::class, 'store']);
    Route::delete('bundle/qcd/{bundleQcd}', [BundleQcdController::class, 'destroy']);
    Route::post('/product-qcd/scrap', [ProductQcdController::class, 'moveToScrap']);
    Route::post('/product-qcd/scrap-all', [ProductQcdController::class, 'scrapAll']);

    // Repair
    Route::get('repair-mv/filter_product', [RepairFilterController::class, 'index']);
    Route::post('repair-mv/filter_product/{id}/add', [RepairFilterController::class, 'store']);
    Route::delete('repair-mv/filter_product/destroy/{id}', [RepairFilterController::class, 'destroy']);
    Route::get('repair-mv', [RepairController::class, 'index']);
    Route::get('repair-mv/{repair}', [RepairController::class, 'show']);
    Route::post('repair-mv', [RepairProductController::class, 'store']);
    Route::delete('repair-mv/{repair}', [RepairController::class, 'destroy']);
    Route::get('repair', [NewProductController::class, 'showRepair']);
    Route::put('repair/update/{id}', [NewProductController::class, 'updateRepair']);
    Route::get('repair-product-mv/{repairProduct}', [RepairProductController::class, 'show']);
    Route::delete('repair-mv/destroy/{id}', [RepairProductController::class, 'destroy']);
    Route::put('product-repair/{repairProduct}', [RepairProductController::class, 'update']);
    Route::delete('product-repair/{repairProduct}', [RepairProductController::class, 'destroy']);

    // Dumps (Barang Buangan)
    Route::get('/dumps', [NewProductController::class, 'listDump']);
    Route::put('/update-dumps/{id}', [NewProductController::class, 'updateDump']);
    Route::put('/update-repair-dump/{id}', [RepairProductController::class, 'updateRepair']);
    Route::put('/update-priceDump/{id}', [NewProductController::class, 'updatePriceDump']);
    Route::get('/export-dumps-excel/{id}', [NewProductController::class, 'exportDumpToExcel']);
    Route::post('/products/status-dump', [NewProductController::class, 'updateStatusToDump']);
});

// Akses: Admin, Spv, Reparasi
// Fitur: Scrap Document (Barang rongsok)
Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Reparasi,Team leader'])->group(function () {
    Route::get('scrap', [ScrapDocumentController::class, 'index']);
    Route::get('scrap/session', [ScrapDocumentController::class, 'getActiveSession']);
    Route::post('scrap/add', [ScrapDocumentController::class, 'addProductToScrap']);
    Route::post('scrap/add-all', [ScrapDocumentController::class, 'addAllDumpToCart']);
    Route::delete('scrap/remove', [ScrapDocumentController::class, 'removeProductFromScrap']);
    Route::get('scrap/{id}', [ScrapDocumentController::class, 'show']);
    Route::post('scrap/{id}/lock', [ScrapDocumentController::class, 'lockSession']);
    Route::post('scrap/{id}/finish', [ScrapDocumentController::class, 'finishScrap']);
    Route::get('scrap/{id}/export', [ScrapDocumentController::class, 'exportQCD']);
    Route::get('export-scrap-qcd', [ScrapDocumentController::class, 'exportAllProductsQCD']);
});

// Akses: Admin, Spv, Team leader, Developer, Crew
// Fitur: Bundle Scans, Export Product Input
Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Team leader,Developer,Crew'])->group(function () {
    Route::get('bundle-scans/filter_product', [ProductFilterController::class, 'listFilterScans']);
    Route::post('bundle-scans/filter_product/{id}', [ProductBundleController::class, 'addFilterScan']);
    Route::delete('bundle-scans/filter_product/{id}', [ProductFilterController::class, 'destroyFilterScan']);
    Route::get('bundle-scans', [BundleController::class, 'listBundleScan']);
    Route::post('bundle-scans', [ProductBundleController::class, 'createBundleScan']);
    Route::delete('bundle-scans/{bundle}', [BundleController::class, 'unbundleScan']);
    Route::post('bundle-scans/product/{bundle}', [ProductBundleController::class, 'addProductInBundle']);
    Route::delete('bundle-scans/product/{bundle}', [ProductBundleController::class, 'destroyProductBundle']);
    Route::get('exportProductInput', [ProductInputController::class, 'exportProductInput']);
});

// Akses: Admin, Spv, Team leader, Developer
// Fitur: Bundle Management, Warehouses
Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Team leader,Developer'])->group(function () {
    Route::get('bundle/filter_product', [ProductFilterController::class, 'index']);
    Route::post('bundle/filter_product/{id}/add', [ProductFilterController::class, 'store']);
    Route::delete('bundle/filter_product/destroy/{id}', [ProductFilterController::class, 'destroy']);

    Route::get('bundle', [BundleController::class, 'index']);
    Route::get('bundle/{bundle}', [BundleController::class, 'show']);
    Route::put('bundle/{bundle}', [BundleController::class, 'update']);
    Route::post('bundle', [ProductBundleController::class, 'store']);
    Route::delete('bundle/{bundle}', [BundleController::class, 'destroy']);
    Route::get('product-bundle/{new_product}/{bundle}/add', [ProductBundleController::class, 'addProductBundle']);
    Route::delete('product-bundle/{productBundle}', [ProductBundleController::class, 'destroy']);
    Route::get('bundle/product', [ProductBundleController::class, 'index']);
    Route::delete('bundle/destroy/{id}', [ProductBundleController::class, 'destroy']);

    Route::resource('warehouses', WarehouseController::class);
});

// Akses: Admin, Spv, Team leader, Crew
// Fitur: Palet Management, Filter Palet, Category Palet
Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Team leader,Crew'])->group(function () {
    // Palet Filter
    Route::get('palet/filter_product', [PaletFilterController::class, 'index']);
    Route::post('palet/filter_product/{barcode}/add', [PaletFilterController::class, 'store']);
    Route::delete('palet/filter_product/destroy/{id}', [PaletFilterController::class, 'destroy']);

    // Palet CRUD & Ops
    Route::get('palet/display', [PaletController::class, 'display']);
    Route::get('palet', [PaletController::class, 'index']);
    Route::get('palet/{palet}', [PaletController::class, 'show']);
    Route::post('palet', [PaletProductController::class, 'store']);
    Route::delete('palet/{palet}', [PaletController::class, 'destroy']);
    Route::put('palet/{palet}', [PaletController::class, 'update']);
    Route::get('product-palet/{new_product}/{palet}/add', [PaletProductController::class, 'addProductPalet']);
    Route::delete('product-palet/{paletProduct}', [PaletProductController::class, 'destroy']);
    Route::get('palet-select', [PaletController::class, 'palet_select']);
    Route::delete('palet-delete/{palet}', [PaletController::class, 'destroy_with_product']);

    Route::resource('category_palets', CategoryPaletController::class);
});

// Akses: Admin, Spv, Team leader, Admin Kasir, Kasir leader, Crew
// Fitur: Racks Management (Display & Staging)
Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Team leader,Kasir leader,Admin Kasir,Crew'])->group(function () {
    Route::get('racks/list-product-staging', [RackController::class, 'listStagingProducts']);
    Route::get('racks/list-product-display', [RackController::class, 'listDisplayProducts']);
    Route::get('racks/list', [RackController::class, 'getRackList']);
    Route::apiResource('racks', RackController::class);
    Route::post('racks/add-product-by-barcode', [RackController::class, 'addProductByBarcode']);
    Route::post('racks/{id}/move-to-display', [RackController::class, 'moveAllProductsInRackToDisplay']);
    Route::post('racks/remove-product', [RackController::class, 'removeProduct']);
});

// ========================================================================================================
// 6. OUTBOUND (KELUAR BARANG / SALES)
// ========================================================================================================

// Akses: Admin, Spv, Team leader, Kasir leader, Admin Kasir, Reparasi
// Fitur: Migrate (Pindah Gudang/Toko), Migrate Bulky
Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Team leader,Kasir leader,Admin Kasir,Reparasi'])->group(function () {
    Route::resource('destinations', DestinationController::class)->except(['destroy']);
    Route::get('countColor', [NewProductController::class, 'totalPerColor']);
    Route::get('colorDestination', [NewProductController::class, 'colorDestination']);

    // Migrate Documents
    Route::resource('migrates', MigrateController::class)->except(['destroy']);
    Route::get('displayMigrate', [MigrateController::class, 'displayMigrate']);
    Route::post('migrate-finish', [MigrateDocumentController::class, 'MigrateDocumentFinish']);
    Route::resource('migrate-documents', MigrateDocumentController::class)->except(['destroy']);

    // Migrate Bulky
    Route::get('migrate-bulky', [MigrateBulkyController::class, 'index']);
    Route::get('migrate-bulky/{migrate_bulky}', [MigrateBulkyController::class, 'show']);
    Route::get('migrate-bulky/product/{id}', [MigrateBulkyProductController::class, 'show']);
    Route::post('migrate-bulky-finish', [MigrateBulkyController::class, 'finishMigrateBulky']);
    Route::get('migrate-product', [MigrateBulkyProductController::class, 'listMigrateProducts']);
    Route::get('migrate-bulky-product', [MigrateBulkyProductController::class, 'index']);
    Route::post('migrate-bulky-product/add', [MigrateBulkyProductController::class, 'store']);
    Route::post('migrate-bulky-product/addByBarcode', [MigrateBulkyProductController::class, 'storeByBarcode']);
    Route::put('migrate-bulky/product/{id}', [MigrateBulkyProductController::class, 'update']);
    Route::delete('migrate-bulky-product/{migrate_bulky_product}/delete', [MigrateBulkyProductController::class, 'destroy']);
    Route::put('migrate-bulky/product/{id}/to-display', [MigrateBulkyProductController::class, 'toDisplay']);
});

// Akses: Admin, Spv, Admin Kasir, Kasir leader
// Fitur: Sales Transaction, Bulky Sales, Buyer Management, B2B
Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Admin Kasir,Kasir leader'])->group(function () {
    // Sales
    Route::resource('sales', SaleController::class);
    Route::put('/sales/update-price/{sale}', [SaleController::class, 'livePriceUpdates']);
    Route::resource('sale-documents', SaleDocumentController::class)->except(['destroy']);
    Route::post('sale-finish', [SaleDocumentController::class, 'saleFinish']);
    Route::put('order-into-bulky/{saleDocument}', [SaleDocumentController::class, 'orderIntoBulky']);
    Route::get('sale-report', [SaleDocumentController::class, 'combinedReport']);

    // Buyers
    Route::put('update-email-buyer/{buyer}', [BuyerController::class, 'updateEmail']);
    Route::apiResource('buyers', BuyerController::class)->except(['destroy', 'index']);

    // Bulky Sales & B2B
    Route::resource('bulky-sales', BulkySaleController::class);
    Route::delete('bulky-documents/{bulkyDocument}', [BulkyDocumentController::class, 'destroy']);
    Route::post('bulky-sale-finish', [BulkyDocumentController::class, 'bulkySaleFinish']);
    Route::post('create-b2b', [BulkyDocumentController::class, 'createBulkyDocument']);
    Route::post('export-b2b', [BulkyDocumentController::class, 'export']);

    Route::resource('vehicle-types', VehicleTypeController::class);

    // Approve Product Flow
    Route::get('get_approve_spv/{status}/{external_id}', [ApproveQueueController::class, 'get_approve_spv']);
});

// Akses: Admin, Spv, Kasir leader
// Fitur: Approval System for Sales/Discounts
Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Kasir leader'])->group(function () {
    Route::put('approved-document/{id_sale_document}', [SaleDocumentController::class, 'approvedDocument']);
    Route::put('approved-product/{id_sale}', [SaleDocumentController::class, 'approvedProduct']);
    Route::put('reject-product/{id_sale}', [SaleDocumentController::class, 'rejectProduct']);
    Route::put('reject-document/{id_sale}', [SaleDocumentController::class, 'rejectAllDiscounts']);
    Route::put('doneApproveDiscount/{id_sale_document}', [SaleDocumentController::class, 'doneApproveDiscount']);

    Route::post('approve-edit/{id}', [ApproveQueueController::class, 'approve']);
    Route::post('reject-edit/{id}', [ApproveQueueController::class, 'reject']);
});

// ========================================================================================================
// 7. MASTER DATA & SYSTEM ADMIN
// ========================================================================================================

// Akses: Admin, Spv, Team leader, Admin Kasir, Crew, Reparasi, Kasir leader
// Fitur: Categories, Colors, Filter BKL, Monitoring Abnormal/Damaged
Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Team leader,Admin Kasir,Crew,Reparasi,Kasir leader'])->group(function () {
    // Categories & Colors
    Route::get('categories', [CategoryController::class, 'index']);
    Route::get('color_tags', [ColorTagController::class, 'index']);
    Route::get('color_tags2', [ColorTag2Controller::class, 'index']);
    Route::get('product_byColor', [NewProductController::class, 'getTagColor']);
    Route::get('product_byColor2', [NewProductController::class, 'getTagColor2']);
    Route::get('product_byCategory', [NewProductController::class, 'getByCategory']);
    Route::get('getByNameColor', [ColorTagController::class, 'getByNameColor']);
    Route::get('getByNameColor2', [ColorTag2Controller::class, 'getByNameColor2']);

    // BKL (Barang Keluar Lain?)
    Route::resource('bkls', BklController::class);
    Route::get('bkl/filter_product', [FilterBklController::class, 'index']);
    Route::post('bkl/filter_product/{id}/add', [FilterBklController::class, 'store']);
    Route::delete('bkl/filter_product/destroy/{id}', [FilterBklController::class, 'destroy']);
    Route::get('export-bkl', [BklController::class, 'exportProduct']);
    Route::post('/bkl/add-bklDocument', [BklController::class, 'storeBklDocument']);
    Route::get('/bkl/{id}/bklDocument', [BklController::class, 'detailBklDocument']);
    Route::post('/bkl/{id}/to-edit', [BklController::class, 'toEdit']);
    Route::put('/bkl/{id}/bklDocument', [BklController::class, 'updateBklDocument']);
    Route::get('/bkl/list-bklDocument', [BklController::class, 'listBklDocument']);
    Route::get('/bkl-documents/generate-code', [BklController::class, 'generateCode']);

    // Monitoring Status Produk
    Route::get('productAbnormal', [NewProductController::class, 'productAbnormal']);
    Route::get('productDamaged', [NewProductController::class, 'productDamaged']);
    Route::get('productNon', [NewProductController::class, 'productNon']);

    // History & Pricing
    Route::get('refresh_history_doc/{code_document}', [DocumentController::class, 'findDataDocs'])->where('code_document', '.*');
    Route::post('add_product', [NewProductController::class, 'addProductByAdmin']);
    Route::get('get-latestPrice', [NewProductController::class, 'getLatestPrice']);
});

// Akses: Admin, Spv
// Fitur: Management Categories, User Panel SPV, Stock Opname (SO)
Route::middleware(['auth:sanctum', 'check.role:Admin,Spv'])->group(function () {
    Route::resource('categories', CategoryController::class)->except(['destroy', 'show', 'index']);
    Route::resource('color_tags', ColorTagController::class)->except(['destroy']);
    Route::resource('color_tags2', ColorTag2Controller::class)->except(['destroy']);
    Route::resource('format-barcodes', FormatBarcodeController::class);

    // User Management (SPV level)
    Route::resource('users', UserController::class)->except(['store']);
    Route::post('panel-spv/add-barcode', [UserController::class, 'addFormatBarcode']);
    Route::delete('panel-spv/format-delete/{id}', [UserController::class, 'deleteFormatBarcode']);
    Route::get('panel-spv/format-barcode', [UserController::class, 'allFormatBarcode']);
    Route::get('format-user', [FormatBarcodeController::class, 'formatsUsers']);
    Route::get('panel-spv/detail/{user}', [UserController::class, 'showFormatBarcode']);

    // Fitur SO (Stock Opname)
    Route::post('start_so', [SummarySoCategoryController::class, 'startSo']);
    Route::put('stop_so', [SummarySoCategoryController::class, 'stopSo']);
    Route::get('active_so_category', [SummarySoCategoryController::class, 'checkSoCategoryActive']);
    Route::post('start_so_color', [SummarySoColorController::class, 'startSoColor']);
    Route::put('stop_so_color', [SummarySoColorController::class, 'stopSo']);
});

// Akses: Admin (Super Admin privileges)
// Fitur: Delete Data, Register User, Accounting Sync, Manage Roles
Route::middleware(['auth:sanctum', 'check.role:Admin'])->group(function () {
    Route::post('register', [AuthController::class, 'register']);
    Route::post('sale-documents/bulking-invoice-to-jurnal', [SaleDocumentController::class, 'bulkingInvoiceByDateToJurnal']);
    Route::resource('roles', RoleController::class);
    Route::get('generateApikey/{userId}', [UserController::class, 'generateApiKey']);

    // Buyer Points Adjustment
    Route::put('buyer/add-point/{buyer}', [BuyerController::class, 'addBuyerPoint']);
    Route::put('buyer/reduce-point/{buyer}', [BuyerController::class, 'reduceBuyerPoint']);

    // Modifikasi Sale Document
    Route::post('sale-document/add-product', [SaleDocumentController::class, 'addProductSaleInDocument']);
    Route::delete('sale-document/{sale_document}/{sale}/delete-product', [SaleDocumentController::class, 'deleteProductSaleInDocument']);

    // DANGEROUS DELETE OPERATIONS
    Route::delete('migrates/{migrate}', [MigrateController::class, 'destroy']);
    Route::delete('migrate-documents/{migrateDocument}', [MigrateDocumentController::class, 'destroy']);
    Route::delete('sale-documents/{saleDocument}', [SaleDocumentController::class, 'destroy']);
    Route::delete('buyers/{buyer}', [BuyerController::class, 'destroy']);
    Route::delete('categories/{category}', [CategoryController::class, 'destroy']);
    Route::delete('color_tags/{color_tag}', [ColorTagController::class, 'destroy']);
    Route::delete('product_olds/{product_old}', [ProductOldController::class, 'destroy']);
    Route::delete('documents/{document}', [DocumentController::class, 'destroy']);
    Route::delete('historys/{history}', [RiwayatCheckController::class, 'destroy']);
    Route::delete('notifications/{notification}', [NotificationController::class, 'destroy']);
    Route::delete('destinations/{destination}', [DestinationController::class, 'destroy']);
    Route::delete('bundle/qcd/{bundleQcd}/destroy', [BundleQcdController::class, 'destroyBundle']);
    Route::delete('new_products/{new_product}', [NewProductController::class, 'destroy']);
    Route::delete('delete-all-products-old', [ProductOldController::class, 'deleteAll']);
    Route::delete('delete_all_by_codeDocument', [ProductApproveController::class, 'delete_all_by_codeDocument']);
    Route::delete('deleteCustomBarcode', [DocumentController::class, 'deleteCustomBarcode']);
    Route::delete('delete-all-new-products', [NewProductController::class, 'deleteAll']);
    Route::delete('delete-all-documents', [DocumentController::class, 'deleteAll']);
    Route::delete('color_tags2/{color_tags2}', [ColorTag2Controller::class, 'destroy']);
});

// Akses: Admin, Spv, Team leader
// Fitur: WMS Scan, Loyalty Rank, Sync Palet
Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Team leader'])->group(function () {
    Route::get('wms-scan', [UserController::class, 'wmsScans']);
    Route::resource('loyalty_ranks', LoyaltyRankController::class);
    Route::post('/approveSyncPalet', [PaletController::class, 'approveSyncPalet']);
    Route::post('/rejectSyncPalet', [PaletController::class, 'rejectSyncPalet']);
});

// ========================================================================================================
// 8. EXPORT & MISC (Role Campuran)
// ========================================================================================================

// Akses: Admin, Spv, Team leader, Kasir leader, Admin Kasir
// Fitur: Check Price, Approve Notification, Export Data
Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Team leader,Kasir leader,Admin Kasir'])->group(function () {
    Route::post('/check-price', [NewProductController::class, 'checkPrice']);
    Route::get('/spv/approve/{notificationId}', [NotificationController::class, 'approveTransaction'])->name('admin.approve');

    // Exports
    Route::post('export_product_byCategory', [NewProductController::class, 'exportProductByCategory']);
    Route::post('export_product_byColor', [NewProductController::class, 'exportProductByColor']);
    Route::post('exportCategory', [CategoryController::class, 'exportCategory']);
    Route::post('exportBundlesDetail/{id}', [BundleController::class, 'exportBundlesDetail']);
    Route::post('exportProductExpired', [NewProductController::class, 'export_product_expired']);
    Route::post('export-palet/{id}', [PaletController::class, 'exportpaletsDetail']);
    Route::post('exportRepairDetail/{id}', [RepairController::class, 'exportRepairDetail']);
    Route::post('exportMigrateDetail/{id}', [MigrateDocumentController::class, 'exportMigrateDetail']);
    Route::post('exportBuyers', [BuyerController::class, 'exportBuyers']);
    Route::post('exportUsers', [UserController::class, 'exportUsers']);

    // Archive
    Route::post('archive_storage_exports', [ArchiveStorageController::class, 'exports']);
});

// Akses: Semua role operasional
// Fitur: Notifikasi
Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Crew,Reparasi,Team leader,Admin Kasir,Kasir leader'])->group(function () {
    Route::get('notificationByRole', [NotificationController::class, 'getNotificationByRole']);
    Route::get('documents-approve', [ProductApproveController::class, 'documentsApprove']);
    Route::get('notif_widget', [NotificationController::class, 'notifWidget']);
});

// ========================================================================================================
// 9. COLLABORATION / MTC / SPECIFIC FEATURES
// ========================================================================================================

// Akses: Admin, Spv, Team leader, Crew, Developer
// Fitur: Palet Attributes (Brands, Conditions), Sync Palet, Product Collab
Route::middleware('auth.multiple:Admin,Spv,Team leader,Crew,Developer')->group(function () {
    // Palet Attributes
    Route::resource('product-brands', ProductBrandController::class);
    Route::resource('product-conditions', ProductConditionController::class);
    Route::resource('product-statuses', ProductStatusController::class);
    Route::get('product-statuses2', [ProductStatusController::class, 'index2']);
    Route::resource('palet-brands', PaletBrandController::class)->except(['update']);
    Route::put('palet-brands/{palet_id}', [PaletBrandController::class, 'update'])->name('palet-brands.update');
    Route::resource('palet-images', PaletImageController::class)->except(['update', 'show']);
    Route::put('palet-images/{palet_id}', [PaletImageController::class, 'update'])->name('palet-images.update');
    Route::get('palet-images/{palet_id}', [PaletImageController::class, 'show'])->name('palet-images.sh ow');

    // Palet Sync
    Route::get('palets', [PaletController::class, 'index2']);
    Route::get('syncPalet', [PaletController::class, 'syncPalet']);
    Route::get('palets-detail/{palet}', [PaletController::class, 'show']);
    Route::put('palet/{palet}', [PaletController::class, 'update']);
    Route::post('addPalet', [PaletController::class, 'store']);
    Route::delete('palets/{palet}', [PaletController::class, 'destroy']);
    Route::delete('palet_pdf/{id_palet}', [PaletController::class, 'delete_pdf_palet']);

    // General Lists
    Route::get('productBycategory', [NewProductController::class, 'getByCategory']);
    Route::get('list-categories', [CategoryController::class, 'index']);
    Route::get('list-categories2', [CategoryPaletController::class, 'index2']);
    // Route::resource('color_tags2', ColorTag2Controller::class)->except(['destroy']); // Duplikat middleware, tapi dibiarkan jika logic auth.multiple beda

    // Product Input & Scans (Collab)
    Route::resource('product_inputs', ProductInputController::class);
    Route::get('filter-product-input', [FilterProductInputController::class, 'index']);
    Route::post('filter-product-input/{id}/add', [FilterProductInputController::class, 'store']);
    Route::delete('filter-product-input/destroy/{id}', [FilterProductInputController::class, 'destroy']);
    Route::post('move_products', [ProductInputController::class, 'move_products']);
    Route::resource('product_scans', ProductScanController::class);
    Route::get('product_scan_search ', [ProductScanController::class, 'product_scan_search']);
    Route::post('move_to_staging ', [ProductScanController::class, 'move_to_staging']);
    Route::post('to_product_input ', [ProductScanController::class, 'to_product_input']);
    Route::post('addProductById/{id}', [NewProductController::class, 'addProductById']);
});

// ========================================================================================================
// 10. FRONTEND REQUESTS & COMPLEX LOGIC
// ========================================================================================================

// Akses: Semua Role Login
// Fitur: Check Login, SO Category/Color, B2B Document, Karung (Bag), Export Status
Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Team leader,Admin Kasir,Crew,Reparasi,Kasir leader,Developer'])->group(function () {
    Route::get('checkLogin', [UserController::class, 'checkLogin']);

    // SO Category & Color
    Route::get('search_so', [SummarySoCategoryController::class, 'searchSo']);
    Route::post('update_check', [SummarySoCategoryController::class, 'update_check']);
    Route::post('additionalProductSo', [ProductApproveController::class, 'additionalProductSo']);
    Route::get('filter_so_user', [SummarySoCategoryController::class, 'filterSoUser']);
    Route::resource('summary_so_category', SummarySoCategoryController::class)->except(['destroy', 'update']);
    Route::resource('summary_so_color', SummarySoColorController::class)->except(['destroy', 'update']);
    Route::post('so_color', [SummarySoColorController::class, 'soColor']);

    // B2B & Bulky Docs
    Route::get('bulky-documents', [BulkyDocumentController::class, 'index']);
    Route::get('bulky-documents/{bulkyDocument}', [BulkyDocumentController::class, 'show']);
    Route::put('bulky-documents/{bulkyDocument}', [BulkyDocumentController::class, 'update']);

    // Bag (Karung)
    Route::post('add_product_to_bag', [BulkySaleController::class, 'store2']);
    Route::post('add_new_bag', [BagProductsController::class, 'store']);
    Route::delete('bag_product/{bagProducts}', [BagProductsController::class, 'destroy']);
    Route::get('bag_by_user', [BagProductsController::class, 'index']);
    Route::get('bags/{bagProducts}', [BagProductsController::class, 'show']);

    // Sales & Buyers Lists
    Route::get('sale-products', [SaleController::class, 'products']);
    Route::get('buyers', [BuyerController::class, 'index']);

    // Palet Filter Sync
    Route::get('bulky-filter-approve/{user_id}', [PaletController::class, 'bulkyFilterApprove']);
    Route::get('bulky-filter-palet', [PaletController::class, 'listFilterToBulky']);
    Route::post('bulky-filter-palet/{paletId}', [PaletController::class, 'addFilterBulky']);
    Route::delete('bulky-filter-palet/{paletId}', [PaletController::class, 'toUnFilterBulky']);
    Route::post('bulky-filter-to-approve', [PaletController::class, 'updateToApprove']);

    // Export Request Flow
    Route::post('export-buyers/request', [BuyerController::class, 'requestExportBuyer']);
    Route::get('export-buyers/approvals', [BuyerController::class, 'getPendingExportRequests']);
    Route::get('export-buyers/download/{id}', [BuyerController::class, 'downloadApprovedExport']);
    Route::get('export-buyers/status/{id}', [BuyerController::class, 'checkExportStatus']);

    // Damaged Document Session
    Route::get('damaged/active-session', [DamagedDocumentController::class, 'getActiveSession']);
    Route::delete('damaged/remove-product', [DamagedDocumentController::class, 'removeProduct']);
    Route::post('damaged/add-product', [DamagedDocumentController::class, 'addProduct']);
    Route::post('damaged/add-all-product', [DamagedDocumentController::class, 'addAllToCart']);
    Route::put('damaged/{id}/finish', [DamagedDocumentController::class, 'finish']);
    Route::put('damaged/{id}/lock', [DamagedDocumentController::class, 'lockSession']);
    Route::get('damaged/{id}/export', [DamagedDocumentController::class, 'exportDamaged']);
    Route::get('export-damaged-document', [DamagedDocumentController::class, 'exportAllProductsDamaged']);
    Route::apiResource('damaged', DamagedDocumentController::class);

    // Non Document Session
    Route::get('non/active-session', [NonDocumentController::class, 'getActiveSession']);
    Route::delete('non/remove-product', [NonDocumentController::class, 'removeProduct']);
    Route::post('non/add-product', [NonDocumentController::class, 'addProduct']);
    Route::post('non/add-all-product', [NonDocumentController::class, 'addAllToCart']);
    Route::put('non/{id}/finish', [NonDocumentController::class, 'finish']);
    Route::put('non/{id}/lock', [NonDocumentController::class, 'lockSession']);
    Route::get('non/{id}/export', [NonDocumentController::class, 'exportNon']);
    Route::get('export-non-document', [NonDocumentController::class, 'exportAllProductsNon']);
    Route::apiResource('non', NonDocumentController::class);

    // Abnormal Document Session
    Route::get('abnormal/active-session', [AbnormalDocumentController::class, 'getActiveSession']);
    Route::delete('abnormal/remove-product', [AbnormalDocumentController::class, 'removeProduct']);
    Route::post('abnormal/add-product', [AbnormalDocumentController::class, 'addProduct']);
    Route::post('abnormal/add-all-product', [AbnormalDocumentController::class, 'addAllToCart']);
    Route::put('abnormal/{id}/finish', [AbnormalDocumentController::class, 'finish']);
    Route::put('abnormal/{id}/lock', [AbnormalDocumentController::class, 'lockSession']);
    Route::get('abnormal/{id}/export', [AbnormalDocumentController::class, 'exportAbnormal']);
    Route::get('export-abnormal-document', [AbnormalDocumentController::class, 'exportAllProductsAbnormal']);
    Route::apiResource('abnormal', AbnormalDocumentController::class);
});

// ========================================================================================================
// 11 SO Product
// ========================================================================================================

Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Team leader,Admin Kasir,Crew,Reparasi,Kasir leader,Developer'])->group(function () {
    Route::post('racks/{id}/reset-so', [ProductSoController::class, 'resetSo']);
    Route::post('racks/so', [ProductSoController::class, 'soRackByBarcode']);
    Route::post('racks/so-staging-display', [ProductSoController::class, 'soScanInDisplayRack']);
    Route::post('racks/{id}/so', [ProductSoController::class, 'actionSo']);
    Route::post('staging-products/so', [ProductSoController::class, 'soStagingProduct']);
    Route::post('display-products/so', [ProductSoController::class, 'soDisplayProduct']);
    Route::post('migrate-repair-products/so', [ProductSoController::class, 'soMigrateRepairProduct']);
    Route::post('abnormal-products/so', [ProductSoController::class, 'soAbnomalProduct']);
    Route::post('damaged-products/so', [ProductSoController::class, 'soDamagedProduct']);
    Route::post('non-products/so', [ProductSoController::class, 'soNonProduct']);
    Route::post('b2b-documents/so', [ProductSoController::class, 'soB2BDocument']);
});

Route::middleware(['auth:sanctum', 'check.role:Admin,Spv'])->group(function () {
    Route::post('sku/upload-excel', [SkuDocumentController::class, 'processExcelFiles']);
    Route::post('sku/map-merge', [SkuDocumentController::class, 'mapAndMergeHeaders']);

    Route::post('sku/change-barcode-document', [SkuDocumentController::class, 'changeBarcodeDocument']);
    Route::delete('sku/remove-barcode-document', [SkuDocumentController::class, 'deleteCustomBarcode']);

    Route::post('sku-products/add-bundle/{id}', [SkuProductController::class, 'storeBundle']);
    Route::post('sku-products/add-damaged/{id}', [SkuProductController::class, 'storeDamaged']);
    
    Route::resource('sku-products', SkuProductController::class);
    Route::resource('sku-product-old', SkuProductOldController::class);
    Route::resource('sku-documents', SkuDocumentController::class);


});


// ========================================================================================================
// 12 DEVELOPMENT, DEBUG & LOOSE ROUTES
// ========================================================================================================

// Debug & Tools
Route::post('findSimilarTabel', [StagingApproveController::class, 'findSimilarTabel']);
Route::post('findDifferenceTable', [StagingApproveController::class, 'findDifferenceTable']);
Route::get('difference', [StagingApproveController::class, 'findDifference']);
Route::delete('deleteDuplicateOldBarcodes', [StagingApproveController::class, 'deleteDuplicateOldBarcodes']);
Route::post('importDataNoSo', [BarcodeDamagedController::class, 'importExcelToBarcodeDamaged']);
Route::get('setCache', [StagingApproveController::class, 'cacheProductBarcodes']);
Route::get('selectionDataRedis', [StagingApproveController::class, 'dataSelectionRedis']);
Route::get('getCategoryNull', [SaleController::class, 'getCategoryNull']);
Route::get('exportSale', [SaleController::class, 'exportSale']);
Route::get('invoiceSale/{id}', [SaleDocumentController::class, 'invoiceSale']);
Route::get('export-sale-month', [SaleController::class, 'exportSaleMonth']);
Route::get('export-category-color-null', [NewProductController::class, 'exportCategoryColorNull']);
Route::post('export_product_byColor', [NewProductController::class, 'exportProductByColor']);

// Cronjob / Batch Checks
Route::get('check-manifest-onGoing', [DocumentController::class, 'checkDocumentOnGoing']);
Route::get('countStaging', [StagingProductController::class, 'countPrice']);
Route::post('archieve', [ArchiveStorageController::class, 'store']);
Route::post('archieve2', [ArchiveStorageController::class, 'store2']);
Route::post('archiveTest/{month}/{year}', [DashboardController::class, 'storageReport2']);
Route::post('exportMasSugeng', [NewProductController::class, 'exportMasSugeng']);
Route::post('exportTemplateBulking', [NewProductController::class, 'exportTemplate']);
Route::get('updatePricesFromExcel', [RiwayatCheckController::class, 'updatePricesFromExcel']);
Route::get('validateExcelData', [RiwayatCheckController::class, 'validateExcelData']);

// Buyer Loyalty Batch
Route::get('recalculateBuyerLoyalty', [BuyerLoyaltyController::class, 'recalculateBuyerLoyalty']);
Route::post('recalculate-buyer-loyalty', [BuyerLoyaltyController::class, 'recalculateBuyerLoyalty']);
Route::post('traceExpired', [BuyerLoyaltyController::class, 'traceExpired']);
Route::get('expired-buyer', [BuyerLoyaltyController::class, 'expireBuyerLoyalty']);
Route::get('info-transaction', [BuyerLoyaltyController::class, 'infoTransaction']);

// Summary Sync
Route::get('list-summary-both', [SummaryController::class, 'listSummaryBoth']);
Route::post('summary-inbound', [SummaryController::class, 'summaryInbound']);
Route::post('summary-outbound', [SummaryController::class, 'summaryOutbound']);
Route::get('export-combined-summary-inbound', [SummaryController::class, 'exportCombinedSummaryInbound']);
Route::get('export-combined-summary-outbound', [SummaryController::class, 'exportCombinedSummaryOutbound']);
Route::get('summary-begin-balance', [SummaryController::class, 'summaryBeginBalance']);
Route::get('summary-ending-balance', [SummaryController::class, 'summaryEndingBalance']);

// Misc
Route::get('/monthly-buyers', [BuyerController::class, 'getBuyerMonthlyPoints']);
Route::get('/summary-buyers', [App\Http\Controllers\BuyerController::class, 'getBuyerSummary']);

Route::post('/migrate-new-to-staging', [ProductSoController::class, 'migrateSpecificNewToStaging']);