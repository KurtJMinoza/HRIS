import path from 'path'
import { fileURLToPath } from 'url'
import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'

const __dirname = path.dirname(fileURLToPath(import.meta.url))

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, __dirname, '')
  const proxyTarget = env.VITE_DEV_API_PROXY_TARGET || 'http://127.0.0.1:8000'

  // Set VITE_BASE=/HR/ in .env when the built app lives under a subpath (e.g. http://localhost/HR/).
  // Also set BrowserRouter basename in App.jsx via import.meta.env.BASE_URL.
  const base = env.VITE_BASE || '/'

  return {
    base,
    plugins: [react()],
    publicDir: 'public',
    resolve: {
      alias: {
        '@': path.resolve(__dirname, './src'),
      },
    },
    server: {
      port: 5173,
      // Same-origin /api in dev → no CORS issues. Set VITE_API_URL=/api in .env (see .env.example).
      proxy: {
        '/api': {
          target: proxyTarget,
          changeOrigin: true,
        },
        // Sanctum CSRF cookie (SPA must GET this before POST; same-origin in dev via Vite)
        '/sanctum': {
          target: proxyTarget,
          changeOrigin: true,
        },
      },
    },
  }
})
