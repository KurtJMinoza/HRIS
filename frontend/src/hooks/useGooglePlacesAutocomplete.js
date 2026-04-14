import { useEffect, useRef, useState } from 'react'
import { loadGooglePlaces } from '@/lib/googlePlaces'

export function useGooglePlacesAutocomplete(inputRef, options) {
  const autocompleteRef = useRef(null)
  const [autocomplete, setAutocomplete] = useState(null)
  const [ready, setReady] = useState(false)
  const [loadError, setLoadError] = useState('')

  useEffect(() => {
    let alive = true
    const el = inputRef?.current
    if (!el) return

    loadGooglePlaces()
      .then((google) => {
        if (!alive) return
        setReady(true)
        setLoadError('')
        if (autocompleteRef.current) return
        const instance = new google.maps.places.Autocomplete(el, {
          fields: ['address_components', 'formatted_address', 'name'],
          types: ['address'],
          ...(options || {}),
        })
        autocompleteRef.current = instance
        setAutocomplete(instance)
      })
      .catch((e) => {
        if (!alive) return
        setLoadError(e?.message || 'Failed to load Google Places.')
        setReady(false)
      })

    return () => {
      alive = false
    }
  }, [inputRef, options])

  return { autocomplete, ready, loadError }
}

