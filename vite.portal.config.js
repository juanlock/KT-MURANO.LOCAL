import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { copyFileSync, mkdirSync } from 'node:fs';

const __dirname = dirname(fileURLToPath(import.meta.url));

/**
 * Tabler (Bootstrap 5 UI kit) ships separate LTR and RTL stylesheets and the
 * portal picks one per request (portal.blade.php reads the locale), so they
 * cannot be inlined into app.css. Copy both into the build folder instead.
 */
function copyTablerCss(outDir) {
    const src = resolve(__dirname, 'node_modules/@tabler/core/dist/css');
    return {
        name: 'portal-copy-tabler-css',
        closeBundle() {
            mkdirSync(outDir, { recursive: true });
            for (const f of ['tabler.min.css', 'tabler.rtl.min.css']) {
                copyFileSync(resolve(src, f), resolve(outDir, f));
            }
        },
    };
}

/**
 * Standalone build for the Client Portal (Vue 3 port of the legacy portal.js).
 * Kept SEPARATE from the admin build so the admin's tuned settings are never at
 * risk. Single self-contained bundle (router dynamic-imports inlined), fixed
 * filenames — portal.blade.php loads /portal-app/app.js + app.css with a
 * filemtime cache-bust.
 */
export default defineConfig(({ mode }) => ({
    plugins: [vue(), copyTablerCss(process.env.PORTAL_OUT_DIR || resolve(__dirname, 'public/js/portal'))],
    // Assets (icon fonts) are referenced from app.css by URL, so the base must
    // be the folder portal.blade.php serves the bundle from.
    base: '/js/portal/',
    // Don't copy the project's public/ folder into the build (we build into it).
    publicDir: false,
    build: {
        outDir: process.env.PORTAL_OUT_DIR || resolve(__dirname, 'public/js/portal'),
        emptyOutDir: true,
        cssCodeSplit: false,
        manifest: false,
        rollupOptions: {
            input: resolve(__dirname, 'resources/src/portal/main.js'),
            output: {
                inlineDynamicImports: true,
                entryFileNames: 'app.js',
                // One stylesheet named app.css; fonts keep their own names so
                // the four Tabler icon faces do not overwrite each other.
                assetFileNames: (info) => (String(info.name || '').endsWith('.css') ? 'app.css' : '[name].[ext]'),
            },
        },
    },
}));
