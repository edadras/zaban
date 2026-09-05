<?php

return [
    /*
     * Tripwire terms for the automated content check. Deliberately narrow - this
     * catches obviously unsuitable generated material for a general-audience
     * language course; it is not a moderation system, and anything it flags goes
     * to a human rather than being deleted.
     */
    'flagged_terms' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('CONTENT_FLAGGED_TERMS', '')),
    ))),

    'auto_publish_enabled' => (bool) env('CONTENT_AUTO_PUBLISH', false),
];
