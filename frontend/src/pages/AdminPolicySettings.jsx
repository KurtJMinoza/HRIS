import React, { useState, useEffect, useCallback, useRef, useMemo } from 'react'
import { motion as Motion } from 'framer-motion'
import {
  Loader2,
  Save,
  Copy,
  GripVertical,
  ArrowRight,
  Moon,
  Calculator,
  Layers,
  ListOrdered,
  Sparkles,
  HelpCircle,
  AlertTriangle,
  Trash2,
  Plus,
  ChevronDown,
  ChevronRight,
  Sun,
  CalendarDays,
  Star,
  CalendarHeart,
  Layers2,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Checkbox } from '@/components/ui/checkbox'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { useAuth } from '@/contexts/AuthContext'
import { cn } from '@/lib/utils'
import { FIELD_SELECT_CLASS } from '@/lib/fieldClasses'
import {
  ADMIN_FORM_DIALOG_BODY_CLASS,
  ADMIN_FORM_DIALOG_DESC_CLASS,
  ADMIN_FORM_DIALOG_FOOTER_CLASS,
  ADMIN_FORM_DIALOG_HEADER_INNER_CLASS,
  ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS,
  ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS,
  ADMIN_FORM_DIALOG_TITLE_CLASS,
  adminFormDialogContentClass,
  ADMIN_FORM_DIALOG_MAX_W_MD,
} from '@/lib/adminFormDialogStyles'
import {
  getPayPolicies,
  getPayPolicy,
  updatePayPolicy,
  duplicatePayPolicy,
  deletePayPolicy,
  createPayPolicy,
  getPayPolicyPreview,
  getPayPolicyCompanies,
} from '@/api'
import { useToast } from '@/components/ui/use-toast'
import { PayrollLogisticsPolicyShell } from '@/components/payroll/PayrollLogisticsPolicyShell'

const CONDITION_LABELS = {
  ORD: 'Ordinary Day',
  RD: 'Rest Day',
  RH: 'Regular Holiday',
  RHRD: 'Regular Holiday + Rest Day',
  SH: 'Special Holiday',
  SHRD: 'Special Holiday + Rest Day',
  DH: 'Double Holiday',
  DHRD: 'Double Holiday + Rest Day',
}

/** Lucide icons for each PH rule card (replaces emoji for a consistent admin UI). */
function ConditionRuleIcon({ ruleKey }) {
  const base = 'size-[1.125rem] @sm:size-5 shrink-0'
  const pair = 'flex items-center justify-center gap-px'
  switch (ruleKey) {
    case 'ORD':
      return <Sun className={cn(base, 'text-amber-600 dark:text-amber-400')} aria-hidden />
    case 'RD':
      return <Moon className={cn(base, 'text-slate-600 dark:text-slate-300')} aria-hidden />
    case 'RH':
      return <CalendarHeart className={cn(base, 'text-rose-600 dark:text-rose-400')} aria-hidden />
    case 'RHRD':
      return (
        <span className={pair} aria-hidden>
          <CalendarHeart className={cn(base, 'text-rose-600 dark:text-rose-400')} />
          <Moon className={cn(base, 'text-slate-600 dark:text-slate-300')} />
        </span>
      )
    case 'SH':
      return <Star className={cn(base, 'text-amber-600 dark:text-amber-400')} aria-hidden />
    case 'SHRD':
      return (
        <span className={pair} aria-hidden>
          <Star className={cn(base, 'text-amber-600 dark:text-amber-400')} />
          <Moon className={cn(base, 'text-slate-600 dark:text-slate-300')} />
        </span>
      )
    case 'DH':
      return <Layers2 className={cn(base, 'text-violet-600 dark:text-violet-400')} aria-hidden />
    case 'DHRD':
      return (
        <span className={pair} aria-hidden>
          <Layers2 className={cn(base, 'text-violet-600 dark:text-violet-400')} />
          <Moon className={cn(base, 'text-slate-600 dark:text-slate-300')} />
        </span>
      )
    default:
      return <Layers className={base} aria-hidden />
  }
}

/** Short hints for row tooltips (DOLE-style multipliers vary by day type). */
const CONDITION_HINTS = {
  ORD: 'Standard workday — baseline multipliers.',
  RD: 'Rest day premiums per Labor Code when work is performed.',
  RH: 'Regular holiday rates (e.g. 200% first 8h when worked).',
  RHRD: 'Regular holiday falling on employee rest day — stacked premiums.',
  SH: 'Special non-working holiday treatment.',
  SHRD: 'Special holiday on a rest day.',
  DH: 'Two holidays on the same day.',
  DHRD: 'Double holiday on a rest day.',
}

const PRIORITY_LABELS = {
  holiday_type: 'Holiday type',
  rest_day: 'Rest day',
  worked_flag: 'Worked / not worked',
  hour_type: 'Hour type (regular vs OT)',
}

/** Collapsible groups for multiplier “rule cards” */
const CONDITION_GROUPS = [
  {
    id: 'standard',
    label: 'Standard days',
    hint: 'Ordinary workday & rest day',
    keys: ['ORD', 'RD'],
  },
  {
    id: 'holidays',
    label: 'Holidays',
    hint: 'Regular & special holiday stacks',
    keys: ['RH', 'RHRD', 'SH', 'SHRD'],
  },
  {
    id: 'double',
    label: 'Double & compound',
    hint: 'Double holiday scenarios',
    keys: ['DH', 'DHRD'],
  },
]

/**
 * Subtle header tint behind title row on each multiplier rule card.
 * Dark: single neutral surface (avoids clashing colored 950 bands).
 */
const CONDITION_HEADER_TINT = {
  ORD: 'bg-emerald-50/80 dark:bg-muted/40',
  RD: 'bg-slate-50/90 dark:bg-muted/40',
  RH: 'bg-amber-50/80 dark:bg-muted/40',
  RHRD: 'bg-orange-50/80 dark:bg-muted/40',
  SH: 'bg-cyan-50/70 dark:bg-muted/40',
  SHRD: 'bg-teal-50/70 dark:bg-muted/40',
  DH: 'bg-violet-50/80 dark:bg-muted/40',
  DHRD: 'bg-fuchsia-50/70 dark:bg-muted/40',
}

const GROUP_MATRIX_META = {
  standard: { Icon: Sun, label: 'Baseline workdays' },
  holidays: { Icon: CalendarDays, label: 'Holiday stacks' },
  double: { Icon: Sparkles, label: 'Compound holidays' },
}

/**
 * Segmented-control tabs: no primary-colored border (light theme primary is near-black).
 * Active = raised card; inactive = muted label on soft track (see TabsList below).
 */
/** Grid layout avoids horizontal scroll; 2×2 on small screens, single row from sm+. */
const POLICY_CONFIG_TAB_LIST_CLASS =
  'grid w-full grid-cols-2 gap-1.5 rounded-xl border border-border/35 bg-muted/45 p-2 shadow-inner dark:border-border/40 dark:bg-muted/20 @sm:grid-cols-4 @sm:gap-2'

const POLICY_CONFIG_TAB_TRIGGER_CLASS = cn(
  'relative flex h-auto min-h-0 w-full min-w-0 items-center justify-center gap-2 rounded-lg border-0 px-3 py-2.5 text-sm font-medium transition-all @sm:px-5 @sm:py-3 @md:px-6',
  'shadow-none outline-none focus-visible:outline-none',
  'focus-visible:ring-2 focus-visible:ring-ring/45 focus-visible:ring-offset-0',
  'after:hidden',
  'data-[state=active]:border-transparent data-[state=active]:bg-background data-[state=active]:text-foreground data-[state=active]:shadow-sm',
  'dark:data-[state=active]:border-transparent dark:data-[state=active]:bg-card dark:data-[state=active]:shadow-md dark:data-[state=active]:shadow-black/25',
  'data-[state=inactive]:bg-transparent data-[state=inactive]:text-muted-foreground',
  'hover:data-[state=inactive]:text-foreground'
)

/** Main cards: lift + shadow (no overflow clip). */
const POLICY_CARD_HOVER =
  'transition-all duration-300 ease-[cubic-bezier(0.23,1,0.32,1)] hover:-translate-y-0.5 hover:shadow-lg hover:shadow-slate-900/[0.08] dark:hover:shadow-black/40'

/**
 * Cards/sections with overflow-hidden — box-shadow is clipped; use ring + lift instead.
 */
const POLICY_CARD_HOVER_CLIP =
  'transition-all duration-300 ease-[cubic-bezier(0.23,1,0.32,1)] hover:-translate-y-0.5 hover:ring-1 hover:ring-border/50 hover:ring-offset-0 dark:hover:ring-border/35'

/** Sticky panels: no vertical motion (layout stability). */
const POLICY_CARD_HOVER_STICKY =
  'transition-shadow duration-300 ease-out hover:shadow-md hover:shadow-slate-900/[0.06] dark:hover:shadow-black/25'

/** Small multiplier input tiles inside rule cards. */
const POLICY_INPUT_TILE_HOVER =
  'transition-all duration-200 ease-out hover:-translate-y-0.5 hover:border-border/70 hover:shadow-md hover:shadow-slate-900/[0.05] dark:hover:border-border/50 dark:hover:shadow-black/25'

function parseTimeToMinutes(hhmm) {
  if (!hhmm || typeof hhmm !== 'string') return 0
  const [h, m] = hhmm.split(':').map((x) => parseInt(x, 10))
  if (Number.isNaN(h)) return 0
  return ((h % 24) * 60 + (Number.isNaN(m) ? 0 : m)) % (24 * 60)
}

function hourFromHHMM(s) {
  return Math.floor(parseTimeToMinutes(s || '00:00') / 60)
}

function applyHourToHHMM(prev, hour) {
  const m = parseTimeToMinutes(prev || '00:00') % 60
  const h = ((hour % 24) + 24) % 24
  return `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`
}

/** 24h timeline segments as { left%, width% } for ND window (may wrap midnight). */
function ndWindowSegments(startTime, endTime) {
  const start = parseTimeToMinutes(startTime || '22:00')
  const end = parseTimeToMinutes(endTime || '06:00')
  if (start === end) return [{ left: 0, width: 0 }]
  if (start < end) {
    return [{ left: (start / 1440) * 100, width: ((end - start) / 1440) * 100 }]
  }
  return [
    { left: (start / 1440) * 100, width: ((1440 - start) / 1440) * 100 },
    { left: 0, width: (end / 1440) * 100 },
  ]
}

