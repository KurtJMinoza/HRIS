import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  ArrowRight,
  Building2,
  CalendarClock,
  CalendarRange,
  CheckCircle2,
  ChevronRight,
  Clock3,
  Copy,
  Loader2,
  MoreHorizontal,
  Pencil,
  Plus,
  Repeat,
  Search,
  Trash2,
  WalletCards,
  X,
} from 'lucide-react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select'
import { Switch } from '@/components/ui/switch'
import { Checkbox } from '@/components/ui/checkbox'
import { Skeleton } from '@/components/ui/skeleton'
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table'
import { TooltipProvider } from '@/components/ui/tooltip'
import { cn } from '@/lib/utils'
import { APP_MODAL_OUTLINE_BUTTON_CLASS, APP_MODAL_PRIMARY_BUTTON_CLASS, appModalDialogContentClass } from '@/lib/appModalStyles'
import { useToast } from '@/components/ui/use-toast'
import { createPayCycle, deletePayCycle, getCompanies, getPayCycles, previewPayCycle, updatePayCycle } from '@/api'

const EMPTY_FORM = {
  company_id: '',
  company_ids: [],
  name: '',
  code: 'semi_monthly',
  cut_off_type: 'fixed_day',
  first_cutoff_day: '15',
  second_cutoff_day: '31',
  weekly_anchor_day: 'monday',
  biweekly_start_date: '',
  pay_day_type: 'offset',
  pay_day_offset: '5',
  first_pay_day_offset: '5',
  second_pay_day_offset: '5',
  pay_fixed_day: '20',
  second_pay_fixed_day: '31',
  daily_pay_offset: '1',
  project_start_date: '',
  project_end_date: '',
  project_pay_date: '',
  pro_ration_type: 'daily',
  weekend_adjustment_rule: 'previous_friday',
  is_active: true,
  is_default: false,
}

