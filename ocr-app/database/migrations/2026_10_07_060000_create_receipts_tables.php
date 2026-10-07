<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Spec 3.3: receipts (receipt, invoice, paid receipt), receipt_taxes, receipt_lines, validation_results.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('vendor_name')->nullable();
            $table->string('vendor_address')->nullable();
            $table->string('vendor_phone')->nullable();
            $table->char('registration_number', 14)->nullable();
            $table->date('issue_date')->nullable();
            $table->string('invoice_number', 50)->nullable();
            $table->date('due_date')->nullable();
            $table->string('recipient_name')->nullable();
            $table->integer('subtotal')->nullable();
            $table->integer('total')->nullable();
            // Not in the original spec: 源泉徴収税 on invoices, used by V-05.
            $table->integer('withholding_tax')->nullable();
            $table->string('tax_mode', 10)->nullable();
            $table->string('payment_method', 30)->nullable();
            $table->integer('cash_received')->nullable();
            $table->integer('change')->nullable();
            $table->string('purpose')->nullable();
            $table->json('field_confidence')->nullable();
            $table->timestamps();

            $table->index(['vendor_name', 'issue_date', 'total']);
        });

        Schema::create('receipt_taxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('tax_rate');
            $table->integer('taxable_amount');
            $table->integer('tax_amount');
            $table->timestamps();
        });

        Schema::create('receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('receipt_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('name');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->integer('unit_price')->nullable();
            $table->integer('amount');
            $table->unsignedTinyInteger('tax_rate');
            $table->integer('discount')->default(0);
            $table->timestamps();
        });

        Schema::create('validation_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ocr_run_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('rule_code', 10);
            $table->string('level', 10);
            $table->string('message');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('validation_results');
        Schema::dropIfExists('receipt_lines');
        Schema::dropIfExists('receipt_taxes');
        Schema::dropIfExists('receipts');
    }
};
