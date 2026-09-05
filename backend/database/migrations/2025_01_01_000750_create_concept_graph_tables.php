<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Single registry for every teachable node. Vocabulary senses, grammar
        // concepts, pronunciation items, phrases and subskills each get exactly one
        // row here, which gives mastery tracking, prerequisites and the adaptive
        // engine one id space to work in instead of five polymorphic joins.
        Schema::create('concepts', function (Blueprint $table) {
            $table->id();
            $table->morphs('conceptable');
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('cefr_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();
            $table->string('label')->comment('denormalised display name for admin and reports');
            // Intrinsic difficulty on the shared logit scale.
            $table->decimal('difficulty', 6, 3)->default(0.000);
            // How central this node is to the syllabus; weights remediation priority.
            $table->decimal('importance', 4, 3)->default(0.500);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['conceptable_type', 'conceptable_id'], 'concepts_conceptable_unique');
            $table->index(['language_id', 'skill_id']);
            $table->index('cefr_level_id');
        });

        // Directed prerequisite edges. strength < 1 means "helpful but not blocking",
        // which is how the mastery loop stays intelligent instead of a hard gate (spec 24).
        Schema::create('concept_prerequisites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('concept_id')->constrained()->cascadeOnDelete();
            $table->foreignId('prerequisite_concept_id')->constrained('concepts')->cascadeOnDelete();
            $table->decimal('strength', 4, 3)->default(1.000);
            $table->boolean('is_blocking')->default(false);
            $table->string('detection_method', 32)->default('authored'); // authored|inferred|ai_detected
            $table->timestamps();

            $table->unique(['concept_id', 'prerequisite_concept_id'], 'concept_prereq_unique');
            $table->index('prerequisite_concept_id');
        });

        Schema::create('learning_objectives', function (Blueprint $table) {
            $table->id();
            $table->foreignId('language_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cefr_level_id')->nullable()->constrained('cefr_levels')->nullOnDelete();
            $table->foreignId('skill_id')->nullable()->constrained()->nullOnDelete();
            // "Can-do" statement in CEFR style.
            $table->text('statement');
            $table->string('code', 64)->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('learning_objective_concept', function (Blueprint $table) {
            $table->id();
            $table->foreignId('learning_objective_id')->constrained()->cascadeOnDelete();
            $table->foreignId('concept_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['learning_objective_id', 'concept_id'], 'objective_concept_unique');
        });

        Schema::create('lesson_objective', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('learning_objective_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['lesson_id', 'learning_objective_id'], 'lesson_objective_unique');
        });

        // Which concepts a lesson teaches versus merely recycles.
        Schema::create('lesson_concept', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lesson_id')->constrained()->cascadeOnDelete();
            $table->foreignId('concept_id')->constrained()->cascadeOnDelete();
            $table->string('role', 24)->default('target'); // target|recycled|incidental
            $table->timestamps();
            $table->unique(['lesson_id', 'concept_id'], 'lesson_concept_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lesson_concept');
        Schema::dropIfExists('lesson_objective');
        Schema::dropIfExists('learning_objective_concept');
        Schema::dropIfExists('learning_objectives');
        Schema::dropIfExists('concept_prerequisites');
        Schema::dropIfExists('concepts');
    }
};
