import { createElement, useCallback, useEffect, useMemo, useRef, useState } from 'react'
import L from 'leaflet'
import 'leaflet/dist/leaflet.css'
import { Laptop, LocateFixed, MapPin, Monitor, RefreshCw, ScanLine, Smartphone, Tablet, Trash2 } from 'lucide-react'
import {
  getAdminGeofencing,
  getGeofenceLiveMonitorBoundaries,
  getGeofenceLiveMonitorEvents,
  getGeofenceLiveMonitorSummary,
} from '@/api'
import { useAuth } from '@/contexts/AuthContext'
import { getRealtimeEcho } from '@/lib/realtime'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Switch } from '@/components/ui/switch'
import { Badge } from '@/components/ui/badge'
import { cn } from '@/lib/utils'

const DEFAULT_CENTER = [7.0731, 125.6128]
const STATUS_COLORS = {
  inside: '#22c55e',
  warning: '#eab308',
  outside: '#ef4444',
  failed: '#ef4444',
  skipped: '#64748b',
}
const CLOCK_IN_COLOR = '#22c55e'
const CLOCK_OUT_COLOR = '#2563eb'
const DEVICE_ICONS = {
  mobile: Smartphone,
  tablet: Tablet,
  laptop: Laptop,
  desktop: Monitor,
  kiosk: ScanLine,
}

function todayString() {
  return new Date().toISOString().slice(0, 10)
}

function normalizeEvent(event) {
  const lat = Number(event?.lat ?? event?.latitude)
  const lng = Number(event?.lng ?? event?.longitude)
  if (!Number.isFinite(lat) || !Number.isFinite(lng)) return null
  return {
    ...event,
    event_id: event?.event_id ?? event?.id ?? `${lat}:${lng}:${event?.time ?? Date.now()}`,
    lat,
    lng,
    clock_type: event?.clock_type === 'clock_out' || event?.clock_type === 'out' ? 'clock_out' : 'clock_in',
    geofence_status: String(event?.geofence_status || event?.status || 'inside').toLowerCase(),
    device_type: String(event?.device_type || 'desktop').toLowerCase(),
  }
}

function eventColor(event) {
  if (event.geofence_status === 'inside') {
    return event.clock_type === 'clock_out' ? CLOCK_OUT_COLOR : CLOCK_IN_COLOR
  }
  return STATUS_COLORS[event.geofence_status] || STATUS_COLORS.outside
}

function deviceLabel(value) {
  const type = String(value || 'desktop').toLowerCase()
  return type.charAt(0).toUpperCase() + type.slice(1)
}

function clockLabel(value) {
  return value === 'clock_out' ? 'Out' : 'In'
}

function methodLabel(value) {
  return value || 'Face'
}

function branchOptionLabel(branch) {
  const name = branch?.branch_name || branch?.name || 'Branch'
  const company = branch?.company_name || 'Unknown company'
  return `${name} — ${company}`
}

function markerIcon(event, highlighted = false) {
  const color = eventColor(event)
  const size = highlighted ? 14 : 10
  const border = highlighted ? 3 : 2
  return L.divIcon({
    className: '',
    html: `
      <span style="display:block;width:${size}px;height:${size}px;border-radius:9999px;background:${color};border:${border}px solid white;box-shadow:0 ${highlighted ? 4 : 2}px ${highlighted ? 12 : 8}px rgba(15,23,42,${highlighted ? '.45' : '.35'})"></span>
    `,
    iconSize: [size, size],
    iconAnchor: [size / 2, size / 2],
    popupAnchor: [0, -6],
  })
}

function branchPinIcon(label) {
  const text = String(label || 'Branch')
  return L.divIcon({
    className: '',
    html: `
      <div style="display:flex;align-items:center;gap:6px;white-space:nowrap;font-family:Inter,system-ui,sans-serif;font-size:10px;font-weight:700;color:#111827;text-shadow:0 1px 0 #fff">
        <span style="display:flex;align-items:center;justify-content:center;width:24px;height:24px;border-radius:9999px;background:#f97316;color:white;border:2px solid white;box-shadow:0 4px 12px rgba(15,23,42,.24)">⌖</span>
        <span>${text}</span>
      </div>
    `,
    iconSize: [190, 26],
    iconAnchor: [12, 24],
    popupAnchor: [0, -18],
  })
}

