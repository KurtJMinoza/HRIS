import { createElement, useCallback, useEffect, useMemo, useRef, useState } from 'react'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import * as turf from '@turf/turf'
import {
  Building2,
  ChevronLeft,
  ChevronRight,
  Circle,
  Edit3,
  Pentagon,
  Plus,
  Power,
  RefreshCw,
  Save,
  Search,
  Trash2,
  Users,
} from 'lucide-react'
import {
  companyLogoUrl,
  createBranchGeofence,
  getAdminGeofencing,
  getBranchGeofences,
  searchGeofenceLocation,
  updateBranchGeofence,
  updateBranchGeofenceSettings,
} from '@/api'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import { useToast } from '@/components/ui/use-toast'
import { cn } from '@/lib/utils'

const DEFAULT_CENTER = [14.5995, 120.9842]
const PAGE_SIZE = 5
const ORANGE = '#f04414'

const orangePinIcon = L.divIcon({
  className: 'geofence-pin-icon',
  html: '<span class="block size-8 rounded-full bg-[#f04414] p-1 shadow-lg shadow-orange-900/25 ring-4 ring-[#f04414]/20"><span class="block size-full rounded-full border-2 border-white bg-[#f04414]"></span></span>',
  iconSize: [32, 32],
  iconAnchor: [16, 16],
})

function blankForm(branchId = null, branchName = '') {
  return {
    id: null,
    branch_id: branchId,
    name: branchName || '',
    type: 'circle',
    center_lat: DEFAULT_CENTER[0],
    center_lng: DEFAULT_CENTER[1],
    radius_meters: 100,
    polygon_geojson: null,
    is_active: false,
    enforcement_mode: 'enforce',
    priority: 1,
    accuracy_threshold_meters: 100,
    notes: '',
  }
}

function formatDate(value) {
  if (!value) return '-'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return '-'
  return date.toLocaleDateString(undefined, { month: 'numeric', day: 'numeric', year: 'numeric' })
}

function branchCode(branch) {
  return branch?.branch_code || `BR-${String(branch?.id || '').padStart(4, '0')}`
}

function branchStatus(branch) {
  if (!branch?.geofence_enabled) {
    return { label: 'Disabled', dot: 'bg-slate-400', text: 'text-slate-600' }
  }
  if (Number(branch?.active_geofences_count || 0) > 0) {
    return { label: 'Enabled', dot: 'bg-emerald-500', text: 'text-slate-700' }
  }
  return { label: 'Inactive', dot: 'bg-orange-500', text: 'text-slate-700' }
}

function geofenceCenter(geofence) {
  if (geofence?.type === 'circle' && geofence.center_lat != null && geofence.center_lng != null) {
    return [Number(geofence.center_lat), Number(geofence.center_lng)]
  }
  const coords = geofence?.polygon_geojson?.geometry?.coordinates?.[0]
  if (Array.isArray(coords) && coords.length) {
    return [Number(coords[0][1]), Number(coords[0][0])]
  }
  return null
}

function branchLocation(branch) {
  const lat = Number(branch?.branch_latitude)
  const lng = Number(branch?.branch_longitude)
  if (Number.isFinite(lat) && Number.isFinite(lng)) return [lat, lng]
  return null
}

function validLatLngPair(point) {
  if (!Array.isArray(point) || point.length < 2) return null
  const lat = Number(point[0])
  const lng = Number(point[1])
  if (!Number.isFinite(lat) || !Number.isFinite(lng)) return null
  if (lat < -90 || lat > 90 || lng < -180 || lng > 180) return null
  if (lat === 0 && lng === 0) return null
  return [lat, lng]
}

function polygonPoints(geojson) {
  return geojson?.geometry?.coordinates?.[0]?.map(([lng, lat]) => [lat, lng]) ?? []
}

function editablePolygonPoints(geojson) {
  const points = polygonPoints(geojson)
  if (points.length < 2) return points
  const first = points[0]
  const last = points[points.length - 1]
  if (first && last && Number(first[0]) === Number(last[0]) && Number(first[1]) === Number(last[1])) {
    return points.slice(0, -1)
  }
  return points
}

function polygonFeatureFromPoints(points) {
  const openPoints = points
    .map((point) => [Number(point[0]), Number(point[1])])
    .filter(([lat, lng]) => Number.isFinite(lat) && Number.isFinite(lng))
  if (openPoints.length < 3) return null
  const coords = openPoints.map(([lat, lng]) => [Number(lng.toFixed(7)), Number(lat.toFixed(7))])
  const first = coords[0]
  const last = coords[coords.length - 1]
  if (first[0] !== last[0] || first[1] !== last[1]) coords.push(first)
  let feature = {
    type: 'Feature',
    properties: {},
    geometry: { type: 'Polygon', coordinates: [coords] },
  }
  if (coords.length > 250) {
    feature = turf.simplify(feature, { tolerance: 0.00001, highQuality: false })
  }
  return feature
}

function radiusHandleLatLng(map, center, radiusMeters) {
  const centerPoint = map.latLngToLayerPoint(center)
  const edgeLatLng = L.latLng(center.lat, center.lng + 0.001)
  const metersPerPixel = map.distance(center, edgeLatLng) / Math.max(1, map.latLngToLayerPoint(edgeLatLng).distanceTo(centerPoint))
  return map.layerPointToLatLng([centerPoint.x + (Number(radiusMeters) || 100) / Math.max(metersPerPixel, 0.01), centerPoint.y])
}

function formFromGeofence(branchId, branchName, geofence) {
  if (!geofence) return blankForm(branchId, branchName)
  return {
    ...blankForm(branchId, branchName),
    ...geofence,
    center_lat: geofence.center_lat ?? DEFAULT_CENTER[0],
    center_lng: geofence.center_lng ?? DEFAULT_CENTER[1],
    radius_meters: geofence.radius_meters ?? 100,
    is_active: normalizeBoolean(geofence.is_active),
    accuracy_threshold_meters: geofence.accuracy_threshold_meters ?? 100,
    priority: geofence.priority ?? 1,
    notes: geofence.notes ?? '',
  }
}

function normalizeBoolean(value) {
  if (typeof value === 'boolean') return value
  if (typeof value === 'number') return value === 1
  if (typeof value === 'string') return ['1', 'true', 'yes', 'on'].includes(value.toLowerCase())
  return false
}

function canEditGeofenceShape(form) {
  if (form?.draft_key) return true
  return !normalizeBoolean(form?.is_active)
}

function offsetLatLngMeters(center, meters = 10) {
  const point = validLatLngPair(center) || DEFAULT_CENTER
  const latOffset = meters / 111320
  const lngOffset = meters / (111320 * Math.max(0.2, Math.cos((point[0] * Math.PI) / 180)))
  return [
    Number((point[0] + latOffset).toFixed(7)),
    Number((point[1] + lngOffset).toFixed(7)),
  ]
}

