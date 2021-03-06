<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreatePricePackagesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('price_packages', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->unsignedBigInteger('city_id');
            $table->text('description')->nullable();
            $table->text('description_ar')->nullable();
            $table->json('price');
            $table->string('per');
            $table->string('photo')->nullable();
            $table->smallInteger('order');
            $table->enum('type', ['TOSCHOOL','TOWORK']);
            $table->boolean('is_public')->default(true);
            $table->timestamps();

            $table->index('city_id');
            $table->index('type');

            $table->foreign('city_id')->references('id')->on('cities')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('price_packages');
    }
}
