import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';

// Vite's own dev server runs on its own port, so /api requests need to be
// proxied to the real API. In production nginx serves /api itself (and strips
// the prefix before PHP-FPM), so the built app has no dev-proxy dependency.
// By default the proxy forwards /api unchanged to the docker stack's nginx
// (port 8080), which does the stripping.
const apiProxyTarget = process.env.VITE_API_PROXY_TARGET || 'http://localhost:8080';

const stripPrefix = process.env.VITE_API_PROXY_STRIP_PREFIX === '1';

export default defineConfig({
  plugins: [react()],
  server: {
    proxy: {
      '/api': {
        target: apiProxyTarget,
        changeOrigin: true,
        // The docker stack's nginx (port 8080) expects the /api prefix and strips
        // it itself, so by default the proxy forwards the path unchanged. Set VITE_API_PROXY_STRIP_PREFIX=1 to target Slim directly
        // (e.g. `composer start`), which has no /api prefix.
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
