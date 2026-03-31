<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('color_racks', function (Blueprint $table) {
           $table->id();
            $table->string('name');
            $table->string('barcode')->unique();
            $table->enum('status', ['display', 'process', 'migrate'])->default('display');
            $table->boolean('is_so')->default(false);
            $table->timestamp('so_at')->nullable();
            $table->foreignId('user_so')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('move_to_migrate_at')->nullable();
            $table->foreignId('user_migrate')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('color_racks');
    }
};
