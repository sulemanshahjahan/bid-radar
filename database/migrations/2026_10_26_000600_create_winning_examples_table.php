<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('winning_examples', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->string('category')->index();
            $table->text('proposal_text');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('winning_examples');
    }
};
