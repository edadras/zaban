<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Names the books after their level again.
 *
 * They had been relabelled Foundation / Core / Advancing / Mastery, which made
 * two of them look empty and one of them look like another. Searching the
 * corpus for "elementary" or "advanced" - the words in the books' own
 * filenames, and the words anyone would actually type - matched nothing, so
 * both appeared to have no vocabulary at all when they held 1,739 and 3,462
 * senses. And "Advancing" was the upper-intermediate book, so a search for
 * "Advanc" returned the wrong one, confidently.
 *
 * Keyed on the source file rather than the old title, so it lands correctly
 * whatever a given deployment happens to be called right now.
 */
return new class extends Migration
{
    /** source pdf => the level it actually teaches */
    private const LEVELS = [
        'elementary_3rd.pdf' => 'Elementary',
        'pre_intermediate_intermediate_4th.pdf' => 'Pre-intermediate and Intermediate',
        'upper_intermediate_4th.pdf' => 'Upper-intermediate',
        'advanced_3rd.pdf' => 'Advanced',
    ];

    public function up(): void
    {
        foreach (self::LEVELS as $file => $level) {
            $documentId = DB::table('source_files')
                ->where('original_name', $file)
                ->value('source_document_id');

            if ($documentId) {
                DB::table('source_documents')
                    ->where('id', $documentId)
                    ->update(['title' => "English Vocabulary in Use — {$level}"]);
            }
        }
    }

    public function down(): void
    {
        // Deliberately not reversible: the previous names were the defect.
    }
};
