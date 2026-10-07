<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Spec 3.3 "documents": one uploaded file.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->nullable();
            $table->string('status', 20)->index();
            $table->string('original_name');
            $table->string('file_path');
            $table->string('mime_type', 100);
            $table->char('sha256', 64)->index();
            $table->string('route', 20)->nullable();
            // No foreign key: documents and ocr_runs would reference each other,
            // and SQLite cannot add the constraint after ocr_runs exists.
            $table->unsignedBigInteger('selected_run_id')->nullable()->index();
            $table->text('error_message')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
