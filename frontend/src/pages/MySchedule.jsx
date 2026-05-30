import { createElement, useMemo, useState } from 'react'
import {
  Calendar,
  CalendarClock,
  ChevronRight,
  Clock3,
  Filter,
  Inbox,
  Loader2,
  Plus,
  RefreshCw,
  Search,
  Sparkles,
  Timer,
  Trash2,
  Workflow,
} from 'lucide-react'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Textarea } from '@/components/ui/textarea'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { Skeleton } from '@/components/ui/skeleton'
import { useToast } from '@/components/ui/use-toast'
import { useAuth } from '@/contexts/AuthContext'
import { useHrBasePath } from '@/contexts/HrAppPathContext'
import { ApprovalChainDetailView } from '@/components/approval/ApprovalChainDetailView'
import { createMyScheduleRequest, deleteMyScheduleRequest, getMySchedule, getMyScheduleRequestContext } from '@/api'
import { cn } from '@/lib/utils'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import { Checkbox } from '@/components/ui/checkbox'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { weeklyScheduledHours } from '@/lib/scheduleLib'
import { RemarksPreviewCell } from '@/components/presenceFiling/CorrectionTableCells'
import { ScheduleRequestStatusBadge } from '@/components/schedules/ScheduleRequestTableCells'
import { AdminDataTableActions } from '@/components/admin/AdminDataTableActions'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { sanitizeApprovalDisplayText } from '@/lib/approvalText'
import { formatClockTimeDisplay, formatShiftRange12h } from '@/lib/timeFormat'

const DAY_OPTIONS = [
  { key: 'mon', label: 'M', full: 'Monday' },
  { key: 'tue', label: 'T', full: 'Tuesday' },
  { key: 'wed', label: 'W', full: 'Wednesday' },
  { key: 'thu', label: 'Th', full: 'Thursday' },
  { key: 'fri', label: 'F', full: 'Friday' },
  { key: 'sat', label: 'S', full: 'Saturday' },
  { key: 'sun', label: 'Su', full: 'Sunday' },
]

const DEFAULT_CUSTOM_FORM = {
  name: '',
  time_in: '08:00',
  time_out: '17:00',
  rest_days: ['sat', 'sun'],
  break_duration_minutes: '60',
  grace_period_minutes: '0',
  overtime_buffer_minutes: '15',
  shift_type: '',
}

function toggleRestDay(restDays, dayKey) {
  const current = new Set(restDays || [])
  if (current.has(dayKey)) current.delete(dayKey)
  else current.add(dayKey)
  return Array.from(current)
}

function formatDateTime(value) {
  if (!value) return '—'
  const date = new Date(value)
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString('en-PH', { dateStyle: 'medium', timeStyle: 'short' })
}

function formatHistoryAction(action) {
  return String(action || 'update')
    .replace(/_/g, ' ')
    .replace(/\b\w/g, (char) => char.toUpperCase())
}

