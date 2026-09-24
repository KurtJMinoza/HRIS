import { lazy } from 'react'
import { isStaleChunkLoadError, reloadForStaleChunks } from '@/lib/chunkReload'

export function lazyWithChunkReload(importer) {
  return lazy(() =>
    importer().catch((error) => {
      if (!isStaleChunkLoadError(error)) {
        throw error
      }

      const appVersion = typeof __HRIS_APP_VERSION__ !== 'undefined' ? __HRIS_APP_VERSION__ : 'dev'
      if (reloadForStaleChunks(appVersion)) {
        return new Promise(() => {})
      }

      throw error
    }),
  )
}
