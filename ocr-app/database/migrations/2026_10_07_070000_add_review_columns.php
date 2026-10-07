<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Milestone 5 (review and correction). Not in the original spec 3.3:
// - ocr_runs.extracted: what each engine's run extracted, compared with the confirmed receipt (FR-18)
// - ocr_runs.images: the engine's preprocessed page images, so OCR boxes can be drawn on them
// - receipts.field_boxes: where on the page each field was read
// - receipts.corrected_fields: fields the user changed by hand
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ocr_runs', function (Blueprint $table) {
            $table->json('extracted')->nullable();
            $table->json('images')->nullable();
        });

        Schema::table('receipts', function (Blueprint $table) {
            $table->json('field_boxes')->nullable();
            $table->json('corrected_fields')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ocr_runs', fn (Blueprint $table) => $table->dropColumn(['extracted', 'images']));
        Schema::table('receipts', fn (Blueprint $table) => $table->dropColumn(['field_boxes', 'corrected_fields']));
    }
};
