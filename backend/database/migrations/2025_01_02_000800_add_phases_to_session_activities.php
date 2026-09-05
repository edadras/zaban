<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A session was an ordered list of activities with no shape a learner could
 * see. It opened with whatever bucket happened to hold the most items, which
 * for anyone with review debt meant it opened by testing them on words it had
 * not taught yet - the reason the learning screen read as a quiz.
 *
 * `phase` gives the session its parts, so the client can say what is happening
 * and why: warm up, study, practise, use, consolidate. `rationale` is the one
 * line explaining this particular activity to the person doing it, as opposed
 * to `selection_reason`, which is the machine-readable audit of the choice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('session_activities', function (Blueprint $table) {
            $table->string('phase', 24)->nullable()->after('position')->index();
            $table->unsignedSmallInteger('phase_position')->default(0)->after('phase');
            $table->string('rationale', 255)->nullable()->after('selection_reason');
        });
    }

    public function down(): void
    {
        Schema::table('session_activities', function (Blueprint $table) {
            $table->dropIndex(['phase']);
            $table->dropColumn(['phase', 'phase_position', 'rationale']);
        });
    }
};
