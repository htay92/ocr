<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Spec 3.3 "ocr_runs": every engine run is kept (FR-11).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocr_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->string('engine', 30);
            $table->string('engine_version', 50)->nullable();
            $table->json('lines')->nullable();
            $table->decimal('avg_confidence', 4, 3)->nullable();
            $table->unsignedSmallInteger('critical_failures')->default(0);
            $table->unsignedInteger('elapsed_ms')->nullable();
            $table->string('error_code', 30)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_runs');
    }
};
