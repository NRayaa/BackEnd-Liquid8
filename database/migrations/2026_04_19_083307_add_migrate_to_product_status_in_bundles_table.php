<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up()
    {
        DB::statement("ALTER TABLE bundles MODIFY COLUMN product_status ENUM('sale', 'not sale', 'bundle', 'migrate')");
    }

    public function down()
    {
        DB::statement("ALTER TABLE bundles MODIFY COLUMN product_status ENUM('sale', 'not sale', 'bundle',) DEFAULT");
    }
};
