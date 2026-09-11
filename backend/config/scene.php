<?php

/**
 * The acted scenes.
 *
 * Only the modelled kit lives here. Everything else about a scene - the words,
 * the staging, the voices - is content, and content belongs in the database
 * where it can be reviewed and changed without a deploy.
 */
return [
    /*
     * The rigged cast and the eight rooms, as served to the player.
     *
     * Both are optional. A path that is not on disk is simply not offered, and
     * the player falls back to the figures and rooms it builds itself - which
     * is also what happens on a first checkout, before the kit is fetched.
     */
    'kit' => [
        'cast' => env('SCENE_KIT_CAST', '/scene-kit/cast.glb'),
        'rooms' => env('SCENE_KIT_ROOMS', '/scene-kit/rooms.glb'),
    ],

    /*
     * Where the kit was authored, so the files can be rebuilt rather than only
     * copied. These are 3D scene-builder projects on the studio account; the
     * scripts that produce them are in docs/SCENES.md.
     */
    'kit_sources' => [
        'cast' => env('SCENE_KIT_CAST_PROJECT', '1e77ad93-8031-4504-81c8-1476bf0173d2'),
        'rooms' => env('SCENE_KIT_ROOMS_PROJECT', '3ba2b1d4-1f01-4a50-aaa8-ce73a5ecaa15'),
    ],
];