function nextDraftCenter(branch, geofences, fallbackCenter = null) {
  const centers = geofences.map(geofenceCenter).map(validLatLngPair).filter(Boolean)
  const base = centers.at(-1) || validLatLngPair(branchLocation(branch)) || validLatLngPair(fallbackCenter) || DEFAULT_CENTER
  const offsetMeters = 5 + ((geofences.length % 4) * 5)
  return centers.length ? offsetLatLngMeters(base, offsetMeters) : base
}

function nextDraftPriority(geofences) {
  return Math.max(0, ...geofences.map((geofence) => Number(geofence.priority || 0))) + 1
}

function nextDraftName(branch, geofences) {
  const base = branch?.branch_name || branch?.name || 'Branch'
  return `${base} geofence ${geofences.length + 1}`
}

function draftFormForBranch(branchId, branch, geofences, fallbackCenter = null) {
  const next = blankForm(branchId, nextDraftName(branch, geofences))
  const center = nextDraftCenter(branch, geofences, fallbackCenter)

  return {
    form: {
      ...next,
      draft_key: `draft:${Date.now()}`,
      center_lat: center[0],
      center_lng: center[1],
      priority: nextDraftPriority(geofences),
      radius_meters: Number(next.radius_meters || 100),
    },
    center,
  }
}

function geofenceMapStatus(geofence, selected = false) {
  if (!geofence?.id || geofence?.draft_key) return 'draft'
  if (normalizeBoolean(geofence.is_active)) return 'active'
  return selected ? 'draft' : 'inactive'
}

function geofenceMapStyle(status, selected = false) {
  const styles = {
    active: { color: '#16a34a', fillColor: '#22c55e', fillOpacity: 0.12, dashArray: null },
    inactive: { color: '#94a3b8', fillColor: '#cbd5e1', fillOpacity: 0.09, dashArray: '5 5' },
    draft: { color: ORANGE, fillColor: ORANGE, fillOpacity: 0.16, dashArray: '6 5' },
  }
  return {
    ...styles[status],
    weight: selected ? 4 : 2,
  }
}

function geofenceMapLabel(status, name) {
  return `<div class="rounded bg-white/95 px-2 py-1 text-[10px] font-bold uppercase tracking-wide shadow">${status.toUpperCase()}${name ? ` · ${name}` : ''}</div>`
}

function SegmentButton({ active, icon, children, onClick, disabled = false }) {
  return (
    <Button
      type="button"
      size="sm"
      variant="outline"
      onClick={onClick}
      disabled={disabled}
      className={cn(
        'h-9 gap-2 rounded-md border-slate-200 bg-white px-4 text-xs font-semibold text-slate-800 shadow-none hover:bg-slate-50 dark:border-border dark:bg-background dark:text-foreground',
        active && 'border-[#f04414] bg-orange-50 text-[#f04414] hover:bg-orange-50 dark:border-orange-500 dark:bg-orange-500/10 dark:text-orange-300',
      )}
    >
      {createElement(icon, { className: 'size-4' })}
      {children}
    </Button>
  )
}

function SelectBox({ value, onChange, children, className, disabled = false }) {
  return (
    <select
      className={cn(
        'h-9 w-full rounded-md border border-slate-200 bg-white px-3 text-xs text-slate-800 shadow-sm outline-none transition focus:border-[#f04414] focus:ring-2 focus:ring-orange-100 dark:border-border dark:bg-background dark:text-foreground',
        className,
      )}
      value={value}
      onChange={onChange}
      disabled={disabled}
    >
      {children}
    </select>
  )
}

function CompanyLogo({ branch }) {
  const logoUrl = companyLogoUrl(branch)

  return (
    <span className="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-border dark:bg-background">
      {logoUrl ? (
        <img src={logoUrl} alt="" className="size-full object-contain p-1.5" />
      ) : (
        <Building2 className="size-5 text-slate-400" />
      )}
    </span>
  )
}

