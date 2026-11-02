import { fileURLToPath, URL } from 'node:url';

import tailwindcss from '@tailwindcss/vite';
import react from '@vitejs/plugin-react';
import { defineConfig } from 'vitest/config';

/**
 * The SPA is served from `/app/` by nginx, and the build lands directly in
 * `public/app/` so the Dockerfile and the release zip need no extra copy step.
 *
 * In development Vite proxies the panel API and the auth endpoints to the PHP
 * container, so cookies are issued for the dev-server origin and behave exactly
 * like they do in production.
 */
export default defineConfig({
  base: '/app/',
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  build: {
    outDir: '../public/app',
    emptyOutDir: true,
    sourcemap: false,
    target: 'es2022',
  },
  server: {
    port: 5173,
    strictPort: true,
    proxy: {
      '/panel-api': {
        target: 'http://localhost:5600',
        changeOrigin: false,
      },
      '/auth': {
        target: 'http://localhost:5600',
        changeOrigin: false,
      },
      '/api': {
        target: 'http://localhost:5600',
        changeOrigin: false,
      },
    },
  },
  test: {
    environment: 'jsdom',
    globals: false,
    css: true,
    setupFiles: ['./src/test/setup.ts'],
    include: ['src/**/*.test.{ts,tsx}'],
    coverage: {
      provider: 'v8',
      reporter: ['text', 'lcov'],
      include: ['src/**/*.{ts,tsx}'],
      exclude: ['src/**/*.test.{ts,tsx}', 'src/test/**', 'src/main.tsx'],
    },
  },
});
