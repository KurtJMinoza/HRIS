import { useMemo } from 'react'
import { useJsApiLoader } from '@react-google-maps/api'

const GOOGLE_MAPS_LOADER_ID = 'google-maps-places'
const GOOGLE_MAPS_LIBRARIES = ['places']

/**
 * Shared Google Maps Places loader.
 * Ensures all consumers use the exact same loader options and script id.
 */
export function useGoogleMapsLoader() {
  const apiKey = import.meta.env.VITE_GOOGLE_PLACES_API_KEY || ''
  const missingKeyError = useMemo(() => {
    if (apiKey) return null
    return new Error('Missing VITE_GOOGLE_PLACES_API_KEY (Google Places API key).')
  }, [apiKey])

  const { isLoaded, loadError } = useJsApiLoader({
    id: GOOGLE_MAPS_LOADER_ID,
    googleMapsApiKey: apiKey,
    libraries: GOOGLE_MAPS_LIBRARIES,
  })

  return {
    isLoaded: !missingKeyError && isLoaded,
    loadError: missingKeyError || loadError || null,
  }
}

