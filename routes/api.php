<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BklController;
use App\Http\Controllers\BulkyDocumentController;
use App\Http\Controllers\BulkySaleController;
use App\Http\Controllers\BundleController;
use App\Http\Controllers\BundleQcdController;
use App\Http\Controllers\BuyerController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CheckConnectionController;
use App\Http\Controllers\ColorTag2Controller;
use App\Http\Controllers\ColorTagController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DestinationController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\FilterBklController;
use App\Http\Controllers\FilterProductInputController;
use App\Http\Controllers\FilterQcdController;
use App\Http\Controllers\FilterStagingController;
use App\Http\Controllers\FormatBarcodeController;
use App\Http\Controllers\GenerateController;
use App\Http\Controllers\MigrateBulkyController;
use App\Http\Controllers\MigrateBulkyProductController;
use App\Http\Controllers\MigrateController;
use App\Http\Controllers\MigrateDocumentController;
use App\Http\Controllers\NewProductController;
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
use App\Http\Controllers\ProductStatusController;
use App\Http\Controllers\PromoController;
use App\Http\Controllers\RepairController;
use App\Http\Controllers\RepairFilterController;
use App\Http\Controllers\RepairProductController;
use App\Http\Controllers\RiwayatCheckController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SaleDocumentController;
use App\Http\Controllers\StagingApproveController;
use App\Http\Controllers\StagingProductController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserScanWebController;
use App\Http\Controllers\VehicleTypeController;
use App\Http\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::fallback(function () {
   return response()->json(['status' => false, 'message' => 'Not Found!'], 404);
});

//patokan urutan role : Admin,Spv,Team leader,Admin Kasir,Crew,Reparasi,

// =========================================== Dashboard ==================================================

// Dashboard : Admin,Spv,Team leader,Admin Kasir
Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Team leader,Admin Kasir'])->group(function () {
   Route::get('dashboard', [DashboardController::class, 'index']);
   Route::get('dashboard2', [DashboardController::class, 'index2']);
   Route::get('dashboard/summary-transaction', [DashboardController::class, 'summaryTransaction']);
   Route::get('dashboard/summary-sales', [DashboardController::class, 'summarySales']);
   Route::get('dashboard/storage-report', [DashboardController::class, 'storageReport']);
   Route::get('dashboard/storage-report/export', [DashboardController::class, 'exportStorageReport']);
   Route::get('dashboard/monthly-analytic-sales', [DashboardController::class, 'monthlyAnalyticSales']);
   Route::get('dashboard/monthly-analytic-sales/export', [DashboardController::class, 'exportMonthlyAnalyticSales']);
   Route::get('dashboard/yearly-analytic-sales', [DashboardController::class, 'yearlyAnalyticSales']);
   Route::get('dashboard/yearly-analytic-sales/export', [DashboardController::class, 'exportYearlyAnalyticSales']);
   Route::get('dashboard/general-sales', [DashboardController::class, 'generalSale']);
   Route::get('dashboard/analytic-slow-moving', [DashboardController::class, 'analyticSlowMoving']);
   Route::get('export/product-expired', [DashboardController::class, 'productExpiredExport']);
});

// end dashboard =========================================== Dashboard ==================================================

//=========================================== inbound ==========================================================

//inbound process, check history, check product, Manual inbound : Admin,Spv,Team leader, kasir leader
Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Team leader,Kasir leader,Admin Kasir'])->group(function () {
   //generates file excel -> input data ekspedisi
   Route::post('/generate', [GenerateController::class, 'processExcelFiles']);
   Route::post('/generate/merge-headers', [GenerateController::class, 'mapAndMergeHeaders']);

   //scam
   //change barcode
   Route::post('changeBarcodeDocument', [DocumentController::class, 'changeBarcodeDocument']);

   //product approve
   Route::resource('product-approves', ProductApproveController::class);

   Route::get('productApprovesByDoc', [ProductApproveController::class, 'searchByDocument']);
   // Route::delete('delete_all_by_codeDocument', [ProductApproveController::class, 'delete_all_by_codeDocument']);
   Route::get('product-approveByDoc/{code_document}', [ProductApproveController::class, 'productsApproveByDoc'])
      ->where('code_document', '.*');

   // Route::delete('/delete-all-documents', [DocumentController::class, 'deleteAll']);
   Route::get('/documentDone', [DocumentController::class, 'documentDone']);
   Route::get('/documentInProgress', [DocumentController::class, 'documentInProgress']);
   Route::get('get-latestPrice', [NewProductController::class, 'getLatestPrice']);

   //riwayat
   Route::resource('historys', RiwayatCheckController::class)->except(['destroy']);
   Route::get('riwayat-document/code_document', [RiwayatCheckController::class, 'getByDocument']);
   Route::post('history/exportToExcel', [RiwayatCheckController::class, 'exportToExcel']);

   //notifications
   Route::resource('notifications', NotificationController::class)->except(['destroy']);

   //manual inbound
   Route::post('add_product', [NewProductController::class, 'addProductByAdmin']);

   Route::resource('user_scan_webs', UserScanWebController::class);
   Route::get('user_scan_webs/{code_document}', [UserScanWebController::class, 'detail_user_scan'])->where('code_document', '.*');

   Route::get('total_scan_users', [UserScanWebController::class, 'total_user_scans']);
});

