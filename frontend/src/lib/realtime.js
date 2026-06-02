import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { apiOrigin, getToken } from '@/api'

let echoInstance = null
let echoKey = null

function firstEnv(...keys) {
  for (const key of keys) {
    const value = import.meta.env[key]
    if (typeof value === 'string' && value.trim() !== '') return value.trim()
  }
  return ''
}

function realtimeConfigKey() {
  return [
    firstEnv('VITE_REVERB_APP_KEY', 'VITE_PUSHER_APP_KEY'),
    firstEnv('VITE_REVERB_HOST', 'VITE_PUSHER_HOST'),
    firstEnv('VITE_REVERB_PORT', 'VITE_PUSHER_PORT'),
    firstEnv('VITE_REVERB_SCHEME', 'VITE_PUSHER_SCHEME'),
    getToken() || '',
  ].join('|')
}

export function getRealtimeEcho() {
  if (typeof window === 'undefined') return null

  const appKey = firstEnv('VITE_REVERB_APP_KEY', 'VITE_PUSHER_APP_KEY')
  if (!appKey) return null

  const nextKey = realtimeConfigKey()
  if (echoInstance && echoKey === nextKey) return echoInstance

  if (echoInstance) {
    try {
      echoInstance.disconnect()
    } catch {
      // ignore stale socket teardown errors
    }
  }

  window.Pusher = Pusher

  const scheme = firstEnv('VITE_REVERB_SCHEME', 'VITE_PUSHER_SCHEME') || 'http'
  const host = firstEnv('VITE_REVERB_HOST', 'VITE_PUSHER_HOST') || window.location.hostname
  const port = Number(firstEnv('VITE_REVERB_PORT', 'VITE_PUSHER_PORT') || (scheme === 'https' ? 443 : 80))
  const token = getToken()

  echoInstance = new Echo({
    broadcaster: firstEnv('VITE_BROADCASTER') || (firstEnv('VITE_REVERB_APP_KEY') ? 'reverb' : 'pusher'),
    key: appKey,
    cluster: firstEnv('VITE_PUSHER_APP_CLUSTER') || 'mt1',
    wsHost: host,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    authEndpoint: `${apiOrigin()}/broadcasting/auth`,
    auth: {
      headers: {
        Accept: 'application/json',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
    },
    withCredentials: true,
  })
  echoKey = nextKey
  window.Echo = echoInstance

  return echoInstance
}

export function disconnectRealtimeEcho() {
  if (!echoInstance) return
  try {
    echoInstance.disconnect()
  } catch {
    // ignore disconnect issues during logout/navigation
  }
  echoInstance = null
  echoKey = null
  if (typeof window !== 'undefined' && window.Echo) {
    delete window.Echo
  }
}