function todayIsoLocal() {
  const d = new Date()
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

function formatShortDate(iso) {
  if (!iso) return '—'
  const d = new Date(`${iso}T12:00:00`)
  return Number.isNaN(d.getTime()) ? iso : d.toLocaleDateString('en-PH', { dateStyle: 'medium' })
}

/** API maps custom proposals into `working_schedule` summary; use kind for the user-facing label. */
function requestedScheduleTitle(item) {
  if (String(item?.request_kind || '').toLowerCase() === 'custom') {
    return 'Custom schedule'
  }
  return item?.working_schedule?.name || 'Template schedule'
}

function requestedScheduleSubtitle(item) {
  const kind = String(item?.request_kind || '').toLowerCase()
  const payloadName = item?.custom_schedule_payload?.name
  if (kind === 'custom' && payloadName) return String(payloadName)
  return null
}

function scheduleTimeRange(item) {
  const ws = item?.working_schedule
  const tIn = ws?.time_in
  const tOut = ws?.time_out
  if (!tIn && !tOut) return '—'
  return `${tIn || '—'} – ${tOut || '—'}`
}

function scheduleDetailSummary(item) {
  const ws = item?.working_schedule || {}
  const payload = item?.custom_schedule_payload || {}
  const restDays = Array.isArray(ws.rest_days) && ws.rest_days.length ? ws.rest_days : (Array.isArray(payload.rest_days) ? payload.rest_days : [])
  const workDaysCount = typeof ws.work_days_per_week === 'number'
    ? ws.work_days_per_week
    : Math.max(0, 7 - restDays.length)
  const breakMinutes = Number(payload.break_duration_minutes ?? 0)
  const hasBreakWindow = ws.break_start && ws.break_end
  const breakLabel = hasBreakWindow
    ? `${ws.break_start} – ${ws.break_end}`
    : breakMinutes > 0
      ? `${breakMinutes} min`
      : 'No break set'
  return {
    restDays,
    workDaysCount,
    breakLabel,
  }
}

function compactDayLabel(value) {
  const key = String(value || '').trim().toLowerCase().slice(0, 3)
  const labels = {
    mon: 'MON',
    tue: 'TUE',
    wed: 'WED',
    thu: 'THU',
    fri: 'FRI',
    sat: 'SAT',
    sun: 'SUN',
  }
  return labels[key] || String(value || '').trim().toUpperCase()
}

export default function MySchedule() {
  const { user } = useAuth()
  const hrBase = useHrBasePath()
  const { toast } = useToast()
  const queryClient = useQueryClient()
  const [dialogOpen, setDialogOpen] = useState(false)
  const [detailOpen, setDetailOpen] = useState(false)
  const [selectedRequest, setSelectedRequest] = useState(null)
  const [requestMode, setRequestMode] = useState('template')
  const [customForm, setCustomForm] = useState(() => ({ ...DEFAULT_CUSTOM_FORM }))
  const [form, setForm] = useState({ working_schedule_id: '', remarks: '', effective_from: todayIsoLocal() })
  const [schedulePickerOpen, setSchedulePickerOpen] = useState(false)
  const [scheduleSearch, setScheduleSearch] = useState('')
  const [requestsQuery, setRequestsQuery] = useState('')
  const [deleteDialog, setDeleteDialog] = useState({ open: false, request: null })

  const canRequest = useMemo(() => new Set(user?.permissions || []).has('request-schedule'), [user])
  const canApprove = useMemo(() => new Set(user?.permissions || []).has('approve-schedule'), [user])

  const scheduleQuery = useQuery({
    queryKey: ['my-schedule'],
    queryFn: getMySchedule,
    staleTime: 5 * 60 * 1000,
  })

  const requestContextQuery = useQuery({
    queryKey: ['my-schedule-request-context'],
    queryFn: getMyScheduleRequestContext,
    enabled: dialogOpen,
    staleTime: 5 * 60 * 1000,
  })

  const createRequestMutation = useMutation({
    mutationFn: createMyScheduleRequest,
    onMutate: async (nextPayload) => {
      await queryClient.cancelQueries({ queryKey: ['my-schedule'] })
      const previous = queryClient.getQueryData(['my-schedule'])
      return { previous, nextPayload }
    },
    onSuccess: (created) => {
      if (created?.request) {
        queryClient.setQueryData(['my-schedule'], (prev) => {
          const safePrev = prev || {}
          const existing = Array.isArray(safePrev.requests) ? safePrev.requests : []
          return {
            ...safePrev,
            requests: [created.request, ...existing.filter((r) => Number(r.id) !== Number(created.request.id))],
          }
        })
      }
      toast({
        title: 'Request sent',
        description:
          "Your schedule change request has been submitted successfully. You will be notified once it's reviewed.",
      })
      setDialogOpen(false)
      setRequestMode('template')
      setCustomForm({ ...DEFAULT_CUSTOM_FORM })
      setForm({ working_schedule_id: '', remarks: '', effective_from: todayIsoLocal() })
    },
    onError: (error, _variables, context) => {
      if (context?.previous) {
        queryClient.setQueryData(['my-schedule'], context.previous)
      }
      toast({ title: 'Schedule request', description: error.message || 'Failed to submit schedule request', variant: 'destructive' })
    },
    onSettled: () => {
      queryClient.invalidateQueries({ queryKey: ['my-schedule'] })
    },
  })

  const deleteRequestMutation = useMutation({
    mutationFn: deleteMyScheduleRequest,
    onSuccess: () => {
      toast({ title: 'Request deleted', description: 'Your pending schedule request was deleted.' })
      setDeleteDialog({ open: false, request: null })
      queryClient.invalidateQueries({ queryKey: ['my-schedule'] })
    },
    onError: (error) => {
      toast({ title: 'Schedule request', description: error.message || 'Failed to delete schedule request', variant: 'destructive' })
    },
  })

  async function openRequestDialog() {
    setDialogOpen(true)
    setRequestMode('template')
    setCustomForm({ ...DEFAULT_CUSTOM_FORM })
    setForm((prev) => ({ ...prev, effective_from: todayIsoLocal() }))
  }

  function handleSubmit(e) {
    e.preventDefault()
    if (requestMode === 'template') {
      createRequestMutation.mutate({
        request_kind: 'template',
        working_schedule_id: Number(form.working_schedule_id),
        effective_from: form.effective_from,
        remarks: form.remarks.trim() || null,
      })
    } else {
      createRequestMutation.mutate({
        request_kind: 'custom',
        effective_from: form.effective_from,
        remarks: form.remarks.trim() || null,
        custom_schedule: {
          name: customForm.name.trim(),
          time_in: customForm.time_in,
          time_out: customForm.time_out,
          rest_days: customForm.rest_days,
          break_duration_minutes:
            customForm.break_duration_minutes ? Number(customForm.break_duration_minutes) : null,
          grace_period_minutes: customForm.grace_period_minutes ? Number(customForm.grace_period_minutes) : 0,
          overtime_buffer_minutes: customForm.overtime_buffer_minutes
            ? Number(customForm.overtime_buffer_minutes)
            : 15,
          shift_type: customForm.shift_type || null,
        },
      })
    }
  }

  const requestContext = requestContextQuery.data || null
  const current = scheduleQuery.data?.current_schedule || null
  const pendingChange = scheduleQuery.data?.pending_schedule_change || null
  const requests = Array.isArray(scheduleQuery.data?.requests) ? scheduleQuery.data.requests : []
  const loading = scheduleQuery.isLoading
  const refreshing = scheduleQuery.isFetching
  const submitting = createRequestMutation.isPending
  const filteredRequests = useMemo(() => {
    const q = requestsQuery.trim().toLowerCase()
    if (!q) return requests
    return requests.filter((item) => {
      const id = String(item.id ?? '')
      const sched = `${item.working_schedule?.name || ''} ${item.custom_schedule_payload?.name || ''}`.toLowerCase()
      const label = `${requestedScheduleTitle(item)} ${requestedScheduleSubtitle(item) || ''}`.toLowerCase()
      const times = `${item.working_schedule?.time_in || ''} ${item.working_schedule?.time_out || ''} ${item.custom_schedule_payload?.time_in || ''} ${item.custom_schedule_payload?.time_out || ''}`.toLowerCase()
      const status = `${item.display_status || ''} ${item.status || ''}`.toLowerCase()
      const remarks = String(item.remarks || '').toLowerCase()
      return (
        id.includes(q) ||
        sched.includes(q) ||
        label.includes(q) ||
        times.includes(q) ||
        status.includes(q) ||
        remarks.includes(q)
      )
    })
  }, [requests, requestsQuery])

  const cellPad = '!p-3.5'
  const restDayBadges = Array.isArray(current?.rest_days) ? current.rest_days : []
  const workDayBadges = current?.work_days_label ? String(current.work_days_label).split(',').map((value) => value.trim()).filter(Boolean) : []
  const breakDuration = useMemo(() => {
    if (!current?.break_start || !current?.break_end) return null
    const [startHour, startMinute] = String(current.break_start).split(':').map((value) => Number(value))
    const [endHour, endMinute] = String(current.break_end).split(':').map((value) => Number(value))
    if ([startHour, startMinute, endHour, endMinute].some((value) => Number.isNaN(value))) return `${current.break_start} - ${current.break_end}`
    const totalMinutes = ((endHour * 60) + endMinute) - ((startHour * 60) + startMinute)
    if (totalMinutes <= 0) return `${current.break_start} - ${current.break_end}`
    const hours = Math.floor(totalMinutes / 60)
    const minutes = totalMinutes % 60
    if (hours > 0 && minutes > 0) return `${hours}h ${minutes}m`
    if (hours > 0) return `${hours} hour${hours === 1 ? '' : 's'}`
    return `${minutes} min`
  }, [current])
  const workDaysPerWeek = workDayBadges.length > 0 ? `${workDayBadges.length} day${workDayBadges.length === 1 ? '' : 's'}` : '—'
  const availableSchedules = Array.isArray(requestContext?.available_schedules) ? requestContext.available_schedules : []
  const filteredSchedules = useMemo(() => {
    const needle = scheduleSearch.trim().toLowerCase()
    return availableSchedules.filter((schedule) => {
      if (!needle) return true
      const haystack = `${schedule.name} ${schedule.time_in} ${schedule.time_out} ${schedule.rest_days_label} ${schedule.work_days_label}`.toLowerCase()
      return haystack.includes(needle)
    })
  }, [availableSchedules, scheduleSearch])
  const selectedSchedule = useMemo(
    () => availableSchedules.find((schedule) => String(schedule.id) === String(form.working_schedule_id)) || null,
    [availableSchedules, form.working_schedule_id]
  )

  const rightPanelSchedule = useMemo(() => {
    if (requestMode === 'template') return selectedSchedule
    const name = customForm.name.trim()
    if (!name) return null
    const rest = customForm.rest_days || []
    const workLabels = DAY_OPTIONS.filter((d) => !rest.includes(d.key)).map((d) => d.full)
    return {
      id: null,
      name,
      time_in: customForm.time_in,
      time_out: customForm.time_out,
      break_start: null,
      break_end: null,
      rest_days: rest,
      rest_days_label: rest.length ? rest.map((r) => String(r).toUpperCase()).join(', ') : 'None',
      work_days_label: workLabels.join(', '),
    }
  }, [requestMode, selectedSchedule, customForm])

  const customWeeklyHoursPreview = useMemo(() => {
    return weeklyScheduledHours({
      time_in: customForm.time_in,
      time_out: customForm.time_out,
      break_start: null,
      break_end: null,
      rest_days: customForm.rest_days || [],
    })
  }, [customForm.time_in, customForm.time_out, customForm.rest_days])

  const submitDisabled =
    submitting ||
    !form.effective_from ||
    (requestMode === 'template' && !form.working_schedule_id) ||
    (requestMode === 'custom' &&
      (!customForm.name.trim() || !customForm.time_in || !customForm.time_out || customForm.rest_days.length >= 7))

  return (
    <div className="space-y-6 scheme-light dark:scheme-dark">
      <section className="relative overflow-hidden rounded-2xl border border-border bg-card p-6 text-card-foreground shadow-sm dark:border-border dark:shadow-[0_14px_48px_-28px_rgba(0,0,0,0.55)] sm:p-8">
        <div className="pointer-events-none absolute right-8 top-6 hidden h-36 w-64 rounded-full bg-brand/10 blur-3xl lg:block" />
        <div className="relative flex flex-col gap-8 lg:flex-row lg:items-center lg:justify-between">
          <div className="max-w-2xl space-y-4">
            <p className="text-xs font-extrabold uppercase tracking-[0.18em] text-brand">My workplace hours</p>
            <div className="space-y-3">
              <h1 className="text-3xl font-extrabold tracking-tight text-foreground sm:text-4xl">My Schedule</h1>
              <p className="max-w-xl text-sm leading-relaxed text-muted-foreground">
                See the shift you are on today and, when you need a change, send a simple request for your manager and HR to review.
              </p>
            </div>
          </div>
          <div className="flex flex-col items-stretch gap-4 sm:flex-row sm:items-center lg:gap-8">
            <div className="hidden items-center justify-center lg:flex">
              <div className="relative flex size-36 items-center justify-center rounded-4xl bg-brand/15 text-brand">
                <CalendarClock className="size-20" aria-hidden />
                <div className="absolute -right-3 bottom-3 flex size-14 items-center justify-center rounded-full border border-border bg-card shadow-sm">
                  <Clock3 className="size-7" aria-hidden />
                </div>
              </div>
            </div>
            <div className="flex flex-wrap gap-3">
              {canApprove ? (
                <Button
                  variant="outline"
                  className="h-11 rounded-lg border-border bg-background px-4 font-semibold text-foreground hover:bg-muted"
                  onClick={() => window.location.assign(`${hrBase}/schedule-requests`)}
                >
                  <Workflow className="mr-2 size-4" />
                  Review team requests
                </Button>
              ) : null}
              {canRequest ? (
                <Button
                  className="h-11 rounded-lg bg-linear-to-r from-brand to-brand-strong px-5 font-bold text-brand-foreground shadow-[0_18px_32px_-20px_color-mix(in_oklab,var(--brand)_45%,transparent)] hover:opacity-95"
                  onClick={openRequestDialog}
                >
                  <Plus className="mr-2 size-4" />
                  Request schedule change
                </Button>
              ) : null}
            </div>
          </div>
        </div>
      </section>

      {pendingChange?.schedule ? (
        <div className="rounded-xl border border-border bg-muted/25 px-4 py-3 text-sm text-card-foreground shadow-sm">
          <p className="font-semibold text-foreground">Upcoming schedule change</p>
          <p className="mt-1 leading-relaxed text-muted-foreground">
            Starting <span className="font-semibold tabular-nums text-foreground">{formatShortDate(pendingChange.effective_from)}</span>, your schedule will update to{' '}
            <span className="font-semibold text-foreground">{pendingChange.schedule.name}</span>
            {pendingChange.schedule.time_in && pendingChange.schedule.time_out ? (
              <span className="text-muted-foreground">
                {' '}
                ({formatShiftRange12h(pendingChange.schedule.time_in, pendingChange.schedule.time_out)})
              </span>
            ) : null}
            .
          </p>
        </div>
      ) : null}

      <div className="grid gap-6">
        <Card className="overflow-hidden rounded-2xl border-border bg-card text-card-foreground shadow-sm dark:border-border dark:shadow-[0_14px_48px_-28px_rgba(0,0,0,0.45)]">
          <CardHeader className="px-6 py-6">
            <CardTitle className="text-xl font-bold tracking-tight text-foreground">Your current schedule</CardTitle>
            <CardDescription className="text-sm text-muted-foreground">
              This is what attendance, overtime, and leave use for your working hours.
            </CardDescription>
          </CardHeader>
          <CardContent className="px-4 pb-6 pt-0 sm:px-6">
            {loading ? (
              <div className="space-y-3">
                <Skeleton className="h-8 w-64" />
                <Skeleton className="h-40 w-full" />
              </div>
            ) : current ? (
              <div className="rounded-2xl border border-border bg-background p-4 shadow-sm dark:border-border sm:p-6">
                <div className="space-y-5">
                  <div className="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
                    <div className="min-w-0 space-y-5">
                      <div className="flex flex-wrap items-center gap-3">
                        <span className="text-xs font-semibold text-muted-foreground">Schedule type</span>
                        <Badge className="max-w-full rounded-full border-transparent bg-brand/15 px-3 py-1 text-[11px] font-bold text-brand hover:bg-brand/15">
                          {current.name}
                        </Badge>
                        <Badge className="rounded-full bg-chart-2/10 px-3 py-1 text-[11px] font-bold text-chart-2 hover:bg-chart-2/10">
                          {current.status || 'Active'}
                        </Badge>
                      </div>
                      <div className="flex items-center gap-4">
                        <div className="flex size-12 shrink-0 items-center justify-center rounded-xl bg-brand/15 text-brand ring-1 ring-brand/25">
                          <Calendar className="size-6" aria-hidden />
                        </div>
                        <h2 className="min-w-0 truncate text-2xl font-extrabold tracking-tight text-foreground sm:text-3xl">
                          {current.name}
                        </h2>
                      </div>
                      <div className="max-w-md rounded-xl border border-border bg-card px-4 py-4 shadow-sm dark:border-border">
                        <p className="text-[11px] font-bold uppercase tracking-[0.16em] text-muted-foreground">Shift Hours</p>
                        <p className="mt-2 text-2xl font-extrabold tracking-tight text-brand">
                          <span>{current.time_in}</span>
                          <span className="mx-2 text-muted-foreground">–</span>
                          <span>{current.time_out}</span>
                        </p>
                      </div>
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2 lg:min-w-96">
                      <ScheduleMetricCard icon={Calendar} label="Work Days / Week" value={workDaysPerWeek} />
                      <ScheduleMetricCard icon={Timer} label="Break Duration" value={breakDuration || 'No break'} />
                    </div>
                  </div>

                  <div className="grid overflow-hidden rounded-xl border border-border lg:grid-cols-[minmax(0,1fr)_minmax(0,1.75fr)] lg:divide-x lg:divide-border">
                    <DayStrip title="Rest Days" items={restDayBadges} emptyLabel="No configured rest days" tone="rest" />
                    <DayStrip title="Work Week" items={workDayBadges} emptyLabel="No configured work days" tone="work" />
                  </div>

                  <div className="grid gap-4 md:grid-cols-3">
                    <ScheduleMetaCard icon={Clock3} label="Start Time" value={current.time_in} />
                    <ScheduleMetaCard icon={Clock3} label="End Time" value={current.time_out} />
                    <ScheduleMetaCard icon={Timer} label="Break Window" value={current.break_start && current.break_end ? `${current.break_start} - ${current.break_end}` : 'No break set'} />
                  </div>
                </div>
              </div>
            ) : (
              <div className="rounded-xl border border-dashed border-border bg-background p-8 text-center dark:border-border/50">
                <CalendarClock className="mx-auto size-10 text-muted-foreground" />
                <h3 className="mt-4 text-lg font-semibold text-foreground">No schedule on file yet</h3>
                <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                  Once HR assigns a schedule, your shift times will show here.
                  {canRequest ? (
                    <>
                      {' '}
                      Use <span className="font-medium text-foreground">Request schedule change</span> at the top of this page when you need a different shift.
                    </>
                  ) : (
                    <> Contact HR if you need a different shift.</>
                  )}
                </p>
              </div>
            )}
          </CardContent>
        </Card>
      </div>

      <Card className="w-full min-w-0 overflow-hidden rounded-2xl border-border bg-card text-card-foreground shadow-sm dark:border-border dark:shadow-[0_14px_48px_-28px_rgba(0,0,0,0.45)]">
        <CardHeader className="space-y-1 px-5 py-5 @md:px-6 @md:py-6">
          <CardTitle className="text-xl font-bold tracking-tight text-foreground">
            Your schedule requests
          </CardTitle>
          <CardDescription className="text-sm text-muted-foreground">
            Request ID, proposed shift, your note, status, and when you submitted — same layout as Correction Requests.
          </CardDescription>
        </CardHeader>
        <CardContent className="p-0">
          {loading && requests.length === 0 ? (
            <div className="flex items-center justify-center py-16">
              <Loader2 className="size-8 animate-spin text-muted-foreground" />
            </div>
          ) : (
            <>
              <div className="border-b border-border px-4 py-5 @sm:px-6 md:px-8">
                <div className="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                  <div className="relative w-full min-w-0 max-w-full lg:max-w-3xl">
                    <Search
                      className="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                      aria-hidden
                    />
                    <Input
                      type="search"
                      value={requestsQuery}
                      onChange={(e) => setRequestsQuery(e.target.value)}
                      placeholder="Search request ID, schedule name, status, or note…"
                      className="h-12 w-full min-w-0 rounded-xl border-input bg-background pl-10 pr-4 text-[15px] text-foreground shadow-sm"
                      aria-label="Search schedule requests"
                    />
                  </div>
                  <div className="flex shrink-0 flex-wrap items-center gap-3">
                    <Button type="button" variant="outline" className="h-11 rounded-lg border-border bg-background px-4 hover:bg-muted">
                      <Filter className="mr-2 size-4" />
                      Filters
                    </Button>
                    <Button
                      type="button"
                      variant="outline"
                      size="icon"
                      className="size-11 rounded-lg border-border bg-background hover:bg-muted"
                      onClick={() => scheduleQuery.refetch()}
                      disabled={refreshing}
                      aria-label="Refresh requests"
                    >
                      {refreshing ? <Loader2 className="size-4 animate-spin" /> : <RefreshCw className="size-4" />}
                    </Button>
                  </div>
                </div>
              </div>

              {!loading && filteredRequests.length === 0 ? (
                <div className="flex flex-col items-center justify-center px-6 py-20 text-center">
                  <div className="mb-6 flex size-24 items-center justify-center rounded-3xl bg-brand/15 text-brand">
                    <Inbox className="size-12" aria-hidden />
                  </div>
                  <div className="flex items-center gap-2 text-xl font-bold tracking-tight text-foreground">
                    <Sparkles className="size-6 text-brand" aria-hidden />
                    {requests.length === 0 ? 'No schedule requests yet' : 'No requests match'}
                  </div>
                  <p className="mt-3 max-w-md text-sm leading-relaxed text-muted-foreground">
                    {requests.length === 0
                      ? 'When you request a template or custom schedule, it will appear here with live status updates. Submit a new request using the button at the top of this page.'
                      : 'Try a different search keyword or clear the filter.'}
                  </p>
                </div>
              ) : (
                <div className="w-full min-w-0 touch-pan-x overflow-x-auto bg-card px-4 pb-5 pt-0 sm:px-6 md:px-8">
                  <p className="py-3 text-xs text-muted-foreground">
                    <span className="font-semibold tabular-nums text-foreground">{filteredRequests.length}</span>
                    {filteredRequests.length === 1 ? ' request' : ' requests'}
                    {requestsQuery.trim() ? ' · filtered' : ''}
                  </p>
                  <Table className="w-full min-w-[900px]">
                    <TableHeader>
                      <TableRow className="border-b border-border bg-muted/20 hover:bg-muted/20">
                        <TableHead className={cn('min-w-26 py-3.5 pl-2 font-semibold sm:pl-3')}>
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Request ID</span>
                        </TableHead>
                        <TableHead className="min-w-44 py-3.5">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Schedule name</span>
                        </TableHead>
                        <TableHead className="min-w-48 py-3.5">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Proposed shift</span>
                        </TableHead>
                        <TableHead className="min-w-56 py-3.5">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Note</span>
                        </TableHead>
                        <TableHead className="min-w-48 py-3.5">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Status</span>
                        </TableHead>
                        <TableHead className="min-w-40 py-3.5">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Submitted on</span>
                        </TableHead>
                        <TableHead className="w-30 min-w-30 py-3.5 pr-2 text-right sm:pr-3">
                          <span className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">Actions</span>
                        </TableHead>
                      </TableRow>
                    </TableHeader>
                    <TableBody>
                      {filteredRequests.map((item, rowIdx) => (
                        <TableRow
                          key={item.id}
                          className={cn(
                            'border-b border-border text-[15px] leading-snug transition-colors hover:bg-muted/25',
                            rowIdx % 2 === 1 ? 'bg-card' : 'bg-muted/10',
                          )}
                        >
                          <TableCell className={cn('align-middle font-mono text-sm text-muted-foreground', cellPad)}>
                            #{item.id}
                          </TableCell>
                          <TableCell className={cn('align-top', cellPad)}>
                            {(() => {
                              return <div>
                              <p className="font-semibold leading-snug text-foreground">{requestedScheduleTitle(item)}</p>
                              {requestedScheduleSubtitle(item) ? (
                                <p className="mt-0.5 text-xs font-medium text-muted-foreground">{requestedScheduleSubtitle(item)}</p>
                              ) : null}
                              {String(item.request_kind || '').toLowerCase() === 'custom' ? (
                                <Badge
                                  variant="outline"
                                  className="mt-1.5 rounded-lg border-border bg-background px-2 py-0 text-[10px] font-medium shadow-sm"
                                >
                                  Custom schedule
                                </Badge>
                              ) : null}
                            </div>
                            })()}
                          </TableCell>
                          <TableCell className={cn('align-top', cellPad)}>
                            <p className="font-mono text-sm tabular-nums text-foreground">{scheduleTimeRange(item)}</p>
                            <p className="mt-1 text-xs text-muted-foreground">Effective {formatShortDate(item.effective_from)}</p>
                          </TableCell>
                          <TableCell className={cn('max-w-[16rem] align-top', cellPad)}>
                            <RemarksPreviewCell text={item.remarks} />
                          </TableCell>
                          <TableCell className={cn('align-top', cellPad)}>
                            <ScheduleRequestStatusBadge item={item} />
                          </TableCell>
                          <TableCell className={cn('align-middle text-sm tabular-nums text-foreground', cellPad)}>
                            {item.filed_at ? formatDateTime(item.filed_at) : '—'}
                          </TableCell>
                          <TableCell className={cn('text-right align-middle', cellPad)}>
                            <AdminDataTableActions
                              onView={() => {
                                setSelectedRequest(item)
                                setDetailOpen(true)
                              }}
                              viewLabel="View details"
                              viewAriaLabel="View schedule request details"
                              showDelete={Boolean(item.actor_can_delete)}
                              onDelete={() => setDeleteDialog({ open: true, request: item })}
                            />
                          </TableCell>
                        </TableRow>
                      ))}
                    </TableBody>
                  </Table>
                </div>
              )}
            </>
          )}
        </CardContent>
      </Card>

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent
          closeButtonClassName="border-border bg-card/95 text-foreground shadow-sm hover:bg-muted"
          className="w-full max-w-[min(100vw-1.25rem,80rem)] overflow-hidden border-border bg-card p-0 shadow-2xl sm:max-w-[min(100vw-2rem,80rem)] dark:shadow-black/45"
          innerClassName="gap-0 overflow-hidden p-0 pr-0"
        >
          <div className="grid max-h-[min(92vh,900px)] min-h-0 grid-cols-1 items-stretch overflow-hidden xl:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
            <div className="min-h-0 overflow-y-auto bg-card overscroll-contain">
              <DialogHeader className="border-b border-border px-8 py-7">
                <DialogTitle className="text-2xl tracking-tight">Request schedule change</DialogTitle>
                <DialogDescription className="max-w-2xl text-sm leading-relaxed text-muted-foreground">
                  Pick an existing template from Admin → Schedules, or describe a custom shift. Approved schedules apply to attendance, overtime, leave, and payroll.
                </DialogDescription>
              </DialogHeader>
              <form onSubmit={handleSubmit} className="space-y-6 px-8 py-7">
                <Tabs value={requestMode} onValueChange={setRequestMode} className="w-full space-y-6">
                  <TabsList className="grid h-auto w-full max-w-xl grid-cols-2 gap-1 p-1">
                    <TabsTrigger value="template" className="rounded-md px-3 py-2.5 text-sm">
                      Choose from templates
                    </TabsTrigger>
                    <TabsTrigger value="custom" className="rounded-md px-3 py-2.5 text-sm">
                      Create custom schedule
                    </TabsTrigger>
                  </TabsList>

                  <TabsContent value="template" className="mt-0 space-y-6 outline-none">
                    <RequestSection
                      eyebrow="Templates"
                      title="Company schedule templates"
                      description="These are the same live templates your HR team maintains in Admin → Schedules."
                    >
                      <div className="space-y-5">
                        <div className="space-y-2">
                          <Label htmlFor="schedule-type-picker">Schedule template</Label>
                          <Popover
                            open={schedulePickerOpen}
                            onOpenChange={(open) => {
                              setSchedulePickerOpen(open)
                              if (!open) setScheduleSearch('')
                            }}
                          >
                            <PopoverTrigger asChild>
                              <button
                                type="button"
                                id="schedule-type-picker"
                                className="flex min-h-11 w-full items-center justify-between gap-3 rounded-lg border border-border bg-background px-3 py-2 text-left shadow-sm transition-colors hover:bg-muted/20"
                              >
                                <div className="min-w-0 flex-1">
                                  <span className={cn('block truncate text-sm', selectedSchedule ? 'text-foreground' : 'text-muted-foreground')}>
                                    {selectedSchedule
                                      ? `${selectedSchedule.name} · ${formatShiftRange12h(selectedSchedule.time_in, selectedSchedule.time_out, ' - ')}`
                                      : 'Search and select a schedule template'}
                                  </span>
                                </div>
                                <ChevronRight className={cn('size-4 text-muted-foreground transition-transform', schedulePickerOpen && 'rotate-90')} />
                              </button>
                            </PopoverTrigger>
                            <PopoverContent className="w-[min(100vw-2rem,40rem)] p-0" align="start">
                              <div className="border-b border-border p-3">
                                <div className="relative">
                                  <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                                  <Input
                                    value={scheduleSearch}
                                    onChange={(e) => setScheduleSearch(e.target.value)}
                                    placeholder="Search by name, time, or workdays..."
                                    className="h-10 border-border bg-background pl-9"
                                    autoFocus
                                  />
                                </div>
                              </div>
                              <div className="max-h-80 overflow-y-auto p-2">
                                {filteredSchedules.length === 0 ? (
                                  <div className="px-3 py-6 text-center text-sm text-muted-foreground">No schedules match this search.</div>
                                ) : (
                                  filteredSchedules.map((schedule) => (
                                    <button
                                      key={schedule.id}
                                      type="button"
                                      onClick={() => {
                                        setForm((prev) => ({ ...prev, working_schedule_id: String(schedule.id) }))
                                        setSchedulePickerOpen(false)
                                        setScheduleSearch('')
                                      }}
                                      className={cn(
                                        'flex w-full items-start gap-3 rounded-lg px-3 py-3 text-left text-sm transition-colors hover:bg-muted/40',
                                        String(form.working_schedule_id) === String(schedule.id) && 'bg-muted/50'
                                      )}
                                    >
                                      <div className="min-w-0 flex-1">
                                        <p className="font-medium text-foreground">{schedule.name}</p>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                          {formatShiftRange12h(schedule.time_in, schedule.time_out, ' - ')}
                                        </p>
                                        <p className="mt-1 text-xs text-muted-foreground">Rest days: {schedule.rest_days_label || 'None'}</p>
                                      </div>
                                    </button>
                                  ))
                                )}
                              </div>
                            </PopoverContent>
                          </Popover>
                          <p className="text-xs text-muted-foreground">After final approval, this template is assigned to you as-is.</p>
                        </div>

                        <div className="rounded-xl border border-border bg-background p-5 shadow-sm dark:border-border/50">
                          <p className="text-sm font-medium text-foreground">Template preview</p>
                          {selectedSchedule ? (
                            <div className="mt-4 grid gap-3 sm:grid-cols-2">
                              <ScheduleMetaCard icon={Clock3} label="Start Time" value={formatClockTimeDisplay(selectedSchedule.time_in)} />
                              <ScheduleMetaCard icon={Clock3} label="End Time" value={formatClockTimeDisplay(selectedSchedule.time_out)} />
                              <ScheduleMetaCard
                                icon={Timer}
                                label="Break"
                                value={
                                  selectedSchedule.break_start && selectedSchedule.break_end
                                    ? `${selectedSchedule.break_start} - ${selectedSchedule.break_end}`
                                    : 'No break set'
                                }
                              />
                              <ScheduleMetaCard icon={CalendarClock} label="Rest Days" value={selectedSchedule.rest_days_label || 'None'} />
                            </div>
                          ) : (
                            <div className="mt-4 rounded-lg border border-dashed border-border bg-muted/20 p-4 text-sm text-muted-foreground dark:border-border/50">
                              Select a template to preview shift hours, break window, and rest days.
                            </div>
                          )}
                        </div>
                      </div>
                    </RequestSection>
                  </TabsContent>

                  <TabsContent value="custom" className="mt-0 space-y-6 outline-none">
                    <RequestSection
                      eyebrow="Custom"
                      title="Propose your shift details"
                      description="Matches the fields HR uses in Admin → Schedules. If approved, we create a new schedule record and assign it to you."
                    >
                      <div className="space-y-5">
                        <div className="space-y-2">
                          <Label htmlFor="custom-schedule-name">Schedule name</Label>
                          <Input
                            id="custom-schedule-name"
                            value={customForm.name}
                            onChange={(e) => setCustomForm((f) => ({ ...f, name: e.target.value }))}
                            placeholder="e.g. Early shift – Customer support"
                            className="h-11 rounded-lg border-border bg-background"
                            maxLength={255}
                          />
                        </div>
                        <div className="grid gap-3 min-[420px]:grid-cols-2">
                          <div className="space-y-2">
                            <Label htmlFor="custom-time-in">Start time</Label>
                            <Input
                              id="custom-time-in"
                              type="time"
                              value={customForm.time_in}
                              onChange={(e) => setCustomForm((f) => ({ ...f, time_in: e.target.value }))}
                              className="h-11 rounded-lg border-border bg-background"
                              required
                            />
                          </div>
                          <div className="space-y-2">
                            <Label htmlFor="custom-time-out">End time</Label>
                            <Input
                              id="custom-time-out"
                              type="time"
                              value={customForm.time_out}
                              onChange={(e) => setCustomForm((f) => ({ ...f, time_out: e.target.value }))}
                              className="h-11 rounded-lg border-border bg-background"
                              required
                            />
                          </div>
                        </div>
                        <p className="text-xs text-muted-foreground">Night shift: set end time earlier than start (e.g. 22:00 → 06:00).</p>

                        <div className="space-y-2">
                          <Label>Rest days (days off)</Label>
                          <p className="text-xs text-muted-foreground">Checked days are unpaid rest days. Leave at least one working day.</p>
                          <div className="flex flex-wrap gap-2">
                            {DAY_OPTIONS.map((d) => (
                              <label
                                key={d.key}
                                className="flex cursor-pointer items-center gap-2 rounded-lg border border-border bg-background px-3 py-2 text-sm shadow-sm"
                              >
                                <Checkbox
                                  checked={customForm.rest_days.includes(d.key)}
                                  onCheckedChange={() =>
                                    setCustomForm((f) => ({ ...f, rest_days: toggleRestDay(f.rest_days, d.key) }))
                                  }
                                />
                                <span>{d.full}</span>
                              </label>
                            ))}
                          </div>
                        </div>

                        <div className="grid gap-3 min-[420px]:grid-cols-2">
                          <div className="space-y-2">
                            <Label htmlFor="custom-break-mins">Break duration (minutes)</Label>
                            <Input
                              id="custom-break-mins"
                              type="number"
                              min={0}
                              max={240}
                              value={customForm.break_duration_minutes}
                              onChange={(e) => setCustomForm((f) => ({ ...f, break_duration_minutes: e.target.value }))}
                              className="h-11 rounded-lg border-border bg-background"
                            />
                            <p className="text-[11px] text-muted-foreground">Placed in the middle of your shift span (same rule as payroll).</p>
                          </div>
                          <div className="space-y-2">
                            <Label>Shift type (optional)</Label>
                            <Select
                              value={customForm.shift_type || 'none'}
                              onValueChange={(v) => setCustomForm((f) => ({ ...f, shift_type: v === 'none' ? '' : v }))}
                            >
                              <SelectTrigger className="h-11 rounded-lg border-border bg-background">
                                <SelectValue placeholder="Select" />
                              </SelectTrigger>
                              <SelectContent>
                                <SelectItem value="none">Not specified</SelectItem>
                                <SelectItem value="day">Day</SelectItem>
                                <SelectItem value="night">Night</SelectItem>
                                <SelectItem value="rotating">Rotating</SelectItem>
                              </SelectContent>
                            </Select>
                          </div>
                        </div>

                        <div className="grid gap-3 min-[420px]:grid-cols-2">
                          <div className="space-y-2">
                            <Label htmlFor="custom-grace">Grace period (minutes)</Label>
                            <Input
                              id="custom-grace"
                              type="number"
                              min={0}
                              max={240}
                              value={customForm.grace_period_minutes}
                              onChange={(e) => setCustomForm((f) => ({ ...f, grace_period_minutes: e.target.value }))}
                              className="h-11 rounded-lg border-border bg-background"
                            />
                          </div>
                          <div className="space-y-2">
                            <Label htmlFor="custom-ot-buf">Overtime buffer (minutes)</Label>
                            <Input
                              id="custom-ot-buf"
                              type="number"
                              min={0}
                              max={480}
                              value={customForm.overtime_buffer_minutes}
                              onChange={(e) => setCustomForm((f) => ({ ...f, overtime_buffer_minutes: e.target.value }))}
                              className="h-11 rounded-lg border-border bg-background"
                            />
                          </div>
                        </div>

                        <div className="rounded-lg border border-border bg-muted/20 px-4 py-3 text-sm text-muted-foreground dark:border-border/50">
                          <span className="font-medium text-foreground">Work days per week: </span>
                          {7 - (customForm.rest_days?.length || 0)} ·
                          <span className="ml-2 font-medium text-foreground">Weekly hours (preview): </span>
                          {customWeeklyHoursPreview.toFixed(1)}h
                        </div>
                      </div>
                    </RequestSection>
                  </TabsContent>
                </Tabs>

                <RequestSection
                  eyebrow="Effective date & note"
                  title="When should this start?"
                  description="Choose the first day the new schedule should apply. Add a short note if it helps HR approve faster."
                >
                  <div className="grid gap-5 md:grid-cols-2">
                    <div className="space-y-2 md:col-span-1">
                      <Label htmlFor="effective-from">Effective date</Label>
                      <Input
                        id="effective-from"
                        type="date"
                        value={form.effective_from}
                        min={todayIsoLocal()}
                        onChange={(e) => setForm((prev) => ({ ...prev, effective_from: e.target.value }))}
                        className="h-11 rounded-lg border-border bg-background"
                        required
                      />
                    </div>
                  </div>
                  <div className="mt-5 space-y-2">
                    <Label htmlFor="schedule-remarks">Note to approvers (optional)</Label>
                    <Textarea
                      id="schedule-remarks"
                      rows={4}
                      value={form.remarks}
                      onChange={(e) => setForm((prev) => ({ ...prev, remarks: e.target.value }))}
                      placeholder="For example: agreed with your lead, or a temporary change."
                      className="rounded-lg border-border bg-background shadow-sm"
                    />
                  </div>
                </RequestSection>

                <DialogFooter className="border-t border-border pt-6">
                  <Button type="button" variant="outline" className="rounded-lg border-border bg-background hover:bg-muted" onClick={() => setDialogOpen(false)}>
                    Cancel
                  </Button>
                  <Button
                    type="submit"
                    className="rounded-lg bg-linear-to-r from-brand to-brand-strong px-6 font-bold text-brand-foreground shadow-[0_18px_32px_-20px_color-mix(in_oklab,var(--brand)_40%,transparent)] hover:opacity-95 dark:shadow-black/35"
                    disabled={submitDisabled}
                  >
                    {submitting ? <Loader2 className="mr-2 size-4 animate-spin" /> : <Plus className="mr-2 size-4" />}
                    Submit request
                  </Button>
                </DialogFooter>
              </form>
            </div>

            <aside className="flex h-full min-h-0 flex-col border-t border-border bg-muted/20 lg:border-t-0 lg:border-l dark:border-border/50 dark:bg-muted/10">
              <div className="shrink-0 border-b border-border bg-muted/30 px-5 py-4 dark:bg-muted/15">
                <div className="flex flex-wrap items-start justify-between gap-2">
                  <div className="space-y-1">
                    <Badge variant="outline" className="rounded-full border-border/70 bg-background text-muted-foreground">
                      Preview & summary
                    </Badge>
                    <h3 className="text-lg font-semibold tracking-tight text-foreground">What you&apos;re requesting</h3>
                    <p className="text-xs font-medium text-muted-foreground">
                      Scroll to review schedule details, approval routing, and impact.
                    </p>
                  </div>
                </div>
              </div>

              <div className="min-h-0 flex-1 overflow-y-auto overscroll-contain px-5 py-5 lg:px-6 lg:py-6">
                <div className="space-y-5 pb-2">
                  <div className="rounded-xl border border-border bg-card p-5 shadow-sm dark:border-border/50">
                    <div className="flex items-start justify-between gap-3">
                      <div className="min-w-0 flex-1">
                        <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-muted-foreground">
                          {requestMode === 'custom' ? 'Your proposal' : 'Selected template'}
                        </p>
                        <p className="mt-2 wrap-break-word text-lg font-semibold text-foreground">{rightPanelSchedule?.name || '—'}</p>
                        <p className="mt-1 text-sm text-muted-foreground">
                          {rightPanelSchedule
                            ? formatShiftRange12h(rightPanelSchedule.time_in, rightPanelSchedule.time_out)
                            : 'Complete the form on the left.'}
                        </p>
                        <p className="mt-3 text-sm text-muted-foreground">
                          <span className="font-medium text-foreground">Effective: </span>
                          {form.effective_from ? formatShortDate(form.effective_from) : '—'}
                        </p>
                      </div>
                      {rightPanelSchedule ? (
                        <Badge className="shrink-0 rounded-full border border-transparent bg-chart-2/15 px-3 py-0.5 text-[11px] font-bold text-chart-2 hover:bg-chart-2/15">
                          Ready
                        </Badge>
                      ) : (
                        <Badge variant="outline" className="shrink-0 rounded-full">
                          Incomplete
                        </Badge>
                      )}
                    </div>
                  </div>

                  {form.remarks?.trim() ? (
                    <div className="rounded-xl border border-border bg-muted/20 p-4 shadow-sm">
                      <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-muted-foreground">Remarks</p>
                      <p className="mt-2 whitespace-pre-wrap text-sm leading-relaxed text-foreground">{form.remarks.trim()}</p>
                    </div>
                  ) : null}

                  {rightPanelSchedule ? (
                    <>
                      <div>
                        <p className="mb-4 text-[11px] font-semibold uppercase tracking-[0.16em] text-muted-foreground">Shift details</p>
                        <div className="grid gap-3">
                          <ScheduleMetaCard icon={Clock3} label="Start time" value={formatClockTimeDisplay(rightPanelSchedule.time_in)} />
                          <ScheduleMetaCard icon={Clock3} label="End time" value={formatClockTimeDisplay(rightPanelSchedule.time_out)} />
                          <ScheduleMetaCard
                            icon={Timer}
                            label="Break"
                            value={
                              rightPanelSchedule.break_start && rightPanelSchedule.break_end
                                ? `${rightPanelSchedule.break_start} - ${rightPanelSchedule.break_end}`
                                : requestMode === 'custom' && customForm.break_duration_minutes
                                  ? `${customForm.break_duration_minutes} min (centered in shift)`
                                  : 'No break set'
                            }
                          />
                        </div>
                      </div>
                      <div className="grid gap-4">
                        <DayBadgePanel
                          title="Rest days"
                          items={rightPanelSchedule.rest_days || []}
                          emptyLabel="No rest days set"
                          tone="muted"
                        />
                        <DayBadgePanel
                          title="Work days"
                          items={
                            rightPanelSchedule.work_days_label
                              ? String(rightPanelSchedule.work_days_label)
                                  .split(',')
                                  .map((value) => value.trim())
                                  .filter(Boolean)
                              : []
                          }
                          emptyLabel="No work days set"
                          tone="sky"
                        />
                      </div>
                    </>
                  ) : null}

                  <div className="rounded-xl border border-border bg-background/80 p-4 shadow-sm dark:border-border/50 dark:bg-background/40">
                    <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-muted-foreground">After approval</p>
                    <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                      Your assigned schedule updates for <span className="font-medium text-foreground">clock in/out</span>,{' '}
                      <span className="font-medium text-foreground">overtime</span>,{' '}
                      <span className="font-medium text-foreground">leave</span>, and{' '}
                      <span className="font-medium text-foreground">daily computation</span>
                      — so your hours match what payroll and attendance use.
                    </p>
                  </div>

                  <div className="rounded-xl border border-border bg-card p-5 shadow-sm dark:border-border/50">
                    <p className="text-[11px] font-semibold uppercase tracking-[0.16em] text-muted-foreground">Approval routing</p>
                    <p className="mt-2 text-sm leading-relaxed text-muted-foreground">
                      Your request goes through your manager, then HR final approval, depending on your role.
                    </p>
                    {canApprove ? (
                      <ul className="mt-3 list-disc space-y-1 pl-5 text-sm text-muted-foreground">
                        <li>Employee → Department Head → Admin (HR)</li>
                        <li>Department Head → Branch Head → Admin (HR)</li>
                        <li>Branch Head → Company Head → Admin (HR)</li>
                        <li>Company Head → Admin (HR)</li>
                        <li>Admin (HR) → Admin (HR) — self-approval when applicable</li>
                      </ul>
                    ) : null}
                    <div className="mt-4">
                      <ApprovalChainDetailView steps={requestContext?.approval_chain_preview || []} />
                    </div>
                  </div>
                </div>
              </div>
            </aside>
          </div>
        </DialogContent>
      </Dialog>

      <Dialog open={detailOpen} onOpenChange={setDetailOpen}>
        <DialogContent
          closeButtonClassName="border-border bg-card/95 text-foreground shadow-sm hover:bg-muted"
          className="max-w-4xl border-border bg-card shadow-2xl dark:shadow-black/45"
        >
          <DialogHeader>
            <DialogTitle>
              {selectedRequest
                ? `${requestedScheduleTitle(selectedRequest)}${requestedScheduleSubtitle(selectedRequest) ? ` — ${requestedScheduleSubtitle(selectedRequest)}` : ''}`
                : 'Schedule request'}
            </DialogTitle>
            <DialogDescription>
              Request #{selectedRequest?.id ?? '—'} · {selectedRequest?.display_status || 'Request details'}
            </DialogDescription>
          </DialogHeader>
          {selectedRequest ? (
            <div className="grid gap-6 lg:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
              <div className="space-y-4">
                <div className="rounded-xl border border-border bg-muted/20 p-4">
                  <p className="text-sm font-medium text-foreground">Requested schedule</p>
                  <p className="mt-1 text-lg font-semibold text-foreground">{requestedScheduleTitle(selectedRequest)}</p>
                  {requestedScheduleSubtitle(selectedRequest) ? (
                    <p className="mt-1 text-sm font-medium text-muted-foreground">{requestedScheduleSubtitle(selectedRequest)}</p>
                  ) : null}
                  <p className="mt-2 font-mono text-sm text-muted-foreground">{scheduleTimeRange(selectedRequest)}</p>
                  {(() => {
                    const details = scheduleDetailSummary(selectedRequest)
                    return (
                      <div className="mt-3 space-y-2">
                        <div className="flex flex-wrap items-center gap-2">
                          <span className="text-xs font-medium text-muted-foreground">Rest days:</span>
                          {details.restDays.length ? details.restDays.map((d) => (
                            <Badge
                              key={`modal-rest-${d}`}
                              variant="outline"
                              className="rounded-full px-2 py-0 text-[10px]"
                            >
                              {String(d).toUpperCase()}
                            </Badge>
                          )) : <span className="text-xs text-muted-foreground">None</span>}
                        </div>
                        <p className="text-xs text-muted-foreground">Break: {details.breakLabel}</p>
                        <p className="text-xs text-muted-foreground">Work days: {details.workDaysCount} per week</p>
                      </div>
                    )
                  })()}
                  {selectedRequest.request_kind === 'custom' ? (
                    <p className="mt-2 text-xs text-muted-foreground">
                      Custom schedule — after final approval HR creates a schedule record and assigns it to you.
                    </p>
                  ) : null}
                </div>
                <div className="rounded-xl border border-border bg-background p-4 dark:border-border/50">
                  <p className="text-sm font-medium text-foreground">Effective date requested</p>
                  <p className="mt-2 text-sm tabular-nums text-muted-foreground">{formatShortDate(selectedRequest.effective_from)}</p>
                </div>
                <div className="rounded-xl border border-border bg-muted/20 p-4">
                  <p className="text-sm font-medium text-foreground">Your note to approvers</p>
                  <p className="mt-2 whitespace-pre-wrap text-sm leading-relaxed text-muted-foreground">
                    {selectedRequest.remarks?.trim() ? selectedRequest.remarks : 'No note added.'}
                  </p>
                </div>
                <div className="rounded-xl border border-border bg-background p-4 dark:border-border/50">
                  <p className="text-sm font-medium text-foreground">Approval history</p>
                  <div className="mt-3 space-y-3">
                    {(selectedRequest.approval_history || []).map((entry, index) => (
                      <div key={`${entry.action}-${index}`} className="rounded-lg border border-border px-3 py-3 dark:border-border/50">
                        <p className="text-sm font-medium text-foreground">{formatHistoryAction(entry.action)}</p>
                        <p className="text-xs text-muted-foreground">{entry.actor_name || 'System'} · {formatDateTime(entry.at)}</p>
                        <p className="mt-1 text-sm text-muted-foreground">
                          {sanitizeApprovalDisplayText(entry?.details) || 'No remarks'}
                        </p>
                      </div>
                    ))}
                  </div>
                </div>
              </div>
              <div>
                <ApprovalChainDetailView steps={selectedRequest.approval_progress || []} />
              </div>
            </div>
          ) : null}
        </DialogContent>
      </Dialog>

      <Dialog open={deleteDialog.open} onOpenChange={(open) => !open && setDeleteDialog({ open: false, request: null })}>
        <DialogContent className="max-w-md border-border bg-card shadow-2xl">
          <DialogHeader>
            <DialogTitle>Delete schedule request</DialogTitle>
            <DialogDescription>Are you sure you want to delete this request?</DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button
              type="button"
              variant="outline"
              onClick={() => setDeleteDialog({ open: false, request: null })}
              disabled={deleteRequestMutation.isPending}
            >
              Cancel
            </Button>
            <Button
              type="button"
              variant="destructive"
              onClick={() => deleteDialog.request && deleteRequestMutation.mutate(deleteDialog.request.id)}
              disabled={deleteRequestMutation.isPending}
            >
              {deleteRequestMutation.isPending ? <Loader2 className="mr-2 size-4 animate-spin" /> : <Trash2 className="mr-2 size-4" />}
              Delete
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

function ScheduleMetaCard({ icon, label, value }) {
  const toneClass = 'text-brand bg-brand/12 ring-brand/25'

  return (
    <div className="rounded-xl border border-border bg-card p-4 shadow-sm">
      <div className="flex items-center gap-2 text-muted-foreground">
        <span className={cn('flex size-6 items-center justify-center rounded-full ring-1', toneClass)}>
          {createElement(icon, { className: 'size-3.5', 'aria-hidden': true })}
        </span>
        <span className="text-xs font-bold uppercase tracking-[0.12em]">{label}</span>
      </div>
      <p className="mt-3 text-base font-bold leading-relaxed text-foreground">{value}</p>
    </div>
  )
}

function ScheduleMetricCard({ icon, label, value }) {
  const toneClass = 'text-brand bg-brand/12 ring-brand/25'

  return (
    <div className="flex items-center justify-between gap-4 rounded-xl border border-border bg-card p-4 shadow-sm">
      <div>
        <p className="text-[11px] font-bold uppercase tracking-[0.16em] text-muted-foreground">{label}</p>
        <p className="mt-2 text-lg font-extrabold text-foreground">{value}</p>
      </div>
      <span className={cn('flex size-10 shrink-0 items-center justify-center rounded-xl ring-1', toneClass)}>
        {createElement(icon, { className: 'size-5', 'aria-hidden': true })}
      </span>
    </div>
  )
}

function DayStrip({ title, items, emptyLabel, tone }) {
  const badgeClass =
    tone === 'work'
      ? 'border-transparent bg-chart-1/15 text-chart-1 hover:bg-chart-1/15'
      : 'border-transparent bg-brand/15 text-brand hover:bg-brand/15'

  return (
    <div className="bg-muted/10 p-4 dark:bg-muted/5">
      <p className="text-[11px] font-bold uppercase tracking-[0.14em] text-muted-foreground">{title}</p>
      <div className="mt-3 flex flex-wrap gap-2">
        {Array.isArray(items) && items.length > 0 ? (
          items.map((item) => (
            <Badge key={item} className={cn('rounded-full px-3 py-1 text-[11px] font-bold', badgeClass)}>
              {compactDayLabel(item)}
            </Badge>
          ))
        ) : (
          <span className="text-sm text-muted-foreground">{emptyLabel}</span>
        )}
      </div>
    </div>
  )
}

function RequestSection({ eyebrow, title, description, children }) {
  return (
    <section className="rounded-xl border border-border bg-card p-6 shadow-sm dark:border-border/50">
      <div className="mb-5 space-y-1">
        <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-muted-foreground">{eyebrow}</p>
        <h3 className="text-lg font-semibold text-foreground">{title}</h3>
        <p className="text-sm leading-relaxed text-muted-foreground">{description}</p>
      </div>
      {children}
    </section>
  )
}

function DayBadgePanel({ title, items, emptyLabel, tone }) {
  const toneClass =
    tone === 'sky'
      ? 'border-transparent bg-chart-1/15 text-chart-1 hover:bg-chart-1/15'
      : 'border-border bg-muted text-muted-foreground hover:bg-muted'

  return (
    <div className="rounded-xl border border-border bg-card p-4 shadow-sm">
      <p className="text-xs font-medium uppercase tracking-[0.12em] text-muted-foreground">{title}</p>
      <div className="mt-3 flex flex-wrap gap-2">
        {Array.isArray(items) && items.length > 0 ? (
          items.map((item) => (
            <Badge key={item} className={`rounded-full px-3 py-1 ${toneClass}`}>
              {String(item).toUpperCase()}
            </Badge>
          ))
        ) : (
          <span className="text-sm text-muted-foreground">{emptyLabel}</span>
        )}
      </div>
    </div>
  )
}
