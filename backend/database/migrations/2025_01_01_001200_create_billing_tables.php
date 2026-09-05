<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 48)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('interval', 24);                   // monthly|quarterly|annual|lifetime
            $table->unsignedSmallInteger('interval_count')->default(1);
            $table->unsignedSmallInteger('trial_days')->default(0);
            $table->unsignedSmallInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        // Per-currency/region pricing, plus the id this plan carries at each gateway.
        Schema::create('plan_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->char('currency', 3);
            $table->unsignedBigInteger('amount')->comment('minor units, e.g. cents');
            $table->char('country_code', 2)->nullable()->comment('null = default for the currency');
            $table->string('gateway', 32)->nullable();
            $table->string('gateway_price_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['plan_id', 'currency', 'country_code'], 'plan_prices_lookup_idx');
        });

        // Entitlements are enforced server-side only (spec 5). Flutter never decides access.
        Schema::create('plan_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->string('feature', 64);                    // ai_messages|speech_minutes|generated_media|exam_prep|premium_tutor
            $table->boolean('is_enabled')->default(true);
            // null limit = unlimited. Period scopes the counter reset.
            $table->unsignedInteger('limit_value')->nullable();
            $table->string('limit_period', 16)->nullable();   // day|week|month|total
            $table->timestamps();

            $table->unique(['plan_id', 'feature']);
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('discount_type', 16);              // percent|fixed
            $table->unsignedInteger('discount_value');
            $table->char('currency', 3)->nullable()->comment('required for fixed discounts');
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('redemption_count')->default(0);
            $table->unsignedSmallInteger('duration_months')->nullable();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained()->restrictOnDelete();
            $table->foreignId('plan_price_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();

            $table->string('gateway', 32);
            $table->string('gateway_subscription_id')->nullable();
            $table->string('gateway_customer_id')->nullable();

            // trialing|active|past_due|paused|canceled|expired|incomplete
            $table->string('status', 24)->default('incomplete');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('current_period_start')->nullable();
            $table->timestamp('current_period_end')->nullable();
            $table->timestamp('cancel_at')->nullable();
            $table->timestamp('canceled_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->boolean('cancel_at_period_end')->default(false);

            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->unique(['gateway', 'gateway_subscription_id'], 'subs_gateway_id_unique');
            $table->index('current_period_end');
        });

        Schema::create('subscription_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('gateway', 32);
            $table->string('gateway_transaction_id')->nullable();
            $table->string('type', 24);                       // charge|refund|chargeback|adjustment
            $table->string('status', 24);                     // pending|succeeded|failed|refunded
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->unsignedBigInteger('refunded_amount')->default(0);
            $table->text('failure_reason')->nullable();
            $table->json('gateway_payload')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'gateway_transaction_id'], 'sub_tx_gateway_id_unique');
            $table->index(['user_id', 'status']);
        });

        // Every checkout initiation, so failures are diagnosable without gateway access.
        Schema::create('payment_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('plan_price_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->string('gateway', 32);
            $table->string('idempotency_key', 100)->unique();
            $table->string('status', 24)->default('initiated'); // initiated|redirected|succeeded|failed|abandoned
            $table->unsignedBigInteger('amount');
            $table->char('currency', 3);
            $table->string('gateway_reference')->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('subscription_transaction_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 64)->unique();
            $table->string('status', 24)->default('draft');   // draft|open|paid|void|uncollectible
            $table->unsignedBigInteger('subtotal');
            $table->unsignedBigInteger('discount_total')->default(0);
            $table->unsignedBigInteger('tax_total')->default(0);
            $table->unsignedBigInteger('total');
            $table->char('currency', 3);
            $table->json('billing_details')->nullable();
            $table->string('pdf_path')->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
        });

        Schema::create('coupon_redemptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subscription_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('redeemed_at');
            $table->timestamps();

            $table->unique(['coupon_id', 'user_id']);
        });

        // Raw webhook log. Verified before processing, deduped by gateway event id.
        Schema::create('payment_webhooks', function (Blueprint $table) {
            $table->id();
            $table->string('gateway', 32);
            $table->string('event_id')->nullable();
            $table->string('event_type', 96);
            $table->boolean('signature_verified')->default(false);
            $table->string('status', 24)->default('received'); // received|processed|failed|ignored
            $table->json('payload');
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();

            $table->unique(['gateway', 'event_id']);
            $table->index('status');
        });

        // Counters backing entitlement enforcement, reset per limit_period.
        Schema::create('entitlement_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('feature', 64);
            $table->date('period_start');
            $table->string('period', 16);
            $table->unsignedInteger('used')->default(0);
            $table->unsignedInteger('limit_value')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'feature', 'period', 'period_start'], 'entitlement_usage_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entitlement_usage');
        Schema::dropIfExists('payment_webhooks');
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('payment_attempts');
        Schema::dropIfExists('subscription_transactions');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('plan_entitlements');
        Schema::dropIfExists('plan_prices');
        Schema::dropIfExists('plans');
    }
};
