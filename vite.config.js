import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/sass/app.scss',
                'resources/js/app.js',
                'resources/css/tekora/tekora.css',
                'resources/css/tekora/ambient.css',
                'resources/js/tekora/tekora.js',
                'resources/css/tekora/account-shell.css',
                'resources/css/tekora/dashboards.css',
                'resources/js/tekora/account-shell.js',
                'resources/js/tekora/dashboards.js',
            ],
            refresh: true,
        }),
    ],
});