export default function AdminPayCycleManagementPage() {
  const { toast } = useToast()
  const [cycles, setCycles] = useState([])
  const [companies, setCompanies] = useState([])
  const [loading, setLoading] = useState(true)
  const [saving, setSaving] = useState(false)
  const [deleting, setDeleting] = useState(false)
  const [dialogOpen, setDialogOpen] = useState(false)
  const [deleteDialogOpen, setDeleteDialogOpen] = useState(false)
  const [cycleToDelete, setCycleToDelete] = useState(null)
  const [editing, setEditing] = useState(null)
  const [form, setForm] = useState(EMPTY_FORM)
  const [preview, setPreview] = useState(null)
  const [query, setQuery] = useState('')
  const [activeFilter, setActiveFilter] = useState('all')
  const [lastLoadedAt, setLastLoadedAt] = useState(null)
  const [companyPickerOpen, setCompanyPickerOpen] = useState(false)
  const [companySearch, setCompanySearch] = useState('')

  const loadData = useCallback(async () => {
    setLoading(true)
    try {
      const [cyclesRes, companiesRes] = await Promise.all([getPayCycles(), getCompanies()])
      setCycles(Array.isArray(cyclesRes?.data) ? cyclesRes.data : [])
      setCompanies(Array.isArray(companiesRes?.companies) ? companiesRes.companies : [])
      setLastLoadedAt(new Date())
    } catch (error) {
      toast({ title: 'Pay cycles', description: error.message || 'Failed to load pay cycles', variant: 'destructive' })
    } finally {
      setLoading(false)
    }
  }, [toast])

  useEffect(() => {
    loadData()
  }, [loadData])

  useEffect(() => {
    if (!dialogOpen) {
      setPreview(null)
      return
    }
    const timer = setTimeout(async () => {
      try {
        const data = await previewPayCycle(buildPayload(form, true))
        setPreview(data?.data || null)
      } catch {
        setPreview(null)
      }
    }, 180)
    return () => clearTimeout(timer)
  }, [dialogOpen, form])

  const stats = useMemo(() => ([
    {
      label: 'Active Cycles',
      value: cycles.filter((item) => item.is_active).length,
      icon: WalletCards,
      tone: 'blue',
      note: 'Ready for assignment',
    },
    {
      label: 'Default Cycles',
      value: cycles.filter((item) => item.is_default).length,
      icon: Repeat,
      tone: 'teal',
      note: 'Company-level baselines',
    },
    {
      label: 'Semi-monthly Standard',
      value: cycles.filter((item) => item.code === 'semi_monthly').length,
      icon: CalendarRange,
      tone: 'violet',
      note: 'Common PH setup',
    },
  ]), [cycles])

  const filteredCycles = useMemo(() => {
    const needle = query.trim().toLowerCase()
    return cycles.filter((cycle) => {
      const matchesText =
        !needle ||
        `${cycle.name} ${(cycle.company_names || []).join(' ')} ${cycle.company_name || ''} ${cycle.code} ${describeCutoff(cycle)} ${describePayRule(cycle)}`
          .toLowerCase()
          .includes(needle)
      const matchesFilter =
        activeFilter === 'all'
        || (activeFilter === 'active' && cycle.is_active)
        || (activeFilter === 'default' && cycle.is_default)
        || (activeFilter === 'semi_monthly' && cycle.code === 'semi_monthly')
        || (activeFilter === 'weekly' && (cycle.code === 'weekly' || cycle.code === 'bi_weekly'))
      return matchesText && matchesFilter
    })
  }, [activeFilter, cycles, query])

  const featuredPreview = preview || filteredCycles[0]?.preview || cycles[0]?.preview || null
  const formPreview = useMemo(() => {
    if (!dialogOpen) return null
    return generatePreviewPeriods(form)
  }, [dialogOpen, form])
  const selectedCompanyIds = useMemo(
    () => Array.from(new Set((form.company_ids || []).map((value) => String(value)).filter(Boolean))),
    [form.company_ids]
  )
  const selectedCompanies = useMemo(
    () => companies.filter((company) => selectedCompanyIds.includes(String(company.id))),
    [companies, selectedCompanyIds]
  )
  const filteredCompanyOptions = useMemo(() => {
    const needle = companySearch.trim().toLowerCase()
    return companies.filter((company) => !needle || String(company.name || '').toLowerCase().includes(needle))
  }, [companies, companySearch])

  function handleFrequencyChange(value) {
    setForm((prev) => {
      const next = { ...prev, code: value }
      if (value === 'monthly') {
        next.pay_day_type = 'offset'
        next.pay_day_offset = prev.pay_day_offset || '5'
      } else if (value === 'weekly') {
        next.pay_day_type = 'offset'
        next.pay_day_offset = prev.pay_day_offset || '7'
        next.weekly_anchor_day = prev.weekly_anchor_day || 'monday'
      } else if (value === 'bi_weekly') {
        next.pay_day_type = 'offset'
        next.pay_day_offset = prev.pay_day_offset || '7'
        next.weekly_anchor_day = prev.weekly_anchor_day || 'monday'
        next.biweekly_start_date = prev.biweekly_start_date || toIsoDate(startOfDay(new Date()))
      } else if (value === 'daily') {
        next.pay_day_type = 'offset'
        next.pay_day_offset = prev.daily_pay_offset || prev.pay_day_offset || '1'
        next.daily_pay_offset = prev.daily_pay_offset || '1'
      } else if (value === 'project') {
        next.pay_day_type = 'custom'
        next.project_start_date = prev.project_start_date || toIsoDate(startOfDay(new Date()))
        next.project_end_date = prev.project_end_date || toIsoDate(addDays(startOfDay(new Date()), 13))
        next.project_pay_date = prev.project_pay_date || toIsoDate(addDays(startOfDay(new Date()), 14))
      } else if (value === 'semi_monthly') {
        next.pay_day_type = prev.pay_day_type === 'fixed_day' ? 'fixed_day' : 'offset'
      }
      return next
    })
  }

  function openCreate() {
    setEditing(null)
    setForm({ ...EMPTY_FORM, company_ids: [] })
    setPreview(null)
    setCompanySearch('')
    setCompanyPickerOpen(false)
    setDialogOpen(true)
  }

  function openEdit(cycle) {
    setEditing(cycle)
    const assignedCompanyIds = Array.isArray(cycle.company_ids) && cycle.company_ids.length > 0
      ? cycle.company_ids.map((id) => String(id))
      : (cycle.company_id ? [String(cycle.company_id)] : [])
    setForm({
      company_id: assignedCompanyIds[0] || '',
      company_ids: assignedCompanyIds,
      name: cycle.name || '',
      code: cycle.code || 'semi_monthly',
      cut_off_type: cycle.cut_off_type || 'fixed_day',
      first_cutoff_day: String(cycle.cut_off_value?.[0] ?? 15),
      second_cutoff_day: String(cycle.cut_off_value?.[1] === 'end_of_month' ? 31 : (cycle.cut_off_value?.[1] ?? 31)),
      weekly_anchor_day: cycle.cut_off_value?.day_of_week || 'monday',
      biweekly_start_date: cycle.cut_off_value?.start_date || '',
      pay_day_type: cycle.pay_day_type || 'offset',
      pay_day_offset: String(cycle.pay_day_offset ?? cycle.pay_day_value?.offset ?? 5),
      first_pay_day_offset: String(cycle.pay_day_value?.first_offset ?? cycle.pay_day_offset ?? cycle.pay_day_value?.offset ?? 5),
      second_pay_day_offset: String(cycle.pay_day_value?.second_offset ?? cycle.pay_day_offset ?? cycle.pay_day_value?.offset ?? 5),
      pay_fixed_day: String(cycle.pay_day_value?.day ?? 20),
      second_pay_fixed_day: String(cycle.pay_day_value?.second_day === 'end_of_month' ? 31 : (cycle.pay_day_value?.second_day ?? 31)),
      daily_pay_offset: String(cycle.pay_day_offset ?? cycle.pay_day_value?.offset ?? 1),
      project_start_date: cycle.cut_off_value?.start_date || '',
      project_end_date: cycle.cut_off_value?.end_date || '',
      project_pay_date: cycle.pay_day_value?.date || '',
      pro_ration_type: cycle.pro_ration_type || 'daily',
      weekend_adjustment_rule: cycle.weekend_adjustment_rule || cycle.metadata?.weekend_adjustment_rule || 'previous_friday',
      is_active: Boolean(cycle.is_active),
      is_default: Boolean(cycle.is_default),
    })
    setPreview(cycle.preview || null)
    setCompanySearch('')
    setCompanyPickerOpen(false)
    setDialogOpen(true)
  }

  async function handleDuplicate(cycle) {
    try {
      const payload = {
        company_id: cycle.company_id ? Number(cycle.company_id) : null,
        company_ids: Array.isArray(cycle.company_ids) ? cycle.company_ids.map((id) => Number(id)) : (cycle.company_id ? [Number(cycle.company_id)] : []),
        name: `${cycle.name} Copy`,
        code: cycle.code,
        cut_off_type: cycle.cut_off_type,
        cut_off_value: cycle.cut_off_value,
        pay_day_type: cycle.pay_day_type,
        pay_day_value: cycle.pay_day_value,
        pay_day_offset: cycle.pay_day_offset,
        pro_ration_type: cycle.pro_ration_type,
        is_active: Boolean(cycle.is_active),
        is_default: false,
      }
      const response = await createPayCycle(payload)
      toast({ title: 'Pay cycles', description: response?.message || 'Pay cycle duplicated.' })
      await loadData()
    } catch (error) {
      toast({ title: 'Pay cycles', description: error.message || 'Failed to duplicate pay cycle', variant: 'destructive' })
    }
  }

  async function handleSubmit(e) {
    e.preventDefault()
    setSaving(true)
    try {
      const payload = buildPayload(form)
      const response = editing ? await updatePayCycle(editing.id, payload) : await createPayCycle(payload)
      toast({ title: 'Pay cycles', description: response?.message || 'Pay cycle saved.' })
      setDialogOpen(false)
      setEditing(null)
      setForm(EMPTY_FORM)
      await loadData()
    } catch (error) {
      toast({ title: 'Pay cycles', description: error.message || 'Failed to save pay cycle', variant: 'destructive' })
    } finally {
      setSaving(false)
    }
  }

  function requestDelete(cycle) {
    setCycleToDelete(cycle)
    setDeleteDialogOpen(true)
  }

  async function handleDelete() {
    if (!cycleToDelete) return
    setDeleting(true)
    try {
      await deletePayCycle(cycleToDelete.id)
      toast({ title: 'Pay cycles', description: 'Pay cycle deleted.' })
      setDeleteDialogOpen(false)
      setCycleToDelete(null)
      await loadData()
    } catch (error) {
      toast({ title: 'Pay cycles', description: error.message || 'Failed to delete pay cycle', variant: 'destructive' })
    } finally {
      setDeleting(false)
    }
  }

  return (
    <TooltipProvider>
      <div className="space-y-6">
        <div className="rounded-2xl border border-border/60 bg-background p-6 shadow-sm dark:border-border/50">
          <div className="flex flex-col gap-5 xl:flex-row xl:items-end xl:justify-between">
            <div className="max-w-3xl space-y-2">
              <Badge variant="outline" className="w-fit rounded-full border-border/70 bg-background px-3 py-1 text-[11px] font-medium tracking-wide text-muted-foreground">
                Payroll Configuration
              </Badge>
              <div className="space-y-2">
                <h1 className="text-3xl font-semibold tracking-tight text-foreground md:text-4xl">Pay Cycles</h1>
                <p className="max-w-2xl text-sm leading-relaxed text-muted-foreground">
                  Manage payroll cut-offs, pay dates, and proration behavior with the same clean admin experience used across the HRIS.
                </p>
              </div>
            </div>
            <div className="flex flex-col items-start gap-3 sm:flex-row sm:items-center">
              <div className="rounded-lg border border-border/60 bg-background px-4 py-3 shadow-sm dark:border-border/50">
                <p className="text-[11px] uppercase tracking-[0.18em] text-muted-foreground">Last updated</p>
                <p className="mt-1 text-sm font-medium text-foreground">
                  {lastLoadedAt ? lastLoadedAt.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }) : '—'}
                </p>
              </div>
              <Button
                onClick={openCreate}
                className="h-11 rounded-lg px-5"
              >
                <Plus className="mr-2 size-4" />
                New Pay Cycle
              </Button>
            </div>
          </div>
        </div>

        <div className="grid gap-4 lg:grid-cols-3">
          {stats.map(({ label, value, icon: Icon, tone, note }) => (
            <KpiCard key={label} label={label} value={value} icon={Icon} tone={tone} note={note} />
          ))}
        </div>

        <div className="grid gap-6 xl:grid-cols-[minmax(0,1.65fr)_380px]">
          <Card className="overflow-hidden border-border/60 shadow-sm dark:border-border/50">
            <CardHeader className="border-b border-border/60 bg-card pb-5">
              <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
                <div className="space-y-1">
                  <CardTitle className="text-xl">Cycle list</CardTitle>
                  <CardDescription>Use semi-monthly as the standard PH baseline, then branch into weekly or project rules where needed.</CardDescription>
                </div>
                <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
                  <div className="relative min-w-[240px]">
                    <Input
                      value={query}
                      onChange={(e) => setQuery(e.target.value)}
                      placeholder="Search cycle, company, or timing…"
                      className="h-11 rounded-lg border-border/60 bg-background pl-4 shadow-sm"
                    />
                  </div>
                  <Select value={activeFilter} onValueChange={setActiveFilter}>
                    <SelectTrigger className="h-11 min-w-[180px] rounded-lg border-border/60 bg-background shadow-sm">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value="all">All cycles</SelectItem>
                      <SelectItem value="active">Active only</SelectItem>
                      <SelectItem value="default">Defaults</SelectItem>
                      <SelectItem value="semi_monthly">Semi-monthly</SelectItem>
                      <SelectItem value="weekly">Weekly / bi-weekly</SelectItem>
                    </SelectContent>
                  </Select>
                </div>
              </div>
            </CardHeader>
            <CardContent className="p-0">
              <div className="overflow-hidden">
                <Table>
                  <TableHeader>
                    <TableRow className="border-b border-border/60 bg-muted/40 hover:bg-muted/40 dark:bg-muted/25 dark:hover:bg-muted/25">
                      <TableHead className="h-14 px-6">Cycle</TableHead>
                      <TableHead className="h-14">Frequency</TableHead>
                      <TableHead className="h-14">Cut-off</TableHead>
                      <TableHead className="h-14">Pay rule</TableHead>
                      <TableHead className="h-14">Proration</TableHead>
                      <TableHead className="h-14 pr-6 text-right">Actions</TableHead>
                    </TableRow>
                  </TableHeader>
                  <TableBody>
                    {loading ? (
                      Array.from({ length: 5 }).map((_, index) => (
                        <TableRow key={index}>
                          <TableCell colSpan={6} className="px-6 py-4">
                            <div className="grid gap-3">
                              <Skeleton className="h-5 w-44" />
                              <Skeleton className="h-4 w-full" />
                            </div>
                          </TableCell>
                        </TableRow>
                      ))
                    ) : filteredCycles.length === 0 ? (
                      <TableRow>
                        <TableCell colSpan={6} className="px-6 py-16 text-center">
                          <div className="mx-auto max-w-md space-y-3">
                            <div className="mx-auto flex size-14 items-center justify-center rounded-lg border border-dashed border-border/60 bg-background shadow-sm dark:border-border/50">
                              <CalendarClock className="size-6 text-muted-foreground" />
                            </div>
                            <div>
                              <p className="text-sm font-medium text-foreground">No cycles match this view</p>
                              <p className="mt-1 text-sm text-muted-foreground">Try a different filter or create a new payroll cycle to get started.</p>
                            </div>
                          </div>
                        </TableCell>
                      </TableRow>
                    ) : filteredCycles.map((cycle) => (
                      <TableRow key={cycle.id} className="group border-b border-border/60 transition-colors hover:bg-muted/30 dark:border-border/40 dark:hover:bg-muted/20">
                        <TableCell className="px-6 py-5">
                          <div className="space-y-2">
                            <div className="flex items-center gap-2">
                              <span className="text-sm font-semibold text-foreground">{cycle.name}</span>
                              {cycle.is_default ? <Badge variant="secondary" className="rounded-full">Default</Badge> : null}
                              {!cycle.is_active ? <Badge variant="outline" className="rounded-full">Inactive</Badge> : null}
                            </div>
                            <p className="text-xs text-muted-foreground">{formatCompanyScope(cycle)}</p>
                          </div>
                        </TableCell>
                        <TableCell className="py-5">
                          <FrequencyBadge code={cycle.code} />
                        </TableCell>
                        <TableCell className="py-5 text-sm text-muted-foreground">{describeCutoff(cycle)}</TableCell>
                        <TableCell className="py-5 text-sm text-muted-foreground">{describePayRule(cycle)}</TableCell>
                        <TableCell className="py-5">
                          <Badge variant="outline" className="rounded-full capitalize border-border/60 bg-background">
                            {String(cycle.pro_ration_type || 'none').replace('_', ' ')}
                          </Badge>
                        </TableCell>
                        <TableCell className="pr-6 text-right">
                          <div className="flex justify-end">
                            <DropdownMenu>
                              <DropdownMenuTrigger asChild>
                                <Button
                                  size="icon"
                                  variant="ghost"
                                  className="rounded-lg text-muted-foreground opacity-70 transition-opacity hover:bg-muted/70 hover:text-foreground group-hover:opacity-100"
                                >
                                  <MoreHorizontal className="size-4" />
                                </Button>
                              </DropdownMenuTrigger>
                              <DropdownMenuContent align="end" className="w-44 rounded-lg">
                                <DropdownMenuItem onClick={() => openEdit(cycle)}>
                                  <Pencil className="size-4" />
                                  Edit cycle
                                </DropdownMenuItem>
                                <DropdownMenuItem onClick={() => handleDuplicate(cycle)}>
                                  <Copy className="size-4" />
                                  Duplicate
                                </DropdownMenuItem>
                                <DropdownMenuSeparator />
                                <DropdownMenuItem variant="destructive" onClick={() => requestDelete(cycle)}>
                                  <Trash2 className="size-4" />
                                  Delete
                                </DropdownMenuItem>
                              </DropdownMenuContent>
                            </DropdownMenu>
                          </div>
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </div>
            </CardContent>
          </Card>

          <div className="space-y-6">
            <Card className="overflow-hidden border-border/60 shadow-sm dark:border-border/50">
              <CardHeader className="border-b border-border/60 bg-card">
                <div className="flex items-start justify-between gap-3">
                  <div className="space-y-1">
                    <CardTitle className="text-lg">Live preview</CardTitle>
                    <CardDescription>Always-on timeline for the current or selected cycle.</CardDescription>
                  </div>
                  <CalendarRange className="size-4 text-muted-foreground" />
                </div>
              </CardHeader>
              <CardContent className="space-y-4 p-5">
                {featuredPreview ? (
                  <>
                    <div className="rounded-lg border border-border/60 bg-card p-4 shadow-sm dark:border-border/50">
                      <div className="flex items-start justify-between gap-3">
                        <div>
                          <p className="text-[11px] uppercase tracking-[0.18em] text-muted-foreground">Current cycle</p>
                          <p className="mt-2 text-lg font-semibold text-foreground">{featuredPreview.cycle_label}</p>
                          <p className="mt-1 text-sm text-muted-foreground">{featuredPreview.cut_off_start_date} to {featuredPreview.cut_off_end_date}</p>
                        </div>
                        <Badge className="rounded-full bg-sky-100 text-sky-800 hover:bg-sky-100 dark:bg-sky-500/15 dark:text-sky-200">
                          {String(featuredPreview.code || 'cycle').replace(/_/g, ' ')}
                        </Badge>
                      </div>
                    </div>
                    <CalendarTimeline preview={featuredPreview} />
                    <div className="grid gap-3">
                      <PreviewLine icon={Clock3} label="Next pay date" value={featuredPreview.pay_date || '—'} />
                      <PreviewLine icon={Repeat} label="Proration mode" value={String(featuredPreview.pro_ration_type || 'none').replace('_', ' ')} />
                      <PreviewLine icon={WalletCards} label="Configuration hint" value={describePayRule(featuredPreview)} />
                    </div>
                  </>
                ) : (
                  <div className="rounded-lg border border-dashed border-border/60 bg-background p-6 text-sm text-muted-foreground dark:border-border/50">
                    Select a cycle or open the configurator to see the timeline and next payout snapshot.
                  </div>
                )}
              </CardContent>
            </Card>
          </div>
        </div>

        <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
          <DialogContent
            className={cn(
              appModalDialogContentClass({ size: 'lg' }),
              'max-h-[min(88vh,680px)] overflow-hidden p-0',
            )}
            innerClassName="gap-0 overflow-hidden p-0 pr-0"
          >
            <div className="grid max-h-[min(88vh,680px)] min-h-0 overflow-hidden xl:grid-cols-[minmax(0,0.96fr)_minmax(0,1.04fr)]">
              <div className="min-h-0 overflow-y-auto overscroll-contain bg-card">
                <DialogHeader className="border-b border-border/60 px-5 py-4">
                  <DialogTitle className="text-lg font-semibold tracking-tight">{editing ? 'Edit pay cycle' : 'Create pay cycle'}</DialogTitle>
                  <DialogDescription className="max-w-2xl text-xs leading-relaxed text-muted-foreground">
                    Cut-off, pay dates, and proration — preview updates as you edit.
                  </DialogDescription>
                </DialogHeader>
                <form onSubmit={handleSubmit} className="space-y-3 px-5 py-4">
                  <ConfiguratorSection
                    eyebrow="Section 1"
                    title="Basic information"
                    description="Cycle name, frequency, and which companies can use this pay cycle."
                    compact
                  >
                    <div className="grid gap-3 lg:grid-cols-2">
                      <Field label="Cycle Name" className="min-w-0" compact>
                        <Input
                          className="h-9 w-full min-w-0 rounded-lg border-border/60 bg-background text-sm shadow-sm"
                          value={form.name}
                          onChange={(e) => setForm((prev) => ({ ...prev, name: e.target.value }))}
                          placeholder="Semi-monthly standard"
                        />
                      </Field>
                      <Field label="Frequency" className="min-w-0" compact>
                        <Select value={form.code} onValueChange={handleFrequencyChange}>
                          <SelectTrigger className="h-9 w-full min-w-0 rounded-lg border-border/60 bg-background text-sm shadow-sm">
                            <SelectValue />
                          </SelectTrigger>
                          <SelectContent className="min-w-[220px]">
                            <SelectItem value="monthly">Monthly</SelectItem>
                            <SelectItem value="semi_monthly">Semi-monthly</SelectItem>
                            <SelectItem value="weekly">Weekly</SelectItem>
                            <SelectItem value="bi_weekly">Bi-weekly</SelectItem>
                            <SelectItem value="daily">Daily</SelectItem>
                            <SelectItem value="project">Project-based</SelectItem>
                          </SelectContent>
                        </Select>
                      </Field>
                      <Field label="Apply to Companies" className="min-w-0 lg:col-span-2" compact>
                        <Popover
                          open={companyPickerOpen}
                          onOpenChange={(open) => {
                            setCompanyPickerOpen(open)
                            if (!open) setCompanySearch('')
                          }}
                        >
                          <PopoverTrigger asChild>
                            <button
                              type="button"
                              className="flex min-h-9 w-full items-center justify-between gap-3 rounded-lg border border-border/60 bg-background px-3 py-2 text-left text-sm shadow-sm transition-colors hover:bg-muted/20"
                            >
                              <div className="min-w-0 flex-1">
                                <div className="flex items-center gap-2">
                                  <Building2 className="size-4 text-muted-foreground" />
                                  <span className={cn('text-sm', selectedCompanies.length ? 'text-foreground' : 'text-muted-foreground')}>
                                    {selectedCompanies.length ? `${selectedCompanies.length} compan${selectedCompanies.length === 1 ? 'y' : 'ies'} selected` : 'Search and select companies'}
                                  </span>
                                </div>
                              </div>
                              <ChevronRight className={cn('size-4 text-muted-foreground transition-transform', companyPickerOpen && 'rotate-90')} />
                            </button>
                          </PopoverTrigger>
                          <PopoverContent className="w-[min(100vw-2rem,36rem)] p-0" align="start">
                            <div className="border-b border-border/60 p-3">
                              <div className="relative">
                                <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                  value={companySearch}
                                  onChange={(e) => setCompanySearch(e.target.value)}
                                  placeholder="Search company name..."
                                  className="h-10 border-border/60 bg-background pl-9"
                                  autoFocus
                                />
                              </div>
                            </div>
                            <div className="max-h-72 overflow-y-auto p-2">
                              {filteredCompanyOptions.length === 0 ? (
                                <div className="px-3 py-6 text-center text-sm text-muted-foreground">No companies match this search.</div>
                              ) : filteredCompanyOptions.map((company) => {
                                const checked = selectedCompanyIds.includes(String(company.id))
                                return (
                                  <button
                                    key={company.id}
                                    type="button"
                                    onClick={() => {
                                      setForm((prev) => {
                                        const current = new Set((prev.company_ids || []).map((value) => String(value)))
                                        if (current.has(String(company.id))) current.delete(String(company.id))
                                        else current.add(String(company.id))
                                        const nextIds = Array.from(current)
                                        return {
                                          ...prev,
                                          company_ids: nextIds,
                                          company_id: nextIds[0] || '',
                                        }
                                      })
                                    }}
                                    className="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left text-sm transition-colors hover:bg-muted/40"
                                  >
                                    <Checkbox checked={checked} className="pointer-events-none" />
                                    <div className="min-w-0 flex-1">
                                      <p className="truncate font-medium text-foreground">{company.name}</p>
                                    </div>
                                  </button>
                                )
                              })}
                            </div>
                          </PopoverContent>
                        </Popover>
                        <p className="mt-1.5 text-[11px] leading-snug text-muted-foreground">
                          Optional — assign companies now or after saving.
                        </p>
                        {selectedCompanies.length > 0 ? (
                          <div className="mt-2 flex flex-wrap gap-1.5">
                            {selectedCompanies.map((company) => (
                              <Badge key={company.id} variant="secondary" className="rounded-full px-3 py-1 text-xs font-medium">
                                {company.name}
                                <button
                                  type="button"
                                  onClick={() => {
                                    setForm((prev) => {
                                      const nextIds = (prev.company_ids || [])
                                        .map((value) => String(value))
                                        .filter((value) => value !== String(company.id))
                                      return {
                                        ...prev,
                                        company_ids: nextIds,
                                        company_id: nextIds[0] || '',
                                      }
                                    })
                                  }}
                                  className="ml-2 rounded-full text-muted-foreground transition-colors hover:text-foreground"
                                  aria-label={`Remove ${company.name}`}
                                >
                                  <X className="size-3" />
                                </button>
                              </Badge>
                            ))}
                          </div>
                        ) : null}
                      </Field>
                    </div>
                  </ConfiguratorSection>

                  <ConfiguratorSection
                    eyebrow="Section 2"
                    title="Cut-off & pay date"
                    description="Fields match your frequency — check the live preview on the right."
                    compact
                  >
                    {form.code === 'semi_monthly' ? (
                      <div className="space-y-3">
                        {/*
                          Cut-off table: wide day inputs (w-24), roomy cells, flex range row so numbers stay readable.
                        */}
                        <div className="overflow-x-auto pb-0.5">
                          <div className="min-w-[44rem] overflow-hidden rounded-xl border border-border/60 bg-background shadow-sm dark:border-border/50">
                          <div className="grid grid-cols-[minmax(10rem,1fr)_minmax(14rem,1.35fr)_minmax(9.5rem,1fr)_minmax(9.5rem,1fr)] gap-0 border-b border-border/60 bg-muted/30 text-[10px] font-semibold uppercase tracking-[0.14em] text-muted-foreground">
                            <div className="border-r border-border/40 px-4 py-2 last:border-r-0">Period</div>
                            <div className="border-r border-border/40 px-4 py-2 last:border-r-0">Range</div>
                            <div className="border-r border-border/40 px-4 py-2 last:border-r-0">Payout Rule</div>
                            <div className="px-4 py-2">Value</div>
                          </div>
                          <div className="grid grid-cols-[minmax(10rem,1fr)_minmax(14rem,1.35fr)_minmax(9.5rem,1fr)_minmax(9.5rem,1fr)] gap-0 border-b border-border/60">
                            <div className="flex items-center border-r border-border/40 px-4 py-3 text-xs font-medium leading-snug text-foreground">
                              First Cut-off Period
                            </div>
                            <div className="flex flex-wrap items-end gap-x-4 gap-y-2 border-r border-border/40 px-4 py-3">
                              <Field label="Start Day" className="shrink-0" compact>
                                <Input
                                  className="h-10 w-24 min-w-[6rem] rounded-lg border-border/60 bg-background px-3 text-center text-base font-semibold tabular-nums text-foreground shadow-sm read-only:bg-muted/35 read-only:text-muted-foreground"
                                  type="number"
                                  min="1"
                                  max="31"
                                  value={String(Math.min(Number(form.second_cutoff_day || 31) + 1, 31))}
                                  readOnly
                                />
                              </Field>
                              <Field label="End Day" className="shrink-0" compact>
                                <Input
                                  className="h-10 w-24 min-w-[6rem] rounded-lg border-border/60 bg-background px-3 text-center text-base font-semibold tabular-nums text-foreground shadow-sm"
                                  type="number"
                                  min="1"
                                  max="31"
                                  value={form.first_cutoff_day}
                                  onChange={(e) => setForm((prev) => ({ ...prev, first_cutoff_day: e.target.value }))}
                                />
                              </Field>
                            </div>
                            <div className="border-r border-border/40 px-4 py-3">
                              <Select value={form.pay_day_type === 'fixed_day' ? 'fixed_day' : 'offset'} onValueChange={(value) => setForm((prev) => ({ ...prev, pay_day_type: value }))}>
                                <SelectTrigger className="h-10 w-full min-w-[8.5rem] rounded-lg border-border/60 bg-background px-3 text-sm shadow-sm">
                                  <SelectValue />
                                </SelectTrigger>
                                <SelectContent className="min-w-[220px]">
                                  <SelectItem value="offset">Offset</SelectItem>
                                  <SelectItem value="fixed_day">Fixed Day</SelectItem>
                                </SelectContent>
                              </Select>
                            </div>
                            <div className="px-4 py-3">
                              {form.pay_day_type === 'fixed_day' ? (
                                <Input
                                  className="h-10 w-full min-w-[6rem] max-w-[7rem] rounded-lg border-border/60 bg-background px-3 text-center text-base font-semibold tabular-nums text-foreground shadow-sm"
                                  type="number"
                                  min="1"
                                  max="31"
                                  value={form.pay_fixed_day}
                                  onChange={(e) => setForm((prev) => ({ ...prev, pay_fixed_day: e.target.value }))}
                                />
                              ) : (
                                <Input
                                  className="h-10 w-full min-w-[6rem] max-w-[7rem] rounded-lg border-border/60 bg-background px-3 text-center text-base font-semibold tabular-nums text-foreground shadow-sm"
                                  type="number"
                                  min="0"
                                  max="60"
                                  value={form.first_pay_day_offset}
                                  onChange={(e) => setForm((prev) => ({ ...prev, first_pay_day_offset: e.target.value, pay_day_offset: e.target.value }))}
                                />
                              )}
                            </div>
                          </div>
                          <div className="grid grid-cols-[minmax(10rem,1fr)_minmax(14rem,1.35fr)_minmax(9.5rem,1fr)_minmax(9.5rem,1fr)] gap-0">
                            <div className="flex items-center border-r border-border/40 px-4 py-3 text-xs font-medium leading-snug text-foreground">
                              Second Cut-off Period
                            </div>
                            <div className="flex flex-wrap items-end gap-x-4 gap-y-2 border-r border-border/40 px-4 py-3">
                              <Field label="Start Day" className="shrink-0" compact>
                                <Input
                                  className="h-10 w-24 min-w-[6rem] rounded-lg border-border/60 bg-background px-3 text-center text-base font-semibold tabular-nums text-foreground shadow-sm read-only:bg-muted/35 read-only:text-muted-foreground"
                                  type="number"
                                  min="1"
                                  max="31"
                                  value={String(Number(form.first_cutoff_day || 15) + 1)}
                                  readOnly
                                />
                              </Field>
                              <Field label="End Day" className="shrink-0" compact>
                                <Input
                                  className="h-10 w-24 min-w-[6rem] rounded-lg border-border/60 bg-background px-3 text-center text-base font-semibold tabular-nums text-foreground shadow-sm"
                                  type="number"
                                  min="1"
                                  max="31"
                                  value={form.second_cutoff_day}
                                  onChange={(e) => setForm((prev) => ({ ...prev, second_cutoff_day: e.target.value }))}
                                />
                              </Field>
                            </div>
                            <div className="border-r border-border/40 px-4 py-3">
                              <div className="flex h-10 items-center rounded-lg border border-border/60 bg-muted/25 px-3 text-xs font-medium text-muted-foreground">
                                {form.pay_day_type === 'fixed_day' ? 'Fixed Day' : 'Offset'}
                              </div>
                            </div>
                            <div className="px-4 py-3">
                              {form.pay_day_type === 'fixed_day' ? (
                                <Select value={String(form.second_pay_fixed_day)} onValueChange={(value) => setForm((prev) => ({ ...prev, second_pay_fixed_day: value }))}>
                                  <SelectTrigger className="h-10 w-full min-w-0 rounded-lg border-border/60 bg-background px-3 text-sm shadow-sm">
                                    <SelectValue />
                                  </SelectTrigger>
                                  <SelectContent className="min-w-[220px]">
                                    <SelectItem value="31">Last day of month</SelectItem>
                                    {[...Array(30)].map((_, idx) => {
                                      const day = String(idx + 1)
                                      return <SelectItem key={day} value={day}>{day}</SelectItem>
                                    })}
                                  </SelectContent>
                                </Select>
                              ) : (
                                <Input
                                  className="h-10 w-full min-w-[6rem] max-w-[7rem] rounded-lg border-border/60 bg-background px-3 text-center text-base font-semibold tabular-nums text-foreground shadow-sm"
                                  type="number"
                                  min="0"
                                  max="60"
                                  value={form.second_pay_day_offset}
                                  onChange={(e) => setForm((prev) => ({ ...prev, second_pay_day_offset: e.target.value }))}
                                />
                              )}
                            </div>
                          </div>
                          </div>
                        </div>
                      </div>
                    ) : form.code === 'monthly' ? (
                      <div className="grid gap-3 lg:grid-cols-2">
                        <Field label="Monthly cut-off" className="min-w-0 lg:col-span-2" compact>
                          <div className="flex h-9 items-center rounded-lg border border-border/60 bg-muted/20 px-3 text-xs text-muted-foreground">
                            1st day to last day of the month
                          </div>
                        </Field>
                        <Field label="Pay date offset (days)" className="min-w-0" compact>
                          <Input className="h-9 w-full min-w-0 rounded-lg border-border/60 bg-background text-sm shadow-sm" type="number" min="0" max="31" value={form.pay_day_offset} onChange={(e) => setForm((prev) => ({ ...prev, pay_day_offset: e.target.value }))} />
                        </Field>
                      </div>
                    ) : form.code === 'weekly' ? (
                      <div className="grid gap-3 lg:grid-cols-2">
                        <Field label="Week starts on" className="min-w-0" compact>
                          <Select value={form.weekly_anchor_day} onValueChange={(value) => setForm((prev) => ({ ...prev, weekly_anchor_day: value }))}>
                            <SelectTrigger className="h-9 w-full min-w-0 rounded-lg border-border/60 bg-background text-sm shadow-sm">
                              <SelectValue />
                            </SelectTrigger>
                            <SelectContent className="min-w-[220px]">
                              {['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'].map((day) => (
                                <SelectItem key={day} value={day}>{day[0].toUpperCase() + day.slice(1)}</SelectItem>
                              ))}
                            </SelectContent>
                          </Select>
                        </Field>
                        <Field label="Pay date offset (days)" className="min-w-0" compact>
                          <Input className="h-9 w-full min-w-0 rounded-lg border-border/60 bg-background text-sm shadow-sm" type="number" min="0" max="14" value={form.pay_day_offset} onChange={(e) => setForm((prev) => ({ ...prev, pay_day_offset: e.target.value }))} />
                        </Field>
                      </div>
                    ) : form.code === 'bi_weekly' ? (
                      <div className="grid gap-3 lg:grid-cols-2">
                        <Field label="Cycle start date" className="min-w-0" compact>
                          <Input className="h-9 w-full min-w-0 rounded-lg border-border/60 bg-background text-sm shadow-sm" type="date" value={form.biweekly_start_date} onChange={(e) => setForm((prev) => ({ ...prev, biweekly_start_date: e.target.value }))} />
                        </Field>
                        <Field label="Pay date offset (days)" className="min-w-0" compact>
                          <Input className="h-9 w-full min-w-0 rounded-lg border-border/60 bg-background text-sm shadow-sm" type="number" min="0" max="21" value={form.pay_day_offset} onChange={(e) => setForm((prev) => ({ ...prev, pay_day_offset: e.target.value }))} />
                        </Field>
                      </div>
                    ) : form.code === 'daily' ? (
                      <div className="grid gap-3 lg:grid-cols-2">
                        <Field label="Daily cut-off" className="min-w-0 lg:col-span-2" compact>
                          <div className="flex h-9 items-center rounded-lg border border-border/60 bg-muted/20 px-3 text-xs text-muted-foreground">
                            Every calendar day
                          </div>
                        </Field>
                        <Field label="Pay date offset (days)" className="min-w-0" compact>
                          <Input className="h-9 w-full min-w-0 rounded-lg border-border/60 bg-background text-sm shadow-sm" type="number" min="1" max="3" value={form.daily_pay_offset} onChange={(e) => setForm((prev) => ({ ...prev, daily_pay_offset: e.target.value, pay_day_offset: e.target.value }))} />
                        </Field>
                      </div>
                    ) : form.code === 'project' ? (
                      <div className="grid gap-3 lg:grid-cols-2">
                        <Field label="Project / milestone start date" className="min-w-0" compact>
                          <Input className="h-9 w-full min-w-0 rounded-lg border-border/60 bg-background text-sm shadow-sm" type="date" value={form.project_start_date} onChange={(e) => setForm((prev) => ({ ...prev, project_start_date: e.target.value }))} />
                        </Field>
                        <Field label="Project / milestone end date" className="min-w-0" compact>
                          <Input className="h-9 w-full min-w-0 rounded-lg border-border/60 bg-background text-sm shadow-sm" type="date" value={form.project_end_date} onChange={(e) => setForm((prev) => ({ ...prev, project_end_date: e.target.value }))} />
                        </Field>
                        <Field label="Pay date" className="min-w-0 lg:col-span-2" compact>
                          <Input className="h-9 w-full min-w-0 rounded-lg border-border/60 bg-background text-sm shadow-sm" type="date" value={form.project_pay_date} onChange={(e) => setForm((prev) => ({ ...prev, project_pay_date: e.target.value }))} />
                        </Field>
                      </div>
                    ) : (
                      <div className="grid gap-3 lg:grid-cols-2">
                        <Field label="Pay day rule" className="min-w-0" compact>
                          <Select value={form.pay_day_type} onValueChange={(value) => setForm((prev) => ({ ...prev, pay_day_type: value }))}>
                            <SelectTrigger className="h-9 w-full min-w-0 rounded-lg border-border/60 bg-background text-sm shadow-sm">
                              <SelectValue />
                            </SelectTrigger>
                            <SelectContent className="min-w-[240px]">
                              <SelectItem value="offset">Offset from cut-off</SelectItem>
                              <SelectItem value="fixed_day">Fixed day</SelectItem>
                            </SelectContent>
                          </Select>
                        </Field>
                        {form.pay_day_type === 'offset' ? (
                          <Field label="Payout offset (days)" className="min-w-0" compact>
                            <Input className="h-9 w-full min-w-0 rounded-lg border-border/60 bg-background text-sm shadow-sm" type="number" min="0" max="60" value={form.pay_day_offset} onChange={(e) => setForm((prev) => ({ ...prev, pay_day_offset: e.target.value }))} />
                          </Field>
                        ) : (
                          <Field label="Payout day" className="min-w-0" compact>
                            <Input className="h-9 w-full min-w-0 rounded-lg border-border/60 bg-background text-sm shadow-sm" type="number" min="1" max="31" value={form.pay_fixed_day} onChange={(e) => setForm((prev) => ({ ...prev, pay_fixed_day: e.target.value }))} />
                          </Field>
                        )}
                      </div>
                    )}
                  </ConfiguratorSection>

                  <ConfiguratorSection
                    eyebrow="Additional"
                    title="Additional fields"
                    description="Proration, weekend handling, and whether this cycle is active or the company default."
                    compact
                  >
                    <div className="grid gap-3 lg:grid-cols-2">
                      <Field label="Proration" className="min-w-0" compact>
                        <Select value={form.pro_ration_type} onValueChange={(value) => setForm((prev) => ({ ...prev, pro_ration_type: value }))}>
                          <SelectTrigger className="h-9 w-full min-w-0 rounded-lg border-border/60 bg-background text-sm shadow-sm">
                            <SelectValue />
                          </SelectTrigger>
                          <SelectContent className="min-w-[220px]">
                            <SelectItem value="daily">Daily</SelectItem>
                            <SelectItem value="hourly">Hourly</SelectItem>
                            <SelectItem value="none">None</SelectItem>
                          </SelectContent>
                        </Select>
                      </Field>
                      <ModernSwitchCard
                        title="Weekend Adjustment"
                        description="When pay day falls on Saturday or Sunday, move it to the previous Friday."
                        checked={form.weekend_adjustment_rule === 'previous_friday'}
                        onCheckedChange={(checked) => setForm((prev) => ({ ...prev, weekend_adjustment_rule: checked ? 'previous_friday' : 'none' }))}
                        compact
                      />
                      <ModernSwitchCard
                        title="Active"
                        description="Can be assigned to employees."
                        checked={form.is_active}
                        onCheckedChange={(checked) => setForm((prev) => ({ ...prev, is_active: checked }))}
                        compact
                      />
                      <ModernSwitchCard
                        title="Set as Company Default"
                        description="Use this cycle when no employee override exists."
                        checked={form.is_default}
                        onCheckedChange={(checked) => setForm((prev) => ({ ...prev, is_default: checked }))}
                        compact
                      />
                    </div>
                  </ConfiguratorSection>

                  <DialogFooter className="flex flex-col gap-2 border-t border-border/60 pt-4 sm:flex-row sm:items-center sm:justify-between dark:border-border/50">
                    <Button type="button" variant="outline" className={APP_MODAL_OUTLINE_BUTTON_CLASS} onClick={() => setDialogOpen(false)}>
                      Cancel
                    </Button>
                    <Button
                      type="submit"
                      className={APP_MODAL_PRIMARY_BUTTON_CLASS}
                      disabled={saving || !form.name.trim()}
                    >
                      {saving ? <Loader2 className="mr-2 size-4 animate-spin" /> : <Plus className="mr-2 size-4" />}
                      {editing ? 'Save changes' : 'Create cycle'}
                    </Button>
                  </DialogFooter>
                </form>
              </div>

              <div className="min-h-0 overflow-y-auto overscroll-contain border-t border-border/60 bg-muted/15 p-4 lg:border-t-0 lg:border-l dark:border-border/50 dark:bg-muted/10">
                <div className="sticky top-0 space-y-3">
                  <div className="space-y-1">
                    <Badge variant="outline" className="rounded-full border-border/70 bg-background text-[10px] text-muted-foreground">
                      Live preview
                    </Badge>
                    <h3 className="text-base font-semibold text-foreground">Upcoming pay periods</h3>
                    <p className="text-xs leading-snug text-muted-foreground">
                      Updates as you edit — next cut-offs and pay dates.
                    </p>
                  </div>
                  {formPreview ? (
                    <>
                      <UpcomingPeriodsPanel preview={formPreview} compact />
                      {form.code !== 'project' ? (
                        <WeekendAdjustmentNotice
                          compact
                          note={formPreview.weekend_adjustment_note || 'When Pay Date falls on Saturday or Sunday, Pay Day will be moved to the previous Friday.'}
                        />
                      ) : null}
                    </>
                  ) : (
                    <div className="rounded-lg border border-dashed border-border/60 bg-background p-4 text-xs text-muted-foreground dark:border-border/50">
                      Configure the cycle to see period and pay date preview.
                    </div>
                  )}
                </div>
              </div>
            </div>
          </DialogContent>
        </Dialog>

        <Dialog
          open={deleteDialogOpen}
          onOpenChange={(open) => {
            if (!deleting) {
              setDeleteDialogOpen(open)
              if (!open) setCycleToDelete(null)
            }
          }}
        >
          <DialogContent className={appModalDialogContentClass({ size: 'sm', className: 'max-h-[min(90vh,520px)]' })}>
            <DialogHeader>
              <DialogTitle>Delete pay cycle</DialogTitle>
              <DialogDescription>
                Delete pay cycle "{cycleToDelete?.name || 'this cycle'}"?
              </DialogDescription>
            </DialogHeader>

            <div className="rounded-xl border border-border/60 bg-muted/20 px-4 py-3 text-sm text-muted-foreground dark:border-border/50">
              This removes the pay cycle configuration from the company list. Employees or company defaults using this cycle may need to be reassigned afterward.
            </div>

            <DialogFooter className="mt-2 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <Button
                type="button"
                variant="outline"
                className={APP_MODAL_OUTLINE_BUTTON_CLASS}
                onClick={() => {
                  setDeleteDialogOpen(false)
                  setCycleToDelete(null)
                }}
                disabled={deleting}
              >
                Cancel
              </Button>
              <Button
                type="button"
                className="rounded-lg bg-rose-600 text-white hover:bg-rose-700"
                onClick={handleDelete}
                disabled={deleting}
              >
                {deleting ? 'Deleting...' : 'Delete'}
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </div>
    </TooltipProvider>
  )
}

function buildPayload(form, forPreview = false) {
  const companyIds = Array.from(new Set((form.company_ids || []).map((value) => Number(value)).filter(Boolean)))
  const payload = {
    name: form.name.trim(),
    company_id: companyIds[0] ?? null,
    company_ids: companyIds,
    code: form.code,
    cut_off_type: form.code === 'weekly' || form.code === 'bi_weekly' ? 'day_of_week' : 'fixed_day',
    cut_off_value: form.code === 'semi_monthly'
      ? [Number(form.first_cutoff_day || 15), Number(form.second_cutoff_day || 31)]
      : (form.code === 'weekly'
        ? { day_of_week: form.weekly_anchor_day }
        : (form.code === 'bi_weekly'
          ? { day_of_week: form.weekly_anchor_day, start_date: form.biweekly_start_date || null }
          : (form.code === 'project'
            ? { start_date: form.project_start_date || null, end_date: form.project_end_date || null }
            : null))),
    pay_day_type: form.pay_day_type,
    pay_day_value: form.code === 'semi_monthly'
      ? (
        form.pay_day_type === 'fixed_day'
          ? {
            first_day: Number(form.pay_fixed_day || 15),
            second_day: Number(form.second_pay_fixed_day || 31) >= 31 ? 'end_of_month' : Number(form.second_pay_fixed_day || 31),
          }
          : {
            first_offset: Number(form.first_pay_day_offset || form.pay_day_offset || 0),
            second_offset: Number(form.second_pay_day_offset || form.pay_day_offset || 0),
          }
      )
      : (form.code === 'project'
        ? { date: form.project_pay_date || null }
        : (form.pay_day_type === 'fixed_day' ? { day: Number(form.pay_fixed_day || 5) } : null)),
    pay_day_offset: form.pay_day_type === 'offset' ? Number((form.code === 'daily' ? form.daily_pay_offset : form.pay_day_offset) || 0) : null,
    pro_ration_type: form.pro_ration_type,
    metadata: {
      weekend_adjustment_rule: form.weekend_adjustment_rule || 'previous_friday',
    },
    is_active: Boolean(form.is_active),
    is_default: Boolean(form.is_default),
  }
  if (forPreview) {
    delete payload.company_id
    delete payload.company_ids
  }
  return payload
}

function generatePreviewPeriods(formValues, count = 6) {
  const code = String(formValues?.code || 'semi_monthly')
  const weekendRule = String(formValues?.weekend_adjustment_rule || 'previous_friday')
  const reference = startOfDay(new Date())

  if (code === 'semi_monthly') {
    const periods = buildSemiMonthlyPreviewPeriods(formValues, reference, count, weekendRule)
    const current = periods[0]
    return {
      code,
      pro_ration_type: formValues?.pro_ration_type || 'none',
      cut_off_start_date: current?.cut_off_start_date || null,
      cut_off_end_date: current?.cut_off_end_date || null,
      pay_date: current?.pay_date || null,
      preview_periods: periods,
      weekend_adjustment_note: periods.some((period) => period.weekend_adjusted)
        ? 'When Pay Date falls on Saturday or Sunday, Pay Day will be moved to the previous Friday.'
        : 'When Pay Date falls on Saturday or Sunday, Pay Day will be moved to the previous Friday.',
    }
  }

  if (code === 'monthly') {
    const periods = buildMonthlyPreviewPeriods(formValues, reference, count, weekendRule)
    const current = periods[0]
    return buildPreviewEnvelope(code, formValues, periods, current)
  }

  if (code === 'weekly') {
    const periods = buildWeeklyPreviewPeriods(formValues, reference, count, weekendRule)
    const current = periods[0]
    return buildPreviewEnvelope(code, formValues, periods, current)
  }

  if (code === 'bi_weekly') {
    const periods = buildBiWeeklyPreviewPeriods(formValues, reference, count, weekendRule)
    const current = periods[0]
    return buildPreviewEnvelope(code, formValues, periods, current)
  }

  if (code === 'daily') {
    const periods = buildDailyPreviewPeriods(formValues, reference, count, weekendRule)
    const current = periods[0]
    return buildPreviewEnvelope(code, formValues, periods, current)
  }

  if (code === 'project') {
    const periods = buildProjectPreviewPeriods(formValues, reference)
    const current = periods[0]
    return buildPreviewEnvelope(code, formValues, periods, current, null)
  }

  const payDate = adjustPreviewWeekend(addDays(reference, Number(formValues?.pay_day_offset || 0)), weekendRule)
  return {
    code,
    pro_ration_type: formValues?.pro_ration_type || 'none',
    cut_off_start_date: toIsoDate(reference),
    cut_off_end_date: toIsoDate(reference),
    pay_date: toIsoDate(payDate),
    preview_periods: [{
      cut_off_start_date: toIsoDate(reference),
      cut_off_end_date: toIsoDate(reference),
      pay_date: toIsoDate(payDate),
      period_days: 1,
      weekend_adjusted: !isSameDate(payDate, addDays(reference, Number(formValues?.pay_day_offset || 0))),
    }],
    weekend_adjustment_note: 'When Pay Date falls on Saturday or Sunday, Pay Day will be moved to the previous Friday.',
  }
}

function buildPreviewEnvelope(code, formValues, periods, current, weekendNote = 'When Pay Date falls on Saturday or Sunday, Pay Day will be moved to the previous Friday.') {
  return {
    code,
    pro_ration_type: formValues?.pro_ration_type || 'none',
    cut_off_start_date: current?.cut_off_start_date || null,
    cut_off_end_date: current?.cut_off_end_date || null,
    pay_date: current?.pay_date || null,
    preview_periods: periods,
    weekend_adjustment_note: weekendNote,
  }
}

function buildSemiMonthlyPreviewPeriods(formValues, referenceDate, count, weekendRule) {
  const firstCutoff = clampDay(formValues?.first_cutoff_day, 15)
  const secondCutoff = clampDay(formValues?.second_cutoff_day, 31)
  const periods = []
  let cursor = startOfDay(referenceDate)

  for (let index = 0; index < count; index += 1) {
    const period = resolveSemiMonthlyPreviewPeriod(formValues, cursor, firstCutoff, secondCutoff, weekendRule)
    periods.push(period)
    cursor = addDays(fromIsoDate(period.cut_off_end_date), 1)
  }

  return periods
}

function buildMonthlyPreviewPeriods(formValues, referenceDate, count, weekendRule) {
  const periods = []
  const offset = Number(formValues?.pay_day_offset || 5)
  let cursor = startOfMonth(referenceDate)

  for (let index = 0; index < count; index += 1) {
    const start = startOfMonth(cursor)
    const end = endOfMonth(cursor)
    const rawPayDate = addDays(end, offset)
    const payDate = adjustPreviewWeekend(rawPayDate, weekendRule)
    periods.push({
      cut_off_start_date: toIsoDate(start),
      cut_off_end_date: toIsoDate(end),
      pay_date: toIsoDate(payDate),
      period_days: dayDiffInclusive(start, end),
      weekend_adjusted: !isSameDate(rawPayDate, payDate),
      preview_label: `${monthLabel(start)} (${start.getDate()} - ${end.getDate()})`,
    })
    cursor = addMonths(cursor, 1)
  }

  return periods
}

function buildWeeklyPreviewPeriods(formValues, referenceDate, count, weekendRule) {
  const periods = []
  const offset = Number(formValues?.pay_day_offset || 7)
  const anchorDay = dayNameToIndex(formValues?.weekly_anchor_day || 'monday')
  const start = moveToWeekStart(referenceDate, anchorDay)

  for (let index = 0; index < count; index += 1) {
    const periodStart = addDays(start, index * 7)
    const periodEnd = addDays(periodStart, 6)
    const rawPayDate = addDays(periodEnd, offset)
    const payDate = adjustPreviewWeekend(rawPayDate, weekendRule)
    periods.push({
      cut_off_start_date: toIsoDate(periodStart),
      cut_off_end_date: toIsoDate(periodEnd),
      pay_date: toIsoDate(payDate),
      period_days: 7,
      weekend_adjusted: !isSameDate(rawPayDate, payDate),
      preview_label: `Week of ${shortDateLabel(periodStart)} - ${shortDateLabel(periodEnd)}`,
    })
  }

  return periods
}

function buildBiWeeklyPreviewPeriods(formValues, referenceDate, count, weekendRule) {
  const periods = []
  const offset = Number(formValues?.pay_day_offset || 7)
  const start = formValues?.biweekly_start_date ? fromIsoDate(formValues.biweekly_start_date) : startOfDay(referenceDate)

  for (let index = 0; index < count; index += 1) {
    const periodStart = addDays(start, index * 14)
    const periodEnd = addDays(periodStart, 13)
    const rawPayDate = addDays(periodEnd, offset)
    const payDate = adjustPreviewWeekend(rawPayDate, weekendRule)
    periods.push({
      cut_off_start_date: toIsoDate(periodStart),
      cut_off_end_date: toIsoDate(periodEnd),
      pay_date: toIsoDate(payDate),
      period_days: 14,
      weekend_adjusted: !isSameDate(rawPayDate, payDate),
      preview_label: `${shortDateLabel(periodStart)} - ${shortDateLabel(periodEnd)} (2 weeks)`,
    })
  }

  return periods
}

function buildDailyPreviewPeriods(formValues, referenceDate, count, weekendRule) {
  const periods = []
  const offset = Number(formValues?.daily_pay_offset || formValues?.pay_day_offset || 1)

  for (let index = 0; index < count; index += 1) {
    const periodStart = addDays(referenceDate, index)
    const rawPayDate = addDays(periodStart, offset)
    const payDate = adjustPreviewWeekend(rawPayDate, weekendRule)
    periods.push({
      cut_off_start_date: toIsoDate(periodStart),
      cut_off_end_date: toIsoDate(periodStart),
      pay_date: toIsoDate(payDate),
      period_days: 1,
      weekend_adjusted: !isSameDate(rawPayDate, payDate),
      preview_label: `Daily - ${shortDateLabel(periodStart)}`,
    })
  }

  return periods
}

function buildProjectPreviewPeriods(formValues, referenceDate) {
  const start = formValues?.project_start_date ? fromIsoDate(formValues.project_start_date) : startOfDay(referenceDate)
  const end = formValues?.project_end_date ? fromIsoDate(formValues.project_end_date) : addDays(start, 13)
  const payDate = formValues?.project_pay_date ? fromIsoDate(formValues.project_pay_date) : addDays(end, 1)

  return [{
    cut_off_start_date: toIsoDate(start),
    cut_off_end_date: toIsoDate(end),
    pay_date: toIsoDate(payDate),
    period_days: dayDiffInclusive(start, end),
    weekend_adjusted: false,
    preview_label: `Project period: ${shortDateLabel(start)} - ${shortDateLabel(end)}`,
  }]
}

function resolveSemiMonthlyPreviewPeriod(formValues, referenceDate, firstCutoff, secondCutoff, weekendRule) {
  const currentMonthEnd = endOfMonth(referenceDate).getDate()
  const effectiveSecondCutoff = Math.min(secondCutoff, currentMonthEnd)
  let start
  let end
  let segment

  if (referenceDate.getDate() <= firstCutoff) {
    const previousMonth = addMonths(referenceDate, -1)
    const previousMonthEnd = endOfMonth(previousMonth).getDate()
    start = secondCutoff >= previousMonthEnd
      ? startOfMonth(referenceDate)
      : createDate(previousMonth.getFullYear(), previousMonth.getMonth(), Math.min(secondCutoff + 1, previousMonthEnd))
    end = createDate(referenceDate.getFullYear(), referenceDate.getMonth(), Math.min(firstCutoff, currentMonthEnd))
    segment = 'first'
  } else if (referenceDate.getDate() <= effectiveSecondCutoff) {
    start = createDate(referenceDate.getFullYear(), referenceDate.getMonth(), Math.min(firstCutoff + 1, currentMonthEnd))
    end = createDate(referenceDate.getFullYear(), referenceDate.getMonth(), effectiveSecondCutoff)
    segment = 'second'
  } else {
    const nextMonth = addMonths(referenceDate, 1)
    const nextMonthEnd = endOfMonth(nextMonth).getDate()
    start = createDate(referenceDate.getFullYear(), referenceDate.getMonth(), Math.min(effectiveSecondCutoff + 1, currentMonthEnd))
    end = createDate(nextMonth.getFullYear(), nextMonth.getMonth(), Math.min(firstCutoff, nextMonthEnd))
    segment = 'first'
  }

  const rawPayDate = resolveSemiMonthlyPreviewPayDate(formValues, end, segment)
  const adjustedPayDate = adjustPreviewWeekend(rawPayDate, weekendRule)

  return {
    cut_off_start_date: toIsoDate(start),
    cut_off_end_date: toIsoDate(end),
    pay_date: toIsoDate(adjustedPayDate),
    period_days: dayDiffInclusive(start, end),
    weekend_adjusted: !isSameDate(rawPayDate, adjustedPayDate),
  }
}

function resolveSemiMonthlyPreviewPayDate(formValues, periodEnd, segment) {
  if (String(formValues?.pay_day_type || 'offset') === 'fixed_day') {
    const firstPayDay = clampDay(formValues?.pay_fixed_day, 15)
    const secondPayDayRaw = Number(formValues?.second_pay_fixed_day || 31)
    if (segment === 'first') {
      return createDate(periodEnd.getFullYear(), periodEnd.getMonth(), Math.min(firstPayDay, endOfMonth(periodEnd).getDate()))
    }
    if (secondPayDayRaw >= 31) {
      return endOfMonth(periodEnd)
    }
    return createDate(periodEnd.getFullYear(), periodEnd.getMonth(), Math.min(secondPayDayRaw, endOfMonth(periodEnd).getDate()))
  }

  const offset = segment === 'first'
    ? Number(formValues?.first_pay_day_offset || formValues?.pay_day_offset || 0)
    : Number(formValues?.second_pay_day_offset || formValues?.pay_day_offset || 0)
  return addDays(periodEnd, offset)
}

function adjustPreviewWeekend(date, rule) {
  const next = startOfDay(date)
  if (rule !== 'previous_friday') return next
  if (next.getDay() === 6) return addDays(next, -1)
  if (next.getDay() === 0) return addDays(next, -2)
  return next
}

function clampDay(value, fallback) {
  const parsed = Number(value)
  if (!Number.isFinite(parsed)) return fallback
  return Math.max(1, Math.min(31, Math.trunc(parsed)))
}

function startOfDay(date) {
  const next = new Date(date)
  next.setHours(0, 0, 0, 0)
  return next
}

function startOfMonth(date) {
  return createDate(date.getFullYear(), date.getMonth(), 1)
}

function endOfMonth(date) {
  return createDate(date.getFullYear(), date.getMonth() + 1, 0)
}

function addMonths(date, months) {
  return createDate(date.getFullYear(), date.getMonth() + months, date.getDate())
}

function addDays(date, days) {
  const next = new Date(date)
  next.setDate(next.getDate() + Number(days || 0))
  return startOfDay(next)
}

function moveToWeekStart(date, anchorDay) {
  const cursor = startOfDay(date)
  const diff = (cursor.getDay() - anchorDay + 7) % 7
  return addDays(cursor, -diff)
}

function dayNameToIndex(day) {
  return ({
    sunday: 0,
    monday: 1,
    tuesday: 2,
    wednesday: 3,
    thursday: 4,
    friday: 5,
    saturday: 6,
  }[String(day || 'monday').toLowerCase()] ?? 1)
}

function createDate(year, month, day) {
  const next = new Date(year, month, day)
  next.setHours(0, 0, 0, 0)
  return next
}

function toIsoDate(date) {
  const normalized = startOfDay(date)
  const year = normalized.getFullYear()
  const month = String(normalized.getMonth() + 1).padStart(2, '0')
  const day = String(normalized.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

function fromIsoDate(value) {
  const [year, month, day] = String(value).split('-').map(Number)
  return createDate(year, (month || 1) - 1, day || 1)
}

function isSameDate(left, right) {
  return toIsoDate(left) === toIsoDate(right)
}

function dayDiffInclusive(start, end) {
  const ms = fromIsoDate(toIsoDate(end)).getTime() - fromIsoDate(toIsoDate(start)).getTime()
  return Math.round(ms / 86400000) + 1
}

function describeCutoff(cycle) {
  if (cycle.code === 'semi_monthly') {
    const first = cycle.cut_off_value?.[0] ?? 15
    const second = cycle.cut_off_value?.[1]
    const secondLabel = second === 'end_of_month' || Number(second) >= 31 ? 'month-end' : second
    return `Days ${first} & ${secondLabel}`
  }
  if (cycle.code === 'weekly' || cycle.code === 'bi_weekly') return `${cycle.cut_off_value?.day_of_week || 'Monday'} anchor`
  if (cycle.code === 'daily') return 'Every attendance day'
  if (cycle.code === 'project') return 'Project-defined window'
  return cycle.preview?.cycle_label || 'Calendar based'
}

function describePayRule(cycle) {
  if (cycle.code === 'semi_monthly' && cycle.pay_day_type === 'fixed_day') {
    const first = cycle.pay_day_value?.first_day || cycle.pay_day_value?.day || cycle.pay_day_offset || 15
    const second = cycle.pay_day_value?.second_day
    const secondLabel = second === 'end_of_month' || Number(second) >= 31 ? 'month-end' : `${second}`
    return `${first} and ${secondLabel}`
  }
  if (cycle.code === 'semi_monthly' && cycle.pay_day_type === 'offset') {
    const first = cycle.pay_day_value?.first_offset ?? cycle.pay_day_offset ?? 0
    const second = cycle.pay_day_value?.second_offset ?? cycle.pay_day_offset ?? 0
    return `+${first}d / +${second}d after cut-off`
  }
  if (cycle.pay_day_type === 'fixed_day') return `Fixed day ${cycle.pay_day_value?.day || cycle.pay_day_offset || 5}`
  return `${cycle.pay_day_offset || 0} day offset`
}

function FrequencyBadge({ code }) {
  const label = String(code || '').replace(/_/g, ' ')
  const tone = {
    monthly: 'border-border/70 bg-background text-muted-foreground',
    semi_monthly: 'border-sky-200/80 bg-sky-50 text-sky-900 dark:border-sky-500/20 dark:bg-sky-500/10 dark:text-sky-100',
    weekly: 'border-border/70 bg-background text-muted-foreground',
    bi_weekly: 'border-border/70 bg-background text-muted-foreground',
    daily: 'border-border/70 bg-background text-muted-foreground',
    project: 'border-border/70 bg-background text-muted-foreground',
  }[code] || 'border-border/70 bg-background text-muted-foreground'
  return <Badge variant="outline" className={cn('rounded-full capitalize px-2.5 py-1', tone)}>{label}</Badge>
}

function PreviewLine({ icon, label, value }) {
  const PreviewIcon = icon
  return (
    <div className="rounded-lg border border-border/60 bg-card p-4 shadow-sm dark:border-border/50">
      <div className="flex items-center gap-2 text-[11px] uppercase tracking-[0.18em] text-muted-foreground">
        <PreviewIcon className="size-3.5 text-muted-foreground" />
        {label}
      </div>
      <p className="mt-2 text-sm font-medium text-foreground">{value}</p>
    </div>
  )
}

function KpiCard({ label, value, icon, tone = 'blue', note }) {
  const KpiIcon = icon
  const iconTone = {
    blue: 'bg-muted text-muted-foreground ring-1 ring-border/50',
    teal: 'bg-muted text-muted-foreground ring-1 ring-border/50',
    violet: 'bg-muted text-muted-foreground ring-1 ring-border/50',
  }[tone]

  return (
    <Card className="overflow-hidden border border-border/60 bg-background shadow-sm transition-colors dark:border-border/50">
      <CardContent className="flex items-start justify-between gap-4 p-6">
        <div className="space-y-2">
          <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-muted-foreground">{label}</p>
          <p className="text-4xl font-semibold tracking-tight text-foreground">{value}</p>
          <p className="text-sm text-muted-foreground">{note}</p>
        </div>
        <div className={cn('flex size-12 items-center justify-center rounded-md', iconTone)}>
          <KpiIcon className="size-6" />
        </div>
      </CardContent>
    </Card>
  )
}

function Field({ label, children, className, compact }) {
  return (
    <div className={cn(compact ? 'space-y-1.5' : 'space-y-2', className)}>
      <Label className={cn(compact ? 'text-xs font-medium' : 'text-sm font-medium', 'text-foreground')}>{label}</Label>
      {children}
    </div>
  )
}

function ConfiguratorSection({ eyebrow, title, description, children, compact }) {
  return (
    <section
      className={cn(
        'rounded-lg border border-border/60 bg-card shadow-sm dark:border-border/50',
        compact ? 'space-y-2.5 p-3.5' : 'space-y-4 p-5',
      )}
    >
      <div className={cn(compact ? 'space-y-0.5' : 'space-y-1')}>
        <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-muted-foreground">{eyebrow}</p>
        <div className="flex items-center gap-2">
          <h3 className={cn(compact ? 'text-base font-semibold' : 'text-lg font-semibold', 'text-foreground')}>{title}</h3>
          <ChevronRight className="size-4 text-muted-foreground" />
        </div>
        <p className={cn(compact ? 'text-xs leading-snug' : 'text-sm leading-relaxed', 'text-muted-foreground')}>{description}</p>
      </div>
      {children}
    </section>
  )
}

function ModernSwitchCard({ title, description, checked, onCheckedChange, compact }) {
  return (
    <div
      className={cn(
        'flex h-full items-center justify-between rounded-lg border border-border/60 bg-background shadow-sm dark:border-border/50',
        compact ? 'px-3 py-2.5' : 'px-4 py-4',
      )}
    >
      <div className={cn(compact ? 'pr-2' : 'pr-4')}>
        <p className={cn(compact ? 'text-sm font-medium' : 'font-medium', 'text-foreground')}>{title}</p>
        <p className={cn(compact ? 'mt-0.5 text-[11px] leading-snug' : 'mt-1 text-xs leading-relaxed', 'text-muted-foreground')}>{description}</p>
      </div>
      <Switch checked={checked} onCheckedChange={onCheckedChange} />
    </div>
  )
}

function CalendarTimeline({ preview }) {
  const items = [
    { label: 'Cut-off opens', value: preview.cut_off_start_date, icon: CalendarRange },
    { label: 'Cut-off closes', value: preview.cut_off_end_date, icon: CheckCircle2 },
    { label: 'Payroll runs', value: preview.pay_date, icon: WalletCards },
  ]

  return (
    <div className="rounded-lg border border-border/60 bg-card p-4 shadow-sm dark:border-border/50">
      <div className="mb-4 flex items-center justify-between">
        <div>
          <p className="text-[11px] uppercase tracking-[0.18em] text-muted-foreground">Visual timeline</p>
          <p className="mt-1 text-sm font-medium text-foreground">Current to next payout flow</p>
        </div>
        <CalendarRange className="size-4 text-muted-foreground" />
      </div>
      <div className="flex items-center gap-2 overflow-x-auto pb-1">
        {items.map((item, index) => {
          const Icon = item.icon
          return (
            <div key={item.label} className="flex min-w-[112px] items-center gap-2">
              <div className="rounded-lg border border-border/60 bg-background px-3 py-3 dark:border-border/50">
                <div className="flex items-center gap-2 text-xs font-medium text-muted-foreground">
                  <Icon className="size-3.5 text-muted-foreground" />
                  <span>{item.label}</span>
                </div>
                <p className="mt-2 text-sm font-semibold text-foreground">{item.value || '—'}</p>
              </div>
              {index < items.length - 1 ? <ArrowRight className="size-4 shrink-0 text-muted-foreground" /> : null}
            </div>
          )
        })}
      </div>
    </div>
  )
}

function CycleSummaryPanel({ preview }) {
  const summaryItems = [
    { label: 'Cut-off example', value: `${preview.cut_off_start_date} to ${preview.cut_off_end_date}` },
    { label: 'Pay date example', value: preview.pay_date || '—' },
    { label: 'Cycle summary', value: preview.cycle_label || '—' },
  ]

  return (
    <div className="rounded-xl border border-border/60 bg-card p-6 shadow-sm dark:border-border/50">
      <div className="mb-4">
        <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-muted-foreground">Cycle summary</p>
        <p className="mt-1 text-sm text-muted-foreground">A quick example of how this configuration will read once assigned to employees.</p>
      </div>
      <div className="space-y-3">
        {summaryItems.map((item) => (
          <div key={item.label} className="flex items-start justify-between gap-4 rounded-lg border border-border/60 bg-background px-4 py-4 dark:border-border/50">
            <span className="text-sm text-muted-foreground">{item.label}</span>
            <span className="max-w-[62%] text-right text-sm font-medium leading-relaxed text-foreground">{item.value}</span>
          </div>
        ))}
      </div>
    </div>
  )
}

function ProrationExampleCard({ preview }) {
  const mode = String(preview.pro_ration_type || 'none')
  const example = getProrationExample(mode)

  return (
    <div className="rounded-xl border border-border/60 bg-card p-6 shadow-sm dark:border-border/50">
      <div className="mb-4">
        <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-muted-foreground">Pro-ration calculation preview</p>
        <p className="mt-1 text-sm text-muted-foreground">
          Example only, so payroll admins can understand how this cycle behaves before saving.
        </p>
      </div>
      <div className="rounded-lg border border-border/60 bg-background p-5 dark:border-border/50">
        <p className="text-base font-semibold text-foreground">{example.title}</p>
        <p className="mt-2 text-sm leading-relaxed text-muted-foreground">{example.formula}</p>
        <p className="mt-4 text-sm font-semibold text-foreground">{example.result}</p>
      </div>
    </div>
  )
}

function UpcomingPeriodsPanel({ preview, compact }) {
  const periods = Array.isArray(preview?.preview_periods) ? preview.preview_periods : []
  if (periods.length === 0) return null

  return (
    <div
      className={cn(
        'rounded-lg border border-border/60 bg-card shadow-sm dark:border-border/50',
        compact ? 'p-3' : 'p-6',
      )}
    >
      <div className={cn(compact ? 'mb-2' : 'mb-4')}>
        <p className="text-[10px] font-semibold uppercase tracking-[0.16em] text-muted-foreground">Upcoming pay periods</p>
        {!compact ? (
          <p className="mt-1 text-sm text-muted-foreground">Exact preview based on the current cycle configuration.</p>
        ) : (
          <p className="mt-0.5 text-[11px] leading-snug text-muted-foreground">From current settings.</p>
        )}
      </div>
      <div className={cn(compact ? 'space-y-1.5' : 'space-y-3')}>
        {periods.slice(0, 6).map((period, index) => (
          <div
            key={`${period.preview_line}-${index}`}
            className={cn(
              'rounded-md border border-border/60 bg-background dark:border-border/50',
              compact ? 'px-3 py-2' : 'px-4 py-3',
            )}
          >
            <p className={cn(compact ? 'text-xs font-medium' : 'text-sm font-medium', 'text-foreground')}>
              {period.preview_label || formatPreviewRange(period.cut_off_start_date, period.cut_off_end_date)}
              <span className="mx-1.5 text-muted-foreground">|</span>
              <span className="font-semibold">Pay: {formatPreviewDate(period.pay_date)}</span>
            </p>
            <p className="mt-0.5 text-[11px] text-muted-foreground">
              {period.period_days} day(s)
              {period.weekend_adjusted ? ' • Fri adjust' : ''}
            </p>
          </div>
        ))}
      </div>
    </div>
  )
}

function WeekendAdjustmentNotice({ note, compact }) {
  return (
    <div
      className={cn(
        'rounded-lg border border-amber-200/80 bg-amber-50/80 text-amber-900 dark:border-amber-500/20 dark:bg-amber-500/10 dark:text-amber-100',
        compact ? 'px-3 py-2 text-[11px] leading-snug' : 'px-4 py-3 text-sm',
      )}
    >
      {note}
    </div>
  )
}

function formatPreviewDate(value) {
  if (!value) return '—'
  const date = new Date(`${value}T12:00:00`)
  if (Number.isNaN(date.getTime())) return value
  return date.toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })
}

function formatPreviewRange(start, end) {
  return `${formatPreviewDate(start)} - ${formatPreviewDate(end)}`
}

function formatCompanyScope(cycle) {
  const names = Array.isArray(cycle?.company_names) ? cycle.company_names.filter(Boolean) : []
  if (names.length === 0) {
    return cycle?.company_name || 'Unassigned to companies'
  }
  if (names.length === 1) return names[0]
  return `${names.slice(0, 2).join(', ')}${names.length > 2 ? ` +${names.length - 2} more` : ''}`
}

function monthLabel(dateLike) {
  const date = startOfDay(dateLike)
  return date.toLocaleDateString('en-PH', { month: 'long', year: 'numeric' })
}

function shortDateLabel(dateLike) {
  const date = startOfDay(dateLike)
  return date.toLocaleDateString('en-PH', { month: 'short', day: 'numeric' })
}

function getProrationExample(mode) {
  if (mode === 'hourly') {
    return {
      title: 'Hourly example',
      formula: 'Worked 72 of 80 hours in cycle',
      result: 'Pay factor: 72 / 80 = 90% of cycle amount',
    }
  }

  if (mode === 'daily') {
    return {
      title: 'Daily example',
      formula: 'Worked 11 of 12 payable days in cycle',
      result: 'Pay factor: 11 / 12 = 91.7% of cycle amount',
    }
  }

  return {
    title: 'No proration',
    formula: 'Employees receive the configured cycle amount',
    result: 'Pay factor: 100% of cycle amount',
  }
}
