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
    // Stable in dev — avoids index.html cache-bust reload on every Vite/PM2 restart.
    return 'dev'
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

  const envFlag = (name) => {
    const value = process.env[name] ?? env[name]
    return value === '1' || value === 'true'
  }

  const stablePublicDev =
    envFlag('FRONTEND_DEV_DISABLE_HMR') || envFlag('VITE_DISABLE_HMR')

  const devOptimizeDepsInclude = [
    '@mediapipe/tasks-vision',
    'react',
    'react-dom',
    'react-dom/client',
    'react-router-dom',
    '@tanstack/react-query',
    'framer-motion',
    'lucide-react',
    'recharts',
    'laravel-echo',
    'pusher-js',
    'axios',
    'date-fns',
    'sonner',
    'exceljs',
    'maplibre-gl',
    'survey-core',
    'survey-react-ui',
  ]

  /** Retry when Octane is still booting or a worker is restarting (Windows). */
  function devApiProxy() {
    const proxyOptions = { target: proxyTarget, changeOrigin: true }
    const maxRetries = 4
    const retryDelayMs = 600

    return {
      ...proxyOptions,
      configure: (proxy) => {
        proxy.on('error', (err, req, res) => {
          const retryable = err?.code === 'ECONNREFUSED' || err?.code === 'ECONNRESET'
          const attempt = Number(req?._hrisOctaneProxyRetryCount || 0)
          if (retryable && req && res && !res.headersSent && attempt < maxRetries) {
            req._hrisOctaneProxyRetryCount = attempt + 1
            setTimeout(() => {
              proxy.web(req, res, proxyOptions)
            }, retryDelayMs * (attempt + 1))
            return
          }
          console.error('[vite] http proxy error:', req?.url ?? '', err?.message ?? err)
        })
      },
    }
  }

  function devWarmupPlugin() {
    if (mode !== 'development') {
      return { name: 'hris-dev-warmup' }
    }

    const warmUrls = [
      '/src/main.jsx',
      '/src/App.jsx',
      '/src/pages/AdminDashboard.jsx',
      '/src/pages/EmployeeDashboard.jsx',
    ]

    return {
      name: 'hris-dev-warmup',
      configureServer(server) {
        server.httpServer?.once('listening', () => {
          void (async () => {
            for (const url of warmUrls) {
              try {
                await server.warmupRequest(url)
              } catch {
                // best-effort
              }
            }
          })()
        })
      },
    }
  }

  return {
    base,
    define: {
      __HRIS_APP_VERSION__: JSON.stringify(appVersion),
    },
    plugins: [
      react({ fastRefresh: !stablePublicDev }),
      devWarmupPlugin(),
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
      include: devOptimizeDepsInclude,
      holdUntilCrawlEnd: true,
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
      port: Number(env.FRONTEND_DEV_PORT || 5100),
      host: true,
      strictPort: true,
      // Cloudflare → Apache → Vite: HMR + "optimized deps changed" full reload breaks 2nd refresh.
      hmr: stablePublicDev ? false : undefined,
      headers: {
        'Cache-Control': 'no-store, must-revalidate',
      },
      allowedHosts: mode === 'development' ? true : ['localhost', '127.0.0.1', 'hris.agctek.co'],
      warmup: {
        clientFiles: [
          './src/main.jsx',
          './src/App.jsx',
          './src/pages/AdminDashboard.jsx',
          './src/pages/EmployeeDashboard.jsx',
          './src/pages/AdminGeofencing.jsx',
          './src/pages/AdminEvaluation.jsx',
        ],
      },
      // Same-origin /api in dev → no CORS issues. Set VITE_API_URL=/api in .env (see .env.example).
      proxy: {
        '/api': devApiProxy(),
        // Sanctum CSRF cookie (SPA must GET this before POST; same-origin in dev via Vite)
        '/sanctum': devApiProxy(),
      },
    },
  }
})