//manifest inbound, histroy index : Admin,Spv,Team leader,Crew
Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Team leader,Crew'])->group(function () {

   //product old
   Route::get('product_olds-search', [ProductOldController::class, 'searchByDocument']);
   // Route::delete('delete-all-products-old', [ProductOldController::class, 'deleteAll']);
   Route::get('search_barcode_product', [ProductOldController::class, 'searchByBarcode']);

   //send approve
   Route::post('product-approves', [ProductApproveController::class, 'store']);
   Route::post('addProductOld', [ProductApproveController::class, 'addProductOld']);

   //document
   Route::resource('/documents', DocumentController::class)->except(['destroy']);

   //riwayat
   Route::get('historys', [RiwayatCheckController::class, 'index']);
   Route::post('historys', [RiwayatCheckController::class, 'store']);
   Route::get('getProductLolos/{code_document}', [ProductOldController::class, 'getProductLolos'])->where('code_document', '.*');
   Route::get('getProductDamaged/{code_document}', [ProductOldController::class, 'getProductDamaged'])->where('code_document', '.*');
   Route::get('getProductAbnormal/{code_document}', [ProductOldController::class, 'getProductAbnormal'])->where('code_document', '.*');
   Route::get('discrepancy/{code_document}', [ProductOldController::class, 'discrepancy'])->where('code_document', '.*');
});

//inbound bulking
Route::middleware(['auth:sanctum', 'check.role:Admin,Spv'])->group(function () {
   //bulking
   Route::post('/excelOld', [StagingProductController::class, 'processExcelFilesCategoryStaging']);
   // Route::post('/excelOld', [StagingProductController::class, 'importProductApprove']);
   Route::post('/bulkingInventory', [NewProductController::class, 'processExcelFilesCategory']);
   // Route::post('/excelOld/merge', [NewProductController::class, 'mapAndMergeHeadersCategory']);
   Route::post('/bulking_tag_warna', [NewProductController::class, 'processExcelFilesTagColor']);
});

//end inbound =========================================== inbound ==========================================================

//=========================================== Staging ==========================================================

// Admin,Spv,Admin Kasir
Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Kasir leader,Admin Kasir,Team leader'])->group(function () {
   //store nya untuk mindah ke approve staging
   Route::resource('staging_products', StagingProductController::class);
   Route::get('staging/filter_product', [FilterStagingController::class, 'index']);
   Route::post('staging/filter_product/{id}/add', [FilterStagingController::class, 'store']);
   Route::post('staging/move_to_lpr/{id}', [StagingProductController::class, 'toLpr']);
   Route::delete('staging/filter_product/destroy/{id}', [FilterStagingController::class, 'destroy']);
   Route::get('export-staging', [StagingProductController::class, 'export']);
   Route::resource('staging_approves', StagingApproveController::class);

   Route::post('batchToLpr', [StagingProductController::class, 'batchToLpr']);
   Route::delete('deleteToLprBatch', [StagingProductController::class, 'deleteToLprBatch']);
});

Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Kasir leader,Admin Kasir'])->group(function () {
   //untuk spv me approve staging ke inventory
   Route::post('stagingTransactionApprove', [StagingApproveController::class, 'stagingTransaction']);

   //ppn
   Route::resource('ppn', PpnController::class);
   Route::put('ppn-set-default/{id}', [PpnController::class, 'set_default']);
});

