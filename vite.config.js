import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';

export default defineConfig(({ command }) => ({
    // Relative asset URLs in the built CSS/JS (fonts: url(./bootstrap-icons-….woff2)), so the build works both at a
    // site root and under a sub-folder such as http://localhost/SCHOOLINSPECTION/public. The default base is the
    // absolute /build/, which breaks the icon font under a sub-folder. Blade @vite tags use asset() and are unaffected.
    base: command === 'build' ? './' : undefined,
    plugins: [
        laravel({
            input: ['resources/scss/app.scss', 'resources/js/app.js', 'resources/js/wizard.js', 'resources/js/camera.js', 'resources/js/register.js', 'resources/js/review.js', 'resources/js/map.js', 'resources/js/schedule-planner.js', 'resources/js/schedule-route.js'],
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
}));
