import fs from 'node:fs';
import path from 'node:path';
import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';

const buildDir = path.join(process.cwd(), 'public/build');
const manifestPath = path.join(buildDir, 'manifest.json');

function filesFromManifest(filePath) {
    const files = new Set();
    if (!fs.existsSync(filePath)) {
        return files;
    }
    try {
        const manifest = JSON.parse(fs.readFileSync(filePath, 'utf8'));
        for (const entry of Object.values(manifest)) {
            if (!entry || typeof entry !== 'object') {
                continue;
            }
            if (typeof entry.file === 'string') {
                files.add(entry.file);
            }
            for (const css of entry.css || []) {
                files.add(css);
            }
            for (const asset of entry.assets || []) {
                files.add(asset);
            }
        }
    } catch {
        // Keep going; a unreadable manifest should not fail the build.
    }

    return files;
}

/**
 * Do not wipe public/build on each compile. An open admin tab still points at
 * the previous hashed files; deleting them shows "Admin assets failed to load".
 * Keep the last two manifests, then prune older chunks.
 */
function keepPreviousViteAssets() {
    let previous = new Set();

    return {
        name: 'keep-previous-vite-assets',
        apply: 'build',
        buildStart() {
            previous = filesFromManifest(manifestPath);
        },
        closeBundle() {
            const keep = new Set([...previous, ...filesFromManifest(manifestPath)]);
            const assetsDir = path.join(buildDir, 'assets');
            if (!fs.existsSync(assetsDir) || keep.size === 0) {
                return;
            }
            for (const name of fs.readdirSync(assetsDir)) {
                const rel = `assets/${name}`;
                if (keep.has(rel)) {
                    continue;
                }
                try {
                    fs.unlinkSync(path.join(assetsDir, name));
                } catch {
                    // Ignore busy files on Windows.
                }
            }
        },
    };
}

export default defineConfig({
    build: {
        modulePreload: false,
        emptyOutDir: false,
        rollupOptions: {
            output: {
                manualChunks(id) {
                    if (id.includes('node_modules/grapesjs')) {
                        return 'vendor-grapesjs';
                    }
                    if (id.includes('node_modules/echarts')) {
                        return 'vendor-echarts';
                    }
                    if (id.includes('node_modules/@tiptap')) {
                        return 'vendor-tiptap';
                    }
                    if (id.includes('node_modules/gridstack')) {
                        return 'vendor-gridstack';
                    }
                    if (id.includes('node_modules/react/') || id.includes('node_modules/react-dom/')) {
                        return 'vendor-react';
                    }
                },
            },
        },
    },
    experimental: {
        renderBuiltUrl() {
            return { relative: true };
        },
    },
    plugins: [
        laravel({
            input: [
                'resources/css/admin.css',
                'resources/js/admin/app.jsx',
                'storefront/src/styles.css',
                'storefront/src/main.jsx',
            ],
            refresh: true,
        }),
        tailwindcss(),
        react(),
        keepPreviousViteAssets(),
    ],
    server: {
        host: 'localhost',
        hmr: {
            host: 'localhost',
        },
        cors: {
            origin: ['http://localhost:8000', 'http://127.0.0.1:8000'],
        },
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
