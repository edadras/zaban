<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The in-app notification list.
 *
 * Laravel's own shape, so `$user->notify(...)` works and the bell is a query
 * rather than a bespoke table. A class starting is the first thing the product
 * has ever had to tell a learner at a particular minute; everything before it
 * was a reply to something they had just submitted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // The bell asks one question - "what is unread for me" - and this
            // is the index that answers it without a scan.
            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
