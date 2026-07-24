import { createElement, useCallback, useEffect, useMemo, useRef, useState } from 'react'
import maplibregl from 'maplibre-gl'
import 'maplibre-gl/dist/maplibre-gl.css'
import { Viewer as MapillaryViewer } from 'mapillary-js'
import 'mapillary-js/dist/mapillary.css'
import * as turf from '@turf/turf'
import {
  Camera,
  ChevronLeft,
  ChevronRight,
  Circle,
  Edit3,
  ImageOff,
  Pentagon,
  Plus,
  Power,
  RefreshCw,
  Save,
  Search,
  Trash2,
  Users,
} from 'lucide-react'
import AdminGeofenceLiveMonitor from '@/pages/AdminGeofenceLiveMonitor'
import {
  captureAttendanceLocation,
  companyLogoUrl,
  createBranchGeofence,
  deleteBranchGeofence,
  getAdminGeofencing,
  getBranchGeofences,
  searchGeofenceLocation,
  testAttendanceGeofence,
  updateAttendanceWithoutGeofenceSettings,
  updateBranchGeofence,
  updateBranchGeofenceSettings,
  updateGeofenceEmployeeExemptions,
  updateGeofenceModuleSettings,
} from '@/api'
import { useAuth } from '@/contexts/AuthContext'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Textarea } from '@/components/ui/textarea'
import { useToast } from '@/components/ui/use-toast'
import { cn } from '@/lib/utils'

const DEFAULT_CENTER = [14.5995, 120.9842]
const PAGE_SIZE = 5
const ORANGE = '#f04414'
const DEVICE_SCOPE_OPTIONS = [
  { value: 'all_devices', label: 'All Devices', mapLabel: 'All Devices', color: '#f97316' },
  { value: 'desktop_laptop', label: 'Desktop / Laptop only', mapLabel: 'Desktop/Laptop', color: '#2563eb' },
  { value: 'mobile_tablet', label: 'Mobile / Tablet only', mapLabel: 'Mobile/Tablet', color: '#16a34a' },
  { value: 'desktop', label: 'Desktop only', mapLabel: 'Desktop', color: '#2563eb' },
  { value: 'laptop', label: 'Laptop only', mapLabel: 'Laptop', color: '#3b82f6' },
  { value: 'mobile', label: 'Mobile only', mapLabel: 'Mobile', color: '#16a34a' },
  { value: 'tablet', label: 'Tablet only', mapLabel: 'Tablet', color: '#22c55e' },
  { value: 'kiosk', label: 'Kiosk only', mapLabel: 'Kiosk', color: '#9333ea' },
]
const EMPTY_FEATURE_COLLECTION = { type: 'FeatureCollection', features: [] }
const MAPILLARY_ACCESS_TOKEN = import.meta.env.VITE_MAPILLARY_ACCESS_TOKEN || ''
const MAPILLARY_TILE_URL = MAPILLARY_ACCESS_TOKEN
  ? `https://tiles.mapillary.com/maps/vtp/mly1_public/2/{z}/{x}/{y}?access_token=${encodeURIComponent(MAPILLARY_ACCESS_TOKEN)}`
  : null
const OSM_RASTER_STYLE = {
  version: 8,
  glyphs: 'https://demotiles.maplibre.org/font/{fontstack}/{range}.pbf',
  sources: {
    osm: {
      type: 'raster',
      tiles: ['https://tile.openstreetmap.org/{z}/{x}/{y}.png'],
      tileSize: 256,
      attribution: '&copy; OpenStreetMap contributors',
    },
    esriWorldImagery: {
      type: 'raster',
      tiles: ['https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}'],
      tileSize: 256,
      attribution: 'Tiles &copy; Esri &mdash; Source: Esri, Maxar, Earthstar Geographics, and the GIS User Community',
    },
    esriWorldTransportation: {
      type: 'raster',
      tiles: ['https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Transportation/MapServer/tile/{z}/{y}/{x}'],
      tileSize: 256,
      attribution: 'Transportation &copy; Esri',
    },
    esriWorldBoundariesAndPlaces: {
      type: 'raster',
      tiles: ['https://server.arcgisonline.com/ArcGIS/rest/services/Reference/World_Boundaries_and_Places/MapServer/tile/{z}/{y}/{x}'],
      tileSize: 256,
      attribution: 'Labels &copy; Esri',
    },
  },
  layers: [
    {
      id: 'osm',
      type: 'raster',
      source: 'osm',
    },
    {
      id: 'esri-world-imagery',
      type: 'raster',
      source: 'esriWorldImagery',
      layout: {
        visibility: 'none',
      },
    },
    {
      id: 'esri-world-transportation',
      type: 'raster',
      source: 'esriWorldTransportation',
      layout: {
        visibility: 'none',
      },
    },
    {
      id: 'esri-world-boundaries-places',
      type: 'raster',
      source: 'esriWorldBoundariesAndPlaces',
      layout: {
        visibility: 'none',
      },
    },
  ],
}
function employeeInitials(name) {
  return String(name || 'Employee')
    .split(/\s+/)
    .filter(Boolean)
    .slice(0, 2)
    .map((part) => part[0]?.toUpperCase())
    .join('') || 'E'
}