function popupHtml(event) {
  const rows = [
    ['Employee', event.employee_name],
    ['Employee #', event.employee_number],
    ['Company', event.company_name],
    ['Branch', event.branch_name],
    ['Department', event.department],
    ['Clock type', clockLabel(event.clock_type)],
    ['Device type', deviceLabel(event.device_type)],
    ['Browser', event.browser || methodLabel(event.method)],
    ['Latitude', event.lat],
    ['Longitude', event.lng],
    ['Accuracy meters', event.accuracy_meters ?? event.accuracy],
    ['Distance from geofence', event.distance_meters],
    ['Geofence status', event.geofence_status],
    ['Matched geofence', event.matched_geofence],
    ['Time', event.time],
    ['Failure reason', event.failure_reason],
  ]
  return `
    <div style="min-width:260px;font-family:Inter,system-ui,sans-serif">
      <strong style="display:block;margin-bottom:8px">${event.employee_name || 'Attendance event'}</strong>
      ${rows.map(([label, value]) => `
        <div style="display:flex;gap:8px;justify-content:space-between;border-top:1px solid #e5e7eb;padding:4px 0;font-size:12px">
          <span style="color:#64748b">${label}</span>
          <span style="max-width:150px;text-align:right;color:#0f172a">${value ?? '-'}</span>
        </div>
      `).join('')}
    </div>
  `
}

function passesClientToggles(event, toggles) {
  if (!toggles.showOutsideAttempts && ['outside', 'failed'].includes(event.geofence_status)) return false
  if (toggles.insideOnly && event.geofence_status !== 'inside') return false
  if (toggles.outsideOnly && !['outside', 'failed'].includes(event.geofence_status)) return false
  return true
}

function uniqueById(items) {
  const map = new Map()
  items.forEach((item) => map.set(String(item.event_id), item))
  return [...map.values()]
}

function timeOnly(value) {
  const raw = value || ''
  const parsed = new Date(raw)
  if (!Number.isNaN(parsed.getTime())) {
    return parsed.toLocaleTimeString('en-PH', { hour: '2-digit', minute: '2-digit', hour12: true })
  }
  const match = String(raw).match(/\b(\d{1,2}:\d{2})(?::\d{2})?\b/)
  return match ? match[1] : '-'
}

function dateTimeLabel(value) {
  const raw = value || ''
  const parsed = new Date(raw)
  if (!Number.isNaN(parsed.getTime())) {
    return parsed.toLocaleString('en-PH', {
      month: '2-digit',
      day: '2-digit',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      hour12: true,
    })
  }
  return String(raw || '-')
}

function employeeInitials(name) {
  return String(name || '?')
    .trim()
    .split(/\s+/)
    .map((part) => part[0])
    .join('')
    .toUpperCase()
    .slice(0, 2) || '?'
}

function eventTitle(event) {
  if (event.geofence_status === 'warning') return 'Accuracy Warning'
  if (event.geofence_status === 'skipped') return 'Skipped / Disabled'
  if (['outside', 'failed'].includes(event.geofence_status)) return 'Outside Geofence'
  return event.clock_type === 'clock_out' ? 'Clock Out (Inside)' : 'Clock In (Inside)'
}

function eventSubtitle(event) {
  if (event.geofence_status === 'warning') return 'Low GPS accuracy'
  if (event.geofence_status === 'skipped') return 'Geofence skipped'
  if (['outside', 'failed'].includes(event.geofence_status)) return 'Outside allowed area'
  return 'Within geofence'
}

function statusMeta(event) {
  if (event.geofence_status === 'warning') {
    return { label: 'Warning', className: 'bg-amber-50 text-amber-700 ring-amber-100' }
  }
  if (['outside', 'failed'].includes(event.geofence_status)) {
    return { label: 'Failed', className: 'bg-red-50 text-red-600 ring-red-100' }
  }
  return { label: 'Success', className: 'bg-emerald-50 text-emerald-700 ring-emerald-100' }
}

