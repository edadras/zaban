<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_providers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 48)->unique();             // higgsfield|openai|anthropic|azure_speech|elevenlabs
            $table->string('name');
            // Which interfaces this provider satisfies: text, image, video, audio, stt, alignment.
            $table->json('capabilities');
            $table->string('driver', 64)->comment('service-container binding key');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('priority')->default(100)->comment('lower wins when several can serve');
            $table->json('config')->nullable()->comment('non-secret settings only; credentials live in env');
            $table->timestamps();
        });

        Schema::create('ai_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_provider_id')->constrained()->cascadeOnDelete();
            $table->string('code', 96);
            $table->string('name');
            $table->string('modality', 24);                   // text|image|video|audio|stt|embedding
            $table->boolean('is_active')->default(true);
            $table->boolean('is_fallback')->default(false);
            $table->unsignedInteger('context_tokens')->nullable();
            // Cost in micro-units per 1k tokens / per second / per generation.
            $table->decimal('input_cost_per_1k', 12, 6)->nullable();
            $table->decimal('output_cost_per_1k', 12, 6)->nullable();
            $table->decimal('unit_cost', 12, 6)->nullable();
            $table->decimal('credit_cost', 10, 3)->nullable()->comment('provider credits, e.g. Higgsfield');
            $table->json('capabilities')->nullable();
            $table->timestamps();

            $table->unique(['ai_provider_id', 'code']);
            $table->index(['modality', 'is_active']);
        });

        // Versioned prompt templates. Prompts live here, never inline in controllers (spec 42).
        Schema::create('ai_prompts', function (Blueprint $table) {
            $table->id();
            $table->string('key', 96);
            $table->unsignedSmallInteger('version')->default(1);
            $table->string('name');
            $table->string('purpose', 64);                    // lesson_generation|exercise_generation|tutor|
                                                              // speech_feedback|media_image|exam_scoring
            $table->longText('system_template')->nullable();
            $table->longText('user_template');
            $table->longText('negative_template')->nullable()->comment('media providers');
            // Declared inputs, validated before rendering so a missing var fails loudly.
            $table->json('variables')->nullable();
            $table->json('output_schema')->nullable();
            $table->decimal('temperature', 3, 2)->nullable();
            $table->unsignedInteger('max_tokens')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['key', 'version']);
            $table->index(['purpose', 'is_active']);
        });

        // One row per outbound AI call: the cost-control and observability ledger (spec 43, 44).
        Schema::create('ai_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_provider_id')->constrained()->restrictOnDelete();
            $table->foreignId('ai_model_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ai_prompt_id')->nullable()->constrained()->nullOnDelete();

            $table->string('feature', 64)->comment('which subsystem spent the money');
            $table->string('status', 24)->default('pending'); // pending|running|succeeded|failed|timeout|cancelled
            $table->string('request_id')->nullable()->comment('provider-side id');
            $table->string('idempotency_key', 100)->nullable();

            // Hash of the rendered prompt + params: the cache key that stops us paying
            // twice for identical standard-lesson media (spec 18).
            $table->string('cache_key', 64)->nullable();
            $table->boolean('served_from_cache')->default(false);

            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->decimal('credits_used', 12, 4)->nullable();
            $table->decimal('estimated_cost', 12, 6)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedTinyInteger('attempt')->default(1);
            $table->foreignId('fallback_of_id')->nullable()->constrained('ai_requests')->nullOnDelete();

            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'feature', 'created_at'], 'ai_requests_user_feature_idx');
            $table->index(['status', 'created_at']);
            $table->index('cache_key');
        });

        Schema::create('ai_generations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ai_request_id')->constrained()->cascadeOnDelete();
            $table->string('output_type', 24);                // text|json|image|video|audio
            $table->longText('prompt')->comment('fully rendered prompt actually sent');
            $table->longText('negative_prompt')->nullable();
            $table->longText('output_text')->nullable();
            $table->json('output_json')->nullable();
            $table->foreignId('media_asset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('output_url')->nullable()->comment('provider URL before it is mirrored to our storage');
            $table->string('seed')->nullable();
            $table->json('parameters')->nullable();
            $table->json('provider_metadata')->nullable();
            // Reuse counter: proves the caching policy is working.
            $table->unsignedInteger('reuse_count')->default(0);
            $table->timestamps();

            $table->index('output_type');
        });

        // Rolling aggregates so limit checks never scan ai_requests.
        Schema::create('ai_usage', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('feature', 64)->nullable();
            $table->date('usage_date');
            $table->unsignedInteger('request_count')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
            $table->decimal('credits_used', 14, 4)->default(0);
            $table->decimal('estimated_cost', 14, 6)->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'feature', 'usage_date'], 'ai_usage_unique');
            $table->index('usage_date');
        });

        Schema::create('ai_usage_limits', function (Blueprint $table) {
            $table->id();
            // Either a global default (both null) or scoped to a plan or a single user.
            $table->foreignId('plan_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('feature', 64)->nullable();
            $table->string('period', 16);                     // day|month
            $table->unsignedInteger('max_requests')->nullable();
            $table->decimal('max_cost', 12, 4)->nullable();
            $table->decimal('max_credits', 12, 4)->nullable();
            $table->string('on_exceed', 24)->default('block'); // block|fallback_model|degrade
            $table->timestamps();

            $table->index(['plan_id', 'feature', 'period']);
            $table->index(['user_id', 'feature', 'period']);
        });

        // Review workflow for anything AI produced (spec 37, 38).
        Schema::create('content_reviews', function (Blueprint $table) {
            $table->id();
            $table->morphs('reviewable');                     // lesson|exercise|dialogue|passage|media_asset
            $table->string('status', 24)->default('draft');   // draft|review|approved|published|rejected
            $table->decimal('validation_score', 4, 3)->nullable();
            // Per-check results from the automated validators.
            $table->json('validation_results')->nullable();
            $table->boolean('auto_publishable')->default(false);
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('reviewer_notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->unique(['reviewable_type', 'reviewable_id'], 'content_reviews_unique');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('content_reviews');
        Schema::dropIfExists('ai_usage_limits');
        Schema::dropIfExists('ai_usage');
        Schema::dropIfExists('ai_generations');
        Schema::dropIfExists('ai_requests');
        Schema::dropIfExists('ai_prompts');
        Schema::dropIfExists('ai_models');
        Schema::dropIfExists('ai_providers');
    }
};
