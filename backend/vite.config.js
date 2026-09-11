import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/js/app.js',
                // The admin and coach website. Its own bundle so the live
                // classroom's media SDK is not downloaded by every page.
                'resources/css/panel.css',
                'resources/js/panel.js',
                'resources/js/room.js',
                // The acted-scene player. Its own bundle: it carries a 3D
                // renderer that no other page should have to download.
                'resources/css/scene.css',
                'resources/js/scene/main.js',
                // One talking figure, shared by lessons, the speaking exam and
                // a coach with no camera. Its own bundle for the same reason:
                // it carries the renderer and the rig loader.
                'resources/css/speaker.css',
                'resources/js/speaker/main.js',
            ],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
                // Persian. The panel is right-to-left throughout.
                bunny('Vazirmatn', {
                    weights: [400, 500, 700],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