//end staging =========================================== Staging ==========================================================

//=========================================== Inventory ==========================================================
//product by category,color : Admin,Spv,Team leader,Admin Kasir

Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Team leader,Admin Kasir,Kasir leader'])->group(function () {

   //slow moving product
   //list product r
   Route::get('new_product/expired', [NewProductController::class, 'listProductExp']);

   //promo
   Route::get('promo', [PromoController::class, 'index']);
   Route::get('promo/{id}', [PromoController::class, 'show']);
   Route::post('promo', [PromoController::class, 'store']);
   Route::put('promo/{promo}', [PromoController::class, 'update']);
   Route::delete('promo/destroy/{promoId}/{productId}', [PromoController::class, 'destroy']);

   Route::resource('new_products', NewProductController::class)->except(['destroy']);
});

Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Team leader,Admin Kasir,Reparasi'])->group(function () {
   //tabel kiri repair
   Route::get('new_product/display-expired', [NewProductController::class, 'listProductExpDisplay']);

   //to display
   Route::get('getProductRepair', [RepairController::class, 'getProductRepair']);

   //qcd
   Route::get('qcd/filter_product', [FilterQcdController::class, 'index']);
   Route::post('qcd/filter_product/{id}/add', [FilterQcdController::class, 'store']);
   Route::delete('qcd/destroy/{id}', [FilterQcdController::class, 'destroy']);
   Route::get('bundle/qcd', [BundleQcdController::class, 'index']);
   Route::get('bundle/qcd/{bundleQcd}', [BundleQcdController::class, 'show']);
   Route::post('bundle/qcd', [ProductQcdController::class, 'store']);
   Route::delete('bundle/qcd/{bundleQcd}', [BundleQcdController::class, 'destroy']);

   //filters product repair
   Route::get('repair-mv/filter_product', [RepairFilterController::class, 'index']);
   Route::post('repair-mv/filter_product/{id}/add', [RepairFilterController::class, 'store']);
   Route::delete('repair-mv/filter_product/destroy/{id}', [RepairFilterController::class, 'destroy']);

   //repair
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
   //list dump
   Route::get('/dumps', [NewProductController::class, 'listDump']);
   Route::put('/update-dumps/{id}', [NewProductController::class, 'updateDump']);
   Route::put('/update-repair-dump/{id}', [RepairProductController::class, 'updateRepair']);
   Route::put('/update-priceDump/{id}', [NewProductController::class, 'updatePriceDump']);
   Route::get('/export-dumps-excel/{id}', [NewProductController::class, 'exportDumpToExcel']);
});

Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Team leader,Developer'])->group(function () {

   //filter product bundle - mtc 
   Route::get('bundle-scans/filter_product', [ProductFilterController::class, 'listFilterScans']);
   Route::post('bundle-scans/filter_product/{id}', [ProductBundleController::class, 'addFilterScan']);
   Route::delete('bundle-scans/filter_product/{id}', [ProductFilterController::class, 'destroyFilterScan']);

   //bundle-scans
   Route::get('bundle-scans', [BundleController::class, 'listBundleScan']);
   Route::post('bundle-scans', [ProductBundleController::class, 'createBundleScan']);
   Route::delete('bundle-scans/{bundle}', [BundleController::class, 'unbundleScan']);
   Route::post('bundle-scans/product/{bundle}', [ProductBundleController::class, 'addProductInBundle']);
   Route::delete('bundle-scans/product/{bundle}', [ProductBundleController::class, 'destroyProductBundle']);
});


Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Team leader,Developer'])->group(function () {

   //filters product bundle
   Route::get('bundle/filter_product', [ProductFilterController::class, 'index']);
   Route::post('bundle/filter_product/{id}/add', [ProductFilterController::class, 'store']);
   Route::delete('bundle/filter_product/destroy/{id}', [ProductFilterController::class, 'destroy']);

   //bundle
   Route::get('bundle', [BundleController::class, 'index']);
   Route::get('bundle/{bundle}', [BundleController::class, 'show']);
   Route::put('bundle/{bundle}', [BundleController::class, 'update']);
   Route::post('bundle', [ProductBundleController::class, 'store']);
   Route::delete('bundle/{bundle}', [BundleController::class, 'destroy']);
   Route::get('product-bundle/{new_product}/{bundle}/add', [ProductBundleController::class, 'addProductBundle']);
   Route::delete('product-bundle/{productBundle}', [ProductBundleController::class, 'destroy']);

   Route::get('bundle/product', [ProductBundleController::class, 'index']);
   Route::delete('bundle/destroy/{id}', [ProductBundleController::class, 'destroy']);

   //warehouse
   Route::resource('warehouses', WarehouseController::class);
});

Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Team leader,Crew'])->group(function () {
   //palet filter
   Route::get('palet/filter_product', [PaletFilterController::class, 'index']);
   Route::post('palet/filter_product/{barcode}/add', [PaletFilterController::class, 'store']);
   Route::delete('palet/filter_product/destroy/{id}', [PaletFilterController::class, 'destroy']);

   //palet
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
});

