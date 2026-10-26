<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->text('proposal_text');
            $table->text('edited_text')->nullable();
            $table->decimal('bid_amount', 12, 2);
            $table->string('currency', 8)->default('USD');
            $table->boolean('is_hourly')->default(false);
            $table->unsignedInteger('delivery_days')->nullable();
            $table->json('milestones')->nullable();
            $table->string('price_reason')->nullable();
            $table->json('questions_to_ask_later')->nullable();
            $table->json('check_failures')->nullable();
            $table->string('instruction')->nullable();
            $table->unsignedTinyInteger('attempts')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('drafts');
    }
};
