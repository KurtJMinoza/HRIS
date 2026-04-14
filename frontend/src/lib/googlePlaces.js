let loaderPromise = null
const SHARED_GOOGLE_MAPS_SCRIPT_ID = 'google-maps-places'

function buildScriptUrl(apiKey) {
  const params = new URLSearchParams({
    key: apiKey,
    libraries: 'places',
  })
  return `https://maps.googleapis.com/maps/api/js?${params.toString()}`
}

export function loadGooglePlaces() {
  if (typeof window === 'undefined') return Promise.reject(new Error('Google Places can only load in the browser.'))
  const apiKey = import.meta?.env?.VITE_GOOGLE_PLACES_API_KEY
  if (!apiKey) {
    return Promise.reject(new Error('Missing VITE_GOOGLE_PLACES_API_KEY (Google Places API key).'))
  }

  if (window.google?.maps?.places) return Promise.resolve(window.google)
  if (loaderPromise) return loaderPromise

  loaderPromise = new Promise((resolve, reject) => {
    const existing = document.getElementById(SHARED_GOOGLE_MAPS_SCRIPT_ID) || document.querySelector('script[data-google-places="true"]')
    if (existing) {
      existing.addEventListener('load', () => resolve(window.google))
      existing.addEventListener('error', () => reject(new Error('Failed to load Google Places script.')))
      return
    }

    const script = document.createElement('script')
    script.src = buildScriptUrl(apiKey)
    script.async = true
    script.defer = true
    script.id = SHARED_GOOGLE_MAPS_SCRIPT_ID
    script.dataset.googlePlaces = 'true'
    script.onload = () => resolve(window.google)
    script.onerror = () => reject(new Error('Failed to load Google Places script.'))
    document.head.appendChild(script)
  })

  return loaderPromise
}

function component(components, type) {
  return components.find((c) => Array.isArray(c.types) && c.types.includes(type))
}

export function mapPlaceToAddressFields(place) {
  const components = Array.isArray(place?.address_components) ? place.address_components : []

  const streetNumber = component(components, 'street_number')?.long_name || ''
  const route = component(components, 'route')?.long_name || ''
  const premise = component(components, 'premise')?.long_name || ''
  const subpremise = component(components, 'subpremise')?.long_name || ''

  const barangay =
    component(components, 'sublocality_level_1')?.long_name ||
    component(components, 'sublocality')?.long_name ||
    component(components, 'neighborhood')?.long_name ||
    ''

  const city =
    component(components, 'locality')?.long_name ||
    component(components, 'administrative_area_level_3')?.long_name ||
    component(components, 'administrative_area_level_2')?.long_name ||
    ''

  const province =
    component(components, 'administrative_area_level_1')?.long_name ||
    component(components, 'administrative_area_level_2')?.long_name ||
    ''

  let postalCode = component(components, 'postal_code')?.long_name || ''
  const country = component(components, 'country')?.long_name || ''

  // Fallback: some PH results only include ZIP at the end of formatted_address
  if (!postalCode && place?.formatted_address) {
    const match = String(place.formatted_address).match(/(\d{4})(?:\D*)$/)
    if (match) {
      postalCode = match[1]
    }
  }

  const streetParts = [
    [streetNumber, route].filter(Boolean).join(' ').trim(),
    premise,
    subpremise ? `#${subpremise}` : '',
  ]
    .filter(Boolean)
    .join(', ')

  return {
    full_address: place?.formatted_address || place?.name || '',
    street_address: streetParts,
    barangay,
    city,
    province,
    postal_code: postalCode,
    country,
  }
}

