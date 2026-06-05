import { defineConfig } from 'vite';
import { fileURLToPath, URL } from 'node:url';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
  resolve: {
    alias: {
      // Preline 4.2.0 removed ./variants.css from its exports map; point past it directly.
      'preline/variants.css': fileURLToPath(new URL('./node_modules/preline/variants.css', import.meta.url)),
    },
  },
  plugins: [
    laravel({
      input: [
        // Admin panel (Tailwind v4 / Preline)
        'resources/js/admin-v2/vendor.js',
        'resources/js/admin-v2/app.js',
      ],
      refresh: true,
    }),
    tailwindcss(),
  ],
  define: {
    'global': 'window',
  },
  build: {
    target: 'es2015', // Changed from esnext for better compatibility
    rollupOptions: {
      output: {
        // Organize output files by type
        assetFileNames: (assetInfo) => {
          const info = assetInfo.name.split('.');
          const extType = info[info.length - 1];

          // Organize CSS files
          if (extType === 'css') {
            // Check the original source path to determine the subdirectory
            const source = assetInfo.originalFileNames?.[0] || assetInfo.name;

            if (source.includes('resources/css/admin-v2') || source.includes('admin-v2/')) {
              return `css/admin-v2/[name]-[hash].css`;
            }
            if (source.includes('resources/scss/admin') || source.includes('admin/app')) {
              return `css/admin/[name]-[hash].css`;
            }
            if (source.includes('resources/scss/events') || source.includes('events/app')) {
              return `css/events/[name]-[hash].css`;
            }
            if (source.includes('resources/scss/public') || source.includes('public/app')) {
              return `css/public/[name]-[hash].css`;
            }
            return `css/[name]-[hash].css`;
          }

          // Keep fonts in their original location
          if (['woff', 'woff2', 'eot', 'ttf', 'svg'].includes(extType)) {
            return `fonts/[name][extname]`;
          }

          // Organize other assets
          return `assets/[name]-[hash][extname]`;
        },
        chunkFileNames: (chunkInfo) => {
          // Organize JS chunks. admin-v2 must match before admin so it
          // doesn't get bucketed into the legacy admin path.
          if (chunkInfo.name.includes('admin-v2')) {
            return `js/admin-v2/[name]-[hash].js`;
          }
          if (chunkInfo.name.includes('admin')) {
            return `js/admin/[name]-[hash].js`;
          }
          if (chunkInfo.name.includes('events')) {
            return `js/events/[name]-[hash].js`;
          }
          if (chunkInfo.name.includes('public')) {
            return `js/public/[name]-[hash].js`;
          }
          return `js/[name]-[hash].js`;
        },
        entryFileNames: (chunkInfo) => {
          if (chunkInfo.name.includes('admin-v2')) {
            return `js/admin-v2/[name]-[hash].js`;
          }
          if (chunkInfo.name.includes('admin')) {
            return `js/admin/[name]-[hash].js`;
          }
          if (chunkInfo.name.includes('events')) {
            return `js/events/[name]-[hash].js`;
          }
          if (chunkInfo.name.includes('public')) {
            return `js/public/[name]-[hash].js`;
          }
          return `js/[name]-[hash].js`;
        }
      }
    }
  }
});
