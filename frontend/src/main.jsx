import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { QueryClientProvider } from '@tanstack/react-query'
import './index.css'
import App from './App.jsx'
import { initializeAuthSession } from './api'
import { queryClient } from '@/lib/queryClient'
import { registerChunkReloadHandler } from '@/lib/chunkReload'

const appVersion = typeof __HRIS_APP_VERSION__ !== 'undefined' ? __HRIS_APP_VERSION__ : 'dev'
registerChunkReloadHandler(appVersion)

const rootEl = document.getElementById('root')
if (!rootEl) {
  throw new Error('Missing #root — index.html must contain <div id="root"></div>.')
}

async function bootstrap() {
  createRoot(rootEl).render(
    <StrictMode>
      <QueryClientProvider client={queryClient}>
        <App />
      </QueryClientProvider>
    </StrictMode>,
  )

  // Do not block first paint on CSRF/session bootstrap.
  initializeAuthSession().catch(() => {})
}

bootstrap()