Route::middleware(['auth:sanctum', 'check.role:Admin,Spv'])->group(function () {
   //colortags dan category
   Route::resource('categories', CategoryController::class)->except(['destroy']);
   //colortags diskon
   Route::resource('color_tags', ColorTagController::class)->except(['destroy']);
   Route::resource('color_tags2', ColorTag2Controller::class)->except(['destroy']);

   //spv panel
   Route::resource('format-barcodes', FormatBarcodeController::class);

   Route::resource('users', UserController::class)->except(['store']);

   Route::post('panel-spv/add-barcode', [UserController::class, 'addFormatBarcode']);
   Route::delete('panel-spv/format-delete/{id}', [UserController::class, 'deleteFormatBarcode']);
   Route::get('panel-spv/format-barcode', [UserController::class, 'allFormatBarcode']);
   Route::get('format-user', [FormatBarcodeController::class, 'formatsUsers']);

   Route::get('panel-spv/detail/{user}', [UserController::class, 'showFormatBarcode']);
});

//end inventory=========================================== Inventory ==========================================================

//=========================================== Outbound ==========================================================

Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Team leader,Kasir leader'])->group(function () {
   //migrate

   Route::resource('destinations', DestinationController::class)->except(['destroy']);
   Route::get('countColor', [NewProductController::class, 'totalPerColor']); //baru
   Route::get('colorDestination', [NewProductController::class, 'colorDestination']); //baru

   Route::resource('migrates', MigrateController::class)->except(['destroy']);
   Route::get('displayMigrate', [MigrateController::class, 'displayMigrate']);
   Route::post('migrate-finish', [MigrateDocumentController::class, 'MigrateDocumentFinish']);
   Route::resource('migrate-documents', MigrateDocumentController::class)->except(['destroy']);

   Route::get('migrate-bulky', [MigrateBulkyController::class, 'index']);
   Route::get('migrate-bulky/{migrate_bulky}', [MigrateBulkyController::class, 'show']);
   Route::post('migrate-bulky-finish', [MigrateBulkyController::class, 'finishMigrateBulky']);
   Route::get('migrate-bulky-product', [MigrateBulkyProductController::class, 'index']);
   Route::get('migrate-bulky-product/{new_product}/add', [MigrateBulkyProductController::class, 'store']);
   Route::delete('migrate-bulky-product/{migrate_bulky_product}/delete', [MigrateBulkyProductController::class, 'destroy']);
});

Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Admin Kasir,Kasir leader'])->group(function () {
   //sale
   Route::resource('sales', SaleController::class);
   Route::put('/sales/gabor/{sale}', [SaleController::class, 'gabor']);
   Route::put('/sales/update-price/{sale}', [SaleController::class, 'livePriceUpdates']);
   Route::post('sale-documents/bulking-invoice-to-jurnal', [SaleDocumentController::class, 'bulkingInvoiceToJurnal']);
   Route::resource('sale-documents', SaleDocumentController::class)->except(['destroy']);
   Route::post('sale-finish', [SaleDocumentController::class, 'saleFinish']);
   Route::get('sale-report', [SaleDocumentController::class, 'combinedReport']);
   // Route::get('sale-report-by-product', [SaleDocumentController::class, 'combinedReport']);
   Route::get('sale-products', [SaleController::class, 'products']);

   //bulky-sale
   Route::resource('bulky-sales', BulkySaleController::class);
   //bulky-document
   Route::resource('bulky-documents', BulkyDocumentController::class);

   Route::apiResource('buyers', BuyerController::class)->except(['destroy']);

   Route::resource('vehicle-types', VehicleTypeController::class);

   //approve discount get
   Route::get('get_approve_discount/{id_sale_document}', [SaleDocumentController::class, 'get_approve_discount']);
});

Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Kasir leader'])->group(function () {
   Route::put('approved-document/{id_sale_document}', [SaleDocumentController::class, 'approvedDocument']);
   Route::put('approved-product/{id_sale}', [SaleDocumentController::class, 'approvedProduct']);
   Route::put('reject-product/{id_sale}', [SaleDocumentController::class, 'rejectProduct']);
   Route::put('reject-document/{id_sale}', [SaleDocumentController::class, 'rejectAllDiscounts']);
   Route::put('doneApproveDiscount/{id_sale_document}', [SaleDocumentController::class, 'doneApproveDiscount']);
});
//end outbound

