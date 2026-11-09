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
                'resources/css/tekora/marketplace.css',
                'resources/js/tekora/marketplace.js',
                'resources/css/tekora/operations.css',
                'resources/js/tekora/operations.js',
                'resources/css/tekora/identity.css',
                'resources/js/tekora/identity.js',
            ],
            refresh: true,
        }),
    ],
});
