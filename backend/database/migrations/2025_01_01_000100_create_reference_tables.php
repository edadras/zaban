<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->id();
            $table->string('code', 12)->unique();          // BCP-47, e.g. en, en-GB, fa
            $table->string('name');                         // English exonym
            $table->string('native_name');                  // endonym
            $table->enum('direction', ['ltr', 'rtl'])->default('ltr');
            $table->boolean('is_learnable')->default(false); // can be a target language
            $table->boolean('is_interface')->default(false); // can be a UI/native language
            $table->timestamps();
        });

        Schema::create('cefr_levels', function (Blueprint $table) {
            $table->id();
            $table->string('code', 8)->unique();            // Pre-A1, A1, A2, B1, B2, C1, C2
            $table->unsignedTinyInteger('ordinal')->unique(); // 0..6, the sort/compare key
            $table->string('name');
            $table->text('description')->nullable();
            // Ability band on the logit scale used by the CAT/difficulty engines.
            $table->decimal('ability_min', 6, 3);
            $table->decimal('ability_max', 6, 3);
            $table->timestamps();
        });

        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();           // reading, listening, speaking, writing,
                                                            // vocabulary, grammar, pronunciation
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_productive')->default(false); // speaking/writing need AI grading
            $table->boolean('assessed_in_placement')->default(true);
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('subskills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->string('code', 64);
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->unique(['skill_id', 'code']);
        });

        Schema::create('topics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('topics')->nullOnDelete();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();
            $table->index('parent_id');
        });

        Schema::create('parts_of_speech', function (Blueprint $table) {
            $table->id();
            $table->string('code', 32)->unique();           // noun, verb, adjective, ...
            $table->string('name');
            $table->string('abbreviation', 16)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('parts_of_speech');
        Schema::dropIfExists('topics');
        Schema::dropIfExists('subskills');
        Schema::dropIfExists('skills');
        Schema::dropIfExists('cefr_levels');
        Schema::dropIfExists('languages');
    }
};
