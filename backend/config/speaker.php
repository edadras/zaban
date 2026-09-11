<?php

/**
 * The talking figure.
 *
 * One rigged character, framed head and shoulders, with its mouth moved by
 * whatever is making the sound. It is the same cast the acted scenes use, so
 * the person who serves you coffee in a scene is the person who reads the line
 * you are about to repeat - which is most of what makes a course feel like one
 * production rather than a pile of features.
 */
return [
    /*
     * Who reads a line in a lesson.
     *
     * One presenter rather than a face per lesson, on purpose: a learner who
     * meets the same person on every drill stops noticing the person and
     * starts noticing the mouth, which is the only reason the figure is there.
     */
    'presenter' => env('SPEAKER_PRESENTER', 'grace'),

    /*
     * Who sits opposite in the speaking exam. Omar runs the two exam scenes in
     * conversation practice, so a candidate meets the examiner they rehearsed
     * against.
     */
    'examiner' => env('SPEAKER_EXAMINER', 'omar'),

    /*
     * The figure a coach is given when their camera is off. `learner` is the
     * neutral rig - not one of the named cast, who are people in the course.
     */
    'coach' => env('SPEAKER_COACH', 'learner'),

    /*
     * Everyone in cast.glb. A request for anybody else is refused rather than
     * quietly served an empty stage, because "no figure appeared" is not a
     * fault anyone would think to report.
     */
    'cast' => [
        'aiko', 'daniel', 'grace', 'ines', 'learner', 'lena', 'omar', 'peter', 'tomas',
    ],

    /*
     * How long a speaker link is good for. Long enough for a lesson or an exam
     * section, short enough that a link which leaks is worth nothing later.
     */
    'link_minutes' => (int) env('SPEAKER_LINK_MINUTES', 180),
];