function blankForm(branchId = null, branchName = '') {
  return {
    id: null,
    branch_id: branchId,
    name: branchName || '',
    type: 'circle',
    device_scope: '',
    center_lat: DEFAULT_CENTER[0],
    center_lng: DEFAULT_CENTER[1],
    radius_meters: 100,
    polygon_geojson: null,
    polygon_draft_points: [],
    is_active: false,
    status: 'draft',
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

function formatMapillaryCaptureDate(value) {
  const timestamp = Number(value)
  if (!Number.isFinite(timestamp)) return null
  const date = new Date(timestamp)
  if (Number.isNaN(date.getTime())) return null
  return date.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' })
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
  if (geofence?.center_lat != null && geofence.center_lng != null) {
    return [Number(geofence.center_lat), Number(geofence.center_lng)]
  }
  if (geofence?.type === 'polygon') return polygonFeatureCenter(geofence.polygon_geojson)
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

function coordinateTextToLatLng(value) {
  const match = String(value || '').match(/^\s*(-?\d+(?:\.\d+)?)\s*[, ]\s*(-?\d+(?:\.\d+)?)\s*$/)
  if (!match) return null

  const first = Number(match[1])
  const second = Number(match[2])
  return validLatLngPair([first, second]) || validLatLngPair([second, first])
}

function mapSearchDisplayText(result, fallback = '') {
  const text = String(result?.address || result?.label || fallback || '').trim()
  return text
    .replace(/\s*,?\s*Philippines\s*$/i, '')
    .replace(/\s*,\s*$/, '')
    .trim() || fallback
}

function mapillaryGraphUrl(point, radiusMeters = 50) {
  const latLng = validLatLngPair(point)
  if (!latLng || !MAPILLARY_ACCESS_TOKEN) return null
  const radius = Math.max(1, Math.min(50, Number(radiusMeters) || 50))
  const params = new URLSearchParams({
    access_token: MAPILLARY_ACCESS_TOKEN,
    fields: 'id,geometry,computed_compass_angle,captured_at,is_pano,thumb_256_url',
    lat: String(latLng[0]),
    lng: String(latLng[1]),
    radius: String(radius),
    limit: '1',
  })
  return `https://graph.mapillary.com/images?${params.toString()}`
}

function mapillaryFeatureImageId(feature) {
  const properties = feature?.properties || {}
  return properties.id || properties.image_id || properties.key || null
}

function mapillaryFeaturePayload(feature) {
  const coordinates = feature?.geometry?.coordinates
  const lng = Array.isArray(coordinates) ? Number(coordinates[0]) : null
  const lat = Array.isArray(coordinates) ? Number(coordinates[1]) : null
  return {
    imageId: mapillaryFeatureImageId(feature),
    latitude: Number.isFinite(lat) ? lat : null,
    longitude: Number.isFinite(lng) ? lng : null,
    capturedAt: feature?.properties?.captured_at ?? null,
    source: 'Mapillary coverage',
  }
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

function polygonFeatureCenter(geojson) {
  const points = editablePolygonPoints(geojson)
  if (!points.length) return null

  try {
    if (geojson?.geometry?.type === 'Polygon') {
      const [lng, lat] = turf.centroid(geojson).geometry.coordinates
      const center = validLatLngPair([lat, lng])
      if (center) return [Number(center[0].toFixed(7)), Number(center[1].toFixed(7))]
    }
  } catch {
    // Fall through to an average of editable points for draft polygons.
  }

  const totals = points.reduce((acc, point) => [acc[0] + Number(point[0]), acc[1] + Number(point[1])], [0, 0])
  return [Number((totals[0] / points.length).toFixed(7)), Number((totals[1] / points.length).toFixed(7))]
}

function polygonFeatureAroundCenter(center, radiusMeters = 80) {
  const latLng = validLatLngPair(center)
  const lngLat = toLngLat(latLng)
  if (!lngLat) return null

  const points = [45, 135, 225, 315].map((bearing) => {
    const [lng, lat] = turf.destination(lngLat, Math.max(10, Number(radiusMeters) || 80), bearing, { units: 'meters' }).geometry.coordinates
    return [Number(lat.toFixed(7)), Number(lng.toFixed(7))]
  })

  return polygonFeatureFromPoints(points)
}

function polygonOpenPointCount(geojson) {
  return editablePolygonPoints(geojson).length
}

function polygonEditPoints(form) {
  const savedPoints = editablePolygonPoints(form?.polygon_geojson)
  if (savedPoints.length) return savedPoints
  return Array.isArray(form?.polygon_draft_points)
    ? form.polygon_draft_points.map(validLatLngPair).filter(Boolean)
    : []
}

function polygonFormCenter(form) {
  return polygonFeatureCenter(form?.polygon_geojson)
    || polygonFeatureCenter(polygonFeatureFromPoints(polygonEditPoints(form)))
    || validLatLngPair([form?.center_lat, form?.center_lng])
}

function polygonFormPointCount(form) {
  return polygonEditPoints(form).length
}

function formFromGeofence(branchId, branchName, geofence) {
  if (!geofence) return blankForm(branchId, branchName)
  return {
    ...blankForm(branchId, branchName),
    ...geofence,
    center_lat: geofence.center_lat ?? DEFAULT_CENTER[0],
    center_lng: geofence.center_lng ?? DEFAULT_CENTER[1],
    radius_meters: geofence.radius_meters ?? 100,
    polygon_draft_points: [],
    status: geofence.status || (normalizeBoolean(geofence.is_active) ? 'active' : 'inactive'),
    is_active: (geofence.status || (normalizeBoolean(geofence.is_active) ? 'active' : 'inactive')) === 'active',
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
  return form?.status !== 'active'
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
  if (geofence.status === 'draft') return 'draft'
  if (geofence.status === 'active' || normalizeBoolean(geofence.is_active)) return 'active'
  return selected && geofence.status !== 'inactive' ? 'draft' : 'inactive'
}

function deviceScopeMeta(scope) {
  return DEVICE_SCOPE_OPTIONS.find((option) => option.value === scope) || DEVICE_SCOPE_OPTIONS[0]
}

function poiMatchesCategory(poi, category) {
  if (category === 'all') return true
  if (category === 'building') return ['building', 'mall', 'office'].includes(poi?.category)
  return poi?.category === category
}

function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;')
}

function geofenceMapText(status, name, deviceScope) {
  const scope = deviceScopeMeta(deviceScope).mapLabel
  return `${name || 'Geofence'} - ${scope} - ${status.toUpperCase()}`
}

function toLngLat(point) {
  const latLng = validLatLngPair(point)
  return latLng ? [latLng[1], latLng[0]] : null
}

function circleFeature(center, radiusMeters, properties = {}) {
  const lngLat = toLngLat(center)
  const radius = Math.max(5, Number(radiusMeters) || 100)
  if (!lngLat) return null
  return turf.circle(lngLat, radius, { steps: 96, units: 'meters', properties })
}

function geofenceFeature(geofence, selected = false) {
  const status = geofenceMapStatus(geofence, selected)
  const properties = {
    geofenceId: geofence?.id ?? geofence?.draft_key ?? 'selected',
    status,
    scopeColor: deviceScopeMeta(geofence?.device_scope).color,
    name: geofence?.name || 'Geofence',
    label: geofenceMapText(status, geofence?.name || 'Geofence', geofence?.device_scope),
  }

  if (geofence?.type === 'circle' && geofence.center_lat != null && geofence.center_lng != null) {
    return circleFeature([Number(geofence.center_lat), Number(geofence.center_lng)], geofence.radius_meters, properties)
  }

  if (geofence?.type === 'polygon') {
    const feature = geofence.polygon_geojson
    const points = polygonPoints(feature)
    if (feature?.geometry?.type === 'Polygon' && points.length >= 3) {
      return {
        ...feature,
        properties: {
          ...(feature.properties || {}),
          ...properties,
        },
      }
    }
  }

  return null
}

function geofenceLabelFeature(geofence, selected = false) {
  const center = geofenceCenter(geofence)
  const lngLat = toLngLat(center)
  if (!lngLat) return null
  const status = geofenceMapStatus(geofence, selected)
  return {
    type: 'Feature',
    properties: {
      geofenceId: geofence?.id ?? geofence?.draft_key ?? 'selected',
      status,
      label: geofenceMapText(status, geofence?.name || 'Geofence', geofence?.device_scope),
    },
    geometry: { type: 'Point', coordinates: lngLat },
  }
}

function geofenceFeatureCollection(items, selected = false) {
  return {
    type: 'FeatureCollection',
    features: items.map((item) => geofenceFeature(item, selected)).filter(Boolean),
  }
}

function geofenceLabelCollection(items, selected = false) {
  return {
    type: 'FeatureCollection',
    features: items.map((item) => geofenceLabelFeature(item, selected)).filter(Boolean),
  }
}

function poiFeatureCollection(pois) {
  return {
    type: 'FeatureCollection',
    features: pois.map((poi) => {
      const lat = Number(poi.latitude)
      const lng = Number(poi.longitude)
      if (!Number.isFinite(lat) || !Number.isFinite(lng)) return null
      return {
        type: 'Feature',
        properties: {
          id: String(poi.id || ''),
          name: poi.name || poi.label || 'OSM place',
          category: poi.category_label || poi.category || 'Place',
        },
        geometry: { type: 'Point', coordinates: [lng, lat] },
      }
    }).filter(Boolean),
  }
}

function markerElement(kind = 'handle') {
  const element = document.createElement('span')
  if (kind === 'center') {
    element.className = 'block size-8 rounded-full bg-[#f04414] p-1 shadow-lg shadow-orange-900/25 ring-4 ring-[#f04414]/20'
    element.innerHTML = '<span class="block size-full rounded-full border-2 border-white bg-[#f04414]"></span>'
    return element
  }
  element.className = kind === 'poi'
    ? 'block size-6 rounded-full border-2 border-white bg-blue-600 shadow ring-4 ring-blue-500/20'
    : 'block size-4 rounded-full border-2 border-white bg-[#f04414] shadow ring-2 ring-[#f04414]/30'
  return element
}

function radiusHandleLngLat(center, radiusMeters) {
  const lngLat = toLngLat(center)
  if (!lngLat) return null
  return turf.destination(lngLat, Math.max(5, Number(radiusMeters) || 100), 90, { units: 'meters' }).geometry.coordinates
}

function distanceMeters(a, b) {
  const from = toLngLat(a)
  const to = toLngLat(b)
  if (!from || !to) return 0
  return turf.distance(from, to, { units: 'meters' })
}

function geojsonBounds(featureCollection) {
  if (!featureCollection?.features?.length) return null
  const [west, south, east, north] = turf.bbox(featureCollection)
  if (![west, south, east, north].every(Number.isFinite)) return null
  return [[west, south], [east, north]]
}

function pointBounds(points) {
  const validPoints = points.map(toLngLat).filter(Boolean)
  if (!validPoints.length) return null
  const west = Math.min(...validPoints.map(([lng]) => lng))
  const east = Math.max(...validPoints.map(([lng]) => lng))
  const south = Math.min(...validPoints.map(([, lat]) => lat))
  const north = Math.max(...validPoints.map(([, lat]) => lat))
  return [[west, south], [east, north]]
}

function createPoiPopupContent(poi, onUsePoi) {
  const lat = Number(poi.latitude)
  const lng = Number(poi.longitude)
  const popup = document.createElement('div')
  popup.className = 'space-y-1 text-xs'
  popup.innerHTML = `
    <div class="font-bold">${escapeHtml(poi.name || poi.label || 'OSM place')}</div>
    <div>Category: ${escapeHtml(poi.category_label || poi.category || 'Place')}</div>
    ${poi.address ? `<div>${escapeHtml(poi.address)}</div>` : ''}
    <div>Coordinates: ${lat.toFixed(6)}, ${lng.toFixed(6)}</div>
    <div>OSM: ${escapeHtml(poi.osm_type || poi.source || 'osm')}${poi.osm_id ? `/${escapeHtml(poi.osm_id)}` : ''}</div>
  `
  const button = document.createElement('button')
  button.type = 'button'
  button.className = 'mt-2 rounded bg-[#f04414] px-2 py-1 text-[11px] font-semibold text-white'
  button.textContent = 'Use as Geofence Center'
  button.addEventListener('click', (event) => {
    event.stopPropagation()
    onUsePoi?.(poi)
  })
  popup.appendChild(button)
  return popup
}

function createMapillaryPopupContent(image, onViewImage) {
  const lat = Number(image.latitude)
  const lng = Number(image.longitude)
  const popup = document.createElement('div')
  popup.className = 'space-y-1 text-xs'
  const captured = formatMapillaryCaptureDate(image.capturedAt)
  popup.innerHTML = `
    <div class="font-bold">Mapillary streetview</div>
    ${captured ? `<div>Captured: ${escapeHtml(captured)}</div>` : ''}
    ${Number.isFinite(lat) && Number.isFinite(lng) ? `<div>Coordinates: ${lat.toFixed(6)}, ${lng.toFixed(6)}</div>` : ''}
  `
  const button = document.createElement('button')
  button.type = 'button'
  button.className = 'mt-2 rounded bg-[#05cb63] px-2 py-1 text-[11px] font-semibold text-slate-950'
  button.textContent = 'Open Streetview'
  button.addEventListener('click', (event) => {
    event.stopPropagation()
    onViewImage?.(image)
  })
  popup.appendChild(button)
  return popup
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
  const logoUrl = branch?.company_logo_url || branch?.logo_url
    ? companyLogoUrl({ company_logo_url: branch.company_logo_url, logo_url: branch.logo_url })
    : undefined
  const [failedLogoUrl, setFailedLogoUrl] = useState(null)
  const logoFailed = Boolean(logoUrl && failedLogoUrl === logoUrl)
  const companyName = branch?.company_name || branch?.branch_name || ''
  const initials = companyName
    .split(/\s+/)
    .filter(Boolean)
    .map((part) => part[0])
    .join('')
    .slice(0, 3)
    .toUpperCase() || 'CO'

  return (
    <span className="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-border dark:bg-background">
      {logoUrl && !logoFailed ? (
        <img
          src={logoUrl}
          alt=""
          className="size-full object-contain p-1.5"
          onError={() => setFailedLogoUrl(logoUrl)}
        />
      ) : (
        <span className="flex size-full items-center justify-center bg-orange-50 px-1 text-[10px] font-black leading-none text-[#f04414] dark:bg-orange-500/10 dark:text-orange-300">
          {initials}
        </span>
      )}
    </span>
  )
}

function MapillaryStreetviewPanel({
  image,
  loading,
  error,
  target,
  onOpenTarget,
  onClose,
}) {
  const containerRef = useRef(null)
  const viewerRef = useRef(null)

  useEffect(() => {
    const container = containerRef.current
    if (!container || !MAPILLARY_ACCESS_TOKEN || !image?.imageId) return undefined

    viewerRef.current?.remove()
    viewerRef.current = new MapillaryViewer({
      accessToken: MAPILLARY_ACCESS_TOKEN,
      container,
      imageId: image.imageId,
    })

    return () => {
      viewerRef.current?.remove()
      viewerRef.current = null
    }
  }, [image?.imageId])

  return (
    <div className="border-t border-slate-200 bg-white p-3 dark:border-border dark:bg-card">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <div className="flex items-center gap-2 text-sm font-bold text-slate-950 dark:text-foreground">
            <Camera className="size-4 text-[#05cb63]" />
            Mapillary Streetview
          </div>
          <p className="text-xs text-slate-500 dark:text-muted-foreground">
            Review street-level imagery for the selected branch or geofence boundary.
          </p>
        </div>
        <div className="flex gap-2">
          <Button
            type="button"
            size="sm"
            variant="outline"
            className="h-8 rounded-md text-xs"
            disabled={loading || !target || !MAPILLARY_ACCESS_TOKEN}
            onClick={onOpenTarget}
          >
            {loading ? 'Finding imagery...' : 'View selected location'}
          </Button>
          {image ? (
            <Button type="button" size="sm" variant="ghost" className="h-8 rounded-md text-xs" onClick={onClose}>
              Close
            </Button>
          ) : null}
        </div>
      </div>

      {!MAPILLARY_ACCESS_TOKEN ? (
        <div className="mt-3 rounded-md border border-dashed border-slate-200 p-4 text-xs text-slate-500 dark:border-border dark:text-muted-foreground">
          Add <code>VITE_MAPILLARY_ACCESS_TOKEN</code> in <code>frontend/.env</code> to enable Mapillary streetview.
        </div>
      ) : error ? (
        <div className="mt-3 flex items-start gap-2 rounded-md border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-200">
          <ImageOff className="mt-0.5 size-4 shrink-0" />
          <span>{error}</span>
        </div>
      ) : null}

      {image?.imageId ? (
        <div className="mt-3 overflow-hidden rounded-md border border-slate-200 bg-slate-950 dark:border-border">
          <div ref={containerRef} className="h-[340px] w-full" />
          <div className="flex flex-wrap items-center justify-between gap-2 border-t border-slate-800 bg-slate-950 px-3 py-2 text-[11px] text-slate-300">
            <span>Image {image.imageId}</span>
            <span>
              {[formatMapillaryCaptureDate(image.capturedAt), image.source].filter(Boolean).join(' - ') || 'Mapillary'}
            </span>
          </div>
        </div>
      ) : !error ? (
        <div className="mt-3 rounded-md border border-dashed border-slate-200 p-4 text-xs text-slate-500 dark:border-border dark:text-muted-foreground">
          Click a green Mapillary image dot on the map or use "View selected location" to find the nearest streetview image.
        </div>
      ) : null}
    </div>
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
  poiResults = [],
  poiCategory = 'all',
  selectedPoiId = null,
  onUsePoi,
  onSelectMapillaryImage,
}) {
  const mapEl = useRef(null)
  const mapRef = useRef(null)
  const editMarkersRef = useRef([])
  const poiPopupRef = useRef(null)
  const mapillaryPopupRef = useRef(null)
  const fittedBranchRef = useRef('')
  const focusKeyRef = useRef('')
  const [mapReady, setMapReady] = useState(false)
  const [mapillaryVisible, setMapillaryVisible] = useState(Boolean(MAPILLARY_TILE_URL))
  const [baseMap, setBaseMap] = useState('osm')

  const visiblePois = useMemo(
    () => poiResults.filter((poi) => poiMatchesCategory(poi, poiCategory)),
    [poiCategory, poiResults],
  )

  useEffect(() => {
    if (!mapEl.current || mapRef.current) return undefined

    const map = new maplibregl.Map({
      container: mapEl.current,
      style: OSM_RASTER_STYLE,
      center: [DEFAULT_CENTER[1], DEFAULT_CENTER[0]],
      zoom: 16,
    })
    map.addControl(new maplibregl.NavigationControl({ visualizePitch: false }), 'top-right')
    mapRef.current = map

    const mapStyles = {
      active: { line: ['get', 'scopeColor'], fill: ['get', 'scopeColor'], opacity: 0.12, dash: null },
      inactive: { line: '#94a3b8', fill: '#cbd5e1', opacity: 0.09, dash: [2, 2] },
      draft: { line: ['get', 'scopeColor'], fill: ['get', 'scopeColor'], opacity: 0.16, dash: [3, 2] },
    }
    const addSource = (id) => map.addSource(id, { type: 'geojson', data: EMPTY_FEATURE_COLLECTION })
    const addGeofenceLayers = (sourceId, prefix, selected = false) => {
      Object.entries(mapStyles).forEach(([status, style]) => {
        map.addLayer({
          id: `${prefix}-fill-${status}`,
          type: 'fill',
          source: sourceId,
          filter: ['==', ['get', 'status'], status],
          paint: {
            'fill-color': style.fill,
            'fill-opacity': style.opacity,
          },
        })
        map.addLayer({
          id: `${prefix}-line-${status}`,
          type: 'line',
          source: sourceId,
          filter: ['==', ['get', 'status'], status],
          paint: {
            'line-color': style.line,
            'line-width': selected ? 4 : 2,
            ...(style.dash ? { 'line-dasharray': style.dash } : {}),
          },
        })
      })
    }
    const addLabelLayer = (sourceId, id) => {
      map.addLayer({
        id,
        type: 'symbol',
        source: sourceId,
        layout: {
          'text-field': ['get', 'label'],
          'text-size': 10,
          'text-font': ['Open Sans Bold', 'Arial Unicode MS Bold'],
          'text-offset': [0, -1.4],
          'text-anchor': 'top',
          'text-allow-overlap': false,
        },
        paint: {
          'text-color': '#334155',
          'text-halo-color': '#ffffff',
          'text-halo-width': 2,
        },
      })
    }
    const addMapillaryLayers = () => {
      if (!MAPILLARY_TILE_URL) return
      map.addSource('mapillary-coverage', {
        type: 'vector',
        tiles: [MAPILLARY_TILE_URL],
        minzoom: 6,
        maxzoom: 14,
      })
      map.addLayer({
        id: 'mapillary-sequences',
        type: 'line',
        source: 'mapillary-coverage',
        'source-layer': 'sequence',
        layout: {
          'line-cap': 'round',
          'line-join': 'round',
          visibility: 'visible',
        },
        paint: {
          'line-color': '#05cb63',
          'line-opacity': 0.65,
          'line-width': ['interpolate', ['linear'], ['zoom'], 12, 1.2, 18, 3],
        },
      })
      map.addLayer({
        id: 'mapillary-images',
        type: 'circle',
        source: 'mapillary-coverage',
        'source-layer': 'image',
        layout: {
          visibility: 'visible',
        },
        paint: {
          'circle-color': '#05cb63',
          'circle-radius': ['interpolate', ['linear'], ['zoom'], 14, 3, 18, 6],
          'circle-stroke-color': '#ffffff',
          'circle-stroke-width': 1.5,
          'circle-opacity': 0.9,
        },
      })
    }

    map.on('load', () => {
      addSource('static-geofence-shapes')
      addSource('static-geofence-labels')
      addSource('selected-geofence-shapes')
      addSource('selected-geofence-labels')
      addSource('poi-points')
      addMapillaryLayers()
      addGeofenceLayers('static-geofence-shapes', 'static-geofence')
      addGeofenceLayers('selected-geofence-shapes', 'selected-geofence', true)
      addLabelLayer('static-geofence-labels', 'static-geofence-labels')
      addLabelLayer('selected-geofence-labels', 'selected-geofence-labels')
      map.addLayer({
        id: 'poi-points',
        type: 'circle',
        source: 'poi-points',
        paint: {
          'circle-radius': 7,
          'circle-color': '#2563eb',
          'circle-stroke-color': '#ffffff',
          'circle-stroke-width': 2,
        },
      })
      map.addLayer({
        id: 'poi-labels',
        type: 'symbol',
        source: 'poi-points',
        layout: {
          'text-field': ['get', 'name'],
          'text-size': 11,
          'text-offset': [0, 1.2],
          'text-anchor': 'top',
          'text-optional': true,
        },
        paint: {
          'text-color': '#1e3a8a',
          'text-halo-color': '#ffffff',
          'text-halo-width': 1.5,
        },
      })
      setMapReady(true)
      window.setTimeout(() => map.resize(), 0)
    })

    return () => {
      setMapReady(false)
      editMarkersRef.current.forEach((marker) => marker.remove())
      editMarkersRef.current = []
      poiPopupRef.current?.remove()
      mapillaryPopupRef.current?.remove()
      map.remove()
      mapRef.current = null
    }
  }, [])

  useEffect(() => {
    const map = mapRef.current
    if (!map || !mapReady) return undefined

    if (map.getLayer('osm')) {
      map.setLayoutProperty('osm', 'visibility', baseMap === 'osm' ? 'visible' : 'none')
    }
    if (map.getLayer('esri-world-imagery')) {
      map.setLayoutProperty('esri-world-imagery', 'visibility', baseMap === 'satellite' ? 'visible' : 'none')
    }
    if (map.getLayer('esri-world-transportation')) {
      map.setLayoutProperty('esri-world-transportation', 'visibility', baseMap === 'satellite' ? 'visible' : 'none')
    }
    if (map.getLayer('esri-world-boundaries-places')) {
      map.setLayoutProperty('esri-world-boundaries-places', 'visibility', baseMap === 'satellite' ? 'visible' : 'none')
    }
  }, [baseMap, mapReady])

  useEffect(() => {
    const map = mapRef.current
    if (!map || !mapReady) return undefined

    if (map.getLayer('mapillary-sequences')) {
      map.setLayoutProperty('mapillary-sequences', 'visibility', mapillaryVisible ? 'visible' : 'none')
    }
    if (map.getLayer('mapillary-images')) {
      map.setLayoutProperty('mapillary-images', 'visibility', mapillaryVisible ? 'visible' : 'none')
    }
  }, [mapReady, mapillaryVisible])

  useEffect(() => {
    const map = mapRef.current
    if (!map || !mapReady || !MAPILLARY_TILE_URL) return undefined

    const onMapillaryImageClick = (event) => {
      const feature = event.features?.[0]
      const image = mapillaryFeaturePayload(feature)
      if (!image.imageId) return
      event.preventDefault?.()
      mapillaryPopupRef.current?.remove()
      mapillaryPopupRef.current = new maplibregl.Popup({ offset: 16 })
        .setLngLat(event.lngLat)
        .setDOMContent(createMapillaryPopupContent(image, onSelectMapillaryImage))
        .addTo(map)
      onSelectMapillaryImage?.(image)
    }
    const showPointer = () => { map.getCanvas().style.cursor = 'pointer' }
    const hidePointer = () => { map.getCanvas().style.cursor = '' }

    map.on('click', 'mapillary-images', onMapillaryImageClick)
    map.on('mouseenter', 'mapillary-images', showPointer)
    map.on('mouseleave', 'mapillary-images', hidePointer)
    map.on('mouseenter', 'mapillary-sequences', showPointer)
    map.on('mouseleave', 'mapillary-sequences', hidePointer)
    return () => {
      map.off('click', 'mapillary-images', onMapillaryImageClick)
      map.off('mouseenter', 'mapillary-images', showPointer)
      map.off('mouseleave', 'mapillary-images', hidePointer)
      map.off('mouseenter', 'mapillary-sequences', showPointer)
      map.off('mouseleave', 'mapillary-sequences', hidePointer)
    }
  }, [mapReady, onSelectMapillaryImage])

  useEffect(() => {
    const map = mapRef.current
    if (!map || !mapReady) return undefined

    const onClick = (event) => {
      if (!canEditGeofenceShape(form)) return
      const layers = [
        'static-geofence-fill-active',
        'static-geofence-fill-inactive',
        'static-geofence-fill-draft',
        'static-geofence-line-active',
        'static-geofence-line-inactive',
        'static-geofence-line-draft',
        'selected-geofence-fill-active',
        'selected-geofence-fill-inactive',
        'selected-geofence-fill-draft',
        'selected-geofence-line-active',
        'selected-geofence-line-inactive',
        'selected-geofence-line-draft',
        'poi-points',
        'mapillary-images',
        'mapillary-sequences',
      ].filter((layer) => map.getLayer(layer))
      if (map.queryRenderedFeatures(event.point, { layers }).length) return

      const lat = Number(event.lngLat.lat.toFixed(7))
      const lng = Number(event.lngLat.lng.toFixed(7))

      if (drawMode === 'circle') {
        setForm((s) => ({ ...s, type: 'circle', center_lat: lat, center_lng: lng }))
        return
      }

      if (drawMode === 'polygon') {
        setForm((s) => {
          const next = [...polygonEditPoints(s), [lat, lng]]
          const polygon = polygonFeatureFromPoints(next)
          const center = polygonFeatureCenter(polygon) || polygonFeatureCenter({ geometry: { type: 'Polygon', coordinates: [next.map(([pointLat, pointLng]) => [pointLng, pointLat])] } })
          return {
            ...s,
            type: 'polygon',
            center_lat: center?.[0] ?? s.center_lat,
            center_lng: center?.[1] ?? s.center_lng,
            polygon_draft_points: next,
            polygon_geojson: polygon,
          }
        })
      }
    }

    map.on('click', onClick)
    return () => map.off('click', onClick)
  }, [drawMode, form, mapReady, setForm])

  useEffect(() => {
    const map = mapRef.current
    if (!map || !mapReady || !focusPoint || focusKeyRef.current === focusKey) return
    const lat = Number(focusPoint.latitude)
    const lng = Number(focusPoint.longitude)
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) return
    focusKeyRef.current = focusKey
    map.flyTo({ center: [lng, lat], zoom: 18, essential: true })
  }, [focusKey, focusPoint, mapReady])

  useEffect(() => {
    const map = mapRef.current
    if (!map || !mapReady) return
    map.getSource('poi-points')?.setData(poiFeatureCollection(visiblePois))

    const bounds = pointBounds(visiblePois.map((poi) => [Number(poi.latitude), Number(poi.longitude)]))
    if (bounds && visiblePois.length > 1) {
      map.fitBounds(bounds, { padding: 70, maxZoom: 18, animate: false })
    }
  }, [mapReady, visiblePois])

  useEffect(() => {
    const map = mapRef.current
    if (!map || !mapReady) return undefined

    const onPoiClick = (event) => {
      const feature = event.features?.[0]
      const poi = visiblePois.find((item) => String(item.id || '') === String(feature?.properties?.id || ''))
      if (!poi) return
      poiPopupRef.current?.remove()
      poiPopupRef.current = new maplibregl.Popup({ offset: 16 })
        .setLngLat(event.lngLat)
        .setDOMContent(createPoiPopupContent(poi, onUsePoi))
        .addTo(map)
    }
    const showPointer = () => { map.getCanvas().style.cursor = 'pointer' }
    const hidePointer = () => { map.getCanvas().style.cursor = '' }

    map.on('click', 'poi-points', onPoiClick)
    map.on('mouseenter', 'poi-points', showPointer)
    map.on('mouseleave', 'poi-points', hidePointer)
    return () => {
      map.off('click', 'poi-points', onPoiClick)
      map.off('mouseenter', 'poi-points', showPointer)
      map.off('mouseleave', 'poi-points', hidePointer)
    }
  }, [mapReady, onUsePoi, visiblePois])

  useEffect(() => {
    const map = mapRef.current
    if (!map || !mapReady || !selectedPoiId) return
    const poi = visiblePois.find((item) => String(item.id || '') === String(selectedPoiId || ''))
    const lat = Number(poi?.latitude)
    const lng = Number(poi?.longitude)
    if (!poi || !Number.isFinite(lat) || !Number.isFinite(lng)) return
    poiPopupRef.current?.remove()
    poiPopupRef.current = new maplibregl.Popup({ offset: 16 })
      .setLngLat([lng, lat])
      .setDOMContent(createPoiPopupContent(poi, onUsePoi))
      .addTo(map)
  }, [mapReady, onUsePoi, selectedPoiId, visiblePois])

  useEffect(() => {
    const map = mapRef.current
    if (!map || !mapReady) return undefined

    const layers = [
      'static-geofence-fill-active',
      'static-geofence-fill-inactive',
      'static-geofence-fill-draft',
      'static-geofence-line-active',
      'static-geofence-line-inactive',
      'static-geofence-line-draft',
    ].filter((layer) => map.getLayer(layer))
    const selectGeofenceFromMap = (event) => {
      const id = event.features?.[0]?.properties?.geofenceId
      const geofence = geofences.find((item) => String(item.id) === String(id))
      if (!geofence) return
      event.preventDefault?.()
      setForm(formFromGeofence(geofence.branch_id, branch?.branch_name || '', geofence))
      setDrawMode(geofence.type === 'polygon' ? 'polygon' : 'circle')
    }
    const showPointer = () => { map.getCanvas().style.cursor = 'pointer' }
    const hidePointer = () => { map.getCanvas().style.cursor = '' }

    layers.forEach((layer) => {
      map.on('click', layer, selectGeofenceFromMap)
      map.on('mouseenter', layer, showPointer)
      map.on('mouseleave', layer, hidePointer)
    })
    return () => {
      layers.forEach((layer) => {
        map.off('click', layer, selectGeofenceFromMap)
        map.off('mouseenter', layer, showPointer)
        map.off('mouseleave', layer, hidePointer)
      })
    }
  }, [branch, geofences, mapReady, setDrawMode, setForm])

  useEffect(() => {
    fittedBranchRef.current = ''
  }, [branch?.id])

  useEffect(() => {
    const map = mapRef.current
    if (!map || !mapReady) return

    const staticGeofences = form.id == null
      ? geofences
      : geofences.filter((geofence) => String(geofence.id) !== String(form.id))
    map.getSource('static-geofence-shapes')?.setData(geofenceFeatureCollection(staticGeofences))
    map.getSource('static-geofence-labels')?.setData(geofenceLabelCollection(staticGeofences))
  }, [branch, geofences, form.id, mapReady])

  useEffect(() => {
    const map = mapRef.current
    if (!map || !mapReady || !branch?.id) return

    const branchKey = String(branch.id)
    if (fittedBranchRef.current === branchKey) return

    const fallbackCenter = geofences.map(geofenceCenter).find(Boolean) || branchLocation(branch)
    if (!geofences.length) {
      const center = toLngLat(fallbackCenter)
      if (center) map.jumpTo({ center, zoom: Math.max(map.getZoom(), 16) })
      fittedBranchRef.current = branchKey
      return
    }

    fittedBranchRef.current = branchKey
    const bounds = geojsonBounds(geofenceFeatureCollection(geofences))
    if (bounds) {
      map.fitBounds(bounds, { padding: 80, maxZoom: 17, animate: false })
      return
    }

    const center = toLngLat(fallbackCenter)
    if (center) map.jumpTo({ center, zoom: Math.max(map.getZoom(), 16) })
  }, [branch, geofences, mapReady])

  useEffect(() => {
    const map = mapRef.current
    if (!map || !mapReady) return undefined

    editMarkersRef.current.forEach((marker) => marker.remove())
    editMarkersRef.current = []

    const editableShape = canEditGeofenceShape(form)
    const selectedShape = geofenceFeature(form, true)
    const selectedLabel = geofenceLabelFeature(form, true)
    map.getSource('selected-geofence-shapes')?.setData(selectedShape ? { type: 'FeatureCollection', features: [selectedShape] } : EMPTY_FEATURE_COLLECTION)
    map.getSource('selected-geofence-labels')?.setData(selectedLabel ? { type: 'FeatureCollection', features: [selectedLabel] } : EMPTY_FEATURE_COLLECTION)

    if (!editableShape) return undefined

    if (form.type === 'circle' && form.center_lat != null && form.center_lng != null) {
      const center = [Number(form.center_lat), Number(form.center_lng)]
      const centerLngLat = toLngLat(center)
      const handleLngLat = radiusHandleLngLat(center, form.radius_meters)
      if (!centerLngLat || !handleLngLat) return undefined

      const syncCircleSource = (nextCenter, nextRadius) => {
        const feature = circleFeature(nextCenter, nextRadius, {
          geofenceId: form.id ?? form.draft_key ?? 'selected',
          status: geofenceMapStatus(form, true),
          name: form.name || 'Selected geofence',
          label: geofenceMapText(geofenceMapStatus(form, true), form.name || 'Selected geofence'),
        })
        map.getSource('selected-geofence-shapes')?.setData(feature ? { type: 'FeatureCollection', features: [feature] } : EMPTY_FEATURE_COLLECTION)
      }

      const centerMarker = new maplibregl.Marker({ element: markerElement('center'), draggable: true })
        .setLngLat(centerLngLat)
        .addTo(map)
      const resizeMarker = new maplibregl.Marker({ element: markerElement('handle'), draggable: true })
        .setLngLat(handleLngLat)
        .addTo(map)

      centerMarker.on('drag', () => {
        const ll = centerMarker.getLngLat()
        const nextCenter = [ll.lat, ll.lng]
        syncCircleSource(nextCenter, form.radius_meters)
        const nextHandle = radiusHandleLngLat(nextCenter, form.radius_meters)
        if (nextHandle) resizeMarker.setLngLat(nextHandle)
      })
      centerMarker.on('dragend', () => {
        const ll = centerMarker.getLngLat()
        setForm((s) => ({ ...s, center_lat: Number(ll.lat.toFixed(7)), center_lng: Number(ll.lng.toFixed(7)) }))
      })
      resizeMarker.on('drag', () => {
        const centerLl = centerMarker.getLngLat()
        const handleLl = resizeMarker.getLngLat()
        const nextRadius = Math.max(5, Math.round(distanceMeters([centerLl.lat, centerLl.lng], [handleLl.lat, handleLl.lng])))
        syncCircleSource([centerLl.lat, centerLl.lng], nextRadius)
      })
      resizeMarker.on('dragend', () => {
        const centerLl = centerMarker.getLngLat()
        const handleLl = resizeMarker.getLngLat()
        const nextRadius = Math.max(5, Math.round(distanceMeters([centerLl.lat, centerLl.lng], [handleLl.lat, handleLl.lng])))
        const nextHandle = radiusHandleLngLat([centerLl.lat, centerLl.lng], nextRadius)
        if (nextHandle) resizeMarker.setLngLat(nextHandle)
        setForm((s) => ({ ...s, radius_meters: nextRadius }))
      })

      editMarkersRef.current = [centerMarker, resizeMarker]
    }

    if (form.type === 'polygon') {
      const openPoints = polygonEditPoints(form)
      if (!openPoints.length) return undefined
      const markers = openPoints.map((point) => new maplibregl.Marker({ element: markerElement('handle'), draggable: true })
        .setLngLat([point[1], point[0]])
        .addTo(map))
      const syncPolygonSource = () => {
        const nextPoints = markers.map((marker) => {
          const ll = marker.getLngLat()
          return [Number(ll.lat.toFixed(7)), Number(ll.lng.toFixed(7))]
        })
        const feature = polygonFeatureFromPoints(nextPoints)
        map.getSource('selected-geofence-shapes')?.setData(feature ? {
          type: 'FeatureCollection',
          features: [{
            ...feature,
            properties: {
              ...(feature.properties || {}),
              geofenceId: form.id ?? form.draft_key ?? 'selected',
              status: geofenceMapStatus(form, true),
              name: form.name || 'Selected geofence',
              label: geofenceMapText(geofenceMapStatus(form, true), form.name || 'Selected geofence'),
            },
          }],
        } : EMPTY_FEATURE_COLLECTION)
        return nextPoints
      }
      markers.forEach((marker) => {
        marker.on('drag', syncPolygonSource)
        marker.on('dragend', () => {
          const nextPoints = syncPolygonSource()
          const polygon = polygonFeatureFromPoints(nextPoints)
          const center = polygonFeatureCenter(polygon)
          setForm((s) => ({
            ...s,
            center_lat: center?.[0] ?? s.center_lat,
            center_lng: center?.[1] ?? s.center_lng,
            polygon_draft_points: nextPoints,
            polygon_geojson: polygon,
          }))
        })
      })
      editMarkersRef.current = markers
    }

    return () => {
      editMarkersRef.current.forEach((marker) => marker.remove())
      editMarkersRef.current = []
    }
  }, [form, mapReady, setForm])

  return (
    <div className="relative h-[680px] min-h-[560px] w-full overflow-hidden rounded-b-lg border-t border-slate-200 bg-slate-100 dark:border-border dark:bg-muted">
      <div ref={mapEl} className="h-full w-full" />
      <div className="pointer-events-none absolute left-3 top-3 z-500 rounded-md border border-slate-200 bg-white/95 px-3 py-1.5 text-[11px] font-bold uppercase tracking-wide text-slate-700 shadow-sm backdrop-blur dark:border-border dark:bg-background/90 dark:text-foreground">
        {baseMap === 'satellite' ? 'Esri World Imagery + Labels + MapLibre GL' : 'OpenStreetMap + MapLibre GL'}
      </div>
      <div className="absolute right-14 top-3 z-500 flex overflow-hidden rounded-md border border-slate-200 bg-white/95 text-[11px] font-bold uppercase tracking-wide text-slate-700 shadow-sm backdrop-blur dark:border-border dark:bg-background/90 dark:text-foreground">
        <button
          type="button"
          className={cn(
            'px-3 py-1.5 hover:bg-slate-50 dark:hover:bg-muted',
            baseMap === 'osm' && 'bg-orange-50 text-[#f04414] dark:bg-orange-500/10 dark:text-orange-300',
          )}
          onClick={() => setBaseMap('osm')}
        >
          OSM
        </button>
        <button
          type="button"
          className={cn(
            'border-l border-slate-200 px-3 py-1.5 hover:bg-slate-50 dark:border-border dark:hover:bg-muted',
            baseMap === 'satellite' && 'bg-orange-50 text-[#f04414] dark:bg-orange-500/10 dark:text-orange-300',
          )}
          onClick={() => setBaseMap('satellite')}
        >
          Satellite
        </button>
      </div>
      <div className="absolute bottom-3 left-3 z-500 flex flex-wrap items-center gap-2 rounded-md border border-slate-200 bg-white/95 px-3 py-2 text-[11px] font-semibold text-slate-700 shadow-sm backdrop-blur dark:border-border dark:bg-background/90 dark:text-foreground">
        <span className="flex items-center gap-1.5">
          <span className={cn('size-2 rounded-full', baseMap === 'satellite' ? 'bg-sky-500' : 'bg-slate-500')} />
          {baseMap === 'satellite' ? 'Esri satellite + road/place labels' : 'OpenStreetMap streets'}
        </span>
        <span className="flex items-center gap-1.5">
          <span className="size-2 rounded-full bg-[#05cb63]" />
          Mapillary streetview coverage
        </span>
        <button
          type="button"
          className="rounded border border-slate-200 px-2 py-1 text-[10px] font-bold uppercase tracking-wide hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-60 dark:border-border dark:hover:bg-muted"
          disabled={!MAPILLARY_TILE_URL}
          onClick={() => setMapillaryVisible((visible) => !visible)}
        >
          {mapillaryVisible ? 'Hide' : 'Show'}
        </button>
      </div>
    </div>
  )
}
export default function AdminGeofencing() {
  const { user } = useAuth()
  const canViewLiveMonitoring = ['admin', 'super_admin'].includes(String(user?.role || '').toLowerCase())
  const [activeTab, setActiveTab] = useState('setup')
  const [branches, setBranches] = useState([])
  const [geofenceModuleEnabled, setGeofenceModuleEnabled] = useState(true)
  const [geofenceModuleSaving, setGeofenceModuleSaving] = useState(false)
  const [attendanceWithoutGeofenceEnabled, setAttendanceWithoutGeofenceEnabled] = useState(true)
  const [allowedWithoutGeofenceBranchIds, setAllowedWithoutGeofenceBranchIds] = useState([])
  const [geofenceExemptEmployeeIds, setGeofenceExemptEmployeeIds] = useState([])
  const [geofenceExemptionEmployees, setGeofenceExemptionEmployees] = useState([])
  const [geofenceExemptionSearch, setGeofenceExemptionSearch] = useState('')
  const [employeeExemptionSaving, setEmployeeExemptionSaving] = useState(false)
  const [bypassSaving, setBypassSaving] = useState(false)
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
  const [deletingGeofenceId, setDeletingGeofenceId] = useState(null)
  const [mapillaryImage, setMapillaryImage] = useState(null)
  const [mapillaryLoading, setMapillaryLoading] = useState(false)
  const [mapillaryError, setMapillaryError] = useState('')
  const [locationTestLoading, setLocationTestLoading] = useState(false)
  const [locationTestResult, setLocationTestResult] = useState(null)
  const mapSearchCacheRef = useRef(new Map())
  const mapSearchAbortRef = useRef(null)
  const coordinateLookupCacheRef = useRef(new Map())
  const coordinateLookupAbortRef = useRef(null)
  const mapillaryAbortRef = useRef(null)
  const formGeofenceIdRef = useRef(null)
  const { toast } = useToast()

  formGeofenceIdRef.current = form.id

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

  const filteredGeofenceExemptionEmployees = useMemo(() => {
    const q = geofenceExemptionSearch.trim().toLowerCase()
    if (!q) return geofenceExemptionEmployees
    return geofenceExemptionEmployees.filter((employee) => [
      employee.name,
      employee.employee_number,
      employee.branch,
      employee.department,
      employee.division,
      employee.section_unit,
    ].some((value) => String(value || '').toLowerCase().includes(q)))
  }, [geofenceExemptionEmployees, geofenceExemptionSearch])

  const totalPages = Math.max(1, Math.ceil(filteredBranches.length / PAGE_SIZE))
  const pagedBranches = filteredBranches.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE)
  const rangeStart = filteredBranches.length ? (page - 1) * PAGE_SIZE + 1 : 0
  const rangeEnd = Math.min(page * PAGE_SIZE, filteredBranches.length)

  const shapeEditable = canEditGeofenceShape(form)
  const streetviewTarget = useMemo(
    () => validLatLngPair(geofenceCenter(form)) || validLatLngPair(branchLocation(selectedBranch)),
    [form, selectedBranch],
  )

  const load = useCallback(async () => {
    setLoading(true)
    try {
      const data = await getAdminGeofencing()
      const nextBranches = data.branches || []
      setBranches(nextBranches)
      setGeofenceModuleEnabled(data.geofence_module?.enabled !== false)
      setAttendanceWithoutGeofenceEnabled(data.attendance_without_geofence?.enabled !== false)
      setAllowedWithoutGeofenceBranchIds(data.attendance_without_geofence?.branch_ids || [])
      setGeofenceExemptEmployeeIds(data.employee_exemptions?.employee_ids || [])
      setGeofenceExemptionEmployees(data.employee_exemptions?.employees || [])
      setSelectedBranchId((current) => current || nextBranches[0]?.id || null)
    } catch (error) {
      toast({ title: 'Failed to load geofencing', description: error.message, variant: 'error' })
    } finally {
      setLoading(false)
    }
  }, [toast])

  const loadBranch = useCallback(async (branchId, options = {}) => {
    const focusMap = options.focusMap !== false
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
      const currentGeofence = !preferredGeofence && formGeofenceIdRef.current
        ? nextGeofences.find((geofence) => String(geofence.id) === String(formGeofenceIdRef.current))
        : null
      const selectedGeofence = preferredGeofence || (focusMap ? nextGeofences[0] : currentGeofence)

      setGeofences(nextGeofences)
      setBranchEmployees(nextEmployees)
      if (data.branch) {
        setBranches((list) => list.map((branch) => (String(branch.id) === String(data.branch.id) ? { ...branch, ...data.branch } : branch)))
      }

      if (!selectedGeofence) {
        const { form: nextForm, center } = draftFormForBranch(branchId, data.branch, nextGeofences)
        setForm(nextForm)
        setDrawMode(nextForm.type || 'circle')
        if (focusMap) {
          setFocusPoint({ latitude: center[0], longitude: center[1] })
          setFocusKey((key) => key + 1)
        }
        return { branch: data.branch, geofences: nextGeofences }
      }

      const nextForm = formFromGeofence(branchId, branchName, selectedGeofence)
      const center = geofenceCenter(selectedGeofence) || branchLocation(data.branch) || geofenceCenter(nextForm) || DEFAULT_CENTER
      setForm({ ...nextForm, center_lat: center[0], center_lng: center[1] })
      setDrawMode(selectedGeofence?.type || 'circle')
      if (focusMap) {
        setFocusPoint({ latitude: center[0], longitude: center[1] })
        setFocusKey((key) => key + 1)
      }
      return { branch: data.branch, geofences: nextGeofences }
    } catch (error) {
      toast({ title: 'Failed to load branch geofences', description: error.message, variant: 'error' })
      return null
    }
  }, [toast])

  const selectMapillaryImage = useCallback((image) => {
    if (!image?.imageId) return
    setMapillaryError('')
    setMapillaryImage({
      imageId: image.imageId,
      latitude: image.latitude ?? null,
      longitude: image.longitude ?? null,
      capturedAt: image.capturedAt ?? null,
      source: image.source || 'Mapillary',
    })
  }, [])

  const openMapillaryStreetview = useCallback(async (point = streetviewTarget, label = 'selected geofence') => {
    const latLng = validLatLngPair(point)
    if (!MAPILLARY_ACCESS_TOKEN) {
      setMapillaryError('Missing Mapillary access token. Add VITE_MAPILLARY_ACCESS_TOKEN in frontend/.env.')
      return
    }
    if (!latLng) {
      setMapillaryError('Select a branch or geofence with valid coordinates before opening streetview.')
      return
    }

    mapillaryAbortRef.current?.abort()
    const controller = new AbortController()
    mapillaryAbortRef.current = controller
    setMapillaryLoading(true)
    setMapillaryError('')

    try {
      const url = mapillaryGraphUrl(latLng, 50)
      const response = await fetch(url, { signal: controller.signal })
      const data = await response.json().catch(() => ({}))
      if (!response.ok) {
        throw new Error(data?.error?.message || data?.message || 'Mapillary imagery lookup failed.')
      }

      const nearest = data?.data?.[0]
      if (!nearest?.id) {
        setMapillaryImage(null)
        setMapillaryError(`No Mapillary streetview imagery was found within 50m of the ${label}. Try a nearby green image dot on the map.`)
        return
      }

      const coordinates = nearest.geometry?.coordinates
      selectMapillaryImage({
        imageId: nearest.id,
        latitude: Array.isArray(coordinates) ? coordinates[1] : latLng[0],
        longitude: Array.isArray(coordinates) ? coordinates[0] : latLng[1],
        capturedAt: nearest.captured_at,
        source: 'Nearest Mapillary image',
      })
    } catch (error) {
      if (error?.name !== 'AbortError') {
        setMapillaryError(error.message || 'Mapillary streetview is unavailable right now.')
      }
    } finally {
      if (!controller.signal.aborted) setMapillaryLoading(false)
    }
  }, [selectMapillaryImage, streetviewTarget])

  useEffect(() => () => {
    mapillaryAbortRef.current?.abort()
  }, [])

  useEffect(() => {
    load()
  }, [load])

  useEffect(() => {
    loadBranch(selectedBranchId, { focusMap: true })
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

    const coordinateCenter = coordinateTextToLatLng(query)
    if (coordinateCenter) {
      setMapSearchResults([])
      setMapSearchLoading(false)

      const nextLat = Number(coordinateCenter[0].toFixed(7))
      const nextLng = Number(coordinateCenter[1].toFixed(7))
      const currentCenter = validLatLngPair([form.center_lat, form.center_lng])
      const alreadyCentered = currentCenter
        && Math.abs(currentCenter[0] - nextLat) < 0.0000001
        && Math.abs(currentCenter[1] - nextLng) < 0.0000001

      if (shapeEditable && !alreadyCentered) {
        setForm((current) => {
          const polygonMode = current.type === 'polygon' || drawMode === 'polygon'
          return {
            ...current,
            type: polygonMode ? 'polygon' : 'circle',
            center_lat: nextLat,
            center_lng: nextLng,
            polygon_draft_points: polygonMode ? [] : current.polygon_draft_points,
            polygon_geojson: polygonMode && polygonOpenPointCount(current.polygon_geojson) < 3
              ? polygonFeatureAroundCenter([nextLat, nextLng], Math.max(30, Number(current.radius_meters) || 80))
              : current.polygon_geojson,
          }
        })
        setFocusPoint({ latitude: nextLat, longitude: nextLng })
        setFocusKey((key) => key + 1)
      }

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
        const results = [...localResults, ...(data.results || [])].slice(0, 8)
        mapSearchCacheRef.current.set(cacheKey, results)
        setMapSearchResults(results)
        if (results.length === 0) {
          toast({
            title: 'No map search results',
            description: 'Try a building, street, barangay, city, or nearby landmark, then click the map if needed.',
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
  }, [drawMode, form, geofences, mapSearch, mapSearchMode, selectedBranch, shapeEditable, toast])

  useEffect(() => {
    if (form.type !== 'circle') return undefined

    const center = validLatLngPair([form.center_lat, form.center_lng])
    coordinateLookupAbortRef.current?.abort()
    if (!center) return undefined

    const coordinateText = `${center[0].toFixed(7)}, ${center[1].toFixed(7)}`
    const cacheKey = `reverse:${coordinateText}`
    const cached = coordinateLookupCacheRef.current.get(cacheKey)
    if (cached) {
      setMapSearch(cached)
      setMapSearchResults([])
      return undefined
    }

    const controller = new AbortController()
    coordinateLookupAbortRef.current = controller
    const timeout = window.setTimeout(async () => {
      try {
        const data = await searchGeofenceLocation(coordinateText, { signal: controller.signal, mode: 'address' })
        const result = data.results?.[0]
        const resolvedPlace = mapSearchDisplayText(result, coordinateText)
        coordinateLookupCacheRef.current.set(cacheKey, resolvedPlace)
        if (!controller.signal.aborted) {
          const normalizedResolvedPlace = resolvedPlace.trim().toLowerCase()
          const searchModes = ['address', 'establishment', 'branch']
          searchModes.forEach((mode) => {
            mapSearchCacheRef.current.set(`${mode}:${normalizedResolvedPlace}`, [])
          })
          setMapSearch(resolvedPlace)
          setMapSearchResults([])
        }
      } catch (error) {
        if (error?.name !== 'AbortError' && !controller.signal.aborted) {
          coordinateLookupCacheRef.current.set(cacheKey, coordinateText)
          setMapSearch(coordinateText)
          setMapSearchResults([])
        }
      }
    }, 650)

    return () => {
      window.clearTimeout(timeout)
      controller.abort()
    }
  }, [form.center_lat, form.center_lng, form.type])

  async function saveGeofence(options = {}) {
    if (!selectedBranchId) return
    if (!form.device_scope) {
      toast({ title: 'Select a device scope', description: 'Each geofence must target a device scope before it can be saved.', variant: 'error' })
      return
    }
    if (form.type === 'circle' && !validLatLngPair([form.center_lat, form.center_lng])) {
      toast({ title: 'Set a valid circle location', description: 'Click the map or enter valid latitude and longitude before saving.', variant: 'error' })
      return
    }
    if (form.type === 'polygon' && polygonFormPointCount(form) < 3) {
      toast({ title: 'Draw a polygon boundary', description: 'A polygon geofence needs at least three points. Click the map or use the starter boundary.', variant: 'error' })
      return
    }
    const polygonGeojson = form.type === 'polygon'
      ? (form.polygon_geojson || polygonFeatureFromPoints(polygonEditPoints(form)))
      : null
    const polygonCenter = form.type === 'polygon' ? polygonFeatureCenter(polygonGeojson) || polygonFormCenter(form) : null
    setSaving(true)
    const status = form.status || (normalizeBoolean(form.is_active) ? 'active' : 'draft')
    const payload = {
      name: form.name || selectedBranch?.branch_name || (form.type === 'circle' ? 'Office radius' : 'Branch polygon'),
      type: form.type,
      device_scope: form.device_scope,
      center_lat: form.type === 'circle' ? Number(form.center_lat) : (polygonCenter?.[0] ?? null),
      center_lng: form.type === 'circle' ? Number(form.center_lng) : (polygonCenter?.[1] ?? null),
      radius_meters: form.type === 'circle' ? Number(form.radius_meters) : null,
      polygon_geojson: polygonGeojson,
      status,
      is_active: status === 'active',
      enforcement_mode: form.enforcement_mode || 'enforce',
      priority: Number(form.priority) || 1,
      accuracy_threshold_meters: Number(form.accuracy_threshold_meters) || 100,
      notes: form.notes || null,
    }
    try {
      const shouldUpdate = form.id && geofences.some((geofence) => String(geofence.id) === String(form.id))
      const data = shouldUpdate
        ? await updateBranchGeofence(selectedBranchId, form.id, payload)
        : await createBranchGeofence(selectedBranchId, payload)
      const savedGeofenceId = data?.geofence?.id || (shouldUpdate ? form.id : null)
      toast({ title: shouldUpdate ? 'Geofence updated' : 'Geofence created', variant: 'success' })
      const branchData = await loadBranch(selectedBranchId, { preferredGeofenceId: savedGeofenceId, focusMap: false })
      await load()
      if (options.addAnother && branchData) {
        const { form: nextForm, center } = draftFormForBranch(selectedBranchId, branchData.branch || selectedBranch, branchData.geofences || [], geofenceCenter(form))
        setForm({ ...nextForm, id: null, status: 'draft', is_active: false })
        setDrawMode('circle')
        setFocusPoint({ latitude: center[0], longitude: center[1] })
        setFocusKey((key) => key + 1)
      }
    } catch (error) {
      if (/geofence not found/i.test(error.message || '')) {
        try {
          const data = await createBranchGeofence(selectedBranchId, payload)
          const savedGeofenceId = data?.geofence?.id || null
          toast({ title: 'Geofence created', variant: 'success' })
          await loadBranch(selectedBranchId, { preferredGeofenceId: savedGeofenceId, focusMap: false })
          await load()
          return
        } catch {
          await loadBranch(selectedBranchId, { focusMap: false })
        }
      }
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
      const status = form.status === 'active' ? 'inactive' : 'active'
      await updateBranchGeofence(selectedBranchId, form.id, { status })
      await loadBranch(selectedBranchId, { preferredGeofenceId: form.id, focusMap: false })
      await load()
    } catch (error) {
      if (/geofence not found/i.test(error.message || '')) {
        await loadBranch(selectedBranchId, { focusMap: false })
      }
      toast({ title: 'Update failed', description: error.message, variant: 'error' })
    }
  }

  async function toggleGeofenceActive(geofence) {
    if (!selectedBranchId || !geofence?.id) return
    try {
      const nextActive = geofence.status !== 'active'
      await updateBranchGeofence(selectedBranchId, geofence.id, { status: nextActive ? 'active' : 'inactive' })
      await loadBranch(selectedBranchId, { preferredGeofenceId: geofence.id, focusMap: false })
      await load()
      toast({ title: nextActive ? 'Geofence enabled' : 'Geofence saved as draft', variant: 'success' })
    } catch (error) {
      if (/geofence not found/i.test(error.message || '')) {
        await loadBranch(selectedBranchId, { focusMap: false })
      }
      toast({ title: 'Update failed', description: error.message, variant: 'error' })
    }
  }

  async function deleteGeofence(geofence) {
    if (!selectedBranchId || !geofence?.id || geofence?.draft_key) return
    const name = geofence.name || 'this geofence'
    if (!window.confirm(`Delete ${name}? Attendance validation will no longer use this branch geofence.`)) {
      return
    }

    setDeletingGeofenceId(geofence.id)
    try {
      await deleteBranchGeofence(selectedBranchId, geofence.id)
      toast({ title: 'Geofence deleted', variant: 'success' })
      await loadBranch(selectedBranchId, { focusMap: false })
      await load()
    } catch (error) {
      if (/geofence not found/i.test(error.message || '')) {
        await loadBranch(selectedBranchId, { focusMap: false })
      }
      toast({ title: 'Delete failed', description: error.message, variant: 'error' })
    } finally {
      setDeletingGeofenceId(null)
    }
  }

  async function saveAttendanceWithoutGeofence(enabled, branchIds) {
    setBypassSaving(true)
    try {
      const data = await updateAttendanceWithoutGeofenceSettings({
        enabled,
        branch_ids: branchIds,
      })
      setAttendanceWithoutGeofenceEnabled(data.attendance_without_geofence?.enabled !== false)
      setAllowedWithoutGeofenceBranchIds(data.attendance_without_geofence?.branch_ids || [])
      setBranches((list) => list.map((branch) => ({
        ...branch,
        allowed_without_geofence: (data.attendance_without_geofence?.branch_ids || []).some((id) => String(id) === String(branch.id)),
      })))
      toast({ title: 'Attendance without geofence settings updated', variant: 'success' })
    } catch (error) {
      toast({ title: 'Settings failed', description: error.message, variant: 'error' })
    } finally {
      setBypassSaving(false)
    }
  }

  async function saveGeofenceModuleEnabled(enabled) {
    const previous = geofenceModuleEnabled
    setGeofenceModuleEnabled(enabled)
    setGeofenceModuleSaving(true)
    try {
      const data = await updateGeofenceModuleSettings({ enabled })
      setGeofenceModuleEnabled(data.geofence_module?.enabled !== false)
      toast({
        title: data.geofence_module?.enabled === false ? 'Geofencing module turned off' : 'Geofencing module turned on',
        description: data.geofence_module?.enabled === false
          ? 'Clock in/out will no longer request location or require geofence validation.'
          : 'Clock in/out will request location and enforce configured geofence rules again.',
        variant: 'success',
      })
    } catch (error) {
      setGeofenceModuleEnabled(previous)
      toast({ title: 'Geofence module setting failed', description: error.message, variant: 'error' })
    } finally {
      setGeofenceModuleSaving(false)
    }
  }

  function toggleAllowedWithoutGeofenceBranch(branchId, checked) {
    const nextIds = checked
      ? [...new Set([...allowedWithoutGeofenceBranchIds, branchId])]
      : allowedWithoutGeofenceBranchIds.filter((id) => String(id) !== String(branchId))
    saveAttendanceWithoutGeofence(attendanceWithoutGeofenceEnabled, nextIds)
  }

  async function saveEmployeeGeofenceExemptions(employeeIds) {
    setEmployeeExemptionSaving(true)
    try {
      const data = await updateGeofenceEmployeeExemptions({ employee_ids: employeeIds })
      setGeofenceExemptEmployeeIds(data.employee_exemptions?.employee_ids || [])
      setGeofenceExemptionEmployees(data.employee_exemptions?.employees || geofenceExemptionEmployees)
      toast({ title: 'Employee geofence exemptions updated', variant: 'success' })
    } catch (error) {
      toast({ title: 'Employee exemption update failed', description: error.message, variant: 'error' })
    } finally {
      setEmployeeExemptionSaving(false)
    }
  }

  function toggleEmployeeGeofenceExemption(employeeId, checked) {
    const nextIds = checked
      ? [...new Set([...geofenceExemptEmployeeIds, employeeId])]
      : geofenceExemptEmployeeIds.filter((id) => String(id) !== String(employeeId))
    saveEmployeeGeofenceExemptions(nextIds)
  }

  async function testCurrentLocation() {
    if (!selectedBranchId) return
    setLocationTestLoading(true)
    setLocationTestResult(null)
    try {
      const location = await captureAttendanceLocation({
        minimumSamples: Number(selectedBranch?.geofence_minimum_samples || 3),
        maximumSamples: Number(selectedBranch?.geofence_maximum_samples || 5),
        timeoutMs: Number(selectedBranch?.geofence_sample_timeout_seconds || 15) * 1000,
        desiredAccuracyMeters: Number(
          selectedBranch?.geofence_desktop_accuracy_threshold_meters
          || selectedBranch?.geofence_default_accuracy_threshold_meters
          || 100,
        ),
      })
      const result = await testAttendanceGeofence({
        branch_id: selectedBranchId,
        clock_type: 'clock_in',
        method: 'admin_test',
        ...location,
      })
      setLocationTestResult({ location, result })
      toast({ title: result.allowed ? 'Current location is inside' : 'Current location is outside', variant: result.allowed ? 'success' : 'error' })
    } catch (error) {
      setLocationTestResult({ error: error.message })
      toast({ title: 'Location test failed', description: error.message, variant: 'error' })
    } finally {
      setLocationTestLoading(false)
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
    setForm((s) => {
      const polygonMode = s.type === 'polygon' || drawMode === 'polygon'
      return {
        ...s,
        type: polygonMode ? 'polygon' : (s.type || 'circle'),
        center_lat: lat,
        center_lng: lng,
        polygon_draft_points: polygonMode ? [] : s.polygon_draft_points,
        polygon_geojson: polygonMode && polygonOpenPointCount(s.polygon_geojson) < 3
          ? polygonFeatureAroundCenter([lat, lng], Math.max(30, Number(s.radius_meters) || 80))
          : s.polygon_geojson,
      }
    })
    setFocusPoint({
      latitude: lat,
      longitude: lng,
    })
    setFocusKey((key) => key + 1)
    setMapSearch(mapSearchDisplayText(result, mapSearch))
    setMapSearchResults([])
  }

  function updateManualCircleCoordinate(field, value) {
    const nextForm = { ...form, type: 'circle', [field]: value }
    setForm((s) => ({ ...s, type: 'circle', [field]: value, polygon_draft_points: [] }))

    const center = validLatLngPair([nextForm.center_lat, nextForm.center_lng])
    if (!center) return

    setDrawMode('circle')
    setFocusPoint({ latitude: center[0], longitude: center[1] })
    setFocusKey((key) => key + 1)
  }

  function switchGeofenceType(nextType) {
    if (!shapeEditable || !['circle', 'polygon'].includes(nextType)) return
    setDrawMode(nextType)
    setForm((current) => {
      if (nextType === 'polygon') {
        const existingPoints = polygonFormPointCount(current)
        const currentPoints = polygonEditPoints(current)
        const center = validLatLngPair([current.center_lat, current.center_lng])
          || validLatLngPair(geofenceCenter(current))
          || validLatLngPair(branchLocation(selectedBranch))
          || DEFAULT_CENTER
        const polygonGeojson = existingPoints >= 3
          ? (current.polygon_geojson || polygonFeatureFromPoints(currentPoints))
          : polygonFeatureAroundCenter(center, Math.max(30, Number(current.radius_meters) || 80))
        const polygonCenter = polygonFeatureCenter(polygonGeojson) || center
        return {
          ...current,
          type: 'polygon',
          center_lat: polygonCenter[0],
          center_lng: polygonCenter[1],
          polygon_draft_points: [],
          polygon_geojson: polygonGeojson,
        }
      }

      const center = validLatLngPair([current.center_lat, current.center_lng])
        || validLatLngPair(geofenceCenter(current))
        || validLatLngPair(branchLocation(selectedBranch))
        || DEFAULT_CENTER
      return {
        ...current,
        type: 'circle',
        polygon_draft_points: [],
        center_lat: center[0],
        center_lng: center[1],
        radius_meters: Math.max(5, Number(current.radius_meters) || 100),
      }
    })
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

  function startNewGeofence(type = drawMode || 'circle') {
    const { form: nextForm, center } = draftFormForBranch(selectedBranchId, selectedBranch, geofences, geofenceCenter(form))
    const nextType = type === 'polygon' ? 'polygon' : 'circle'
    setForm({
      ...nextForm,
      id: null,
      type: nextType,
      status: 'draft',
      is_active: false,
      polygon_draft_points: [],
      polygon_geojson: nextType === 'polygon' ? polygonFeatureAroundCenter(center, 80) : null,
    })
    setDrawMode(nextType)
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
            onClick={() => startNewGeofence(drawMode)}
          >
            <Plus className="size-4" />
            Add geofence
          </Button>
        </div>
      </div>

      <div className="flex flex-wrap gap-2 rounded-lg border border-slate-200 bg-white p-2 shadow-sm dark:border-border dark:bg-card">
        {[
          ['setup', 'Setup'],
          ...(canViewLiveMonitoring ? [['live_monitoring', 'Live Monitoring']] : []),
        ].map(([key, label]) => (
          <button
            key={key}
            type="button"
            onClick={() => setActiveTab(key)}
            className={cn(
              'rounded-md px-4 py-2 text-sm font-semibold transition',
              activeTab === key
                ? 'bg-[#f04414] text-white shadow-sm'
                : 'text-slate-600 hover:bg-slate-100 hover:text-slate-950 dark:text-muted-foreground dark:hover:bg-muted dark:hover:text-foreground',
            )}
          >
            {label}
          </button>
        ))}
      </div>

      {activeTab === 'live_monitoring' && canViewLiveMonitoring ? (
        <AdminGeofenceLiveMonitor />
      ) : (
        <>
      <div className="grid gap-4 xl:grid-cols-[minmax(560px,1fr)_minmax(330px,380px)]">
        <section className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-border dark:bg-card">
          <div className="border-b border-slate-200 p-3 dark:border-border">
            <div className="flex flex-wrap items-center gap-3">
              <SegmentButton disabled={!shapeEditable} active={drawMode === 'circle'} icon={Circle} onClick={() => switchGeofenceType('circle')}>
                Circle
              </SegmentButton>
              <SegmentButton disabled={!shapeEditable} active={drawMode === 'polygon'} icon={Pentagon} onClick={() => switchGeofenceType('polygon')}>
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
                  placeholder={shapeEditable ? 'Search address, building, street, barangay, or landmark' : 'Disable current geofence to move pin'}
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
            onSelectMapillaryImage={selectMapillaryImage}
          />
          <MapillaryStreetviewPanel
            image={mapillaryImage}
            loading={mapillaryLoading}
            error={mapillaryError}
            target={streetviewTarget}
            onOpenTarget={() => openMapillaryStreetview(streetviewTarget, form?.name || selectedBranch?.branch_name || 'selected geofence')}
            onClose={() => {
              setMapillaryImage(null)
              setMapillaryError('')
            }}
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
                  <SelectBox className="mt-1" value={form.type} disabled={!shapeEditable} onChange={(e) => switchGeofenceType(e.target.value)}>
                    <option value="circle">Circle radius</option>
                    <option value="polygon">Polygon</option>
                  </SelectBox>
                </Label>
                <Label className="text-xs font-semibold text-slate-700 dark:text-muted-foreground">
                  Priority
                  <Input className="mt-1 h-9 rounded-md border-slate-200 text-xs shadow-sm dark:border-border" type="number" min="1" value={form.priority} onChange={(e) => setForm((s) => ({ ...s, priority: e.target.value }))} />
                </Label>
              </div>

              <Label className="text-xs font-semibold text-slate-700 dark:text-muted-foreground">
                Device scope
                <SelectBox className="mt-1" value={form.device_scope || ''} onChange={(e) => setForm((s) => ({ ...s, device_scope: e.target.value }))}>
                  <option value="" disabled>Select device scope</option>
                  {DEVICE_SCOPE_OPTIONS.map((option) => (
                    <option key={option.value} value={option.value}>{option.label}</option>
                  ))}
                </SelectBox>
              </Label>

              {form.type === 'circle' ? (
                <>
                  <div className="grid grid-cols-2 gap-3">
                    <Label className="text-xs font-semibold text-slate-700 dark:text-muted-foreground">
                      Latitude
                      <Input className="mt-1 h-9 rounded-md border-slate-200 text-xs shadow-sm dark:border-border" type="number" value={form.center_lat} disabled={!shapeEditable} onChange={(e) => updateManualCircleCoordinate('center_lat', e.target.value)} />
                    </Label>
                    <Label className="text-xs font-semibold text-slate-700 dark:text-muted-foreground">
                      Longitude
                      <Input className="mt-1 h-9 rounded-md border-slate-200 text-xs shadow-sm dark:border-border" type="number" value={form.center_lng} disabled={!shapeEditable} onChange={(e) => updateManualCircleCoordinate('center_lng', e.target.value)} />
                    </Label>
                  </div>
                  <div className="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-[11px] text-slate-600 dark:border-border dark:bg-muted/30 dark:text-muted-foreground">
                    Saved pin: Lat <span className="font-mono font-semibold">{form.center_lat || 'not set'}</span> - Lng <span className="font-mono font-semibold">{form.center_lng || 'not set'}</span> - Radius <span className="font-mono font-semibold">{form.radius_meters || 0}m</span>
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
                    <span>{polygonFormPointCount(form)} polygon points</span>
                    <div className="flex gap-2">
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        className="h-8 rounded-md px-2"
                        disabled={!shapeEditable}
                        onClick={() => {
                          const center = validLatLngPair([form.center_lat, form.center_lng])
                            || validLatLngPair(geofenceCenter(form))
                            || validLatLngPair(branchLocation(selectedBranch))
                            || DEFAULT_CENTER
                          setForm((s) => ({
                            ...s,
                            type: 'polygon',
                            center_lat: center[0],
                            center_lng: center[1],
                            polygon_draft_points: [],
                            polygon_geojson: polygonFeatureAroundCenter(center, Math.max(30, Number(s.radius_meters) || 80)),
                          }))
                        }}
                      >
                        Starter
                      </Button>
                      <Button type="button" variant="outline" size="sm" className="h-8 gap-2 rounded-md" disabled={!shapeEditable} onClick={() => setForm((s) => ({ ...s, polygon_draft_points: [], polygon_geojson: null }))}>
                        <Trash2 className="size-3.5" />
                        Clear
                      </Button>
                    </div>
                  </div>
                  <p className="mt-2 leading-relaxed">
                    Click the map to add points. Drag handles to reshape the boundary. Use Starter to create an editable polygon around the current branch or pin.
                  </p>
                </div>
              )}

              <div className="grid grid-cols-2 gap-3">
                <Label className="text-xs font-semibold text-slate-700 dark:text-muted-foreground">
                  Accuracy threshold
                  <Input className="mt-1 h-9 rounded-md border-slate-200 text-xs shadow-sm dark:border-border" type="number" value={form.accuracy_threshold_meters} onChange={(e) => setForm((s) => ({ ...s, accuracy_threshold_meters: e.target.value }))} />
                </Label>
                <Label className="text-xs font-semibold text-slate-700 dark:text-muted-foreground">
                  Status
                  <SelectBox className="mt-1" value={form.status || 'draft'} onChange={(e) => setForm((s) => ({ ...s, status: e.target.value, is_active: e.target.value === 'active' }))}>
                    <option value="draft">Draft</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                  </SelectBox>
                </Label>
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
                  {form.status === 'draft' ? 'Save draft' : 'Save geofence'}
                </Button>
                <Button variant="outline" className="h-9 gap-2 rounded-md border-slate-200 text-xs shadow-sm" onClick={() => saveGeofence({ addAnother: true })} disabled={saving || !selectedBranchId || form.status !== 'draft'}>
                  <Plus className="size-4" />
                  Save draft & add another
                </Button>
                {form.id ? (
                  <Button variant="outline" className="h-9 gap-2 rounded-md border-slate-200 text-xs shadow-sm" onClick={toggleCurrentGeofence}>
                    <Power className="size-4" />
                    {form.status === 'active' ? 'Set current geofence inactive' : 'Activate current geofence'}
                  </Button>
                ) : null}
                {form.id ? (
                  <Button
                    type="button"
                    variant="destructive"
                    className="h-9 gap-2 rounded-md text-xs shadow-sm"
                    onClick={() => deleteGeofence(form)}
                    disabled={deletingGeofenceId != null}
                  >
                    <Trash2 className="size-4" />
                    {String(deletingGeofenceId) === String(form.id) ? 'Deleting geofence...' : 'Delete current geofence'}
                  </Button>
                ) : null}
              </div>
            </div>
          </div>

        </section>
      </div>

      <section className="grid gap-4">
        <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_340px]">
        <div className="min-w-0 rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-border dark:bg-card">
            <div className="flex items-center justify-between gap-3">
              <div>
                <h2 className="text-base font-bold text-slate-950 dark:text-foreground">Branch geofences</h2>
                <p className="text-xs text-slate-500 dark:text-muted-foreground">Select a saved geofence or add a separate draft.</p>
              </div>
              <Badge variant="secondary" className="rounded-md bg-slate-100 text-slate-700 hover:bg-slate-100 dark:bg-muted dark:text-muted-foreground">
                {geofences.length}
              </Badge>
            </div>
            <div className="mt-3 overflow-x-auto">
              {geofences.length === 0 ? (
                <div className="rounded-md border border-dashed border-slate-200 p-3 text-xs text-slate-500 dark:border-border dark:text-muted-foreground">
                  No saved geofences yet. Use Add geofence to create a draft.
                </div>
              ) : (
                <table className="w-full min-w-[1050px] text-left text-xs">
                  <thead className="bg-slate-50 text-[10px] uppercase text-slate-600 dark:bg-muted/40 dark:text-muted-foreground">
                    <tr>
                      {['Name', 'Type', 'Device Scope', 'Radius', 'Status', 'Accuracy Threshold', 'Enforcement', 'Last Updated', 'Actions'].map((heading) => (
                        <th key={heading} className="px-3 py-2 font-bold">{heading}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-200 dark:divide-border">
                    {geofences.map((geofence) => {
                      const selected = String(form.id || '') === String(geofence.id)
                      const status = geofence.status || (normalizeBoolean(geofence.is_active) ? 'active' : 'inactive')
                      const deleting = String(deletingGeofenceId || '') === String(geofence.id)
                      return (
                        <tr key={geofence.id} className={cn('hover:bg-orange-50/50 dark:hover:bg-orange-500/5', selected && 'bg-orange-50 dark:bg-orange-500/10')}>
                          <td className="px-3 py-2 font-bold">
                            <button type="button" className="text-left hover:text-[#f04414]" onClick={() => selectGeofence(geofence)}>{geofence.name || 'Geofence'}</button>
                          </td>
                          <td className="px-3 py-2 capitalize">{geofence.type}</td>
                          <td className="px-3 py-2 font-semibold" style={{ color: deviceScopeMeta(geofence.device_scope).color }}>{deviceScopeMeta(geofence.device_scope).mapLabel}</td>
                          <td className="px-3 py-2">{geofence.type === 'circle' ? `${Number(geofence.radius_meters || 0)}m` : '-'}</td>
                          <td className="px-3 py-2 capitalize">{status}</td>
                          <td className="px-3 py-2">{Number(geofence.accuracy_threshold_meters || 0)}m</td>
                          <td className="px-3 py-2">{geofence.enforcement_mode || 'enforce'}</td>
                          <td className="px-3 py-2">{formatDate(geofence.updated_at)}</td>
                          <td className="px-3 py-2">
                            <div className="flex gap-2">
                              <Button type="button" size="sm" variant="outline" className="h-7 px-2 text-[11px]" onClick={() => selectGeofence(geofence)} disabled={deleting}>Edit</Button>
                              <Button type="button" size="sm" variant="outline" className="h-7 px-2 text-[11px]" onClick={() => toggleGeofenceActive(geofence)} disabled={deleting}>
                                {status === 'active' ? 'Inactive' : 'Activate'}
                              </Button>
                              <Button type="button" size="sm" variant="destructive" className="h-7 px-2 text-[11px]" onClick={() => deleteGeofence(geofence)} disabled={deletingGeofenceId != null}>
                                <Trash2 className="size-3.5" />
                                {deleting ? 'Deleting' : 'Delete'}
                              </Button>
                            </div>
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              )}
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
                  {branchEmployees.map((employee) => {
                    const exempt = geofenceExemptEmployeeIds.some((id) => String(id) === String(employee.id))
                    return (
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
                        <div className="flex flex-col items-end gap-1">
                          <Badge variant={employee.active ? 'default' : 'secondary'} className={cn('h-6 rounded-md', employee.active ? 'bg-emerald-600' : 'bg-slate-200 text-slate-700 hover:bg-slate-200')}>
                            {employee.active ? 'Active' : 'Inactive'}
                          </Badge>
                          {exempt ? (
                            <Badge variant="secondary" className="h-6 rounded-md bg-orange-100 text-orange-700 hover:bg-orange-100 dark:bg-orange-500/10 dark:text-orange-200">
                              Geofence exempt
                            </Badge>
                          ) : null}
                        </div>
                      </div>
                    )
                  })}
                </div>
              )}
            </div>
          </div>
        </div>

        <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-border dark:bg-card">
          <div className="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h2 className="text-base font-bold text-slate-950 dark:text-foreground">Branch settings</h2>
              <p className="text-xs text-slate-500 dark:text-muted-foreground">Rules used when employees clock in/out at this branch.</p>
            </div>
          </div>
          <div className="mt-3 grid gap-3 lg:grid-cols-2 2xl:grid-cols-4">
            <div className="rounded-lg border border-slate-200 bg-slate-50/70 p-3 dark:border-border dark:bg-muted/20">
              <h3 className="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-muted-foreground">Enforcement</h3>
              <div className="mt-3 grid gap-3">
                <Label className="text-xs font-semibold text-slate-700 dark:text-muted-foreground">
                  Mode
                  <SelectBox className="mt-1" value={selectedBranch?.geofence_enforcement_mode || 'enforce'} onChange={(e) => updateSettings({ geofence_enforcement_mode: e.target.value })}>
                    <option value="enforce">Enforce</option>
                    <option value="warn_only">Warn only</option>
                    <option value="disabled">Disabled</option>
                  </SelectBox>
                </Label>
                <div className="flex h-10 items-center justify-between gap-3 rounded-md border border-slate-200 bg-white px-3 dark:border-border dark:bg-background">
                  <span className="text-xs font-semibold text-slate-700 dark:text-muted-foreground">Allow without geofence</span>
                  <div className="flex items-center gap-2">
                    <span className="text-[11px] text-slate-500">{allowedWithoutGeofenceBranchIds.some((id) => String(id) === String(selectedBranchId)) ? 'Allowed' : 'Required'}</span>
                    <Switch
                      checked={allowedWithoutGeofenceBranchIds.some((id) => String(id) === String(selectedBranchId))}
                      disabled={bypassSaving || !selectedBranchId}
                      onCheckedChange={(checked) => toggleAllowedWithoutGeofenceBranch(selectedBranchId, checked)}
                    />
                  </div>
                </div>
              </div>
            </div>

            <div className="rounded-lg border border-slate-200 bg-slate-50/70 p-3 dark:border-border dark:bg-muted/20">
              <h3 className="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-muted-foreground">Accuracy</h3>
              <div className="mt-3 grid gap-3">
                <div className="grid gap-3 sm:grid-cols-2">
                  <Label className="text-xs font-semibold text-slate-700 dark:text-muted-foreground">
                    Mode
                    <SelectBox className="mt-1" value={selectedBranch?.geofence_accuracy_policy || 'balanced'} onChange={(e) => updateSettings({ geofence_accuracy_policy: e.target.value })}>
                      <option value="strict">Strict</option>
                      <option value="balanced">Balanced</option>
                      <option value="lenient">Lenient</option>
                    </SelectBox>
                  </Label>
                  <Label className="text-xs font-semibold text-slate-700 dark:text-muted-foreground">
                    Buffer
                    <SelectBox className="mt-1" value={selectedBranch?.geofence_accuracy_buffer_mode || 'strict'} onChange={(e) => updateSettings({ geofence_accuracy_buffer_mode: e.target.value })}>
                      <option value="strict">Strict: no buffer</option>
                      <option value="balanced">Balanced: + max 25m</option>
                      <option value="lenient">Lenient: + max 50m</option>
                    </SelectBox>
                  </Label>
                </div>
                <div className="grid gap-3 sm:grid-cols-2">
                  <Label className="text-xs font-semibold text-slate-700 dark:text-muted-foreground">
                    Mobile threshold
                    <Input className="mt-1 h-9 rounded-md border-slate-200 text-xs shadow-sm dark:border-border" type="number" min="5" max="5000" value={selectedBranch?.geofence_mobile_accuracy_threshold_meters ?? 50} onChange={(e) => updateSettings({ geofence_mobile_accuracy_threshold_meters: Number(e.target.value) })} />
                  </Label>
                  <Label className="text-xs font-semibold text-slate-700 dark:text-muted-foreground">
                    Desktop threshold
                    <Input className="mt-1 h-9 rounded-md border-slate-200 text-xs shadow-sm dark:border-border" type="number" min="5" max="5000" value={selectedBranch?.geofence_desktop_accuracy_threshold_meters ?? 100} onChange={(e) => updateSettings({ geofence_desktop_accuracy_threshold_meters: Number(e.target.value) })} />
                  </Label>
                </div>
              </div>
            </div>

            <div className="rounded-lg border border-slate-200 bg-slate-50/70 p-3 dark:border-border dark:bg-muted/20">
              <h3 className="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-muted-foreground">Sampling</h3>
              <div className="mt-3 grid gap-3">
                <div className="grid grid-cols-3 gap-3">
                  <Label className="text-xs font-semibold text-slate-700 dark:text-muted-foreground">
                    Min
                    <Input className="mt-1 h-9 rounded-md border-slate-200 text-xs shadow-sm dark:border-border" type="number" min="1" max="5" value={selectedBranch?.geofence_minimum_samples ?? 3} onChange={(e) => updateSettings({ geofence_minimum_samples: Number(e.target.value) })} />
                  </Label>
                  <Label className="text-xs font-semibold text-slate-700 dark:text-muted-foreground">
                    Max
                    <Input className="mt-1 h-9 rounded-md border-slate-200 text-xs shadow-sm dark:border-border" type="number" min="1" max="5" value={selectedBranch?.geofence_maximum_samples ?? 5} onChange={(e) => updateSettings({ geofence_maximum_samples: Number(e.target.value) })} />
                  </Label>
                  <Label className="text-xs font-semibold text-slate-700 dark:text-muted-foreground">
                    Timeout
                    <Input className="mt-1 h-9 rounded-md border-slate-200 text-xs shadow-sm dark:border-border" type="number" min="5" max="30" value={selectedBranch?.geofence_sample_timeout_seconds ?? 15} onChange={(e) => updateSettings({ geofence_sample_timeout_seconds: Number(e.target.value) })} />
                  </Label>
                </div>
                <Label className="text-xs font-semibold text-slate-700 dark:text-muted-foreground">
                  Poor GPS accuracy
                  <SelectBox className="mt-1" value={selectedBranch?.geofence_poor_accuracy_action || 'block'} onChange={(e) => updateSettings({ geofence_poor_accuracy_action: e.target.value })}>
                    <option value="block">Block</option>
                    <option value="warn">Warn</option>
                  </SelectBox>
                </Label>
              </div>
            </div>

            <div className="rounded-lg border border-slate-200 bg-slate-50/70 p-3 dark:border-border dark:bg-muted/20">
              <h3 className="text-xs font-bold uppercase tracking-wide text-slate-500 dark:text-muted-foreground">Validation</h3>
              <div className="mt-3 grid gap-3">
                <div className="flex h-10 items-center justify-between gap-3 rounded-md border border-slate-200 bg-white px-3 dark:border-border dark:bg-background">
                  <span className="text-xs font-semibold text-slate-700 dark:text-muted-foreground">Cross-branch use</span>
                  <Switch checked={Boolean(selectedBranch?.geofence_allow_cross_branch)} onCheckedChange={(checked) => updateSettings({ geofence_allow_cross_branch: checked })} />
                </div>
                <div className="flex h-10 items-center justify-between gap-3 rounded-md border border-slate-200 bg-white px-3 dark:border-border dark:bg-background">
                  <span className="text-xs font-semibold text-slate-700 dark:text-muted-foreground">Backend validation</span>
                  <Switch checked={selectedBranch?.geofence_require_backend_validation !== false} onCheckedChange={(checked) => updateSettings({ geofence_require_backend_validation: checked })} />
                </div>
              </div>
            </div>

            <div className="rounded-lg border border-slate-200 bg-slate-50 p-3 text-xs text-slate-600 dark:border-border dark:bg-muted/30 dark:text-muted-foreground lg:col-span-2 2xl:col-span-4">
              <Button type="button" variant="outline" className="h-9 w-full gap-2 rounded-md bg-white text-xs shadow-sm dark:bg-background" onClick={testCurrentLocation} disabled={locationTestLoading || !selectedBranchId}>
                <RefreshCw className={cn('size-4', locationTestLoading && 'animate-spin')} />
                Test Current Location
              </Button>
              {locationTestResult?.error ? (
                <p className="mt-2 text-[11px] text-red-600 dark:text-red-300">{locationTestResult.error}</p>
              ) : locationTestResult ? (
                <div className="mt-2 grid gap-1 text-[11px]">
                  <div>Lat <span className="font-mono">{Number(locationTestResult.location?.latitude || 0).toFixed(6)}</span> · Lng <span className="font-mono">{Number(locationTestResult.location?.longitude || 0).toFixed(6)}</span></div>
                  <div>Accuracy <span className="font-semibold">{Math.round(Number(locationTestResult.location?.accuracy_meters || 0))}m</span> · Best of <span className="font-semibold">{locationTestResult.location?.sampled_readings_count || 1}</span> samples · Device <span className="font-semibold">{locationTestResult.location?.device_type || 'desktop'}</span></div>
                  <div>Distance <span className="font-semibold">{Math.round(Number(locationTestResult.result?.distance_meters || 0))}m</span> · Radius <span className="font-semibold">{locationTestResult.result?.radius_meters ?? locationTestResult.result?.matched_geofence?.radius_meters ?? 'n/a'}m</span> · Status <span className={cn('font-bold', locationTestResult.result?.allowed ? 'text-emerald-700 dark:text-emerald-300' : 'text-red-600 dark:text-red-300')}>{locationTestResult.result?.status || 'outside'}</span></div>
                  {!locationTestResult.result?.allowed && (
                    <p className="text-amber-700 dark:text-amber-300">Desktop/laptop location may be inaccurate. Turn on WiFi, enable Windows/Mac location services, and avoid VPN.</p>
                  )}
                </div>
              ) : null}
            </div>
          </div>
        </div>
      </section>

      <section className="min-w-0 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-border dark:bg-card">
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

      <section className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-border dark:bg-card">
        <div className="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 className="text-base font-bold text-slate-950 dark:text-foreground">Geofencing Module</h2>
            <p className="max-w-3xl text-xs leading-relaxed text-slate-500 dark:text-muted-foreground">
              One master switch for attendance geofencing. When turned off, clock in/out will not ask for location and the backend will skip all geofence validation.
            </p>
          </div>
          <div className="flex items-center gap-3 rounded-full border border-slate-200 bg-slate-50 px-4 py-2 text-xs font-bold dark:border-border dark:bg-muted/40">
            <span className={geofenceModuleEnabled ? 'text-emerald-700 dark:text-emerald-300' : 'text-slate-500'}>
              {geofenceModuleEnabled ? 'Enabled' : 'Disabled'}
            </span>
            <Switch
              checked={geofenceModuleEnabled}
              disabled={geofenceModuleSaving}
              onCheckedChange={saveGeofenceModuleEnabled}
            />
          </div>
        </div>
        {!geofenceModuleEnabled ? (
          <div className="border-t border-orange-200 bg-orange-50 px-4 py-3 text-xs font-semibold text-orange-700 dark:border-orange-500/30 dark:bg-orange-500/10 dark:text-orange-200">
            Geofencing is currently off. Employees can clock in/out without location permission prompts.
          </div>
        ) : null}
      </section>

      <div className="grid gap-4 xl:grid-cols-2">
        <section className="min-w-0 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-border dark:bg-card">
          <div className="flex flex-col gap-3 border-b border-slate-200 p-4 dark:border-border sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h2 className="text-base font-bold text-slate-950 dark:text-foreground">Employee Geofence Exemptions</h2>
              <p className="text-xs text-slate-500 dark:text-muted-foreground">Employees who can clock in/out without location validation.</p>
            </div>
            <div className="shrink-0 rounded-full border border-orange-200 bg-orange-50 px-3 py-1.5 text-xs font-bold text-orange-700 dark:border-orange-500/30 dark:bg-orange-500/10 dark:text-orange-200">
              {geofenceExemptEmployeeIds.length} exempt
            </div>
          </div>
          <div className="border-b border-slate-200 p-3 dark:border-border">
            <div className="relative w-full">
              <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
              <Input
                value={geofenceExemptionSearch}
                onChange={(event) => setGeofenceExemptionSearch(event.target.value)}
                placeholder="Search employee, number, branch..."
                className="h-10 rounded-md border-slate-200 pl-9 text-xs shadow-sm placeholder:text-slate-500 focus-visible:ring-orange-100 dark:border-border"
              />
            </div>
          </div>
          <div className="max-h-[520px] space-y-2 overflow-auto p-3">
            {filteredGeofenceExemptionEmployees.length === 0 ? (
              <div className="rounded-lg border border-dashed border-slate-200 px-4 py-10 text-center text-sm text-slate-500 dark:border-border">
                No employees found.
              </div>
            ) : (
              filteredGeofenceExemptionEmployees.map((employee) => {
                const exempt = geofenceExemptEmployeeIds.some((id) => String(id) === String(employee.id))
                return (
                  <div
                    key={employee.id}
                    className={cn(
                      'flex items-center justify-between gap-3 rounded-lg border p-3 transition',
                      exempt
                        ? 'border-orange-200 bg-orange-50/70 dark:border-orange-500/30 dark:bg-orange-500/10'
                        : 'border-slate-200 bg-white hover:bg-slate-50 dark:border-border dark:bg-card dark:hover:bg-muted/40',
                    )}
                  >
                    <div className="flex min-w-0 items-center gap-3">
                      <Avatar className="size-10 shrink-0 rounded-full border border-slate-200 bg-slate-100 dark:border-border dark:bg-muted">
                        <AvatarImage src={employee.profile_image_url || undefined} alt="" className="object-cover" />
                        <AvatarFallback className="rounded-full bg-orange-100 text-xs font-bold text-orange-700 dark:bg-orange-500/10 dark:text-orange-200">
                          {employeeInitials(employee.name)}
                        </AvatarFallback>
                      </Avatar>
                      <div className="min-w-0">
                        <div className="truncate text-sm font-bold text-slate-950 dark:text-foreground">{employee.name}</div>
                        <div className="truncate text-[11px] text-slate-500 dark:text-muted-foreground">
                          {employee.employee_number || 'No number'} - {employee.branch || 'No branch'}
                        </div>
                      </div>
                    </div>
                    <div className="flex shrink-0 items-center gap-3">
                      <Badge variant="secondary" className={cn('rounded-full text-[10px]', exempt ? 'bg-orange-100 text-orange-700 hover:bg-orange-100 dark:bg-orange-500/10 dark:text-orange-200' : 'bg-slate-100 text-slate-600 hover:bg-slate-100 dark:bg-muted dark:text-muted-foreground')}>
                        {exempt ? 'Exempt' : 'Required'}
                      </Badge>
                      <Switch
                        checked={exempt}
                        disabled={employeeExemptionSaving || !geofenceModuleEnabled}
                        onCheckedChange={(checked) => toggleEmployeeGeofenceExemption(employee.id, checked)}
                        aria-label={`Exempt ${employee.name} from geofence`}
                      />
                    </div>
                  </div>
                )
              })
            )}
          </div>
        </section>

        <section className="min-w-0 overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm dark:border-border dark:bg-card">
          <div className="flex flex-col gap-3 border-b border-slate-200 p-4 dark:border-border sm:flex-row sm:items-center sm:justify-between">
            <div>
              <h2 className="text-base font-bold text-slate-950 dark:text-foreground">Attendance Without Geofence Settings</h2>
              <p className="text-xs text-slate-500 dark:text-muted-foreground">Branches where all employees can skip location validation.</p>
            </div>
            <div className="flex shrink-0 items-center gap-3 rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-bold dark:border-border dark:bg-muted/40">
              <span>{attendanceWithoutGeofenceEnabled ? 'Enabled' : 'Disabled'}</span>
              <Switch
                checked={attendanceWithoutGeofenceEnabled}
                disabled={bypassSaving || !geofenceModuleEnabled}
                onCheckedChange={(checked) => saveAttendanceWithoutGeofence(checked, allowedWithoutGeofenceBranchIds)}
              />
            </div>
          </div>
          <div className="max-h-[520px] space-y-2 overflow-auto p-3">
            {branches.map((branch) => {
              const allowed = allowedWithoutGeofenceBranchIds.some((id) => String(id) === String(branch.id))
              const effectivelyAllowed = geofenceModuleEnabled && attendanceWithoutGeofenceEnabled && allowed
              return (
                <div
                  key={branch.id}
                  className={cn(
                    'flex items-center justify-between gap-3 rounded-lg border p-3 transition',
                    effectivelyAllowed
                      ? 'border-emerald-200 bg-emerald-50/70 dark:border-emerald-500/30 dark:bg-emerald-500/10'
                      : 'border-slate-200 bg-white hover:bg-slate-50 dark:border-border dark:bg-card dark:hover:bg-muted/40',
                  )}
                >
                  <div className="min-w-0">
                    <div className="truncate text-sm font-bold text-slate-950 dark:text-foreground">{branch.branch_name}</div>
                    <div className="truncate text-[11px] text-slate-500 dark:text-muted-foreground">
                      {branch.company_name || 'No company'} - {Number(branch.active_geofences_count || 0)} active geofence{Number(branch.active_geofences_count || 0) === 1 ? '' : 's'}
                    </div>
                  </div>
                  <div className="flex shrink-0 items-center gap-3">
                    <Badge variant="secondary" className={cn('rounded-full text-[10px]', effectivelyAllowed ? 'bg-emerald-100 text-emerald-700 hover:bg-emerald-100 dark:bg-emerald-500/10 dark:text-emerald-200' : 'bg-slate-100 text-slate-600 hover:bg-slate-100 dark:bg-muted dark:text-muted-foreground')}>
                      {effectivelyAllowed ? 'Allowed' : 'Required'}
                    </Badge>
                    <Switch
                      checked={allowed}
                      disabled={bypassSaving || !geofenceModuleEnabled}
                      onCheckedChange={(checked) => toggleAllowedWithoutGeofenceBranch(branch.id, checked)}
                      aria-label={`Allow ${branch.branch_name} without geofence`}
                    />
                  </div>
                </div>
              )
            })}
          </div>
        </section>
      </div>

        </>
      )}
    </div>
  )
}
