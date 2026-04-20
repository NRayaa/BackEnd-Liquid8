<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up()
    {
        Schema::create('bkl_scanned_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bkl_document_id')->constrained('bkl_documents')->cascadeOnDelete();
            $table->foreignId('new_product_id')->nullable()->constrained('new_products')->cascadeOnDelete();
            $table->foreignId('bundle_id')->nullable()->constrained('bundles')->cascadeOnDelete();
            $table->string('barcode');
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('bkl_scanned_products');
    }
};