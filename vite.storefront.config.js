import { defineConfig } from 'vite';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import tailwindcss from 'tailwindcss';
import autoprefixer from 'autoprefixer';

const __dirname = dirname(fileURLToPath(import.meta.url));

/**
 * Storefront build (Alpine.js + Tailwind v3) — the replacement for the old
 * Laravel Mix / webpack build. Emits into public/ to the exact paths the store
 * layout expects:
 *   js/storefront.min.js   (Alpine bundle)
 *   css/storefront.css     (compiled Tailwind)
 *
 * Tailwind PostCSS is inlined here (not a global postcss.config.js) so it only
 * runs for this build and never touches the admin/portal/customer-display ones.
 * emptyOutDir is OFF because outDir is the shared public/ folder.
 */
export default defineConfig({
    // Don't copy the project's public/ folder into the build (we build into it).
    publicDir: false,
    css: {
        postcss: {
            plugins: [
                tailwindcss(resolve(__dirname, 'tailwind.config.js')),
                autoprefixer(),
            ],
        },
    },
    build: {
        outDir: resolve(__dirname, 'public'),
        emptyOutDir: false,
        cssCodeSplit: false,
        manifest: false,
        rollupOptions: {
            input: resolve(__dirname, 'resources/src/storefront.js'),
            output: {
                // IIFE so it runs from a plain <script defer> tag (the store
                // layout does not load it as a module), matching the old webpack
                // output. inlineDynamicImports keeps it a single self-run file.
                format: 'iife',
                inlineDynamicImports: true,
                entryFileNames: 'js/storefront.min.js',
                // Everything lands under public/js. The only emitted asset is the
                // compiled CSS (the one url() in the source is an inline data-URI,
                // so nothing else is emitted).
                assetFileNames: (info) => {
                    const name = (info.names && info.names[0]) || info.name || '';
                    return name.endsWith('.css') ? 'js/storefront.css' : 'js/assets/[name][extname]';
                },
            },
        },
    },
});
