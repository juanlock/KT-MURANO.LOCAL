<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateTraysTable extends Migration
{
    public function up()
    {
        if (Schema::hasTable('trays')) {
            return;
        }

        Schema::create('trays', function (Blueprint $table) {
            $table->engine = 'InnoDB';

            $table->integer('id', true);
            $table->string('name', 192);
            $table->text('description')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down()
    {
        Schema::dropIfExists('trays');
    }
}
