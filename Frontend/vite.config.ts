/// <reference types="vitest/config" />
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// https://vite.dev/config/
export default defineConfig({
  plugins: [react()],
  server: {
    port: 3000,
    strictPort: true,
    proxy: {
      '/api': {
        target: 'http://127.0.0.1:8001',
        changeOrigin: true,
      },
      '/sanctum': {
        target: 'http://127.0.0.1:8001',
        changeOrigin: true,
      },
    },
  },
  // Unit tests (npm test): browser APIs such as localStorage come from jsdom.
   test: {
    environment: 'jsdom',
    include: ['src/**/*.test.ts'],
    restoreMocks: true,
    // Slow machines can take several seconds to load Intl currency data on first use.
    testTimeout: 20000,
  },
})