//admin
Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Team leader,Admin Kasir,Crew,Reparasi,Kasir leader'])->group(function () {
   //colortags dan category
   Route::get('categories', [CategoryController::class, 'index']);
   Route::get('color_tags', [ColorTagController::class, 'index']);
   Route::get('color_tags2', [ColorTag2Controller::class, 'index']);
   Route::get('product_byColor', [NewProductController::class, 'getTagColor']);
   Route::get('product_byColor2', [NewProductController::class, 'getTagColor2']);

   Route::get('product_byCategory', [NewProductController::class, 'getByCategory']);
   Route::get('getByNameColor', [ColorTagController::class, 'getByNameColor']);
   Route::get('getByNameColor2', [ColorTag2Controller::class, 'getByNameColor2']);

   //filter-bkl
   Route::resource('bkls', BklController::class);
   Route::get('bkl/filter_product', [FilterBklController::class, 'index']);
   Route::post('bkl/filter_product/{id}/add', [FilterBklController::class, 'store']);
   Route::delete('bkl/filter_product/destroy/{id}', [FilterBklController::class, 'destroy']);
   Route::get('export-bkl', [BklController::class, 'exportProduct']);

   //update history
   Route::get('findDataDocs/{code_document}', [DocumentController::class, 'findDataDocs'])->where('code_document', '.*');;
});

Route::middleware(['auth:sanctum', 'check.role:Admin'])->group(function () {
   Route::post('register', [AuthController::class, 'register']);
   // Route::resource('users', UserController::class)->except(['store']);
   Route::resource('roles', RoleController::class);
   Route::post('sale-document/add-product', [SaleDocumentController::class, 'addProductSaleInDocument']);
   Route::delete('sale-document/{sale_document}/{sale}/delete-product', [SaleDocumentController::class, 'deleteProductSaleInDocument']);
   Route::get('generateApikey/{userId}', [UserController::class, 'generateApiKey']);

   // Tombol delete
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

Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Team leader,Kasir leader,Admin Kasir'])->group(function () {
   Route::post('/check-price', [NewProductController::class, 'checkPrice']);
   Route::get('/spv/approve/{notificationId}', [NotificationController::class, 'approveTransaction'])->name('admin.approve');
   Route::post('/partial-staging/{code_document}', [StagingProductController::class, 'partial'])->where('code_document', '.*');

   //export data by menu
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
   Route::post('sale-document/add-product', [SaleDocumentController::class, 'addProductSaleInDocument']);
});

Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Crew,Reparasi,Team leader,Admin Kasir,Kasir leader'])->group(function () {
   Route::get('notificationByRole', [NotificationController::class, 'getNotificationByRole']);
   Route::get('documents-approve', [ProductApproveController::class, 'documentsApprove']);
   Route::get('notif_widget', [NotificationController::class, 'notifWidget']);
});

Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Team leader'])->group(function () {
   Route::get('wms-scan', [UserController::class, 'wmsScans']);
});