function GeofenceMapOptimized({
  branch,
  geofences,
  form,
  setForm,
  setDrawMode,
  drawMode,
  focusKey,
  focusPoint,
}) {
  const mapEl = useRef(null)
  const mapRef = useRef(null)
  const staticLayerRef = useRef(null)
  const activeLayerRef = useRef(null)
  const fitKeyRef = useRef('')
  const focusKeyRef = useRef('')

  useEffect(() => {
    if (!mapEl.current || mapRef.current) return undefined

    const map = L.map(mapEl.current, { zoomControl: true }).setView(DEFAULT_CENTER, 16)
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
      attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map)

    mapRef.current = map
    staticLayerRef.current = L.featureGroup().addTo(map)
    activeLayerRef.current = L.featureGroup().addTo(map)

    return () => {
      map.remove()
      mapRef.current = null
      staticLayerRef.current = null
      activeLayerRef.current = null
    }
  }, [])

  useEffect(() => {
    const map = mapRef.current
    if (!map) return undefined

    const onClick = (event) => {
      if (!canEditGeofenceShape(form)) return

      const lat = Number(event.latlng.lat.toFixed(7))
      const lng = Number(event.latlng.lng.toFixed(7))

      if (drawMode === 'circle') {
        setForm((s) => ({ ...s, type: 'circle', center_lat: lat, center_lng: lng }))
        return
      }

      if (drawMode === 'polygon') {
        setForm((s) => {
          const current = polygonPoints(s.polygon_geojson)
          const next = [...current.filter((_, index) => index !== current.length - 1), [lat, lng]]
          return { ...s, type: 'polygon', polygon_geojson: polygonFeatureFromPoints(next) }
        })
      }
    }

    map.on('click', onClick)
    return () => map.off('click', onClick)
  }, [drawMode, form, setForm])

  useEffect(() => {
    const map = mapRef.current
    if (!map || !focusPoint || focusKeyRef.current === focusKey) return
    const lat = Number(focusPoint.latitude)
    const lng = Number(focusPoint.longitude)
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return
    focusKeyRef.current = focusKey
    map.setView([lat, lng], 18, { animate: true })
  }, [focusKey, focusPoint])

  useEffect(() => {
    const map = mapRef.current
    const group = staticLayerRef.current
    if (!map || !group) return

    group.clearLayers()
    geofences.forEach((geofence) => {
      if (geofence.id === form.id) return

      const status = geofenceMapStatus(geofence)
      const style = geofenceMapStyle(status)
      const label = geofenceMapLabel(status, geofence.name || 'Geofence')

      if (geofence.type === 'circle' && geofence.center_lat != null && geofence.center_lng != null) {
        const layer = L.circle([geofence.center_lat, geofence.center_lng], {
          ...style,
          radius: Number(geofence.radius_meters || 0),
        }).bindTooltip(label, { permanent: true, direction: 'top', opacity: 0.92, className: 'geofence-map-label' })
        layer.on('click', (event) => {
          L.DomEvent.stopPropagation(event.originalEvent)
          setForm(formFromGeofence(geofence.branch_id, branch?.branch_name || '', geofence))
          setDrawMode('circle')
        })
        group.addLayer(layer)
      }

      if (geofence.type === 'polygon') {
        const points = polygonPoints(geofence.polygon_geojson)
        if (points.length >= 3) {
          const layer = L.polygon(points, style).bindTooltip(label, { permanent: true, direction: 'top', opacity: 0.92, className: 'geofence-map-label' })
          layer.on('click', (event) => {
            L.DomEvent.stopPropagation(event.originalEvent)
            setForm(formFromGeofence(geofence.branch_id, branch?.branch_name || '', geofence))
            setDrawMode('polygon')
          })
          group.addLayer(layer)
        }
      }
    })

    const fitKey = `${branch?.id || 'none'}:${geofences.map((g) => `${g.id}:${g.updated_at || ''}`).join('|')}`
    if (fitKeyRef.current === fitKey) return

    fitKeyRef.current = fitKey
    const bounds = group.getBounds()
    const fallbackCenter = geofences.map(geofenceCenter).find(Boolean)
    if (bounds.isValid()) map.fitBounds(bounds.pad(0.25), { maxZoom: 17, animate: false })
    else if (fallbackCenter) map.setView(fallbackCenter, Math.max(map.getZoom(), 16), { animate: false })
    else if (branch) map.setView(DEFAULT_CENTER, 15, { animate: false })
  }, [branch, geofences, form.id, setDrawMode, setForm])

  useEffect(() => {
    const map = mapRef.current
    const group = activeLayerRef.current
    if (!map || !group) return

    group.clearLayers()
    const editableShape = canEditGeofenceShape(form)
    const selectedStatus = geofenceMapStatus(form, true)
    const selectedStyle = geofenceMapStyle(selectedStatus, true)
    const selectedLabel = geofenceMapLabel(selectedStatus, form.name || 'Selected geofence')

    if (form.type === 'circle' && form.center_lat != null && form.center_lng != null) {
      const center = L.latLng(Number(form.center_lat), Number(form.center_lng))
      const radius = Math.max(5, Number(form.radius_meters) || 100)
      const circle = L.circle(center, {
        radius,
        ...selectedStyle,
      }).bindTooltip(selectedLabel, { permanent: true, direction: 'top', opacity: 0.95, className: 'geofence-map-label' })

      group.addLayer(circle)

      if (!editableShape) {
        return
      }

      const centerMarker = L.marker(center, { draggable: true, icon: orangePinIcon })
      const resizeMarker = L.marker(radiusHandleLatLng(map, center, radius), {
        draggable: true,
        icon: L.divIcon({
          className: 'geofence-radius-handle',
          html: '<span class="block size-4 rounded-full border-2 border-white bg-[#f04414] shadow ring-2 ring-[#f04414]/30"></span>',
          iconSize: [16, 16],
          iconAnchor: [8, 8],
        }),
      })

      centerMarker.on('drag', (event) => {
        const nextCenter = event.target.getLatLng()
        circle.setLatLng(nextCenter)
        resizeMarker.setLatLng(radiusHandleLatLng(map, nextCenter, circle.getRadius()))
      })
      centerMarker.on('dragend', (event) => {
        const ll = event.target.getLatLng()
        setForm((s) => ({ ...s, center_lat: Number(ll.lat.toFixed(7)), center_lng: Number(ll.lng.toFixed(7)) }))
      })
      resizeMarker.on('drag', (event) => {
        circle.setRadius(Math.max(5, Math.round(map.distance(circle.getLatLng(), event.target.getLatLng()))))
      })
      resizeMarker.on('dragend', (event) => {
        const nextRadius = Math.max(5, Math.round(map.distance(circle.getLatLng(), event.target.getLatLng())))
        circle.setRadius(nextRadius)
        resizeMarker.setLatLng(radiusHandleLatLng(map, circle.getLatLng(), nextRadius))
        setForm((s) => ({ ...s, radius_meters: nextRadius }))
      })

      group.addLayer(centerMarker)
      group.addLayer(resizeMarker)
    }

    if (form.type === 'polygon') {
      const openPoints = editablePolygonPoints(form.polygon_geojson)
      if (openPoints.length) {
        const shape = openPoints.length >= 3
          ? L.polygon(openPoints, selectedStyle).bindTooltip(selectedLabel, { permanent: true, direction: 'top', opacity: 0.95, className: 'geofence-map-label' })
          : L.polyline(openPoints, selectedStyle)

        const markers = []
        const syncPolygon = () => {
          const nextPoints = markers.map((marker) => {
            const ll = marker.getLatLng()
            return [Number(ll.lat.toFixed(7)), Number(ll.lng.toFixed(7))]
          })
          shape.setLatLngs(openPoints.length >= 3 ? [nextPoints] : nextPoints)
          return nextPoints
        }

        group.addLayer(shape)
        if (!editableShape) {
          return
        }

        openPoints.forEach((point) => {
          const marker = L.marker(point, {
            draggable: true,
            icon: L.divIcon({
              className: 'geofence-polygon-handle',
              html: '<span class="block size-4 rounded-full border-2 border-white bg-[#f04414] shadow ring-2 ring-[#f04414]/30"></span>',
              iconSize: [16, 16],
              iconAnchor: [8, 8],
            }),
          })
          markers.push(marker)
          marker.on('drag', syncPolygon)
          marker.on('dragend', () => {
            setForm((s) => ({ ...s, polygon_geojson: polygonFeatureFromPoints(syncPolygon()) }))
          })
          group.addLayer(marker)
        })
      }
    }
  }, [form, setForm])

  return (
    <div
      ref={mapEl}
      className="h-[520px] min-h-[420px] w-full overflow-hidden rounded-b-lg border-t border-slate-200 bg-slate-100 dark:border-border dark:bg-muted"
    />
  )
}

