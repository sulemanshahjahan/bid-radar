<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('past_projects', function (Blueprint $table) {
            $table->id();
            $table->string('category')->default('other');
            $table->text('problem');
            $table->text('what_i_did');
            $table->text('result')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('past_projects');
    }
};
