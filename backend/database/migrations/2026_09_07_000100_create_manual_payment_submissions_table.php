<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bank-card (Rial) transfers that wait for an admin to confirm the receipt
 * before the learner's plan is upgraded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_payment_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_price_id')->nullable()->constrained('plan_prices')->nullOnDelete();
            $table->foreignId('payment_attempt_id')->nullable()->constrained('payment_attempts')->nullOnDelete();
            $table->string('status', 32)->default('pending_transfer');
            // TRY amount in minor units (kuruş), matching plan_prices.amount.
            $table->unsignedBigInteger('amount_try');
            // Whole Iranian Rials to transfer (no decimal subunit).
            $table->unsignedBigInteger('amount_irr');
            $table->decimal('fx_rate', 14, 4);
            $table->string('payer_name', 120)->nullable();
            $table->string('receipt_path', 512)->nullable();
            $table->string('receipt_original_name', 255)->nullable();
            $table->text('admin_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('receipt_uploaded_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manual_payment_submissions');
    }
};
