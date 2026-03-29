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
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('color_racks');
    }
};
