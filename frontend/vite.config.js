import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Dev proxy: forwards /api to the docker nginx (:8080), which strips the prefix.
const apiProxyTarget = process.env.VITE_API_PROXY_TARGET || 'http://localhost:8080';

const stripPrefix = process.env.VITE_API_PROXY_STRIP_PREFIX === '1';

export default defineConfig({
  plugins: [react()],
  server: {
    proxy: {
      '/api': {
        target: apiProxyTarget,
        changeOrigin: true,
        // Set VITE_API_PROXY_STRIP_PREFIX=1 to hit Slim directly (no /api prefix).
        rewrite: stripPrefix ? (path) => path.replace(/^\/api/, '') : undefined,
      },
    },
  },
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/setupTests.js'],
    globals: true,
  },
});
