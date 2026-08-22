import { fileURLToPath, URL } from 'node:url'

import vue from '@vitejs/plugin-vue'
// From vitest/config, not vite: it is the same defineConfig widened to accept the `test`
// block, which keeps one config file instead of two that can drift apart.
import { defineConfig } from 'vitest/config'

// The SPA and the API must share an origin in the browser: authentication cookies use the
// __Host- prefix, which forbids a Domain attribute (ADR-0002). In development that is what
// this proxy provides — requests to /api and /health go to the app container, so the browser
// sees a single origin on :5173 and the cookies work exactly as they will in production.
const apiTarget = process.env.VITE_API_PROXY ?? 'http://localhost:8080'

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    host: '0.0.0.0',
    port: 5173,
    strictPort: true,
    // Required for HMR to reach the browser from inside the container.
    watch: { usePolling: true },
    proxy: {
      '/api': { target: apiTarget, changeOrigin: false },
      '/health': { target: apiTarget, changeOrigin: false },
    },
  },
  build: {
    // The production build is served by FrankenPHP from public/. Sourcemaps are shipped
    // because a stack trace from a real user is worth more than hiding the bundle shape —
    // this is a JSON API's SPA, not proprietary logic.
    sourcemap: true,
    chunkSizeWarningLimit: 600,
  },
  test: {
    environment: 'happy-dom',
    globals: true,
    include: ['src/**/*.spec.ts'],
  },
})
