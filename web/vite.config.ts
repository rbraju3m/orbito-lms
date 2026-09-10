/// <reference types="vitest/config" />
import { fileURLToPath, URL } from 'node:url';

import react from '@vitejs/plugin-react';
import { defineConfig } from 'vite';

export default defineConfig({
  plugins: [react()],

  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },

  server: {
    port: 5173,
    strictPort: true,
  },

  build: {
    sourcemap: true,
    rollupOptions: {
      output: {
        // Keep the framework out of the app chunk so a code change does not
        // invalidate the vendor cache on every deploy.
        // Only name the chunks that are genuinely in the entry graph. Vite
        // emits a modulepreload for every MANUAL chunk, so naming a dependency
        // that only lazy routes use would drag it into the first paint —
        // exactly what the code splitting is there to avoid. Everything else is
        // left to the bundler, which places dynamic-only deps in dynamic chunks.
        manualChunks(id) {
          if (!id.includes('node_modules')) return undefined;
          if (/[\\/]node_modules[\\/](react|react-dom|react-router|scheduler)[\\/]/.test(id)) {
            return 'react';
          }
          if (id.includes('node_modules/@mantine')) return 'mantine';
          if (id.includes('node_modules/@tanstack')) return 'query';
          return undefined;
        },
      },
    },
  },

  test: {
    environment: 'jsdom',
    globals: true,
    setupFiles: ['./src/shared/test/setup.ts'],
    css: false,
    include: ['src/**/*.{test,spec}.{ts,tsx}'],
    // Vitest's 5s default is sized for unit tests. These render whole Mantine
    // trees and drive them through userEvent, which is comfortably under a
    // second on an idle machine and several times that when the workers are
    // all busy. The higher ceiling stops that showing up as a flake.
    testTimeout: 20_000,
    // Pinned, the way phpunit.xml pins the API's hosts. Vitest reads the
    // developer's own `.env`, and running beside another local Laravel app
    // means pointing that at orbito.localhost — which moved the API off the
    // `localhost:8000` a dozen test files mock, and failed them only locally.
    env: {
      VITE_API_URL: 'http://localhost:8000',
    },
    coverage: {
      provider: 'v8',
      reporter: ['text', 'lcov'],
      include: ['src/**/*.{ts,tsx}'],
      exclude: ['src/**/*.{test,spec}.{ts,tsx}', 'src/shared/test/**', 'src/main.tsx'],
    },
  },
});