/** Total hours in ND window (handles overnight). */
function ndWindowDurationHours(startTime, endTime) {
  const start = parseTimeToMinutes(startTime || '22:00')
  const end = parseTimeToMinutes(endTime || '06:00')
  if (start === end) return 0
  if (start < end) return (end - start) / 60
  return (1440 - start + end) / 60
}

function formatNdDurationLabel(startTime, endTime) {
  const h = ndWindowDurationHours(startTime, endTime)
  if (h <= 0) return '0h'
  return h % 1 === 0 ? `${Math.round(h)}h` : `${h.toFixed(1)}h`
}

/** True when end is “earlier” on the clock than start (e.g. 22:00 → 06:00). */
function ndIsOvernightWindow(startTime, endTime) {
  const s = parseTimeToMinutes(startTime || '22:00')
  const e = parseTimeToMinutes(endTime || '06:00')
  return s !== e && s >= e
}

const ND_QUICK_PRESETS = [
  { label: '22:00–06:00', start: '22:00', end: '06:00', hint: 'Common PH' },
  { label: '00:00–06:00', start: '00:00', end: '06:00', hint: 'Late night' },
  { label: '18:00–06:00', start: '18:00', end: '06:00', hint: 'Long evening' },
]

function NdNightTimeline({ startTime, endTime, className }) {
  const segments = useMemo(() => ndWindowSegments(startTime, endTime), [startTime, endTime])
  return (
    <div className={cn('space-y-3', className)}>
      <div className="flex items-center justify-between gap-2">
        <span className="text-[11px] font-semibold uppercase tracking-wider text-violet-900/90 dark:text-violet-300/90">
          24h clock
        </span>
        <Moon className="size-4 text-violet-600/80 dark:text-violet-400" aria-hidden />
      </div>
      <div
        className={cn(
          'relative h-14 min-h-14 overflow-hidden rounded-xl border border-violet-200/50 bg-gradient-to-b from-slate-100/90 via-background to-slate-50/80 shadow-inner dark:border-violet-900/45 dark:from-slate-950/80 dark:via-background dark:to-slate-950/50 @sm:h-16',
          POLICY_CARD_HOVER_CLIP
        )}
      >
        <div className="absolute inset-0 flex">
          {Array.from({ length: 24 }).map((_, h) => (
            <div
              key={h}
              className="flex-1 border-r border-border/20 last:border-r-0 dark:border-white/5"
              title={`${String(h).padStart(2, '0')}:00`}
            />
          ))}
        </div>
        {segments.map((s, i) => (
          <div
            key={i}
            className="absolute top-0 bottom-0 bg-violet-500/35 shadow-[inset_0_0_0_1px_rgba(139,92,246,0.45)] dark:bg-violet-500/25 dark:shadow-[inset_0_0_0_1px_rgba(167,139,250,0.35)]"
            style={{ left: `${s.left}%`, width: `${Math.max(s.width, 0.4)}%` }}
          />
        ))}
        <div className="pointer-events-none absolute inset-x-0 bottom-1 flex justify-between px-1 font-mono text-[9px] font-medium leading-none tabular-nums text-muted-foreground/90 @sm:bottom-1.5 @sm:px-1.5 @sm:text-[10px]">
          <span className="min-w-0 shrink-0">00</span>
          <span className="min-w-0 shrink-0">06</span>
          <span className="min-w-0 shrink-0">12</span>
          <span className="min-w-0 shrink-0">18</span>
          <span className="min-w-0 shrink-0">24</span>
        </div>
      </div>
      <p className="text-center text-xs leading-relaxed text-muted-foreground">
        Violet band = hours when <span className="font-medium text-foreground">night differential</span> may apply (
        <span className="font-mono tabular-nums">{startTime || '22:00'}</span>
        <span className="mx-0.5">–</span>
        <span className="font-mono tabular-nums">{endTime || '06:00'}</span>)
      </p>
    </div>
  )
}

function buildPayrollBreakdown({ hourlyRate, ruleRow, ndSetting, regHours, otHours, ndHours }) {
  const rate = Math.max(0, Number(hourlyRate) || 0)
  let f8 = parseFloat(ruleRow?.first8_multiplier)
  if (Number.isNaN(f8)) f8 = 1
  let otM = parseFloat(ruleRow?.ot_multiplier)
  if (Number.isNaN(otM)) otM = 1.25
  let ndA = parseFloat(ruleRow?.nd_addon_multiplier)
  if (Number.isNaN(ndA)) ndA = 0.1

  const regH = Math.max(0, Number(regHours) || 0)
  const otH = Math.max(0, Number(otHours) || 0)
  const ndH = Math.max(0, Number(ndHours) || 0)

  const rows = []
  const regBillable = Math.min(regH, 8)
  rows.push({
    key: 'reg',
    label: 'Regular (first 8h bucket)',
    hours: regBillable,
    detail: `₱${rate.toFixed(2)} × ${f8}`,
    amount: regBillable * rate * f8,
  })

  rows.push({
    key: 'ot',
    label: 'Overtime',
    hours: otH,
    detail: `₱${rate.toFixed(2)} × ${otM}`,
    amount: otH * rate * otM,
  })

  const applyReg = ndSetting?.apply_to_regular !== false
  const applyOt = ndSetting?.apply_to_ot !== false

  if (ndH > 0 && ndA > 0) {
    if (applyReg) {
      rows.push({
        key: 'nd',
        label: 'Night differential (add-on)',
        hours: ndH,
        detail: `₱${rate.toFixed(2)} × ${f8} × ${ndA}`,
        amount: ndH * rate * f8 * ndA,
      })
    } else if (applyOt) {
      rows.push({
        key: 'nd',
        label: 'Night differential (on OT base)',
        hours: ndH,
        detail: `₱${rate.toFixed(2)} × ${otM} × ${ndA}`,
        amount: ndH * rate * otM * ndA,
      })
    } else {
      rows.push({
        key: 'nd',
        label: 'Night differential',
        hours: ndH,
        detail: 'Enable ND on regular or OT above',
        amount: 0,
      })
    }
  }

  const total = rows.reduce((s, r) => s + r.amount, 0)
  return { rows, total }
}

/** YYYY-MM-DD for API/state; never leaves a Laravel date object on the payload. */
function toEffectiveDateString(raw) {
  if (raw == null || raw === '') return ''
  if (typeof raw === 'string') return raw.length > 10 ? raw.slice(0, 10) : raw
  if (typeof raw === 'object' && raw.date != null) return String(raw.date).slice(0, 10)
  return ''
}

/** Ensure one row per condition key and a plain YYYY-MM-DD effective date for inputs. */
function normalizePayPolicyPayload(raw, conditionLabels) {
  if (!raw) return null
  const keys = Object.keys(conditionLabels)
  const multRows = Array.isArray(raw.multipliers) ? raw.multipliers : []
  const byKey = new Map(multRows.map((m) => [m.condition_key, m]))
  const multipliers = keys.map((k) => {
    const row = byKey.get(k)
    if (row) {
      return {
        ...row,
        first8_multiplier: row.first8_multiplier ?? 1,
        ot_multiplier: row.ot_multiplier ?? 1.25,
        nd_addon_multiplier: row.nd_addon_multiplier ?? 0.1,
      }
    }
    return {
      condition_key: k,
      first8_multiplier: 1,
      ot_multiplier: 1.25,
      nd_addon_multiplier: 0.1,
    }
  })
  const ed = toEffectiveDateString(raw.effective_date)
  return { ...raw, multipliers, effective_date: ed }
}

/** Laravel paginator or plain array from GET /admin/payroll/policies */
function policyListFromResponse(res) {
  if (!res) return []
  if (Array.isArray(res)) return res
  if (Array.isArray(res.data)) return res.data
  return []
}

function policyScopeLabel(p, companiesList) {
  if (p?.company?.name) return p.company.name
  if (p?.company_id != null) {
    const c = companiesList.find((x) => Number(x.id) === Number(p.company_id))
    return c?.name ?? `Company #${p.company_id}`
  }
  return 'Global'
}

