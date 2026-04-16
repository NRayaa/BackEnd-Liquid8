<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('migrates', function (Blueprint $table) {
            $table->unsignedBigInteger('color_rack_id')->nullable()->after('code_document_migrate');
            
            $table->string('product_color')->nullable()->change();
            $table->integer('product_total')->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('migrates', function (Blueprint $table) {
            $table->dropColumn('color_rack_id');
        });
    }
};
