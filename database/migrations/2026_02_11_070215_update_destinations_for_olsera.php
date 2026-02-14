<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->softDeletes();

            $table->boolean('is_olsera_integrated')->default(false)->after('id');
            $table->string('olsera_app_id')->nullable()->after('is_olsera_integrated');
            $table->text('olsera_secret_key')->nullable()->after('olsera_app_id');

            $table->text('olsera_access_token')->nullable()->after('olsera_secret_key');
            $table->text('olsera_refresh_token')->nullable()->after('olsera_access_token');
            $table->timestamp('olsera_token_expires_at')->nullable()->after('olsera_refresh_token');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('destinations', function (Blueprint $table) {
            $table->dropSoftDeletes();
            $table->dropColumn([
                'is_olsera_integrated',
                'olsera_app_id',
                'olsera_secret_key',
                'olsera_access_token',
                'olsera_refresh_token',
                'olsera_token_expires_at'
            ]);
        });
    }
};