export default function AdminPolicySettings() {
  useAuth()
  const { toast } = useToast()
  const [companies, setCompanies] = useState([])
  const [policies, setPolicies] = useState([])
  const [selectedPolicy, setSelectedPolicy] = useState(null)
  const [policyDetail, setPolicyDetail] = useState(null)
  const [loadingPolicies, setLoadingPolicies] = useState(false)
  const [loadingPolicyDetail, setLoadingPolicyDetail] = useState(false)
  const policiesReqIdRef = useRef(0)
  const policyDetailReqIdRef = useRef(0)
  const previewReqRef = useRef(0)
  const previewAbortRef = useRef(null)
  const [saving, setSaving] = useState(false)
  const [deleting, setDeleting] = useState(false)
  const [companyFilter, setCompanyFilter] = useState('')
  const [dirty, setDirty] = useState(false)
  const [lastSaved, setLastSaved] = useState(null)
  const [previewRule, setPreviewRule] = useState('ORD')
  const [previewHourly, setPreviewHourly] = useState('100')
  const [preview, setPreview] = useState(null)
  const [loadingPreview, setLoadingPreview] = useState(false)
  const [configTab, setConfigTab] = useState('multipliers')
  const [previewRegHours, setPreviewRegHours] = useState('8')
  const [previewOtHours, setPreviewOtHours] = useState('2')
  const [previewNdHours, setPreviewNdHours] = useState('2')
  const [creating, setCreating] = useState(false)
  const [newPolicyName, setNewPolicyName] = useState('')
  const [newPolicyEffective, setNewPolicyEffective] = useState(() =>
    new Date().toISOString().slice(0, 10)
  )
  const [newPolicyCompanyId, setNewPolicyCompanyId] = useState('')
  const [newPolicyModalOpen, setNewPolicyModalOpen] = useState(false)
  const [headerSaveModalOpen, setHeaderSaveModalOpen] = useState(false)
  const [priorityDragIdx, setPriorityDragIdx] = useState(null)
  const [multiplierSectionsOpen, setMultiplierSectionsOpen] = useState({
    standard: true,
    holidays: true,
    double: true,
  })

  const fetchCompanies = useCallback(async () => {
    try {
      const data = await getPayPolicyCompanies()
      setCompanies(Array.isArray(data) ? data : [])
    } catch (e) {
      toast({ title: 'Failed to load companies', description: e?.message, variant: 'error' })
    }
  }, [toast])

  /** @param {string | undefined} [companyFilterOverride] – use when state has not updated yet (e.g. after create). */
  const fetchPolicies = useCallback(
    async (companyFilterOverride) => {
      const reqId = ++policiesReqIdRef.current
      setLoadingPolicies(true)
      try {
        const params = {}
        const cf =
          companyFilterOverride !== undefined ? companyFilterOverride : companyFilter
        if (cf) params.company_id = parseInt(cf, 10)
        const res = await getPayPolicies(params)
        const items = policyListFromResponse(res)
        if (reqId !== policiesReqIdRef.current) return
        setPolicies(items)
      } catch (e) {
        if (reqId === policiesReqIdRef.current) {
          toast({ title: 'Failed to load policies', description: e?.message, variant: 'error' })
        }
      } finally {
        if (reqId === policiesReqIdRef.current) {
          setLoadingPolicies(false)
        }
      }
    },
    [companyFilter, toast]
  )

  const fetchPolicyDetail = useCallback(async (id, opts = {}) => {
    const keepDetail = opts.keepDetail === true
    if (!id) {
      policyDetailReqIdRef.current += 1
      setPolicyDetail(null)
      setLoadingPolicyDetail(false)
      return
    }
    const reqId = ++policyDetailReqIdRef.current
    setLoadingPolicyDetail(true)
    if (!keepDetail) setPolicyDetail(null)
    try {
      const p = await getPayPolicy(id)
      if (reqId !== policyDetailReqIdRef.current) return
      setPolicyDetail(normalizePayPolicyPayload(p, CONDITION_LABELS))
    } catch (e) {
      if (reqId === policyDetailReqIdRef.current) {
        toast({ title: 'Failed to load policy', description: e?.message, variant: 'error' })
        setPolicyDetail(null)
      }
    } finally {
      if (reqId === policyDetailReqIdRef.current) {
        setLoadingPolicyDetail(false)
      }
    }
  }, [toast])

  useEffect(() => {
    fetchCompanies()
  }, [fetchCompanies])

  useEffect(() => {
    fetchPolicies()
  }, [fetchPolicies])

  useEffect(() => {
    if (selectedPolicy) {
      fetchPolicyDetail(selectedPolicy)
      setDirty(false)
      setConfigTab('multipliers')
    } else {
      setPolicyDetail(null)
    }
  }, [selectedPolicy, fetchPolicyDetail])

  /** Drop selection if the current id is not in the filtered list (e.g. company filter changed). */
  useEffect(() => {
    if (loadingPolicies || !selectedPolicy) return
    const exists = policies.some((p) => Number(p.id) === Number(selectedPolicy))
    if (!exists) {
      setSelectedPolicy(policies[0]?.id ?? null)
    }
  }, [loadingPolicies, policies, selectedPolicy])

  const fetchPreview = useCallback(async () => {
    if (!selectedPolicy) return
    previewAbortRef.current?.abort()
    const ac = new AbortController()
    previewAbortRef.current = ac
    const reqId = ++previewReqRef.current
    setLoadingPreview(true)
    try {
      const params = { rule_code: previewRule }
      if (previewHourly && !Number.isNaN(parseFloat(previewHourly))) {
        params.hourly_rate = parseFloat(previewHourly)
      }
      if (policyDetail?.id) params.policy_id = policyDetail.id
      const data = await getPayPolicyPreview(params, { signal: ac.signal })
      if (reqId !== previewReqRef.current) return
      setPreview(data)
    } catch (e) {
      if (e?.name === 'AbortError') return
      if (reqId !== previewReqRef.current) return
      toast({ title: 'Failed to load preview', description: e?.message, variant: 'error' })
    } finally {
      if (reqId === previewReqRef.current) {
        setLoadingPreview(false)
      }
    }
  }, [selectedPolicy, previewRule, previewHourly, policyDetail?.id, toast])

  const fetchPreviewRef = useRef(fetchPreview)
  fetchPreviewRef.current = fetchPreview

  /** Only after a policy is selected — avoids racing the list/detail requests on first paint.
   *  Intentionally omit fetchPreview from deps so a callback identity change cannot retrigger in a loop. */
  useEffect(() => {
    if (!previewRule || !selectedPolicy) return
    fetchPreviewRef.current()
    return () => {
      previewAbortRef.current?.abort()
    }
  }, [previewRule, previewHourly, policyDetail?.id, selectedPolicy])

  const handleSave = async () => {
    if (!policyDetail || !dirty) return
    if (saving) return
    setSaving(true)
    try {
      const multipliers = (policyDetail.multipliers || []).map((m) => ({
        condition_key: m.condition_key,
        first8_multiplier: parseFloat(m.first8_multiplier) || 1,
        ot_multiplier: parseFloat(m.ot_multiplier) || 1.25,
        nd_addon_multiplier: parseFloat(m.nd_addon_multiplier) ?? 0.1,
      }))
      const nd = policyDetail.nd_setting || policyDetail.ndSetting
      await updatePayPolicy(policyDetail.id, {
        name: policyDetail.name,
        effective_date: toEffectiveDateString(policyDetail.effective_date),
        status: policyDetail.status,
        version_label: policyDetail.version_label,
        priority_order_json: policyDetail.priority_order_json,
        multipliers,
        nd_settings: nd
          ? {
              start_time: nd.start_time || '22:00',
              end_time: nd.end_time || '06:00',
              nd_addon_multiplier: parseFloat(nd.nd_addon_multiplier) ?? 0.1,
              apply_to_regular: nd.apply_to_regular !== false,
              apply_to_ot: nd.apply_to_ot !== false,
              apply_to_premium_days: nd.apply_to_premium_days !== false,
            }
          : undefined,
      })
      setLastSaved(new Date())
      setDirty(false)
      toast({ title: 'Policy saved', variant: 'success' })
      await fetchPolicyDetail(policyDetail.id, { keepDetail: true })
      await fetchPreview()
    } catch (e) {
      toast({ title: 'Failed to save', description: e?.message, variant: 'error' })
    } finally {
      setSaving(false)
    }
  }

  const handleDuplicate = async () => {
    if (!policyDetail) return
    try {
      const effectiveDate = new Date()
      effectiveDate.setMonth(effectiveDate.getMonth() + 1)
      const dateStr = effectiveDate.toISOString().slice(0, 10)
      const created = await duplicatePayPolicy(policyDetail.id, {
        name: `${policyDetail.name} (copy)`,
        effective_date: dateStr,
        version_label: `v${(policyDetail.version || 1) + 1}`,
      })
      const newPolicy = created?.data ?? created
      toast({ title: 'Policy duplicated', variant: 'success' })
      const dupFilter = newPolicy?.company_id != null ? String(newPolicy.company_id) : ''
      setCompanyFilter(dupFilter)
      await fetchPolicies(dupFilter)
      if (newPolicy?.id) setSelectedPolicy(newPolicy.id)
    } catch (e) {
      toast({ title: 'Failed to duplicate', description: e?.message, variant: 'error' })
    }
  }

  const handleCreatePolicy = async () => {
    const name = newPolicyName.trim()
    if (!name) {
      toast({ title: 'Enter a policy name', variant: 'error' })
      return
    }
    setCreating(true)
    try {
      const payload = {
        name,
        effective_date: newPolicyEffective,
        status: 'active',
      }
      if (newPolicyCompanyId) payload.company_id = parseInt(newPolicyCompanyId, 10)
      const created = await createPayPolicy(payload)
      const newPolicy = created?.data ?? created
      const id = newPolicy?.id
      toast({ title: 'Policy created', variant: 'success' })
      setNewPolicyName('')
      setNewPolicyModalOpen(false)
      const filterAfter = newPolicy?.company_id != null ? String(newPolicy.company_id) : ''
      setCompanyFilter(filterAfter)
      await fetchPolicies(filterAfter)
      if (id) setSelectedPolicy(id)
    } catch (e) {
      toast({ title: 'Failed to create policy', description: e?.message, variant: 'error' })
    } finally {
      setCreating(false)
    }
  }

  const handleDelete = async () => {
    if (!policyDetail) return
    if (
      !window.confirm(
        `Delete policy "${policyDetail.name}" permanently? This cannot be undone. Historical payroll rows keep a snapshot; policy_id on those rows will be cleared.`
      )
    ) {
      return
    }
    setDeleting(true)
    try {
      await deletePayPolicy(policyDetail.id)
      toast({ title: 'Policy deleted', variant: 'success' })
      setSelectedPolicy(null)
      setPolicyDetail(null)
      setDirty(false)
      fetchPolicies()
    } catch (e) {
      toast({ title: 'Failed to delete policy', description: e?.message, variant: 'error' })
    } finally {
      setDeleting(false)
    }
  }

  const updateMultiplier = (conditionKey, field, value) => {
    if (!policyDetail) return
    const mults = [...(policyDetail.multipliers || [])]
    const idx = mults.findIndex((m) => m.condition_key === conditionKey)
    if (idx < 0) return
    mults[idx] = { ...mults[idx], [field]: value }
    setPolicyDetail({ ...policyDetail, multipliers: mults })
    setDirty(true)
  }

  const updateNdSetting = (field, value) => {
    if (!policyDetail) return
    const nd = policyDetail.nd_setting || policyDetail.ndSetting || {}
    setPolicyDetail({
      ...policyDetail,
      nd_setting: { ...nd, [field]: value },
      ndSetting: { ...nd, [field]: value },
    })
    setDirty(true)
  }

  const applyNdPreset = (start, end) => {
    if (!policyDetail) return
    const nd = policyDetail.nd_setting || policyDetail.ndSetting || {}
    setPolicyDetail({
      ...policyDetail,
      nd_setting: { ...nd, start_time: start, end_time: end },
      ndSetting: { ...nd, start_time: start, end_time: end },
    })
    setDirty(true)
  }

  const movePriorityToIndex = (from, to) => {
    if (!policyDetail?.priority_order_json) return
    const arr = [...policyDetail.priority_order_json]
    if (from < 0 || from >= arr.length || to < 0 || to >= arr.length || from === to) return
    const [item] = arr.splice(from, 1)
    arr.splice(to, 0, item)
    setPolicyDetail({ ...policyDetail, priority_order_json: arr })
    setDirty(true)
  }

  const multipliers = policyDetail?.multipliers || []
  const ndSetting = policyDetail?.nd_setting || policyDetail?.ndSetting || {}
  const priorityOrder = policyDetail?.priority_order_json || ['holiday_type', 'rest_day', 'worked_flag', 'hour_type']

  const previewRuleRow = useMemo(
    () => (policyDetail?.multipliers || []).find((m) => m.condition_key === previewRule) || null,
    [policyDetail, previewRule]
  )

  const previewBreakdown = useMemo(
    () =>
      buildPayrollBreakdown({
        hourlyRate: previewHourly,
        ruleRow: previewRuleRow,
        ndSetting: policyDetail?.nd_setting || policyDetail?.ndSetting || {},
        regHours: previewRegHours,
        otHours: previewOtHours,
        ndHours: previewNdHours,
      }),
    [previewHourly, previewRuleRow, policyDetail, previewRegHours, previewOtHours, previewNdHours]
  )

  const heroKeys = ['ORD', 'RD', 'RH']

  return (
    <PayrollLogisticsPolicyShell
      dirty={dirty}
      saving={saving}
      onSaveDraft={handleSave}
      onPublish={handleSave}
      disableActions={!policyDetail}
    >
      <Motion.div
        className="mx-auto min-w-0 max-w-[1600px] space-y-6 rounded-2xl border border-transparent bg-transparent pb-10 dark:border-border/25"
        initial={{ opacity: 0, y: 10 }}
        animate={{ opacity: 1, y: 0 }}
        transition={{ duration: 0.25, ease: [0.23, 1, 0.32, 1] }}
      >
        <div className="flex flex-col gap-4 @sm:flex-row @sm:items-center @sm:justify-between">
          <div>
            <h1 className="hr-page-title">Policy settings</h1>
            <CardDescription>
              PH payroll multipliers, night differential, and rule priority — same surface treatment as Daily
              computation logs.
            </CardDescription>
          </div>
        </div>

        {policyDetail && (
          <section className="space-y-4" aria-labelledby="core-multipliers-heading">
            <div className="flex flex-wrap items-end justify-between gap-2">
              <div>
                <h3 id="core-multipliers-heading" className="text-lg font-semibold tracking-tight">
                  Core multipliers
                </h3>
                <p className="text-sm text-muted-foreground">
                  Baseline for{' '}
                  <span className="font-medium tabular-nums text-foreground">
                    {toEffectiveDateString(policyDetail.effective_date) || '—'}
                  </span>{' '}
                  · Philippines payroll
                </p>
              </div>
            </div>
            <div className="grid gap-3 @sm:grid-cols-3 lg:gap-4">
              {heroKeys.map((key) => {
                const row = multipliers.find((m) => m.condition_key === key)
                const f8 = parseFloat(row?.first8_multiplier) || 0
                const ot = parseFloat(row?.ot_multiplier) || 0
                const icons = { ORD: Sun, RD: Moon, RH: CalendarDays }
                const HeroIcon = icons[key] || Layers
                return (
                  <div
                    key={key}
                    className={cn(
                      'rounded-xl border-0 bg-card p-4 shadow-sm dark:bg-card',
                      POLICY_CARD_HOVER
                    )}
                  >
                    <div className="flex min-w-0 items-center gap-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                      <HeroIcon className="size-3.5 shrink-0 opacity-80" aria-hidden />
                      {CONDITION_LABELS[key]}
                    </div>
                    <div className="mt-3 flex flex-wrap items-end justify-between gap-3 border-b border-border/30 pb-2 dark:border-border/50">
                      <span className="text-xs text-muted-foreground">First 8×</span>
                      <span className="text-2xl font-bold tabular-nums tracking-tight text-foreground">
                        {f8.toFixed(2)}
                        <span className="text-base font-semibold text-muted-foreground">×</span>
                      </span>
                    </div>
                    <div className="mt-2 flex flex-wrap items-end justify-between gap-3">
                      <span className="text-xs text-muted-foreground">OT×</span>
                      <span className="text-xl font-bold tabular-nums text-amber-700 dark:text-amber-400">
                        {ot.toFixed(2)}
                        <span className="text-base font-semibold text-muted-foreground">×</span>
                      </span>
                    </div>
                  </div>
                )
              })}
            </div>
          </section>
        )}

        <div className="grid gap-6 lg:grid-cols-[minmax(340px,480px)_minmax(0,1fr)]">
        {/* Policy selector — data table + toolbar (matches Daily computation logs) */}
        <Card
          className={cn(
            'gap-0 border-0 bg-card py-0 shadow-sm lg:sticky lg:top-4 lg:max-h-[calc(100vh-8rem)] lg:flex lg:flex-col lg:self-start lg:overflow-hidden',
            POLICY_CARD_HOVER_STICKY
          )}
        >
            <CardHeader className="border-b border-border/40 bg-muted/20 px-4 py-4 dark:border-border/40 dark:bg-muted/25 @sm:px-6">
              <CardTitle className="text-base font-semibold">Policies</CardTitle>
              <CardDescription className="text-xs">Company filter &amp; selection</CardDescription>
            </CardHeader>
            <CardContent className="flex min-h-0 flex-1 flex-col p-0">
              <div className="border-b border-border/30 bg-muted/30 px-3 py-2.5 backdrop-blur-sm dark:border-border/40 dark:bg-background/40 dark:backdrop-blur-md @sm:px-4">
                <div className="flex flex-col gap-3 @sm:flex-row @sm:items-end @sm:justify-between">
                  <div className="min-w-0 flex-1">
                    <span className="text-[10px] font-bold uppercase tracking-widest text-muted-foreground/70">
                      Company filter
                    </span>
                    <select
                      className={cn('mt-1.5', FIELD_SELECT_CLASS)}
                      value={companyFilter}
                      onChange={(e) => setCompanyFilter(e.target.value)}
                      aria-label="Filter policies by company"
                    >
                      <option value="">All / Global</option>
                      {companies.map((c) => (
                        <option key={c.id} value={c.id}>
                          {c.name}
                        </option>
                      ))}
                    </select>
                  </div>
                  <Button
                    type="button"
                    size="sm"
                    className="shrink-0 font-semibold"
                    onClick={() => setNewPolicyModalOpen(true)}
                  >
                    <Plus className="size-4 mr-1" />
                    New policy
                  </Button>
                </div>
              </div>

              <div className="min-h-0 flex-1 overflow-x-auto overflow-y-auto">
                {loadingPolicies ? (
                  <div className="flex items-center gap-2 px-4 py-8 text-sm text-muted-foreground">
                    <Loader2 className="size-4 animate-spin" />
                    Loading policies…
                  </div>
                ) : (
                  <table className="w-full min-w-[320px] text-left text-sm text-foreground">
                    <thead>
                      <tr className="border-b border-border bg-[#f1f5f9] text-[11px] font-bold uppercase tracking-wider text-muted-foreground shadow-[0_1px_0_0_var(--border)] dark:border-border/40 dark:bg-muted/35">
                        <th className="px-3 py-2.5 @sm:px-4">Policy</th>
                        <th className="hidden px-2 py-2.5 tabular-nums sm:table-cell sm:px-3">Effective</th>
                        <th className="hidden px-2 py-2.5 md:table-cell md:px-3">Scope</th>
                        <th className="px-2 py-2.5 text-right @sm:px-3">Status</th>
                      </tr>
                    </thead>
                    <tbody className="bg-card">
                      {policies.length === 0 ? (
                        <tr>
                          <td colSpan={4} className="px-4 py-10 text-center text-sm text-muted-foreground">
                            No policies match this filter.
                          </td>
                        </tr>
                      ) : (
                        policies.map((p, rowIdx) => {
                          const isSelected = selectedPolicy === p.id
                          return (
                            <tr
                              key={p.id}
                              role="button"
                              tabIndex={0}
                              onClick={() => setSelectedPolicy(p.id)}
                              onKeyDown={(e) => {
                                if (e.key === 'Enter' || e.key === ' ') {
                                  e.preventDefault()
                                  setSelectedPolicy(p.id)
                                }
                              }}
                              className={cn(
                                'cursor-pointer border-b border-border/20 transition-colors dark:border-border/40',
                                rowIdx % 2 === 1
                                  ? 'bg-white hover:bg-slate-50 dark:bg-card/80 dark:hover:bg-muted/30'
                                  : 'bg-[#f8fafc] hover:bg-slate-50 dark:bg-muted/20 dark:hover:bg-muted/30',
                                isSelected && 'bg-muted/50 dark:bg-muted/30'
                              )}
                            >
                              <td className="max-w-[200px] px-3 py-2.5 align-middle @sm:min-w-0 @sm:max-w-none @sm:px-4">
                                <span className="line-clamp-2 font-medium leading-snug">{p.name}</span>
                                <span className="mt-0.5 block text-[11px] tabular-nums text-muted-foreground sm:hidden">
                                  {toEffectiveDateString(p.effective_date) || '—'}
                                </span>
                              </td>
                              <td className="hidden px-2 py-2.5 align-middle tabular-nums text-muted-foreground sm:table-cell sm:px-3">
                                {toEffectiveDateString(p.effective_date) || '—'}
                              </td>
                              <td className="hidden px-2 py-2.5 align-middle text-muted-foreground md:table-cell md:px-3">
                                <span className="line-clamp-2 text-xs">{policyScopeLabel(p, companies)}</span>
                              </td>
                              <td className="px-2 py-2.5 text-right align-middle @sm:px-3">
                                <span
                                  className={cn(
                                    'inline-flex rounded-full px-2 py-0.5 text-[10px] font-semibold',
                                    p.status === 'active'
                                      ? 'bg-muted/80 text-foreground dark:bg-muted/50'
                                      : 'bg-muted text-muted-foreground'
                                  )}
                                >
                                  {p.status === 'active' ? 'Active' : 'Archived'}
                                </span>
                              </td>
                            </tr>
                          )
                        })
                      )}
                    </tbody>
                  </table>
                )}
              </div>
            </CardContent>
          </Card>

          {/* Main content: tabbed config */}
          <div className="min-w-0 space-y-4">
            {!selectedPolicy ? (
              <Card className={cn('border-0 bg-card shadow-sm', POLICY_CARD_HOVER)}>
                <CardContent className="space-y-2 py-16 text-center text-muted-foreground">
                  <p className="text-base font-medium text-foreground">No policy selected</p>
                  <p className="text-sm">
                    {policies.length === 0 && !loadingPolicies
                      ? 'Create a policy (New policy), or adjust the company filter.'
                      : 'Select a row in the policies table, or create a new policy.'}
                  </p>
                </CardContent>
              </Card>
            ) : loadingPolicyDetail && !policyDetail ? (
              <Card className={cn('border-0 bg-card shadow-sm', POLICY_CARD_HOVER)}>
                <CardContent className="flex flex-col items-center justify-center gap-3 py-16 text-muted-foreground">
                  <Loader2 className="size-8 animate-spin text-primary" />
                  <p className="text-sm">Loading policy…</p>
                </CardContent>
              </Card>
            ) : !policyDetail ? (
              <Card className={cn('border-0 bg-card shadow-sm', POLICY_CARD_HOVER)}>
                <CardContent className="py-12 text-center text-muted-foreground">
                  Could not load this policy. Try another or refresh the list.
                </CardContent>
              </Card>
            ) : (
              <>
                <Card
                  className={cn(
                    'sticky top-0 z-30 overflow-hidden border-0 bg-card/95 shadow-none backdrop-blur-md dark:bg-card dark:shadow-none',
                    POLICY_CARD_HOVER_STICKY
                  )}
                >
                  <CardHeader className="flex flex-col gap-4 border-0 bg-transparent pb-4 @sm:flex-row @sm:items-start @sm:justify-between">
                    <div className="min-w-0 flex-1 space-y-2">
                      <div className="flex flex-wrap items-center gap-2">
                        <CardTitle className="text-balance text-2xl font-semibold tracking-tight">
                          {policyDetail?.name}
                        </CardTitle>
                        <span
                          className={cn(
                            'inline-flex shrink-0 items-center rounded-full px-2.5 py-0.5 text-xs font-semibold',
                            policyDetail?.status === 'active'
                              ? 'bg-muted/80 text-foreground dark:bg-muted/50'
                              : 'bg-muted text-muted-foreground'
                          )}
                        >
                          {policyDetail?.status === 'active' ? 'Active' : 'Archived'}
                        </span>
                        {dirty && (
                          <span className="inline-flex items-center gap-1 rounded-full border border-amber-500/40 bg-amber-500/10 px-2 py-0.5 text-xs font-medium text-amber-900 dark:text-amber-100">
                            <AlertTriangle className="size-3" />
                            Unsaved
                          </span>
                        )}
                      </div>
                      <CardDescription className="text-sm">
                        Effective{' '}
                        <span className="font-medium text-foreground">
                          {toEffectiveDateString(policyDetail?.effective_date) || '—'}
                        </span>
                        {lastSaved && (
                          <span className="text-muted-foreground">
                            {' '}
                            · Saved {lastSaved.toLocaleTimeString()}
                          </span>
                        )}
                      </CardDescription>
                    </div>
                    <div className="flex w-full shrink-0 flex-wrap items-center justify-end gap-2 @sm:w-auto">
                      <Button
                        size="sm"
                        variant="ghost"
                        className="text-muted-foreground"
                        onClick={handleDuplicate}
                        disabled={!policyDetail || deleting}
                      >
                        <Copy className="size-4 mr-1" />
                        Duplicate
                      </Button>
                      <Button
                        size="sm"
                        variant="outline"
                        className="border-destructive/40 text-destructive hover:bg-destructive/10"
                        onClick={handleDelete}
                        disabled={!policyDetail || deleting || saving}
                      >
                        {deleting ? (
                          <Loader2 className="size-4 animate-spin mr-1" />
                        ) : (
                          <Trash2 className="size-4 mr-1" />
                        )}
                        Delete
                      </Button>
                      <Button
                        size="default"
                        className="shadow-md"
                        onClick={() => setHeaderSaveModalOpen(true)}
                        disabled={!dirty || saving || deleting}
                      >
                        {saving ? (
                          <Loader2 className="size-4 animate-spin mr-1" />
                        ) : (
                          <Save className="size-4 mr-1" />
                        )}
                        Save changes
                      </Button>
                    </div>
                  </CardHeader>
                </Card>

                {dirty && (
                  <div className="flex items-center gap-2 rounded-xl border border-amber-500/30 bg-amber-500/[0.07] px-4 py-3 text-sm text-amber-950 dark:text-amber-100">
                    <AlertTriangle className="size-4 shrink-0 text-amber-600 dark:text-amber-400" />
                    Unsaved changes — payroll still uses the last saved version until you save.
                  </div>
                )}

                <Tabs value={configTab} onValueChange={setConfigTab} className="space-y-4">
                  <TabsList variant="line" className={POLICY_CONFIG_TAB_LIST_CLASS}>
                    <TabsTrigger value="multipliers" className={POLICY_CONFIG_TAB_TRIGGER_CLASS}>
                      <Layers className="size-4 shrink-0" />
                      Multipliers
                    </TabsTrigger>
                    <TabsTrigger value="nd" className={POLICY_CONFIG_TAB_TRIGGER_CLASS}>
                      <Moon className="size-4 shrink-0" />
                      Night diff
                    </TabsTrigger>
                    <TabsTrigger value="priority" className={POLICY_CONFIG_TAB_TRIGGER_CLASS}>
                      <ListOrdered className="size-4 shrink-0" />
                      Priority
                    </TabsTrigger>
                    <TabsTrigger value="preview" className={POLICY_CONFIG_TAB_TRIGGER_CLASS}>
                      <Calculator className="size-4 shrink-0" />
                      Preview
                    </TabsTrigger>
                  </TabsList>

                  <TabsContent value="multipliers" className="mt-0 outline-none">
                    <Motion.div
                      initial={{ opacity: 0, y: 6 }}
                      animate={{ opacity: 1, y: 0 }}
                      transition={{ duration: 0.2 }}
                    >
                      <Card
                        className={cn(
                          'border-0 bg-card shadow-sm overflow-hidden',
                          POLICY_CARD_HOVER_CLIP
                        )}
                      >
                        <CardHeader className="border-b border-border/40 bg-muted/20 px-4 pb-4 pt-4 dark:border-border/40 dark:bg-muted/25 @sm:px-6">
                          <div className="flex flex-col gap-3 @sm:flex-row @sm:items-start @sm:justify-between">
                            <div className="flex min-w-0 items-start gap-3 @sm:gap-4">
                              <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-primary/10 text-primary ring-1 ring-primary/15 @sm:size-11 dark:bg-primary/15">
                                <Layers className="size-[1.125rem] @sm:size-5" aria-hidden />
                              </div>
                              <div className="min-w-0 flex-1 space-y-1">
                                <CardTitle className="text-lg font-semibold tracking-tight">Pay rule matrix</CardTitle>
                                <CardDescription className="flex flex-wrap items-center gap-1.5 text-sm leading-relaxed">
                                  <HelpCircle className="size-3.5 shrink-0 text-muted-foreground" />
                                  Expand a section to edit multipliers by day type. Premium multipliers are typically
                                  0–5×; ND add-on is a fraction of applicable base (e.g. 0.10).
                                </CardDescription>
                              </div>
                            </div>
                          </div>
                        </CardHeader>
                        <CardContent className="space-y-4 p-4 @sm:p-5">
                          {CONDITION_GROUPS.map((group) => {
                            const GIcon = GROUP_MATRIX_META[group.id]?.Icon ?? Layers
                            const open = multiplierSectionsOpen[group.id]
                            return (
                              <div
                                key={group.id}
                                className={cn(
                                  'overflow-hidden rounded-2xl border border-border/50 bg-muted/20 shadow-sm dark:border-border/40 dark:bg-muted/25',
                                  POLICY_CARD_HOVER_CLIP
                                )}
                              >
                                <button
                                  type="button"
                                  aria-expanded={open}
                                  className={cn(
                                    'flex w-full items-start gap-3 px-3 py-3.5 text-left transition-colors @sm:items-center @sm:gap-4 @sm:px-5 @sm:py-4',
                                    'hover:bg-muted/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 dark:hover:bg-muted/30',
                                    open && 'bg-muted/30 dark:bg-muted/30'
                                  )}
                                  onClick={() =>
                                    setMultiplierSectionsOpen((s) => ({
                                      ...s,
                                      [group.id]: !s[group.id],
                                    }))
                                  }
                                >
                                  <div className="flex size-9 shrink-0 items-center justify-center rounded-xl bg-background shadow-sm ring-1 ring-border/50 @sm:size-10 dark:bg-card">
                                    <GIcon className="size-4 text-foreground/80 @sm:size-5" aria-hidden />
                                  </div>
                                  <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-2">
                                      <span className="text-sm font-semibold tracking-tight">{group.label}</span>
                                      <span className="rounded-full bg-background/80 px-2 py-0.5 text-[10px] font-medium tabular-nums text-muted-foreground ring-1 ring-border/40 dark:bg-muted/50">
                                        {group.keys.length} {group.keys.length === 1 ? 'rule' : 'rules'}
                                      </span>
                                    </div>
                                    <p className="mt-0.5 text-xs text-muted-foreground">{group.hint}</p>
                                    {GROUP_MATRIX_META[group.id]?.label ? (
                                      <p className="mt-1 text-[11px] text-muted-foreground/80">
                                        {GROUP_MATRIX_META[group.id].label}
                                      </p>
                                    ) : null}
                                  </div>
                                  <div className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full border border-border/60 bg-background text-muted-foreground @sm:mt-0 @sm:size-9 dark:bg-card">
                                    {open ? (
                                      <ChevronDown className="size-4 shrink-0" aria-hidden />
                                    ) : (
                                      <ChevronRight className="size-4 shrink-0" aria-hidden />
                                    )}
                                  </div>
                                </button>
                                {open && (
                                  <div className="space-y-4 border-t border-border/40 bg-gradient-to-b from-muted/25 to-card px-3 pb-4 pt-4 dark:border-border/40 dark:from-muted/25 dark:to-background @sm:px-4">
                                    {group.keys.map((key) => {
                                      const label = CONDITION_LABELS[key]
                                      const row = multipliers.find((m) => m.condition_key === key)
                                      const headerTint = CONDITION_HEADER_TINT[key] ?? 'bg-muted/30'
                                      return (
                                        <div
                                          key={key}
                                          className={cn(
                                            'overflow-hidden rounded-2xl border border-border/50 bg-card shadow-md ring-1 ring-border/30 dark:ring-border/40',
                                            POLICY_CARD_HOVER_CLIP
                                          )}
                                        >
                                          <div
                                            className={cn(
                                              'flex flex-col gap-3 border-b border-border/40 px-3 py-3 @sm:flex-row @sm:items-center @sm:gap-4 @sm:px-4 @sm:py-3.5',
                                              headerTint
                                            )}
                                          >
                                            <span
                                              className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-background shadow-sm ring-1 ring-border/40 @sm:size-12 dark:bg-card"
                                              aria-hidden
                                            >
                                              <ConditionRuleIcon ruleKey={key} />
                                            </span>
                                            <div className="min-w-0 flex-1">
                                              <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                                <span className="font-mono text-[10px] font-bold uppercase tracking-wider text-muted-foreground">
                                                  {key}
                                                </span>
                                                <span className="text-sm font-semibold leading-tight text-foreground">
                                                  {label}
                                                </span>
                                              </div>
                                              <p className="mt-1 text-xs leading-snug text-muted-foreground">
                                                {CONDITION_HINTS[key]}
                                              </p>
                                            </div>
                                          </div>
                                          <div className="grid gap-3 p-4 @sm:grid-cols-3">
                                            <div
                                              className={cn(
                                                'rounded-xl border border-border/40 bg-background/90 p-3 shadow-sm dark:border-border/40 dark:bg-background/30',
                                                POLICY_INPUT_TILE_HOVER
                                              )}
                                            >
                                              <Label
                                                htmlFor={`m-${key}-f8`}
                                                className="text-[10px] font-bold uppercase tracking-wide text-muted-foreground"
                                              >
                                                First 8 (base)
                                              </Label>
                                              <div className="relative mt-2">
                                                <Input
                                                  id={`m-${key}-f8`}
                                                  type="number"
                                                  step="0.01"
                                                  min="0"
                                                  max="5"
                                                  className="h-10 border-border/60 pr-8 text-right text-base font-mono tabular-nums focus-visible:ring-2 focus-visible:ring-primary/30"
                                                  value={row?.first8_multiplier ?? ''}
                                                  onChange={(e) =>
                                                    updateMultiplier(key, 'first8_multiplier', e.target.value)
                                                  }
                                                />
                                                <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs font-medium text-muted-foreground">
                                                  ×
                                                </span>
                                              </div>
                                              <p className="mt-1.5 text-[10px] text-muted-foreground">Range 0–5</p>
                                            </div>
                                            <div
                                              className={cn(
                                                'rounded-xl border border-border/40 bg-background/90 p-3 shadow-sm dark:border-border/40 dark:bg-background/30',
                                                POLICY_INPUT_TILE_HOVER
                                              )}
                                            >
                                              <Label
                                                htmlFor={`m-${key}-ot`}
                                                className="text-[10px] font-bold uppercase tracking-wide text-muted-foreground"
                                              >
                                                Overtime
                                              </Label>
                                              <div className="relative mt-2">
                                                <Input
                                                  id={`m-${key}-ot`}
                                                  type="number"
                                                  step="0.01"
                                                  min="0"
                                                  max="5"
                                                  className="h-10 border-border/60 pr-8 text-right text-base font-mono tabular-nums focus-visible:ring-2 focus-visible:ring-primary/30"
                                                  value={row?.ot_multiplier ?? ''}
                                                  onChange={(e) =>
                                                    updateMultiplier(key, 'ot_multiplier', e.target.value)
                                                  }
                                                />
                                                <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-xs font-medium text-muted-foreground">
                                                  ×
                                                </span>
                                              </div>
                                              <p className="mt-1.5 text-[10px] text-muted-foreground">Range 0–5</p>
                                            </div>
                                            <div
                                              className={cn(
                                                'rounded-xl border border-border/40 bg-background/90 p-3 shadow-sm dark:border-border/40 dark:bg-background/30',
                                                POLICY_INPUT_TILE_HOVER
                                              )}
                                            >
                                              <Label
                                                htmlFor={`m-${key}-nd`}
                                                className="text-[10px] font-bold uppercase tracking-wide text-muted-foreground"
                                              >
                                                ND add-on
                                              </Label>
                                              <div className="relative mt-2">
                                                <Input
                                                  id={`m-${key}-nd`}
                                                  type="number"
                                                  step="0.01"
                                                  min="0"
                                                  max="1"
                                                  className="h-10 border-border/60 pr-11 text-right text-base font-mono tabular-nums focus-visible:ring-2 focus-visible:ring-primary/30"
                                                  value={row?.nd_addon_multiplier ?? ''}
                                                  onChange={(e) =>
                                                    updateMultiplier(key, 'nd_addon_multiplier', e.target.value)
                                                  }
                                                />
                                                <span className="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-[10px] font-medium text-muted-foreground">
                                                  rate
                                                </span>
                                              </div>
                                              <p className="mt-1.5 text-[10px] text-muted-foreground">
                                                0–1 (e.g. 0.10 = +10% on base)
                                              </p>
                                            </div>
                                          </div>
                                        </div>
                                      )
                                    })}
                                  </div>
                                )}
                              </div>
                            )
                          })}
                        </CardContent>
                      </Card>
                    </Motion.div>
                  </TabsContent>

                  <TabsContent value="nd" className="mt-0 outline-none">
                    <Motion.div
                      initial={{ opacity: 0, y: 6 }}
                      animate={{ opacity: 1, y: 0 }}
                      transition={{ duration: 0.2, ease: [0.23, 1, 0.32, 1] }}
                    >
                      <Card
                        className={cn(
                          'overflow-hidden border border-violet-200/40 bg-card shadow-sm dark:border-violet-900/35',
                          POLICY_CARD_HOVER_CLIP
                        )}
                      >
                        <CardHeader className="border-b border-violet-200/50 bg-gradient-to-r from-violet-50/90 via-background to-background pb-4 dark:border-violet-900/40 dark:from-violet-950/40 dark:via-card dark:to-card">
                          <div className="flex flex-col gap-2 @sm:flex-row @sm:items-start @sm:justify-between">
                            <div>
                              <CardTitle className="text-lg font-semibold tracking-tight text-foreground">
                                Night differential
                              </CardTitle>
                              <CardDescription className="mt-1 max-w-2xl text-sm leading-relaxed">
                                Define the clock window where ND is evaluated, the add-on rate on applicable base
                                pay, and which hour buckets it stacks with. Matches the violet ND signals in Daily
                                computation.
                              </CardDescription>
                            </div>
                          </div>
                        </CardHeader>
                        <CardContent className="space-y-8 p-5 @sm:p-6">
                          {/* Summary + quick presets */}
                          <div className="flex flex-col gap-4 rounded-xl border border-border/50 bg-muted/20 p-4 dark:border-white/10 dark:bg-muted/15">
                            <div className="flex flex-col gap-3 @md:flex-row @md:items-center @md:justify-between">
                              <div>
                                <p className="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                  Active window
                                </p>
                                <p className="mt-1 font-mono text-2xl font-bold tabular-nums text-violet-950 dark:text-violet-100">
                                  {ndSetting.start_time ?? '22:00'}
                                  <span className="mx-1.5 font-sans text-lg font-normal text-muted-foreground">→</span>
                                  {ndSetting.end_time ?? '06:00'}
                                </p>
                                <div className="mt-2 flex flex-wrap items-center gap-2">
                                  <span className="text-xs text-muted-foreground">
                                    ≈{' '}
                                    <span className="font-medium text-foreground">
                                      {formatNdDurationLabel(
                                        ndSetting.start_time ?? '22:00',
                                        ndSetting.end_time ?? '06:00'
                                      )}
                                    </span>{' '}
                                    per calendar day (for this window shape)
                                  </span>
                                  {ndIsOvernightWindow(
                                    ndSetting.start_time ?? '22:00',
                                    ndSetting.end_time ?? '06:00'
                                  ) ? (
                                    <Badge
                                      variant="secondary"
                                      className="border-violet-300/60 bg-violet-100/80 text-violet-900 dark:border-violet-700/50 dark:bg-violet-950/60 dark:text-violet-200"
                                    >
                                      Overnight window
                                    </Badge>
                                  ) : (
                                    <Badge variant="outline" className="text-xs">
                                      Same-day window
                                    </Badge>
                                  )}
                                </div>
                              </div>
                            </div>
                            <div>
                              <p className="mb-2 text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                                Quick presets
                              </p>
                              <div className="flex flex-wrap gap-2">
                                {ND_QUICK_PRESETS.map((p) => (
                                  <button
                                    key={p.label}
                                    type="button"
                                    onClick={() => applyNdPreset(p.start, p.end)}
                                    className={cn(
                                      'inline-flex flex-col items-start rounded-lg border px-3 py-2 text-left transition-colors',
                                      'border-border/60 bg-background hover:border-violet-400/60 hover:bg-violet-50/50 dark:border-white/10 dark:hover:bg-violet-950/30',
                                    )}
                                  >
                                    <span className="text-sm font-semibold tabular-nums">{p.label}</span>
                                    <span className="text-[10px] text-muted-foreground">{p.hint}</span>
                                  </button>
                                ))}
                              </div>
                            </div>
                          </div>

                          <NdNightTimeline
                            startTime={ndSetting.start_time ?? '22:00'}
                            endTime={ndSetting.end_time ?? '06:00'}
                          />

                          {/* Coarse adjust */}
                          <div>
                            <h4 className="text-sm font-semibold text-foreground">Adjust by hour</h4>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                              Move start/end on the hour; use exact fields below for minutes.
                            </p>
                            <div
                              className={cn(
                                'mt-4 grid gap-6 rounded-xl border border-violet-200/40 bg-violet-50/30 p-4 dark:border-violet-900/35 dark:bg-violet-950/20 @sm:grid-cols-2',
                                POLICY_CARD_HOVER
                              )}
                            >
                              <div>
                                <div className="flex items-baseline justify-between gap-2">
                                  <Label className="text-xs font-semibold">Night start (hour)</Label>
                                  <span className="font-mono text-sm font-medium text-foreground">
                                    {ndSetting.start_time ?? '22:00'}
                                  </span>
                                </div>
                                <input
                                  type="range"
                                  min={0}
                                  max={23}
                                  step={1}
                                  className="mt-3 h-2.5 w-full cursor-pointer accent-violet-600 dark:accent-violet-500"
                                  value={hourFromHHMM(ndSetting.start_time ?? '22:00')}
                                  onChange={(e) =>
                                    updateNdSetting(
                                      'start_time',
                                      applyHourToHHMM(ndSetting.start_time ?? '22:00', Number(e.target.value))
                                    )
                                  }
                                />
                              </div>
                              <div>
                                <div className="flex items-baseline justify-between gap-2">
                                  <Label className="text-xs font-semibold">Night end (hour)</Label>
                                  <span className="font-mono text-sm font-medium text-foreground">
                                    {ndSetting.end_time ?? '06:00'}
                                  </span>
                                </div>
                                <input
                                  type="range"
                                  min={0}
                                  max={23}
                                  step={1}
                                  className="mt-3 h-2.5 w-full cursor-pointer accent-violet-600 dark:accent-violet-500"
                                  value={hourFromHHMM(ndSetting.end_time ?? '06:00')}
                                  onChange={(e) =>
                                    updateNdSetting(
                                      'end_time',
                                      applyHourToHHMM(ndSetting.end_time ?? '06:00', Number(e.target.value))
                                    )
                                  }
                                />
                              </div>
                            </div>
                          </div>

                          {/* Exact times */}
                          <div>
                            <h4 className="text-sm font-semibold text-foreground">Exact times</h4>
                            <p className="mt-0.5 text-xs text-muted-foreground">24-hour clock (HH:MM).</p>
                            <div className="mt-3 grid gap-4 @sm:grid-cols-2">
                              <div>
                                <Label className="text-xs font-medium">Start</Label>
                                <Input
                                  className="mt-1.5 h-11 font-mono text-base"
                                  value={ndSetting.start_time ?? '22:00'}
                                  onChange={(e) => updateNdSetting('start_time', e.target.value)}
                                  placeholder="22:00"
                                  autoComplete="off"
                                />
                              </div>
                              <div>
                                <Label className="text-xs font-medium">End</Label>
                                <Input
                                  className="mt-1.5 h-11 font-mono text-base"
                                  value={ndSetting.end_time ?? '06:00'}
                                  onChange={(e) => updateNdSetting('end_time', e.target.value)}
                                  placeholder="06:00"
                                  autoComplete="off"
                                />
                              </div>
                            </div>
                          </div>

                          {/* Add-on rate */}
                          <div className="rounded-xl border border-border/50 bg-card p-4 dark:border-white/10">
                            <h4 className="text-sm font-semibold text-foreground">ND add-on rate</h4>
                            <p className="mt-1 text-xs text-muted-foreground">
                              Applied on top of the applicable base (regular / OT / holiday stacks per engine). Range
                              0–1 (e.g. 0.10 = +10%).
                            </p>
                            <div className="mt-4 flex flex-wrap items-end gap-4">
                              <div>
                                <Label className="text-xs font-medium">Multiplier</Label>
                                <Input
                                  type="number"
                                  step="0.01"
                                  min="0"
                                  max="1"
                                  className="mt-1.5 h-11 w-32 font-mono text-base tabular-nums"
                                  value={ndSetting.nd_addon_multiplier ?? '0.10'}
                                  onChange={(e) => updateNdSetting('nd_addon_multiplier', e.target.value)}
                                />
                              </div>
                              <div className="rounded-lg border border-violet-200/60 bg-violet-50/50 px-4 py-3 dark:border-violet-800/50 dark:bg-violet-950/30">
                                <p className="text-[11px] font-medium uppercase tracking-wide text-muted-foreground">
                                  Effective add-on
                                </p>
                                <p className="mt-1 text-2xl font-bold tabular-nums text-violet-900 dark:text-violet-100">
                                  {((parseFloat(ndSetting.nd_addon_multiplier) || 0) * 100).toFixed(0)}%
                                </p>
                              </div>
                            </div>
                          </div>

                          {/* Stack on */}
                          <div>
                            <h4 className="text-sm font-semibold text-foreground">Stack ND on</h4>
                            <p className="mt-0.5 text-xs text-muted-foreground">
                              Choose which computed hour types receive the ND add-on when work falls in the window.
                            </p>
                            <div className="mt-3 grid gap-3 @md:grid-cols-3">
                              {[
                                {
                                  field: 'apply_to_regular',
                                  title: 'Regular hours',
                                  desc: 'Scheduled ordinary time inside ND window.',
                                  checked: ndSetting.apply_to_regular !== false,
                                },
                                {
                                  field: 'apply_to_ot',
                                  title: 'Overtime',
                                  desc: 'OT hours that fall inside ND window.',
                                  checked: ndSetting.apply_to_ot !== false,
                                },
                                {
                                  field: 'apply_to_premium_days',
                                  title: 'Premium days',
                                  desc: 'Rest day & holiday stacks when ND applies.',
                                  checked: ndSetting.apply_to_premium_days !== false,
                                },
                              ].map(({ field, title, desc, checked }) => (
                                <label
                                  key={field}
                                  className={cn(
                                    'flex cursor-pointer flex-col gap-2 rounded-xl border p-4 transition-colors',
                                    checked
                                      ? 'border-violet-300/70 bg-violet-50/40 dark:border-violet-700/50 dark:bg-violet-950/25'
                                      : 'border-border/60 bg-muted/20 hover:bg-muted/30 dark:border-white/10',
                                  )}
                                >
                                  <div className="flex items-start gap-3">
                                    <Checkbox
                                      checked={checked}
                                      onCheckedChange={(v) => updateNdSetting(field, !!v)}
                                      className="mt-0.5"
                                    />
                                    <span className="text-sm font-semibold leading-snug">{title}</span>
                                  </div>
                                  <p className="pl-7 text-xs leading-relaxed text-muted-foreground">{desc}</p>
                                </label>
                              ))}
                            </div>
                          </div>
                        </CardContent>
                      </Card>
                    </Motion.div>
                  </TabsContent>

                  <TabsContent value="priority" className="mt-0 outline-none">
                    <Card
                      className={cn(
                        'border-0 bg-card shadow-sm overflow-hidden',
                        POLICY_CARD_HOVER_CLIP
                      )}
                    >
                      <CardHeader className="border-b border-border/40 bg-muted/20 pb-3 dark:border-border/40 dark:bg-muted/25">
                        <CardTitle className="text-base font-semibold">Rule priority</CardTitle>
                        <CardDescription>
                          Evaluation order for classification in previews (business rules stay fixed in engine).
                        </CardDescription>
                      </CardHeader>
                      <CardContent className="space-y-4">
                        <div
                          className={cn(
                            'flex flex-wrap items-center gap-2 rounded-xl border border-dashed border-border/50 bg-white/80 px-4 py-3 text-sm dark:border-border/40 dark:bg-muted/25',
                            POLICY_CARD_HOVER_CLIP
                          )}
                        >
                          {priorityOrder.map((item, i) => (
                            <React.Fragment key={item}>
                              {i > 0 && (
                                <ArrowRight className="size-4 shrink-0 text-muted-foreground" />
                              )}
                              <span className="rounded-full bg-card px-3 py-1 text-xs font-medium shadow-sm ring-1 ring-border/50 dark:bg-background/50">
                                {PRIORITY_LABELS[item] || item}
                              </span>
                            </React.Fragment>
                          ))}
                        </div>
                        <p className="text-xs text-muted-foreground">
                          Drag rows to reorder how rules are evaluated in previews (first = highest precedence in
                          this list).
                        </p>
                        <div className="space-y-2">
                          {priorityOrder.map((item, i) => (
                            <div
                              key={item}
                              draggable
                              onDragStart={() => setPriorityDragIdx(i)}
                              onDragEnd={() => setPriorityDragIdx(null)}
                              onDragOver={(e) => e.preventDefault()}
                              onDrop={(e) => {
                                e.preventDefault()
                                if (priorityDragIdx === null || priorityDragIdx === i) return
                                movePriorityToIndex(priorityDragIdx, i)
                                setPriorityDragIdx(null)
                              }}
                              className={cn(
                                'flex cursor-grab items-center gap-3 rounded-lg border border-border/50 bg-card px-3 py-2.5 shadow-sm transition-all duration-300 ease-out hover:-translate-y-0.5 hover:shadow-md hover:shadow-slate-900/[0.06] active:cursor-grabbing dark:border-border/40 dark:bg-card/90 dark:hover:shadow-black/25',
                                priorityDragIdx === i && 'opacity-60 ring-2 ring-primary/35'
                              )}
                            >
                              <GripVertical className="size-4 shrink-0 text-muted-foreground" aria-hidden />
                              <div className="flex min-w-0 flex-1 flex-col @sm:flex-row @sm:items-center @sm:gap-2">
                                <span className="font-mono text-[11px] text-muted-foreground">{item}</span>
                                <span className="text-sm font-semibold">
                                  {PRIORITY_LABELS[item] || item}
                                </span>
                              </div>
                              <span className="hidden text-[10px] font-medium uppercase tracking-wide text-muted-foreground sm:inline">
                                Drag
                              </span>
                            </div>
                          ))}
                        </div>
                      </CardContent>
                    </Card>
                  </TabsContent>

                  <TabsContent value="preview" className="mt-0 space-y-4 outline-none">
                    <Card
                      className={cn(
                        'border-0 bg-card shadow-sm overflow-hidden',
                        POLICY_CARD_HOVER_CLIP
                      )}
                    >
                      <CardHeader className="border-b border-border/40 bg-muted/20 pb-3 dark:border-border/40 dark:bg-muted/25">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                          <div>
                            <CardTitle className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 text-base font-semibold">
                              <Sparkles className="size-4 shrink-0 text-primary" aria-hidden />
                              <span className="min-w-0">Preview &amp; simulation</span>
                            </CardTitle>
                            <CardDescription>
                              Scenario math on top; server formula strings below for parity with payroll engine.
                            </CardDescription>
                          </div>
                          {loadingPreview && (
                            <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                              <Loader2 className="size-3.5 animate-spin" />
                              Syncing…
                            </span>
                          )}
                        </div>
                      </CardHeader>
                      <CardContent className="space-y-6">
                        {previewRuleRow && (
                          <div
                            className={cn(
                              'grid gap-4 rounded-xl border border-border/40 bg-muted/30 p-4 dark:border-border/40 dark:bg-muted/20 @sm:grid-cols-3',
                              POLICY_CARD_HOVER
                            )}
                          >
                            <div>
                              <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                                First 8 × (base)
                              </p>
                              <p className="mt-1 text-2xl font-bold tabular-nums text-foreground">
                                {(parseFloat(previewRuleRow.first8_multiplier) || 0).toFixed(2)}
                                <span className="text-base font-semibold text-muted-foreground">×</span>
                              </p>
                            </div>
                            <div>
                              <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                                OT ×
                              </p>
                              <p className="mt-1 text-2xl font-bold tabular-nums text-foreground">
                                {(parseFloat(previewRuleRow.ot_multiplier) || 0).toFixed(2)}
                                <span className="text-base font-semibold text-muted-foreground">×</span>
                              </p>
                            </div>
                            <div>
                              <p className="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">
                                ND add-on
                              </p>
                              <p className="mt-1 text-2xl font-bold tabular-nums text-foreground">
                                +{((parseFloat(previewRuleRow.nd_addon_multiplier) || 0) * 100).toFixed(0)}
                                <span className="text-base font-semibold text-muted-foreground">%</span>
                              </p>
                              <p className="mt-0.5 text-[10px] text-muted-foreground">on applicable base</p>
                            </div>
                          </div>
                        )}
                        <div className="grid gap-4 @sm:grid-cols-2 lg:grid-cols-3 @xl:grid-cols-6">
                          <div className="min-w-0 @sm:col-span-2 lg:col-span-2">
                            <Label className="text-xs">Day / rule</Label>
                            <select
                              className={cn('mt-1 w-full max-w-full', FIELD_SELECT_CLASS)}
                              value={previewRule}
                              onChange={(e) => setPreviewRule(e.target.value)}
                            >
                              {Object.entries(CONDITION_LABELS).map(([k, lbl]) => (
                                <option key={k} value={k}>
                                  {`${k} — ${lbl}`}
                                </option>
                              ))}
                            </select>
                          </div>
                          <div>
                            <Label className="text-xs">Hourly rate (₱)</Label>
                            <Input
                              type="number"
                              className="mt-1 font-mono tabular-nums"
                              value={previewHourly}
                              onChange={(e) => setPreviewHourly(e.target.value)}
                            />
                          </div>
                          <div>
                            <Label className="text-xs">Regular hours (≤8 in bucket)</Label>
                            <Input
                              type="number"
                              step="0.25"
                              min="0"
                              className="mt-1 font-mono tabular-nums"
                              value={previewRegHours}
                              onChange={(e) => setPreviewRegHours(e.target.value)}
                            />
                          </div>
                          <div>
                            <Label className="text-xs">OT hours</Label>
                            <Input
                              type="number"
                              step="0.25"
                              min="0"
                              className="mt-1 font-mono tabular-nums"
                              value={previewOtHours}
                              onChange={(e) => setPreviewOtHours(e.target.value)}
                            />
                          </div>
                          <div>
                            <Label className="text-xs">ND hours (illustrative)</Label>
                            <Input
                              type="number"
                              step="0.25"
                              min="0"
                              className="mt-1 font-mono tabular-nums"
                              value={previewNdHours}
                              onChange={(e) => setPreviewNdHours(e.target.value)}
                            />
                          </div>
                        </div>

                        <div
                          className={cn(
                            'overflow-x-auto rounded-xl border border-border/40 bg-card dark:border-border/40 dark:bg-card/90',
                            POLICY_CARD_HOVER_CLIP
                          )}
                        >
                          <table className="w-full min-w-[520px] text-sm text-foreground">
                            <thead>
                              <tr className="border-b border-border bg-[#f1f5f9] text-left text-[11px] font-bold uppercase tracking-wider text-muted-foreground shadow-[0_1px_0_0_var(--border)] dark:border-border/40 dark:bg-muted/35">
                                <th className="px-4 py-2.5">Component</th>
                                <th className="px-4 py-2.5 text-right tabular-nums">Hours</th>
                                <th className="px-4 py-2.5">Basis</th>
                                <th className="px-4 py-2.5 text-right tabular-nums">Amount (₱)</th>
                              </tr>
                            </thead>
                            <tbody className="bg-card">
                              {previewBreakdown.rows.map((r, rowIdx) => (
                                <tr
                                  key={r.key}
                                  className={cn(
                                    'border-b border-border/20 transition-colors dark:border-border/40',
                                    rowIdx % 2 === 1
                                      ? 'bg-white hover:bg-slate-50 dark:bg-card/80 dark:hover:bg-muted/30'
                                      : 'bg-[#f8fafc] hover:bg-slate-50 dark:bg-muted/20 dark:hover:bg-muted/30'
                                  )}
                                >
                                  <td className="px-4 py-2.5 font-medium">{r.label}</td>
                                  <td className="px-4 py-2.5 text-right tabular-nums text-muted-foreground">
                                    {r.hours > 0 ? r.hours : '—'}
                                  </td>
                                  <td className="px-4 py-2.5 font-mono text-xs text-muted-foreground">
                                    {r.detail}
                                  </td>
                                  <td className="px-4 py-2.5 text-right text-base font-semibold tabular-nums">
                                    {previewBreakdown.total > 0 || r.amount > 0
                                      ? r.amount.toLocaleString(undefined, {
                                          minimumFractionDigits: 2,
                                          maximumFractionDigits: 2,
                                        })
                                      : '—'}
                                  </td>
                                </tr>
                              ))}
                              <tr className="border-t border-border/40 bg-muted/30 dark:border-border/40 dark:bg-muted/30">
                                <td className="px-4 py-3 font-semibold" colSpan={3}>
                                  Estimated total
                                </td>
                                <td className="px-4 py-3 text-right text-lg font-bold tabular-nums">
                                  ₱
                                  {previewBreakdown.total.toLocaleString(undefined, {
                                    minimumFractionDigits: 2,
                                    maximumFractionDigits: 2,
                                  })}
                                </td>
                              </tr>
                            </tbody>
                          </table>
                        </div>

                        {preview && (
                          <div
                            className={cn(
                              'space-y-2 rounded-xl border border-border/40 bg-muted/20 p-4 dark:border-border/40 dark:bg-muted/25',
                              POLICY_CARD_HOVER_CLIP
                            )}
                          >
                            <p className="text-xs font-medium uppercase tracking-wide text-muted-foreground">
                              Engine formula strings
                            </p>
                            <div className="grid gap-2 font-mono text-sm @sm:grid-cols-1">
                              <div>
                                <span className="text-muted-foreground">First 8: </span>
                                {preview.first8_formula}
                              </div>
                              <div>
                                <span className="text-muted-foreground">OT: </span>
                                {preview.ot_formula}
                              </div>
                              <div>
                                <span className="text-muted-foreground">ND: </span>
                                {preview.nd_formula}
                              </div>
                            </div>
                            {preview.example_first8_8h != null && (
                              <div className="border-t border-border/40 pt-3 text-sm text-muted-foreground dark:border-border/50">
                                API check (8h / 2h OT): ₱{preview.example_first8_8h.toFixed(2)}
                                {preview.example_ot_2h != null && (
                                  <span className="ml-4">
                                    + OT sample ₱{preview.example_ot_2h.toFixed(2)}
                                  </span>
                                )}
                              </div>
                            )}
                          </div>
                        )}

                        <Button
                          type="button"
                          size="sm"
                          variant="outline"
                          onClick={fetchPreview}
                          disabled={loadingPreview}
                          className="w-full @sm:w-auto"
                        >
                          {loadingPreview ? (
                            <Loader2 className="size-4 animate-spin" />
                          ) : (
                            'Refresh engine formulas'
                          )}
                        </Button>
                      </CardContent>
                    </Card>
                  </TabsContent>
                </Tabs>
              </>
            )}
          </div>
        </div>
      </Motion.div>

      <Dialog open={newPolicyModalOpen} onOpenChange={setNewPolicyModalOpen}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_MD)}
          aria-describedby="new-policy-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>New policy</DialogTitle>
              <p id="new-policy-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                Defaults come from payroll config; you can edit everything after creation.
              </p>
            </DialogHeader>
          </div>
          <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'space-y-3')}>
            <div>
              <Label htmlFor="new-policy-name">Name</Label>
              <Input
                id="new-policy-name"
                className="mt-1"
                placeholder="e.g. Company A — 2026"
                value={newPolicyName}
                onChange={(e) => setNewPolicyName(e.target.value)}
                disabled={creating}
                autoComplete="off"
              />
            </div>
            <div>
              <Label htmlFor="new-policy-effective">Effective date</Label>
              <Input
                id="new-policy-effective"
                type="date"
                className="mt-1 dark:[color-scheme:dark]"
                value={newPolicyEffective}
                onChange={(e) => setNewPolicyEffective(e.target.value)}
                disabled={creating}
              />
            </div>
            <div>
              <Label htmlFor="new-policy-company">Company (optional)</Label>
              <select
                id="new-policy-company"
                className={cn('mt-1', FIELD_SELECT_CLASS)}
                value={newPolicyCompanyId}
                onChange={(e) => setNewPolicyCompanyId(e.target.value)}
                disabled={creating}
              >
                <option value="">Global (all companies)</option>
                {companies.map((c) => (
                  <option key={c.id} value={c.id}>
                    {c.name}
                  </option>
                ))}
              </select>
            </div>
          </div>
          <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
            <Button
              type="button"
              variant="outline"
              onClick={() => setNewPolicyModalOpen(false)}
              disabled={creating}
            >
              Cancel
            </Button>
            <Button
              type="button"
              onClick={handleCreatePolicy}
              disabled={creating || !newPolicyName.trim()}
              className={ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS}
            >
              {creating ? <Loader2 className="size-4 animate-spin mr-1" /> : <Plus className="size-4 mr-1" />}
              Create policy
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={headerSaveModalOpen} onOpenChange={setHeaderSaveModalOpen}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_MD)}
          aria-describedby="policy-save-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Save changes</DialogTitle>
              <p id="policy-save-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                This will persist your edits to this policy. For employees assigned to it, saved multipliers and
                night-differential settings are what payroll uses on the next run.
              </p>
            </DialogHeader>
          </div>
          <DialogFooter className={cn(ADMIN_FORM_DIALOG_FOOTER_CLASS, 'mt-auto')}>
            <Button
              type="button"
              variant="outline"
              onClick={() => setHeaderSaveModalOpen(false)}
              disabled={saving}
            >
              Cancel
            </Button>
            <Button
              type="button"
              onClick={() => {
                setHeaderSaveModalOpen(false)
                void handleSave()
              }}
              disabled={saving}
              className={ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS}
            >
              {saving ? <Loader2 className="size-4 animate-spin mr-1" /> : <Save className="size-4 mr-1" />}
              Save changes
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </PayrollLogisticsPolicyShell>
  )
}
