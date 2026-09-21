import { defineConfig } from 'vite';
import vue from '@vitejs/plugin-vue';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const __dirname = dirname(fileURLToPath(import.meta.url));

/**
 * Standalone build for the Customer Display screen (the Vue 3 port of the
 * legacy customer-display.js). Kept SEPARATE from the admin build (vite.config.js)
 * so the admin's carefully-tuned chunking/CSS settings are never at risk.
 *
 * Single self-contained bundle (no router / lazy chunks), fixed filenames — the
 * Blade loads /customer-display-app/app.js + app.css with a filemtime cache-bust.
 */
export default defineConfig({
    plugins: [vue()],
    // Don't copy the project's public/ folder into the build (we build into it).
    publicDir: false,
    build: {
        outDir: resolve(__dirname, 'public/js/customer-display'),
        emptyOutDir: true,
        cssCodeSplit: false,
        manifest: false,
        rollupOptions: {
            input: resolve(__dirname, 'resources/src/customer-display/main.js'),
            output: {
                inlineDynamicImports: true,
                entryFileNames: 'app.js',
                assetFileNames: 'app.[ext]',
            },
        },
    },
});