//collab mtc
Route::middleware('auth.multiple:Admin,Spv,Team leader,Crew,Developer')->group(function () {
   //=========================================== Api For Bulky ==========================================================
   Route::resource('product-brands', ProductBrandController::class);
   Route::resource('product-conditions', ProductConditionController::class);
   Route::resource('product-statuses', ProductStatusController::class);
   Route::resource('palet-brands', PaletBrandController::class)->except(['update']);
   Route::put('palet-brands/{palet_id}', [PaletBrandController::class, 'update'])->name('palet-brands.update');
   Route::resource('palet-images', PaletImageController::class)->except(['update', 'show']);
   Route::put('palet-images/{palet_id}', [PaletImageController::class, 'update'])->name('palet-images.update');
   Route::get('palet-images/{palet_id}', [PaletImageController::class, 'show'])->name('palet-images.sh ow');
   Route::get('palets', [PaletController::class, 'index2']);
   Route::get('palets-detail/{palet}', [PaletController::class, 'show']);
   Route::put('palets/{palet}', [PaletController::class, 'update']);
   Route::post('addPalet', [PaletController::class, 'store']);
   Route::delete('palets/{palet}', [PaletController::class, 'destroy']);
   Route::delete('palet_pdf/{id_palet}', [PaletController::class, 'delete_pdf_palet']);

   //get
   Route::get('productBycategory', [NewProductController::class, 'getByCategory']);
   Route::get('list-categories', [CategoryController::class, 'index']);

   Route::resource('color_tags2', ColorTag2Controller::class)->except(['destroy']);

   //================================================product-collab======================================================

   //product input
   Route::resource('product_inputs', ProductInputController::class);

   //filter product input
   Route::get('filter-product-input', [FilterProductInputController::class, 'index']);
   Route::post('filter-product-input/{id}/add', [FilterProductInputController::class, 'store']);
   Route::delete('filter-product-input/destroy/{id}', [FilterProductInputController::class, 'destroy']);
   Route::post('move_products', [ProductInputController::class, 'move_products']);

   //inbound-collab
   Route::resource('product_scans', ProductScanController::class);
   Route::get('product_scan_search ', [ProductScanController::class, 'product_scan_search']);
   Route::post('move_to_staging ', [ProductScanController::class, 'move_to_staging']);
   Route::post('to_product_input ', [ProductScanController::class, 'to_product_input']);
   Route::post('addProductById/{id}', [NewProductController::class, 'addProductById']);
});

//all- check user login > request fe
Route::middleware(['auth:sanctum', 'check.role:Admin,Spv,Team leader,Admin Kasir,Crew,Reparasi,Kasir leader,Developer'])->group(function () {
   Route::get('checkLogin', [UserController::class, 'checkLogin']);
});

//non auth

//login
Route::post('login', [AuthController::class, 'login']);

Route::delete('cleargenerate', [GenerateController::class, 'deleteAll']);

Route::delete('deleteAll', [GenerateController::class, 'deleteAllData']);
Route::get('updateCategoryPalet', [PaletController::class, 'updateCategoryPalet']);

// route untuk cek koneksi
Route::get('cek-ping-with-image', [CheckConnectionController::class, 'checkPingWithImage']);

//oret2an debug
Route::post('findSimilarTabel', [StagingApproveController::class, 'findSimilarTabel']);
Route::post('findDifferenceTable', [StagingApproveController::class, 'findDifferenceTable']);
Route::get('difference', [StagingApproveController::class, 'findDifference']);
Route::delete('deleteDuplicateOldBarcodes', [StagingApproveController::class, 'deleteDuplicateOldBarcodes']);
Route::get('setCache', [StagingApproveController::class, 'cacheProductBarcodes']);
Route::get('selectionDataRedis', [StagingApproveController::class, 'dataSelectionRedis']);

Route::post('createDummyData/{count}', [GenerateController::class, 'createDummyData']);

//download template
Route::post('downloadTemplate', [GenerateController::class, 'exportTemplaye']);
Route::get('getCategoryNull', [SaleController::class, 'getCategoryNull']);
Route::get('exportSale', [SaleController::class, 'exportSale']);
Route::get('invoiceSale/{id}', [SaleDocumentController::class, 'invoiceSale']);
Route::get('export-sale-month', [SaleController::class, 'exportSaleMonth']);


//excel
Route::get('export-category-color-null', [NewProductController::class, 'exportCategoryColorNull']);
Route::post('export_product_byColor', [NewProductController::class, 'exportProductByColor']);

//api urgent-> persamaan data check history
Route::get('check-manifest-onGoing', [DocumentController::class, 'checkDocumentOnGoing']);
//test function untuk cronjob cok
// Route::get('testBatchJobs', [ProductApproveController::class, 'processRemainingBatch']);

Route::get('countStaging', [StagingProductController::class, 'countPrice']);
// Route::get('zip-palet/{id_palet}', [PaletController::class, 'zipPalet']);