export default function AdminGeofencing() {
  const [branches, setBranches] = useState([])
  const [selectedBranchId, setSelectedBranchId] = useState(null)
  const [geofences, setGeofences] = useState([])
  const [branchEmployees, setBranchEmployees] = useState([])
  const [form, setForm] = useState(blankForm())
  const [drawMode, setDrawMode] = useState('circle')
  const [branchSearch, setBranchSearch] = useState('')
  const [mapSearch, setMapSearch] = useState('')
  const [mapSearchMode, setMapSearchMode] = useState('address')
  const [mapSearchResults, setMapSearchResults] = useState([])
  const [mapSearchLoading, setMapSearchLoading] = useState(false)
  const [focusPoint, setFocusPoint] = useState(null)
  const [focusKey, setFocusKey] = useState(0)
  const [page, setPage] = useState(1)
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const mapSearchCacheRef = useRef(new Map())
  const mapSearchAbortRef = useRef(null)
  const { toast } = useToast()

  const selectedBranch = useMemo(
    () => branches.find((branch) => String(branch.id) === String(selectedBranchId)) || null,
    [branches, selectedBranchId],
  )

  const filteredBranches = useMemo(() => {
    const q = branchSearch.trim().toLowerCase()
    if (!q) return branches
    return branches.filter((branch) => [
      branch.company_name,
      branch.branch_name,
      branchCode(branch),
      branch.address,
      ...(branch.assigned_employees_preview || []).map((employee) => employee.name),
    ].some((value) => String(value || '').toLowerCase().includes(q)))
  }, [branches, branchSearch])

  const totalPages = Math.max(1, Math.ceil(filteredBranches.length / PAGE_SIZE))
  const pagedBranches = filteredBranches.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE)
  const rangeStart = filteredBranches.length ? (page - 1) * PAGE_SIZE + 1 : 0
  const rangeEnd = Math.min(page * PAGE_SIZE, filteredBranches.length)

  const shapeEditable = canEditGeofenceShape(form)

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const data = await getAdminGeofencing()
      const nextBranches = data.branches || []
      setBranches(nextBranches)
      setSelectedBranchId((current) => current || nextBranches[0]?.id || null)
    } catch (error) {
      toast({ title: 'Failed to load geofencing', description: error.message, variant: 'error' })
    } finally {
      setLoading(false)
    }
  }, [toast])

  const loadBranch = useCallback(async (branchId, options = {}) => {
    if (!branchId) {
      setGeofences([])
      setBranchEmployees([])
      setForm(blankForm())
      return
    }
    try {
      const data = await getBranchGeofences(branchId)
      const nextGeofences = data.geofences || []
      const nextEmployees = data.employees || []
      const branchName = data.branch?.branch_name || ''
      const preferredGeofence = options.preferredGeofenceId
        ? nextGeofences.find((geofence) => String(geofence.id) === String(options.preferredGeofenceId))
        : null
      const selectedGeofence = preferredGeofence || nextGeofences[0]
      const nextForm = formFromGeofence(branchId, branchName, selectedGeofence)
      const center = geofenceCenter(selectedGeofence) || branchLocation(data.branch) || geofenceCenter(nextForm) || DEFAULT_CENTER
      setGeofences(nextGeofences)
      setBranchEmployees(nextEmployees)
      if (data.branch) {
        setBranches((list) => list.map((branch) => (String(branch.id) === String(data.branch.id) ? { ...branch, ...data.branch } : branch)))
      }
      setForm({ ...nextForm, center_lat: center[0], center_lng: center[1] })
      setDrawMode(selectedGeofence?.type || 'circle')
      setFocusPoint({ latitude: center[0], longitude: center[1] })
      setFocusKey((key) => key + 1)
      return { branch: data.branch, geofences: nextGeofences }
    } catch (error) {
      toast({ title: 'Failed to load branch geofences', description: error.message, variant: 'error' })
      return null
    }
  }, [toast])

  useEffect(() => {
    load()
  }, [load])

  useEffect(() => {
    loadBranch(selectedBranchId)
  }, [selectedBranchId, loadBranch])

  useEffect(() => {
    setPage(1)
  }, [branchSearch])

  useEffect(() => {
    setPage((current) => Math.min(current, totalPages))
  }, [totalPages])

  useEffect(() => {
    const query = mapSearch.trim()
    const normalizedQuery = query.toLowerCase()
    mapSearchAbortRef.current?.abort()

    if (query.length < 3) {
      setMapSearchResults([])
      setMapSearchLoading(false)
      return undefined
    }

    const cacheKey = `${mapSearchMode}:${normalizedQuery}`
    const cached = mapSearchCacheRef.current.get(cacheKey)
    if (cached) {
      setMapSearchResults(cached)
      setMapSearchLoading(false)
      return undefined
    }

    const controller = new AbortController()
    mapSearchAbortRef.current = controller
    const timeout = window.setTimeout(async () => {
      setMapSearchLoading(true)
      try {
        const localResults = []
        const branchAddress = selectedBranch?.address || ''
        const branchCenter = geofenceCenter(form) || geofences.map(geofenceCenter).find(Boolean)
        if (
          branchAddress
          && branchCenter
          && branchAddress.toLowerCase().includes(normalizedQuery)
        ) {
          localResults.push({
            id: `branch:${selectedBranch.id}`,
            label: `${selectedBranch.branch_name || 'Selected branch'} address`,
            address: branchAddress,
            latitude: branchCenter[0],
            longitude: branchCenter[1],
            source: 'branch',
          })
        }

        const data = await searchGeofenceLocation(query, { signal: controller.signal, mode: mapSearchMode })
        const results = [...localResults, ...(data.results || [])].slice(0, 5)
        mapSearchCacheRef.current.set(cacheKey, results)
        setMapSearchResults(results)
        if (results.length === 0) {
          toast({
            title: 'No map search results',
            description: 'Try adding “Davao” or a nearby street/landmark, then click the map if needed.',
            variant: 'error',
          })
        }
      } catch (error) {
        if (error?.name !== 'AbortError') {
          toast({ title: 'Address search unavailable', description: 'Try clicking the map or entering coordinates manually.', variant: 'error' })
        }
      } finally {
        if (!controller.signal.aborted) setMapSearchLoading(false)
      }
    }, 500)

    return () => {
      window.clearTimeout(timeout)
      controller.abort()
    }
  }, [form, geofences, mapSearch, mapSearchMode, selectedBranch, toast])

  async function saveGeofence(options = {}) {
    if (!selectedBranchId) return
    setSaving(true)
    try {
      const payload = {
        name: form.name || selectedBranch?.branch_name || (form.type === 'circle' ? 'Office radius' : 'Branch polygon'),
        type: form.type,
        center_lat: form.type === 'circle' ? Number(form.center_lat) : null,
        center_lng: form.type === 'circle' ? Number(form.center_lng) : null,
        radius_meters: form.type === 'circle' ? Number(form.radius_meters) : null,
        polygon_geojson: form.type === 'polygon' ? form.polygon_geojson : null,
        is_active: normalizeBoolean(form.is_active),
        enforcement_mode: form.enforcement_mode || 'enforce',
        priority: Number(form.priority) || 1,
        accuracy_threshold_meters: Number(form.accuracy_threshold_meters) || 100,
        notes: form.notes || null,
      }
      const data = form.id
        ? await updateBranchGeofence(selectedBranchId, form.id, payload)
        : await createBranchGeofence(selectedBranchId, payload)
      const savedGeofenceId = data?.geofence?.id || form.id
      toast({ title: form.id ? 'Geofence updated' : 'Geofence created', variant: 'success' })
      const branchData = await loadBranch(selectedBranchId, { preferredGeofenceId: savedGeofenceId })
      await load()
      if (options.addAnother && branchData) {
        const { form: nextForm, center } = draftFormForBranch(selectedBranchId, branchData.branch || selectedBranch, branchData.geofences || [], geofenceCenter(form))
        setForm({ ...nextForm, id: null, is_active: false })
        setDrawMode('circle')
        setFocusPoint({ latitude: center[0], longitude: center[1] })
        setFocusKey((key) => key + 1)
      }
    } catch (error) {
      toast({ title: 'Save failed', description: error.message, variant: 'error' })
    } finally {
      setSaving(false)
    }
  }

  async function updateSettings(payload) {
    if (!selectedBranchId) return
    await updateBranchSettings(selectedBranchId, payload)
  }

  async function updateBranchSettings(branchId, payload) {
    if (!branchId) return
    try {
      const data = await updateBranchGeofenceSettings(branchId, payload)
      setBranches((list) => list.map((branch) => (branch.id === data.branch.id ? data.branch : branch)))
      toast({ title: 'Settings updated', variant: 'success' })
    } catch (error) {
      toast({ title: 'Settings failed', description: error.message, variant: 'error' })
    }
  }

  async function toggleCurrentGeofence() {
    if (!selectedBranchId || !form.id) return
    try {
      await updateBranchGeofence(selectedBranchId, form.id, { is_active: !normalizeBoolean(form.is_active) })
      await loadBranch(selectedBranchId, { preferredGeofenceId: form.id })
      await load()
    } catch (error) {
      toast({ title: 'Update failed', description: error.message, variant: 'error' })
    }
  }

  async function toggleGeofenceActive(geofence) {
    if (!selectedBranchId || !geofence?.id) return
    try {
      const nextActive = !normalizeBoolean(geofence.is_active)
      await updateBranchGeofence(selectedBranchId, geofence.id, { is_active: nextActive })
      await loadBranch(selectedBranchId, { preferredGeofenceId: geofence.id })
      await load()
      toast({ title: nextActive ? 'Geofence enabled' : 'Geofence saved as draft', variant: 'success' })
    } catch (error) {
      toast({ title: 'Update failed', description: error.message, variant: 'error' })
    }
  }

  async function searchAddress() {
    if (mapSearchResults[0]) {
      await applySearchResult(mapSearchResults[0])
    }
  }

  async function applySearchResult(result) {
    if (!canEditGeofenceShape(form)) {
      toast({
        title: 'Active geofence is locked',
        description: 'Disable it first to save as draft before moving or resizing its pin.',
        variant: 'error',
      })
      return
    }

    const lat = Number(result?.latitude)
    const lng = Number(result?.longitude)
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return
    setForm((s) => ({ ...s, type: s.type || 'circle', center_lat: lat, center_lng: lng }))
    setFocusPoint({
      latitude: lat,
      longitude: lng,
    })
    setFocusKey((key) => key + 1)
    setMapSearch(result.address || result.label || mapSearch)
    setMapSearchResults([])
  }

  function selectGeofence(geofence) {
    if (!geofence) return
    const nextForm = formFromGeofence(geofence.branch_id || selectedBranchId, selectedBranch?.branch_name || '', geofence)
    const center = geofenceCenter(geofence)
    setForm(nextForm)
    setDrawMode(geofence.type || 'circle')
    if (center) {
      setFocusPoint({ latitude: center[0], longitude: center[1] })
      setFocusKey((key) => key + 1)
    }
  }

  function startNewGeofence() {
    const { form: nextForm, center } = draftFormForBranch(selectedBranchId, selectedBranch, geofences, geofenceCenter(form))
    setForm({ ...nextForm, id: null, is_active: false })
    setDrawMode('circle')
    setFocusPoint({ latitude: center[0], longitude: center[1] })
    setFocusKey((key) => key + 1)
  }

  return (
    <div className="space-y-4 text-slate-950 dark:text-foreground">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight">Geofencing</h1>
          <p className="text-sm text-slate-500 dark:text-muted-foreground">Manage branch attendance locations, boundaries, and validation settings.</p>
        </div>
        <div className="flex gap-3">
          <Button
            variant="outline"
            className="h-10 gap-2 rounded-md border-slate-300 bg-white px-5 text-sm font-semibold text-slate-950 shadow-sm hover:bg-slate-50 dark:border-border dark:bg-background dark:text-foreground"
            onClick={load}
          >
            <RefreshCw className="size-4" />
            Refresh
          </Button>
          <Button
            className="h-10 gap-2 rounded-md bg-[#f04414] px-5 text-sm font-semibold text-white shadow-none hover:bg-[#e33a12]"
            onClick={startNewGeofence}
          >
            <Plus className="size-4" />
            Add geofence
          </Button>
        </div>
      </div>

      <div className="grid gap-4 xl:grid-cols-[minmax(560px,1fr)_minmax(330px,380px)]">
        <section className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-border dark:bg-card">
          <div className="border-b border-slate-200 p-3 dark:border-border">
            <div className="flex flex-wrap items-center gap-3">
              <SegmentButton disabled={!shapeEditable} active={drawMode === 'circle'} icon={Circle} onClick={() => { setDrawMode('circle'); setForm((s) => ({ ...s, type: 'circle' })) }}>
                Circle
              </SegmentButton>
              <SegmentButton disabled={!shapeEditable} active={drawMode === 'polygon'} icon={Pentagon} onClick={() => { setDrawMode('polygon'); setForm((s) => ({ ...s, type: 'polygon' })) }}>
                Polygon
              </SegmentButton>
            </div>
            <div className="mt-3 flex gap-2">
              <SelectBox className="w-40" value={mapSearchMode} onChange={(e) => setMapSearchMode(e.target.value)}>
                <option value="address">Address</option>
                <option value="establishment">Establishment</option>
                <option value="branch">Branch name</option>
              </SelectBox>
              <div className="relative min-w-0 flex-1">
                <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                <Input
                  value={mapSearch}
                  onChange={(e) => setMapSearch(e.target.value)}
                  placeholder={shapeEditable ? 'Search address on map' : 'Disable current geofence to move pin'}
                  disabled={!shapeEditable}
                  className="h-10 rounded-md border-slate-200 pl-9 text-xs shadow-sm placeholder:text-slate-500 focus-visible:ring-orange-100 dark:border-border"
                />
                {(mapSearchLoading || mapSearchResults.length > 0) ? (
                  <div className="absolute left-0 right-0 top-11 z-500 overflow-hidden rounded-md border border-slate-200 bg-white text-xs shadow-lg dark:border-border dark:bg-background">
                    {mapSearchLoading ? (
                      <div className="px-3 py-2 text-slate-500">Searching...</div>
                    ) : null}
                    {mapSearchResults.map((result) => (
                      <button
                        key={result.id || `${result.latitude}:${result.longitude}`}
                        type="button"
                        className="block w-full px-3 py-2 text-left hover:bg-orange-50 dark:hover:bg-orange-500/10"
                        onClick={() => applySearchResult(result)}
                      >
                        <span className="block font-semibold text-slate-900 dark:text-foreground">{result.label || result.address}</span>
                        <span className="block truncate text-[11px] text-slate-500 dark:text-muted-foreground">
                          {[result.address, result.city, result.country || result.province].filter(Boolean).join(' - ') || result.source || 'Search result'}
                        </span>
                      </button>
                    ))}
                  </div>
                ) : null}
              </div>
              <Button variant="outline" size="icon" className="size-10 rounded-md border-slate-200 bg-white text-slate-950 shadow-sm hover:bg-slate-50 dark:border-border dark:bg-background dark:text-foreground" onClick={searchAddress} disabled={!shapeEditable} title="Search address">
                <Search className="size-5" />
              </Button>
            </div>
          </div>
          <GeofenceMapOptimized
            branch={selectedBranch}
            geofences={geofences}
            form={form}
            setForm={setForm}
            setDrawMode={setDrawMode}
            drawMode={drawMode}
            focusKey={focusKey}
            focusPoint={focusPoint}
          />
        </section>

        <section className="space-y-4">
          <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-border dark:bg-card">
            <div className="mb-3 flex items-center justify-between gap-3">
              <h2 className="text-base font-bold text-slate-950 dark:text-foreground">Geofence details</h2>
              <Switch checked={Boolean(selectedBranch?.geofence_enabled)} onCheckedChange={(checked) => updateSettings({ geofence_enabled: checked })} />
            </div>
            <div className="grid gap-3">
              <Label className="text-xs font-semibold text-slate-700 dark:text-muted-foreground">
                Name
                <Input className="mt-1 h-9 rounded-md border-slate-200 text-xs shadow-sm dark:border-border" value={form.name} onChange={(e) => setForm((s) => ({ ...s, name: e.target.value }))} />
              </Label>

              <div className="grid grid-cols-[1fr_95px] gap-3">
                <Label className="text-xs font-semibold text-slate-700 dark:text-muted-foreground">
                  Type
                  <SelectBox className="mt-1" value={form.type} disabled={!shapeEditable} onChange={(e) => setForm((s) => ({ ...s, type: e.target.value }))}>
                    <option value="circle">Circle radius</option>
                    <option value="polygon">Polygon</option>
                  </SelectBox>
                </Label>
                <Label className="text-xs font-semibold text-slate-700 dark:text-muted-foreground">
                  Priority
                  <Input className="mt-1 h-9 rounded-md border-slate-200 text-xs shadow-sm dark:border-border" type="number" min="1" value={form.priority} onChange={(e) => setForm((s) => ({ ...s, priority: e.target.value }))} />
                </Label>
              </div>

              {form.type === 'circle' ? (
                <>
                  <div className="grid grid-cols-2 gap-3">
                    <Label className="text-xs font-semibold text-slate-700 dark:text-muted-foreground">
                      Latitude
                      <Input className="mt-1 h-9 rounded-md border-slate-200 text-xs shadow-sm dark:border-border" type="number" value={form.center_lat} disabled={!shapeEditable} onChange={(e) => setForm((s) => ({ ...s, center_lat: e.target.value }))} />
                    </Label>
                    <Label className="text-xs font-semibold text-slate-700 dark:text-muted-foreground">
                      Longitude
                      <Input className="mt-1 h-9 rounded-md border-slate-200 text-xs shadow-sm dark:border-border" type="number" value={form.center_lng} disabled={!shapeEditable} onChange={(e) => setForm((s) => ({ ...s, center_lng: e.target.value }))} />
                    </Label>
                  </div>
                  <Label className="text-xs font-semibold text-slate-700 dark:text-muted-foreground">
                    Radius: <span className="font-bold text-slate-950 dark:text-foreground">{form.radius_meters}m</span>
                    <input
                      className="mt-2 w-full accent-[#f04414]"
                      type="range"
                      min="5"
                      max="1000"
                      step="5"
                      value={form.radius_meters}
                      disabled={!shapeEditable}
                      onChange={(e) => setForm((s) => ({ ...s, radius_meters: e.target.value }))}
                    />
                  </Label>
                </>
              ) : (
                <div className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600 dark:border-border dark:bg-muted/30 dark:text-muted-foreground">
                  <div className="flex items-center justify-between gap-2">
                    <span>{polygonPoints(form.polygon_geojson).filter((_, index, arr) => index !== arr.length - 1).length} polygon points</span>
                    <Button type="button" variant="outline" size="sm" className="h-8 gap-2 rounded-md" disabled={!shapeEditable} onClick={() => setForm((s) => ({ ...s, polygon_geojson: null }))}>
                      <Trash2 className="size-3.5" />
                      Clear
                    </Button>
                  </div>
                </div>
              )}

              <div className="grid grid-cols-[1fr_95px] gap-3">
                <Label className="text-xs font-semibold text-slate-700 dark:text-muted-foreground">
                  Accuracy threshold
                  <Input className="mt-1 h-9 rounded-md border-slate-200 text-xs shadow-sm dark:border-border" type="number" value={form.accuracy_threshold_meters} onChange={(e) => setForm((s) => ({ ...s, accuracy_threshold_meters: e.target.value }))} />
                </Label>
                <div className="pt-5">
                  <div className="flex h-9 items-center justify-between gap-2 rounded-md px-1 text-xs font-semibold text-slate-700 dark:text-muted-foreground">
                    <span>Active</span>
                    <Switch checked={normalizeBoolean(form.is_active)} onCheckedChange={(checked) => setForm((s) => ({ ...s, is_active: checked }))} />
                  </div>
                </div>
              </div>

              <Label className="text-xs font-semibold text-slate-700 dark:text-muted-foreground">
                Geofence enforcement
                <SelectBox className="mt-1" value={form.enforcement_mode || 'enforce'} onChange={(e) => setForm((s) => ({ ...s, enforcement_mode: e.target.value }))}>
                  <option value="enforce">Use for validation</option>
                  <option value="warn_only">Warn only</option>
                  <option value="disabled">Disabled geofence</option>
                </SelectBox>
              </Label>

              <Label className="text-xs font-semibold text-slate-700 dark:text-muted-foreground">
                Notes
                <Textarea
                  className="mt-1 min-h-20 rounded-md border-slate-200 text-xs shadow-sm placeholder:text-slate-500 dark:border-border"
                  placeholder="Enter notes (optional)"
                  value={form.notes}
                  onChange={(e) => setForm((s) => ({ ...s, notes: e.target.value }))}
                />
              </Label>

              <div className="grid gap-2">
                <Button className="h-10 gap-2 rounded-md bg-[#f04414] text-sm font-semibold text-white shadow-none hover:bg-[#e33a12]" onClick={() => saveGeofence()} disabled={saving || !selectedBranchId}>
                  <Save className="size-4" />
                  {normalizeBoolean(form.is_active) ? 'Save geofence' : 'Save draft'}
                </Button>
                <Button variant="outline" className="h-9 gap-2 rounded-md border-slate-200 text-xs shadow-sm" onClick={() => saveGeofence({ addAnother: true })} disabled={saving || !selectedBranchId || normalizeBoolean(form.is_active)}>
                  <Plus className="size-4" />
                  Save draft & add another
                </Button>
                {form.id ? (
                  <Button variant="outline" className="h-9 gap-2 rounded-md border-slate-200 text-xs shadow-sm" onClick={toggleCurrentGeofence}>
                    <Power className="size-4" />
                    {normalizeBoolean(form.is_active) ? 'Disable current geofence' : 'Enable current geofence'}
                  </Button>
                ) : null}
              </div>
            </div>
          </div>

        </section>
      </div>

      <section className="grid gap-4 xl:grid-cols-3">
        <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-border dark:bg-card">
            <div className="flex items-center justify-between gap-3">
              <div>
                <h2 className="text-base font-bold text-slate-950 dark:text-foreground">Branch geofences</h2>
                <p className="text-xs text-slate-500 dark:text-muted-foreground">Select a saved geofence or add a separate draft.</p>
              </div>
              <Badge variant="secondary" className="rounded-md bg-slate-100 text-slate-700 hover:bg-slate-100 dark:bg-muted dark:text-muted-foreground">
                {geofences.length}
              </Badge>
            </div>
            <div className="mt-3 grid gap-2">
              {geofences.length === 0 ? (
                <div className="rounded-md border border-dashed border-slate-200 p-3 text-xs text-slate-500 dark:border-border dark:text-muted-foreground">
                  No saved geofences yet. Use Add geofence to create a draft.
                </div>
              ) : geofences.map((geofence) => {
                const selected = String(form.id || '') === String(geofence.id)
                return (
                  <div
                    key={geofence.id}
                    className={cn(
                      'grid grid-cols-[1fr_auto] items-center gap-3 rounded-md border border-slate-200 px-3 py-2 text-left text-xs transition hover:bg-orange-50 dark:border-border dark:hover:bg-orange-500/10',
                      selected && 'border-[#f04414] bg-orange-50 text-[#f04414] dark:border-orange-500 dark:bg-orange-500/10 dark:text-orange-300',
                    )}
                  >
                    <button type="button" className="min-w-0 text-left" onClick={() => selectGeofence(geofence)}>
                      <span className="block truncate font-bold">{geofence.name || 'Geofence'}</span>
                      <span className="block text-[11px] text-slate-500 dark:text-muted-foreground">
                        {geofence.type === 'circle' ? `Circle · ${Number(geofence.radius_meters || 0)}m` : `Polygon · ${polygonPoints(geofence.polygon_geojson).filter((_, index, arr) => index !== arr.length - 1).length} points`}
                      </span>
                    </button>
                    <div className="flex items-center gap-2">
                      <Badge variant={normalizeBoolean(geofence.is_active) ? 'default' : 'secondary'} className={cn('h-6 rounded-md', normalizeBoolean(geofence.is_active) ? 'bg-emerald-600' : 'bg-slate-200 text-slate-700 hover:bg-slate-200')}>
                        {normalizeBoolean(geofence.is_active) ? 'Active' : 'Draft'}
                      </Badge>
                      <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        className="h-7 rounded-md px-2 text-[11px]"
                        onClick={() => toggleGeofenceActive(geofence)}
                      >
                        {normalizeBoolean(geofence.is_active) ? 'Disable' : 'Enable'}
                      </Button>
                    </div>
                  </div>
                )
              })}
            </div>
          </div>

          <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-border dark:bg-card">
            <div className="flex items-center justify-between gap-3">
              <h2 className="text-base font-bold text-slate-950 dark:text-foreground">Assigned employees</h2>
              <Badge variant="secondary" className="rounded-md bg-slate-100 text-slate-700 hover:bg-slate-100 dark:bg-muted dark:text-muted-foreground">
                {Number(selectedBranch?.employee_count || branchEmployees.length || 0)}
              </Badge>
            </div>
            <div className="mt-3 max-h-64 overflow-auto">
              {branchEmployees.length === 0 ? (
                <div className="flex min-h-24 flex-col items-center justify-center rounded-md border border-dashed border-slate-200 text-center dark:border-border">
                  <Users className="size-7 text-slate-400" />
                  <p className="mt-2 text-xs font-semibold text-slate-600 dark:text-muted-foreground">No active employees assigned</p>
                </div>
              ) : (
                <div className="divide-y divide-slate-100 dark:divide-border">
                  {branchEmployees.map((employee) => (
                    <div key={employee.id} className="grid grid-cols-[1fr_auto] gap-3 py-2.5 text-xs">
                      <div className="min-w-0">
                        <div className="truncate font-bold text-slate-950 dark:text-foreground">{employee.name}</div>
                        <div className="mt-0.5 truncate text-slate-500 dark:text-muted-foreground">
                          {employee.employee_number || 'No number'} - {employee.department || 'No department'}
                        </div>
                        <div className="mt-0.5 truncate text-slate-500 dark:text-muted-foreground">
                          {employee.section_unit || employee.division || 'No section/unit'} - {employee.assignment_type || 'assignment'}
                        </div>
                      </div>
                      <Badge variant={employee.active ? 'default' : 'secondary'} className={cn('h-6 rounded-md', employee.active ? 'bg-emerald-600' : 'bg-slate-200 text-slate-700 hover:bg-slate-200')}>
                        {employee.active ? 'Active' : 'Inactive'}
                      </Badge>
                    </div>
                  ))}
                </div>
              )}
            </div>
          </div>

        <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-border dark:bg-card">
          <h2 className="text-base font-bold text-slate-950 dark:text-foreground">Branch settings</h2>
          <div className="mt-3 grid gap-3">
            <div className="grid grid-cols-[116px_1fr] items-center gap-3">
              <span className="text-xs font-semibold leading-tight text-slate-700 dark:text-muted-foreground">Enforcement</span>
              <SelectBox value={selectedBranch?.geofence_enforcement_mode || 'enforce'} onChange={(e) => updateSettings({ geofence_enforcement_mode: e.target.value })}>
                <option value="enforce">Enforce</option>
                <option value="warn_only">Warn only</option>
                <option value="disabled">Disabled</option>
              </SelectBox>
            </div>
            <div className="grid grid-cols-[116px_1fr] items-center gap-3">
              <span className="text-xs font-semibold leading-tight text-slate-700 dark:text-muted-foreground">No active geofence</span>
              <SelectBox value={selectedBranch?.geofence_no_active_policy || 'block'} onChange={(e) => updateSettings({ geofence_no_active_policy: e.target.value })}>
                <option value="block">Block attendance</option>
                <option value="allow">Allow attendance</option>
              </SelectBox>
            </div>
            <div className="grid grid-cols-[116px_1fr] items-center gap-3">
              <span className="text-xs font-semibold text-slate-700 dark:text-muted-foreground">Desktop accuracy mode</span>
              <SelectBox value={selectedBranch?.geofence_accuracy_policy || 'balanced'} onChange={(e) => updateSettings({ geofence_accuracy_policy: e.target.value })}>
                <option value="strict">Strict</option>
                <option value="balanced">Balanced</option>
                <option value="lenient">Lenient</option>
              </SelectBox>
            </div>
            <div className="grid grid-cols-[116px_1fr] items-center gap-3">
              <span className="text-xs font-semibold leading-tight text-slate-700 dark:text-muted-foreground">Poor GPS accuracy</span>
              <SelectBox value={selectedBranch?.geofence_poor_accuracy_action || 'block'} onChange={(e) => updateSettings({ geofence_poor_accuracy_action: e.target.value })}>
                <option value="block">Block</option>
                <option value="warn">Warn</option>
              </SelectBox>
            </div>
            <div className="flex items-center justify-between gap-3">
              <span className="text-xs font-semibold text-slate-700 dark:text-muted-foreground">Allow other company branches</span>
              <Switch checked={Boolean(selectedBranch?.geofence_allow_cross_branch)} onCheckedChange={(checked) => updateSettings({ geofence_allow_cross_branch: checked })} />
            </div>
          </div>
        </div>
      </section>

      <section className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-border dark:bg-card">
        <div className="flex flex-col gap-3 border-b border-slate-200 p-4 dark:border-border lg:flex-row lg:items-center lg:justify-between">
          <div>
            <h2 className="text-base font-bold text-slate-950 dark:text-foreground">Branch geofence directory</h2>
            <p className="text-xs text-slate-500 dark:text-muted-foreground">Select a branch below to load its map boundary and settings.</p>
          </div>
          <div className="relative w-full lg:w-[360px]">
            <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
            <Input
              value={branchSearch}
              onChange={(e) => setBranchSearch(e.target.value)}
              placeholder="Search address, branch, company..."
              className="h-10 rounded-md border-slate-200 pl-9 text-xs shadow-sm placeholder:text-slate-500 focus-visible:ring-orange-100 dark:border-border"
            />
          </div>
        </div>

        <div className="overflow-x-auto">
          <div className="min-w-[980px]">
            <div className="grid grid-cols-[minmax(360px,1fr)_150px_120px_150px_120px] bg-slate-50 px-5 py-3 text-[11px] font-bold uppercase text-slate-700 dark:bg-muted/40 dark:text-muted-foreground">
              <span>Branch</span>
              <span>Employees</span>
              <span className="text-center">Geofences</span>
              <span>Status</span>
              <span className="text-center">Actions</span>
            </div>

            <div className="min-h-[310px]">
              {loading ? (
                <div className="px-5 py-12 text-center text-sm text-slate-500">Loading branches...</div>
              ) : pagedBranches.length === 0 ? (
                <div className="px-5 py-12 text-center text-sm text-slate-500">No branches found.</div>
              ) : (
                pagedBranches.map((branch) => {
                  const status = branchStatus(branch)
                  const selected = String(selectedBranchId) === String(branch.id)
                  return (
                    <button
                      key={branch.id}
                      type="button"
                      className={cn(
                        'grid w-full grid-cols-[minmax(360px,1fr)_150px_120px_150px_120px] items-center border-t border-slate-200 px-5 py-3 text-left transition hover:bg-orange-50/45 dark:border-border dark:hover:bg-orange-500/5',
                        selected && 'border-l-4 border-l-[#f04414] bg-orange-50/70 dark:bg-orange-500/10',
                      )}
                      onClick={() => setSelectedBranchId(branch.id)}
                    >
                      <span className="flex min-w-0 items-center gap-3">
                        <CompanyLogo branch={branch} />
                        <span className="min-w-0">
                          <span className="block text-sm font-bold uppercase leading-5 text-slate-950 dark:text-foreground">{branch.branch_name}</span>
                          <span className="block truncate text-[11px] font-medium text-slate-600 dark:text-muted-foreground">
                            {branch.company_name || 'Company'} - {branchCode(branch)}
                          </span>
                          <span className="block truncate text-[11px] text-slate-500">{branch.address || 'No address'}</span>
                          <span className="block text-[11px] text-slate-500">Updated {formatDate(branch.last_updated)}</span>
                        </span>
                      </span>
                      <span className="min-w-0 pr-3">
                        <span className="block text-sm font-bold text-slate-950 dark:text-foreground">{Number(branch.employee_count || 0)}</span>
                        <span className="block truncate text-[11px] text-slate-500 dark:text-muted-foreground">
                          {(branch.assigned_employees_preview || []).map((employee) => employee.name).join(', ') || 'No active employees'}
                        </span>
                      </span>
                      <span className="text-center text-sm font-bold">{Number(branch.active_geofences_count || 0)}</span>
                      <span className={cn('flex items-center gap-2 text-xs', status.text)}>
                        <span className={cn('size-2 rounded-full', status.dot)} />
                        {status.label}
                      </span>
                      <span className="flex items-center justify-center gap-1.5">
                        <Button size="icon" variant="ghost" className="size-8 rounded-md text-slate-900 hover:bg-white dark:text-foreground dark:hover:bg-background" title="Edit geofence" onClick={(event) => { event.stopPropagation(); setSelectedBranchId(branch.id) }}>
                          <Edit3 className="size-4" />
                        </Button>
                        <Button size="icon" variant="ghost" className="size-8 rounded-md text-slate-900 hover:bg-white dark:text-foreground dark:hover:bg-background" title="Enable or disable" onClick={(event) => { event.stopPropagation(); updateBranchSettings(branch.id, { geofence_enabled: !branch.geofence_enabled }) }}>
                          <Power className="size-4" />
                        </Button>
                      </span>
                    </button>
                  )
                })
              )}
            </div>
          </div>
        </div>

        <div className="flex flex-col gap-3 border-t border-slate-200 px-4 py-3 text-xs text-slate-600 dark:border-border dark:text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
          <span>Showing {rangeStart} to {rangeEnd} of {filteredBranches.length} branches</span>
          <div className="flex items-center gap-1">
            <Button size="icon" variant="outline" className="size-8 rounded-md" disabled={page <= 1} onClick={() => setPage((current) => Math.max(1, current - 1))}>
              <ChevronLeft className="size-4" />
            </Button>
            {Array.from({ length: Math.min(totalPages, 4) }, (_, index) => index + 1).map((item) => (
              <Button
                key={item}
                size="icon"
                variant={page === item ? 'default' : 'outline'}
                className={cn('size-8 rounded-md text-xs', page === item && 'bg-[#f04414] text-white hover:bg-[#e33a12]')}
                onClick={() => setPage(item)}
              >
                {item}
              </Button>
            ))}
            {totalPages > 4 ? <span className="px-1">...</span> : null}
            {totalPages > 4 ? (
              <Button size="icon" variant="outline" className="size-8 rounded-md text-xs" onClick={() => setPage(totalPages)}>
                {totalPages}
              </Button>
            ) : null}
            <Button size="icon" variant="outline" className="size-8 rounded-md" disabled={page >= totalPages} onClick={() => setPage((current) => Math.min(totalPages, current + 1))}>
              <ChevronRight className="size-4" />
            </Button>
          </div>
        </div>
      </section>

    </div>
  )
}
