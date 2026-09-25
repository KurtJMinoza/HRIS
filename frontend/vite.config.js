import path from 'path'
import { execSync } from 'child_process'
import { fileURLToPath } from 'url'
import { defineConfig, loadEnv } from 'vite'
import react from '@vitejs/plugin-react'

const __dirname = path.dirname(fileURLToPath(import.meta.url))

function resolveAppVersion(mode) {
  if (process.env.HRIS_APP_VERSION) {
    return String(process.env.HRIS_APP_VERSION)
  }

  if (mode === 'development') {
    return `dev-${Date.now()}`
  }

  try {
    return execSync('git rev-parse --short HEAD', { cwd: __dirname, stdio: ['ignore', 'pipe', 'ignore'] })
      .toString()
      .trim()
  } catch {
    return new Date().toISOString().slice(0, 16).replace(/[:-T]/g, '')
  }
}

// https://vite.dev/config/
export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, __dirname, '')
  const proxyTarget = env.VITE_DEV_API_PROXY_TARGET || 'http://127.0.0.1:8000'
  const appVersion = resolveAppVersion(mode)

  // Set VITE_BASE=/HR/ in .env when the built app lives under a subpath (e.g. http://localhost/HR/).
  // Also set BrowserRouter basename in App.jsx via import.meta.env.BASE_URL.
  const base = env.VITE_BASE || '/'

  return {
    base,
    define: {
      __HRIS_APP_VERSION__: JSON.stringify(appVersion),
    },
    plugins: [
      react(),
      {
        name: 'hris-inject-app-version',
        transformIndexHtml(html) {
          return html.replaceAll('__HRIS_APP_VERSION__', appVersion)
        },
      },
    ],
    publicDir: 'public',
    resolve: {
      alias: {
        '@': path.resolve(__dirname, './src'),
      },
    },
    optimizeDeps: {
      include: ['@mediapipe/tasks-vision'],
    },
    build: {
      rollupOptions: {
        output: {
          manualChunks(id) {
            if (!id.includes('node_modules')) return undefined
            if (id.includes('react-dom') || id.includes('/react/')) return 'vendor-react'
            if (id.includes('react-router')) return 'vendor-router'
            if (id.includes('@tanstack/react-query')) return 'vendor-query'
            if (id.includes('framer-motion')) return 'vendor-motion'
            if (id.includes('recharts')) return 'vendor-charts'
            if (id.includes('lucide-react')) return 'vendor-icons'
            if (id.includes('@mediapipe')) return 'vendor-mediapipe'
            if (id.includes('maplibre') || id.includes('mapillary') || id.includes('@turf')) return 'vendor-maps'
            if (id.includes('exceljs')) return 'vendor-excel'
            if (id.includes('@react-pdf')) return 'vendor-pdf'
            if (id.includes('survey-core') || id.includes('survey-react-ui')) return 'vendor-survey'
            return undefined
          },
        },
      },
    },
    server: {
      port: 5173,
      host: true,
      strictPort: true,
      headers: {
        'Cache-Control': 'no-store, must-revalidate',
      },
      allowedHosts: mode === 'development' ? true : ['localhost', '127.0.0.1', 'hris.agctek.co'],
      warmup: {
        clientFiles: [
          './src/pages/AdminGeofencing.jsx',
          './src/pages/AdminEvaluation.jsx',
        ],
      },
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