function accuracyLabel(event) {
  const value = event.accuracy_meters ?? event.accuracy
  if (value == null || value === '') return '—'
  const number = Number(value)
  return Number.isFinite(number) ? `${Math.round(number)}m` : String(value)
}

export default function AdminGeofenceLiveMonitor() {
  const { user } = useAuth()
  const mapElRef = useRef(null)
  const mapRef = useRef(null)
  const geofenceLayerRef = useRef(null)
  const liveClockDotsLayerRef = useRef(null)
  const outsideAttemptsLayerRef = useRef(null)
  const eventMarkersRef = useRef(new Map())
  const [events, setEvents] = useState([])
  const [branches, setBranches] = useState([])
  const [summary, setSummary] = useState(null)
  const [filters, setFilters] = useState({
    company_id: '',
    branch_id: '',
    date: todayString(),
    device_type: '',
    clock_type: '',
    status: '',
    limit: 50,
  })
  const [toggles, setToggles] = useState({
    autoFollow: true,
    showBoundaries: true,
    showOutsideAttempts: true,
    insideOnly: false,
    outsideOnly: false,
  })
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState('')
  const [focusedEventId, setFocusedEventId] = useState('')

  const filteredEvents = useMemo(
    () => events.filter((event) => passesClientToggles(event, toggles)).slice(0, 300),
    [events, toggles],
  )

  const loadBranches = useCallback(async () => {
    const data = await getAdminGeofencing()
    setBranches(data.branches || [])
  }, [])

  const loadRecent = useCallback(async () => {
    setLoading(true)
    setError('')
    try {
      const [eventsData, summaryData] = await Promise.all([
        getGeofenceLiveMonitorEvents({ ...filters, limit: 50 }),
        getGeofenceLiveMonitorSummary({ date: filters.date }),
      ])
      setEvents((eventsData.events || []).map(normalizeEvent).filter(Boolean).slice(0, 50))
      setSummary(summaryData.summary || null)
    } catch (err) {
      setError(err?.message || 'Failed to load live monitor events')
    } finally {
      setLoading(false)
    }
  }, [filters])

  const loadBoundaries = useCallback(async () => {
    if (!mapRef.current) return
    if (geofenceLayerRef.current) {
      geofenceLayerRef.current.clearLayers()
    }
    if (!toggles.showBoundaries) return
    try {
      const data = await getGeofenceLiveMonitorBoundaries({
        company_id: filters.company_id,
        branch_id: filters.branch_id,
      })
      const layer = L.layerGroup()
      ;(data.boundaries || []).forEach((boundary) => {
        const color = '#f97316'
        let labelPoint = null
        if (boundary.type === 'circle' && boundary.center_lat != null && boundary.center_lng != null) {
          labelPoint = [Number(boundary.center_lat), Number(boundary.center_lng)]
          L.circle(labelPoint, {
            radius: Number(boundary.radius_meters || 0),
            color,
            fillColor: color,
            fillOpacity: 0.12,
            weight: 1.5,
            dashArray: '4 4',
          }).bindTooltip(boundary.name || 'Geofence').addTo(layer)
        } else if (boundary.type === 'polygon') {
          const coords = boundary.polygon_geojson?.geometry?.coordinates?.[0] || boundary.polygon_geojson?.coordinates?.[0]
          if (Array.isArray(coords)) {
            const latLngs = coords.map(([lng, lat]) => [Number(lat), Number(lng)]).filter(([lat, lng]) => Number.isFinite(lat) && Number.isFinite(lng))
            if (latLngs.length >= 3) {
              L.polygon(latLngs, { color, fillColor: color, fillOpacity: 0.12, weight: 1.5, dashArray: '4 4' }).bindTooltip(boundary.name || 'Geofence').addTo(layer)
              labelPoint = L.latLngBounds(latLngs).getCenter()
            }
          }
        }
        if (labelPoint) {
          L.marker(labelPoint, { icon: branchPinIcon(boundary.branch_name || boundary.name) }).addTo(layer)
        }
      })
      if (!geofenceLayerRef.current) {
        geofenceLayerRef.current = L.layerGroup().addTo(mapRef.current)
      }
      layer.eachLayer((item) => item.addTo(geofenceLayerRef.current))
    } catch {
      // Boundary display is optional; marker monitoring should continue.
    }
  }, [filters.branch_id, filters.company_id, toggles.showBoundaries])

  useEffect(() => {
    if (!mapElRef.current || mapRef.current) return
    const map = L.map(mapElRef.current, { zoomControl: true }).setView(DEFAULT_CENTER, 13)
    L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
      maxZoom: 19,
      attribution: '&copy; OpenStreetMap contributors',
    }).addTo(map)
    mapRef.current = map
    geofenceLayerRef.current = L.layerGroup().addTo(map)
    liveClockDotsLayerRef.current = L.layerGroup().addTo(map)
    outsideAttemptsLayerRef.current = L.layerGroup().addTo(map)
    return () => {
      map.remove()
      mapRef.current = null
      geofenceLayerRef.current = null
      liveClockDotsLayerRef.current = null
      outsideAttemptsLayerRef.current = null
    }
  }, [])

  useEffect(() => {
    loadBranches().catch(() => {})
  }, [loadBranches])

  useEffect(() => {
    loadRecent()
  }, [loadRecent])

  useEffect(() => {
    loadBoundaries()
  }, [loadBoundaries])

  useEffect(() => {
    const map = mapRef.current
    const liveLayer = liveClockDotsLayerRef.current
    const outsideLayer = outsideAttemptsLayerRef.current
    if (!liveLayer || !outsideLayer || !map) return
    liveLayer.clearLayers()
    outsideLayer.clearLayers()
    eventMarkersRef.current.clear()
    const markers = filteredEvents.map((event) => {
      const eventKey = String(event.event_id)
      const highlighted = focusedEventId === eventKey
      const marker = L.marker([event.lat, event.lng], { icon: markerIcon(event, highlighted) }).bindPopup(popupHtml(event))
      eventMarkersRef.current.set(eventKey, marker)
      if (['outside', 'failed'].includes(event.geofence_status)) {
        marker.addTo(outsideLayer)
      } else {
        marker.addTo(liveLayer)
      }
      return marker
    })
    if (markers.length > 0 && toggles.autoFollow && !focusedEventId) {
      const latest = filteredEvents[0]
      map.setView([latest.lat, latest.lng], Math.max(map.getZoom(), 15), { animate: true })
    } else if (markers.length > 0 && map.getZoom() <= 3) {
      map.fitBounds(L.featureGroup(markers).getBounds().pad(0.2))
    }
  }, [filteredEvents, toggles.autoFollow, focusedEventId])

  const followEventOnMap = useCallback((event) => {
    const map = mapRef.current
    if (!map || !Number.isFinite(event?.lat) || !Number.isFinite(event?.lng)) return
    const eventKey = String(event.event_id)
    setFocusedEventId(eventKey)
    map.setView([event.lat, event.lng], Math.max(map.getZoom(), 16), { animate: true })
    window.setTimeout(() => {
      eventMarkersRef.current.get(eventKey)?.openPopup()
    }, 250)
  }, [])

  useEffect(() => {
    if (!user?.id) return undefined
    const echo = getRealtimeEcho()
    if (!echo) return undefined
    const channel = echo.private('geofence-monitoring.admin')
    const handler = (payload) => {
      const event = normalizeEvent(payload)
      if (!event) return
      setEvents((prev) => uniqueById([event, ...prev]).slice(0, 50))
      setSummary((prev) => prev ? {
        ...prev,
        total: Number(prev.total || 0) + 1,
        clock_in: Number(prev.clock_in || 0) + (event.clock_type === 'clock_in' ? 1 : 0),
        clock_out: Number(prev.clock_out || 0) + (event.clock_type === 'clock_out' ? 1 : 0),
        inside: Number(prev.inside || 0) + (event.geofence_status === 'inside' ? 1 : 0),
        outside: Number(prev.outside || 0) + (['outside', 'failed'].includes(event.geofence_status) ? 1 : 0),
        warning: Number(prev.warning || 0) + (event.geofence_status === 'warning' ? 1 : 0),
        skipped: Number(prev.skipped || 0) + (event.geofence_status === 'skipped' ? 1 : 0),
      } : prev)
    }
    channel.listen('.GeofenceAttendanceEventCreated', handler)
    return () => {
      channel.stopListening('.GeofenceAttendanceEventCreated')
    }
  }, [user?.id])

  function updateFilter(key, value) {
    setFilters((prev) => ({ ...prev, [key]: value }))
  }

  function updateToggle(key, value) {
    setToggles((prev) => ({
      ...prev,
      [key]: value,
      ...(key === 'insideOnly' && value ? { outsideOnly: false } : {}),
      ...(key === 'outsideOnly' && value ? { insideOnly: false } : {}),
    }))
  }

  const selectClassName = 'h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-xs font-medium text-slate-700 shadow-sm outline-none transition focus:border-orange-300 focus:ring-2 focus:ring-orange-100'
  const inputClassName = 'h-10 rounded-md border-slate-200 bg-white text-xs font-medium text-slate-700 shadow-sm focus-visible:ring-orange-100'

  return (
    <div className="overflow-hidden rounded-xl border border-slate-200 bg-white text-slate-950 shadow-sm">
      <div className="flex flex-col gap-3 border-b border-slate-100 px-4 py-3 lg:flex-row lg:items-center lg:justify-between">
        <div className="min-w-0">
          <div className="flex items-center gap-2">
            <MapPin className="size-4 text-orange-500" />
            <h2 className="text-lg font-extrabold tracking-tight">Geofence Live Monitor</h2>
            <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-bold text-emerald-700 ring-1 ring-emerald-100">
              <span className="size-1.5 rounded-full bg-emerald-500" />
              Live
            </span>
          </div>
          <p className="mt-0.5 text-xs font-medium text-slate-500">Live clock-in/out and outside geofence attempts. Latest 50 events only.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          {summary ? (
            <Badge variant="outline" className="h-8 rounded-md border-emerald-100 bg-emerald-50 px-3 text-xs font-bold text-emerald-700 hover:bg-emerald-50">
              {summary.total} today
            </Badge>
          ) : null}
          <Badge variant="outline" className="h-8 rounded-md border-emerald-100 bg-emerald-50 px-3 text-xs font-bold text-emerald-700 hover:bg-emerald-50">
            <span className="mr-1 size-1.5 rounded-full bg-emerald-500" />
            {filteredEvents.length} dots
          </Badge>
          <Button variant="outline" size="sm" className="h-9 rounded-md border-slate-200 bg-white px-4 text-xs font-semibold shadow-sm" onClick={loadRecent} disabled={loading}>
            <RefreshCw className={cn('mr-2 size-3.5', loading && 'animate-spin')} />
            Refresh
          </Button>
          <Button variant="outline" size="sm" className="h-9 rounded-md border-slate-200 bg-white px-4 text-xs font-semibold shadow-sm" onClick={() => { setEvents([]); setFocusedEventId('') }}>
            <Trash2 className="mr-2 size-3.5" />
            Clear Dots
          </Button>
        </div>
      </div>

      {error ? <div className="mx-4 mt-3 rounded-md border border-red-100 bg-red-50 px-3 py-2 text-xs font-medium text-red-600">{error}</div> : null}

      <div className="grid gap-4 p-4 lg:grid-cols-[minmax(0,1fr)_300px]">
        <div className="relative h-[420px] overflow-hidden rounded-lg border border-slate-200 bg-slate-100 shadow-sm">
          <div ref={mapElRef} className="h-full w-full" />
          <div className="absolute left-4 top-4 z-500 rounded-lg border border-slate-200 bg-white/95 px-3 py-2 text-[11px] shadow-md backdrop-blur">
            <div className="mb-1.5 font-bold text-slate-800">Legend</div>
            {[
              ['Clock In (Inside)', CLOCK_IN_COLOR],
              ['Clock Out (Inside)', CLOCK_OUT_COLOR],
              ['Outside / Failed', STATUS_COLORS.outside],
              ['Accuracy Warning', STATUS_COLORS.warning],
              ['Skipped / Disabled', STATUS_COLORS.skipped],
            ].map(([label, color]) => (
              <div key={label} className="flex items-center gap-2 py-0.5 text-slate-600">
                <span className="size-2.5 rounded-full ring-2 ring-white" style={{ backgroundColor: color }} />
                <span className="font-medium">{label}</span>
              </div>
            ))}
          </div>
        </div>

        <aside className="space-y-3">
          <div className="grid gap-2">
            <Label className="text-xs font-bold text-slate-700">Branch</Label>
            <select className={selectClassName} value={filters.branch_id} onChange={(e) => updateFilter('branch_id', e.target.value)}>
              <option value="">All Branches</option>
              {branches
                .filter((branch) => !filters.company_id || String(branch.company_id) === String(filters.company_id))
                .map((branch) => <option key={branch.id} value={branch.id}>{branchOptionLabel(branch)}</option>)}
            </select>
          </div>

          <div className="grid gap-2">
            <Label className="text-xs font-bold text-slate-700">Date</Label>
            <Input className={inputClassName} type="date" value={filters.date} onChange={(e) => updateFilter('date', e.target.value)} />
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div className="grid gap-2">
              <Label className="text-xs font-bold text-slate-700">Device Type</Label>
              <select className={selectClassName} value={filters.device_type} onChange={(e) => updateFilter('device_type', e.target.value)}>
                <option value="">All</option>
                {['mobile', 'tablet', 'laptop', 'desktop', 'kiosk'].map((type) => <option key={type} value={type}>{deviceLabel(type)}</option>)}
              </select>
            </div>
            <div className="grid gap-2">
              <Label className="text-xs font-bold text-slate-700">Clock Type</Label>
              <select className={selectClassName} value={filters.clock_type} onChange={(e) => updateFilter('clock_type', e.target.value)}>
                <option value="">All</option>
                <option value="clock_in">Clock In</option>
                <option value="clock_out">Clock Out</option>
              </select>
            </div>
          </div>

          <div className="grid gap-2">
            <Label className="text-xs font-bold text-slate-700">Geofence Status</Label>
            <select className={selectClassName} value={filters.status} onChange={(e) => updateFilter('status', e.target.value)}>
              <option value="">All</option>
              <option value="inside">Inside</option>
              <option value="outside">Outside</option>
              <option value="warn_only">Warning</option>
              <option value="skipped">Skipped</option>
              <option value="failed">Failed</option>
              <option value="blocked">Blocked</option>
            </select>
          </div>

          {[
            ['autoFollow', 'Auto follow latest event', LocateFixed],
            ['showBoundaries', 'Show geofence boundaries', MapPin],
            ['showOutsideAttempts', 'Show outside attempts', MapPin],
            ['insideOnly', 'Inside only', MapPin],
            ['outsideOnly', 'Outside only', MapPin],
          ].map(([key, label, ControlIcon]) => (
            <div key={key} className="flex h-10 items-center justify-between rounded-md border border-slate-200 bg-white px-3 shadow-sm">
              <span className="flex items-center gap-2 text-xs font-semibold text-slate-700">{createElement(ControlIcon, { className: 'size-3.5 text-slate-400' })}{label}</span>
              <Switch checked={Boolean(toggles[key])} onCheckedChange={(checked) => updateToggle(key, checked)} />
            </div>
          ))}
        </aside>
      </div>

      <div className="overflow-x-auto border-t border-slate-100">
        <div className="min-w-[1120px]">
          <div className="grid grid-cols-[130px_220px_160px_150px_150px_90px_130px_100px_90px_80px] gap-3 border-b border-slate-100 bg-white px-4 py-3 text-[10px] font-extrabold uppercase tracking-wide text-slate-500">
            <span>Time</span>
            <span>Employee</span>
            <span>Event</span>
            <span>Company</span>
            <span>Branch</span>
            <span>Clock</span>
            <span>Device</span>
            <span>Status</span>
            <span>Accuracy</span>
            <span>Map</span>
          </div>
          <div className="max-h-[290px] overflow-y-auto">
            {filteredEvents.slice(0, 50).map((event) => {
              const StatusIcon = DEVICE_ICONS[event.device_type] || Monitor
              const status = statusMeta(event)
              const eventKey = String(event.event_id)
              const isFocused = focusedEventId === eventKey
              return (
                <div
                  key={event.event_id}
                  className={cn(
                    'grid grid-cols-[130px_220px_160px_150px_150px_90px_130px_100px_90px_80px] items-center gap-3 border-b border-slate-100 px-4 py-3 text-xs text-slate-700 last:border-b-0',
                    isFocused && 'bg-orange-50/80 ring-1 ring-inset ring-orange-200',
                  )}
                >
                  <span className="truncate font-medium text-slate-600">{dateTimeLabel(event.created_at || event.time)}</span>
                  <span className="flex min-w-0 items-center gap-3">
                    <span className="flex size-8 shrink-0 items-center justify-center rounded-full bg-orange-100 text-[10px] font-extrabold text-orange-700 ring-1 ring-orange-200">
                      {employeeInitials(event.employee_name)}
                    </span>
                    <span className="min-w-0">
                      <span className="block truncate font-bold text-slate-900">{event.employee_name || '-'}</span>
                      <span className="block truncate text-[10px] font-medium text-slate-400">{event.employee_number || 'employee@company.com'}</span>
                    </span>
                  </span>
                  <span className="flex min-w-0 items-start gap-2">
                    <span className="mt-1 size-2 shrink-0 rounded-full" style={{ backgroundColor: eventColor(event) }} />
                    <span className="min-w-0">
                      <span className="block truncate font-bold text-slate-900">{eventTitle(event)}</span>
                      <span className="block truncate text-[10px] font-medium text-slate-400">{eventSubtitle(event)}</span>
                    </span>
                  </span>
                  <span className="min-w-0">
                    <span className="block truncate font-semibold text-slate-800">{event.company_name || '-'}</span>
                  </span>
                  <span className="min-w-0">
                    <span className="block truncate font-semibold text-slate-800">{event.branch_name || '-'}</span>
                  </span>
                  <span className="font-semibold text-slate-700">{timeOnly(event.created_at || event.time)}</span>
                  <span className="flex min-w-0 items-center gap-2">
                    <StatusIcon className="size-3.5 shrink-0 text-slate-500" />
                    <span className="min-w-0">
                      <span className="block truncate font-bold text-slate-800">{deviceLabel(event.device_type)} App</span>
                      <span className="block truncate text-[10px] font-medium text-slate-400">{event.browser || event.platform || 'Browser'}</span>
                    </span>
                  </span>
                  <span className={cn('inline-flex w-fit items-center rounded-md px-2 py-1 text-[10px] font-extrabold ring-1', status.className)}>
                    {status.label}
                  </span>
                  <span className={cn(
                    'inline-flex w-fit items-center rounded-md px-2 py-1 text-[10px] font-extrabold ring-1',
                    event.geofence_status === 'warning'
                      ? 'bg-amber-50 text-amber-700 ring-amber-100'
                      : accuracyLabel(event) === '—'
                        ? 'bg-slate-50 text-slate-400 ring-slate-100'
                        : 'bg-emerald-50 text-emerald-700 ring-emerald-100',
                  )}>
                    {accuracyLabel(event)}
                  </span>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    className={cn(
                      'h-8 rounded-md px-2.5 text-[10px] font-bold shadow-sm',
                      isFocused
                        ? 'border-orange-300 bg-orange-50 text-orange-700 hover:bg-orange-50'
                        : 'border-slate-200 bg-white text-slate-700',
                    )}
                    onClick={() => followEventOnMap(event)}
                  >
                    <LocateFixed className="mr-1 size-3" />
                    Follow
                  </Button>
                </div>
              )
            })}
            {filteredEvents.length === 0 ? (
              <div className="px-4 py-10 text-center text-sm font-medium text-slate-500">No live geofence events yet.</div>
            ) : null}
          </div>
        </div>
      </div>
    </div>
  )
}
