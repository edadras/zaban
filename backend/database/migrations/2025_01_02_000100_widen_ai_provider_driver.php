<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * driver holds a fully-qualified PHP class name. 64 characters is comfortably
 * exceeded by a namespaced class, and the failure mode is a truncation error on
 * write rather than anything graceful, so widen it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_providers', function (Blueprint $table) {
            $table->string('driver', 255)->change();
        });
    }

    public function down(): void
    {
        Schema::table('ai_providers', function (Blueprint $table) {
            $table->string('driver', 64)->change();
        });
    }
};
