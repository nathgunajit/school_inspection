import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/scss/app.scss', 'resources/js/app.js', 'resources/js/wizard.js', 'resources/js/camera.js', 'resources/js/register.js', 'resources/js/review.js'],
            refresh: true,
        }),
    ],
    css: {
        preprocessorOptions: {
            scss: { quietDeps: true, silenceDeprecations: ['import', 'global-builtin', 'color-functions', 'mixed-decls'] },
        },
    },
    // Supported browsers (BROWSER_SUPPORT.md): Chrome / Edge / Firefox 100+, Safari 15+
    build: { target: ['chrome100', 'edge100', 'firefox100', 'safari15'] },
});
