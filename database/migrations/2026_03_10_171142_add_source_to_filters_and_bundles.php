<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('product__filters', function (Blueprint $table) {
            $table->string('source')->nullable()->after('id');
        });

        Schema::table('product__bundles', function (Blueprint $table) {
            $table->string('source')->nullable()->after('id');
        });

        Schema::table('bundles', function (Blueprint $table) {
            $table->string('source')->nullable()->after('id');
        });
    }

    public function down()
    {
        Schema::table('product__filters', function (Blueprint $table) {
            $table->dropColumn('source');
        });

        Schema::table('product__bundles', function (Blueprint $table) {
            $table->dropColumn('source');
        });

        Schema::table('bundles', function (Blueprint $table) {
            $table->dropColumn('source');
        });
    }
};
