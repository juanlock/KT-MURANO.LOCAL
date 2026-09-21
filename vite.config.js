import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));

/**
 * Builds the Vue 3 + Ant Design admin app into public/js/.
 *
 * Filenames are content-hashed and the Blade view reads .vite/manifest.json to
 * find them. That is not cosmetic: the entry must be loaded WITHOUT a `?v=`
 * cache-busting query. Lazy chunks import the entry as "../app.js" (no query),
 * and browsers key ES modules by full URL — so a query on the entry loads a
 * SECOND copy of the app (and of Vue) for chunk code, whose
 * currentRenderingInstance is always null. That produced
 * "Cannot read properties of null (reading 'ce')" in renderSlot and, earlier,
 * the nextSibling crash. Hashed names give cache-busting without a query.
 */
export default defineConfig({
    plugins: [vue()],
    base: '/js/',
    // Root is the project root, whose `public/` is Vite's default publicDir —
    // which Vite would copy wholesale into outDir. We build INTO public/, and
    // Laravel serves public/ directly, so disable the copy entirely.
    publicDir: false,
    css: {
        preprocessorOptions: {
            scss: {
                // The POS compiles legacy Bootstrap 4.1.3 + the legacy app skin
                // VERBATIM (pos-compat/bootstrap-legacy, globals-legacy). Those
                // sources use @import/darken()/map-get(), which Dart Sass now
                // warns about on every build. They only break in Dart Sass 3.0
                // and the legacy sources must not be rewritten (1:1 parity), so
                // silence the noise instead.
                silenceDeprecations: ['import', 'global-builtin', 'color-functions', 'if-function', 'slash-div'],
                quietDeps: true,
            },
        },
    },
    build: {
        // The shared core (Vue + Ant Design Vue + i18n) legitimately exceeds
        // Vite's default 500 kB notice, and manualChunks is OFF LIMITS here
        // (see the rollupOptions note below — hand-splitting broke module init
        // order). Raise the threshold so a clean build logs clean; if a chunk
        // ever crosses this, that's a real regression worth seeing.
        chunkSizeWarningLimit: 2500,
        // All builds share public/js; the `build` script pre-cleans it once, so
        // each config leaves the others' output in place (emptyOutDir: false).
        outDir: resolve(__dirname, 'public/js'),
        emptyOutDir: false,
        cssCodeSplit: false,
        manifest: true,
        rollupOptions: {
            input: resolve(__dirname, 'resources/src/main.js'),
            output: {
                // NO manualChunks. Hand-slicing node_modules into vendor buckets
                // split a circular import across chunks and broke module init
                // order ("Cannot access 'Jt' before initialization" — a chunk ran
                // before its dependency finished evaluating). Rollup's automatic
                // splitting already shares common code between the lazy page
                // chunks, which is where the real win is.
                entryFileNames: 'app.[hash].js',
                chunkFileNames: 'chunks/[name].[hash].js',
                assetFileNames: 'assets/[name].[hash][extname]',
            },
        },
    },
});
