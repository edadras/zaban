<?php

/*
 * The parts of a class that a machine helps with.
 *
 * All of it degrades to nothing rather than to an error: with no AI provider
 * configured the assistant simply does not answer and the marker simply does
 * not mark, and every one of these features has a human who was going to do it
 * anyway. That is the design, not a fallback - a coach must never find that the
 * only record of a learner's work is one no person looked at.
 */
return [

    'ai' => [
        /*
         * A first-pass answer on the class board.
         *
         * Posted publicly and labelled as the assistant's, not slipped in as
         * if a person wrote it, and the coach endorses or takes it down. A
         * learner asking at eleven at night gets something to work with; the
         * coach still gets the last word in the morning.
         */
        'board_assistant' => (bool) env('CLASSROOM_AI_BOARD', true),

        // Wait a moment before answering, so a classmate who is already
        // typing gets there first. A board where the machine always answers
        // first is a board where nobody else bothers.
        'board_assistant_delay_seconds' => (int) env('CLASSROOM_AI_BOARD_DELAY', 180),

        /*
         * A first pass at marking homework.
         *
         * A suggestion with a score and comments, which the coach edits and
         * releases. Never returned to a learner unmarked by a person unless
         * the assignment says so explicitly.
         */
        'marking' => (bool) env('CLASSROOM_AI_MARKING', true),

        // The language the assistant explains in. The learners are Persian
        // speakers learning English, and an explanation they cannot read is
        // not an explanation.
        'explain_in' => env('CLASSROOM_AI_LANGUAGE', 'Persian'),
    ],

    'homework' => [
        // How long after the due date a submission is still accepted, when the
        // assignment allows late work at all.
        'late_grace_hours' => (int) env('CLASSROOM_LATE_GRACE_HOURS', 72),

        // What one uploaded piece of work may weigh.
        'max_upload_kilobytes' => (int) env('CLASSROOM_MAX_UPLOAD_KB', 51200),
    ],
];
