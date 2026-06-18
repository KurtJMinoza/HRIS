import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import {
  AlertCircle,
  Check,
  ChevronLeft,
  ChevronRight,
  Download,
  Eye,
  FileText,
  Grid2X2,
  List,
  Loader2,
  Mail,
  MailCheck,
  MailX,
  MoreVertical,
  Pencil,
  RefreshCcw,
  RotateCcw,
  Save,
  Search,
  Send,
  Settings,
  X,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Switch } from '@/components/ui/switch'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs'
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table'
import {
  Card,
  CardContent,
} from '@/components/ui/card'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from '@/components/ui/select'
import { useToast } from '@/components/ui/use-toast'
import { cn } from '@/lib/utils'
import {
  getEmailNotificationSettings,
  updateEmailNotificationSetting,
  getEmailTemplates,
  previewEmailTemplate,
  updateEmailTemplate,
  getEmailLogs,
  retryEmailLog,
  sendTestEmail,
  clearEmailNotificationCache,
} from '@/api'

const RECIPIENT_TYPE_LABELS = {
  employee: 'Employee',
  current_approver: 'Current Approver',
  hr_admin: 'HR Admin',
  payroll_admin: 'Payroll Admin',
  department_head: 'Department Head',
  custom: 'Custom Email',
}

const STATUS_BADGE_MAP = {
  queued: { label: 'Queued', className: 'border-blue-500/20 bg-blue-50 text-blue-700 dark:border-blue-400/25 dark:bg-blue-400/10 dark:text-blue-200' },
  sent: { label: 'Sent', className: 'border-emerald-500/20 bg-emerald-50 text-emerald-700 dark:border-emerald-400/25 dark:bg-emerald-400/10 dark:text-emerald-200' },
  delivered: { label: 'Delivered', className: 'border-emerald-500/20 bg-emerald-50 text-emerald-700 dark:border-emerald-400/25 dark:bg-emerald-400/10 dark:text-emerald-200' },
  failed: { label: 'Failed', className: 'border-rose-500/20 bg-rose-50 text-rose-700 dark:border-rose-400/25 dark:bg-rose-400/10 dark:text-rose-200' },
}

const NOTIFICATION_KEY_LABELS = {
  attendance_missing_reminder: 'Attendance Missing Reminder',
  attendance_clock_in: 'Clock In Confirmation',
  attendance_clock_out: 'Clock Out Confirmation',
  leave_needs_approval: 'Leave Needs Approval',
  leave_approved: 'Leave Approved',
  leave_rejected: 'Leave Rejected',
  overtime_needs_approval: 'Overtime Needs Approval',
  overtime_approved: 'Overtime Approved',
  overtime_rejected: 'Overtime Rejected',
  correction_needs_approval: 'Correction Needs Approval',
  correction_approved: 'Correction Approved',
  correction_rejected: 'Correction Rejected',
  payroll_finalized: 'Payroll Finalized',
  payslip_available: 'Payslip Available',
}

const NOTIFICATION_KEY_DESCRIPTIONS = {
  attendance_missing_reminder: 'Sent when an employee has incomplete attendance.',
  attendance_clock_in: 'Sent when a clock-in is recorded.',
  attendance_clock_out: 'Sent when a clock-out is recorded.',
  leave_needs_approval: 'Sent when a leave request needs approval.',
  leave_approved: 'Sent when a leave request is approved.',
  leave_rejected: 'Sent when a leave request is rejected.',
  overtime_needs_approval: 'Sent when overtime needs approval.',
  overtime_approved: 'Sent when overtime is approved.',
  overtime_rejected: 'Sent when overtime is rejected.',
  correction_needs_approval: 'Sent when an attendance correction needs approval.',
  correction_approved: 'Sent when a correction is approved.',
  correction_rejected: 'Sent when a correction is rejected.',
  payroll_finalized: 'Sent when payroll is finalized.',
  payslip_available: 'Sent when a payslip is available.',
}

const RECIPIENT_BADGE_CLASS = {
  employee: 'border-orange-500/20 bg-orange-50 text-orange-700 dark:border-orange-400/25 dark:bg-orange-400/10 dark:text-orange-200',
  current_approver: 'border-blue-500/20 bg-blue-50 text-blue-700 dark:border-blue-400/25 dark:bg-blue-400/10 dark:text-blue-200',
  hr_admin: 'border-zinc-500/20 bg-zinc-100 text-zinc-700 dark:border-zinc-400/25 dark:bg-zinc-400/10 dark:text-zinc-200',
  payroll_admin: 'border-violet-500/20 bg-violet-50 text-violet-700 dark:border-violet-400/25 dark:bg-violet-400/10 dark:text-violet-200',
  department_head: 'border-emerald-500/20 bg-emerald-50 text-emerald-700 dark:border-emerald-400/25 dark:bg-emerald-400/10 dark:text-emerald-200',
  custom: 'border-amber-500/20 bg-amber-50 text-amber-700 dark:border-amber-400/25 dark:bg-amber-400/10 dark:text-amber-200',
}

const TEMPLATE_VARIABLES = [
  '{{ employee_name }}',
  '{{ date }}',
  '{{ time }}',
  '{{ request_type }}',
  '{{ approver_name }}',
  '{{ status }}',
  '{{ action_url }}',
  '{{ company_name }}',
  '{{ branch_name }}',
  '{{ logo_url }}',
  '{{ leave_type }}',
  '{{ start_date }}',
  '{{ end_date }}',
  '{{ hours }}',
  '{{ scheduled_time }}',
  '{{ pay_period }}',
]

function keyLabel(key) {
  return NOTIFICATION_KEY_LABELS[key] || key.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
}

function notificationDescription(key) {
  return NOTIFICATION_KEY_DESCRIPTIONS[key] || 'Sent when this notification event is triggered.'
}

function formatLogDate(value) {
  if (!value) return '—'
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return value
  return new Intl.DateTimeFormat(undefined, {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date)
}

function recipientEmail(log) {
  return log.recipient_email ?? log.recipient ?? log.email ?? '—'
}

function recipientName(log) {
  if (log.recipient_name || log.name) return log.recipient_name || log.name
  const email = recipientEmail(log)
  if (!email || email === '—') return '—'
  return String(email)
    .split('@')[0]
    .replace(/[._-]+/g, ' ')
    .replace(/\b\w/g, (char) => char.toUpperCase())
}

function escapeCsvValue(value) {
  const text = String(value ?? '')
  return /[",\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text
}

/* ────────────────────────────── Settings Tab ────────────────────────────── */

function SettingsTab() {
  const { toast } = useToast()
  const [settings, setSettings] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [editingId, setEditingId] = useState(null)
  const [editDraft, setEditDraft] = useState({})
  const [saving, setSaving] = useState(false)
  const [clearingCache, setClearingCache] = useState(false)
  const [testDialogOpen, setTestDialogOpen] = useState(false)
  const [testEmail, setTestEmail] = useState('')
  const [testNotificationKey, setTestNotificationKey] = useState('')
  const [sendingTest, setSendingTest] = useState(false)
  const [settingsPage, setSettingsPage] = useState(1)
  const [settingsPerPage, setSettingsPerPage] = useState(10)
  const abortRef = useRef(null)

  const fetchSettings = useCallback(() => {
    abortRef.current?.abort()
    const ac = new AbortController()
    abortRef.current = ac
    setLoading(true)
    setError(null)
    getEmailNotificationSettings({ signal: ac.signal })
      .then((data) => {
        setSettings(data.settings ?? data.data ?? [])
        setSettingsPage(1)
        setLoading(false)
      })
      .catch((err) => {
        if (err.name === 'AbortError') return
        setError(err.message)
        setLoading(false)
      })
  }, [])

  useEffect(() => {
    fetchSettings()
    return () => abortRef.current?.abort()
  }, [fetchSettings])

  const startEdit = useCallback((setting) => {
    setEditingId(setting.id)
    setEditDraft({
      enabled: setting.enabled,
      recipient_type: setting.recipient_type,
      queue_name: setting.queue_name ?? 'emails',
      retry_attempts: setting.retry_attempts ?? 3,
      custom_recipient_email: setting.custom_recipient_email ?? '',
    })
  }, [])

  const cancelEdit = useCallback(() => {
    setEditingId(null)
    setEditDraft({})
  }, [])

  const handleSave = useCallback(async () => {
    setSaving(true)
    try {
      await updateEmailNotificationSetting(editingId, editDraft)
      setEditingId(null)
      setEditDraft({})
      toast({ title: 'Setting updated', description: 'Notification setting saved successfully.' })
      fetchSettings()
    } catch (err) {
      toast({ variant: 'destructive', title: 'Error', description: err.message })
    } finally {
      setSaving(false)
    }
  }, [editingId, editDraft, toast, fetchSettings])

  const handleToggle = useCallback(async (setting) => {
    const prev = settings
    const newVal = !setting.enabled
    setSettings((s) => s.map((r) => (r.id === setting.id ? { ...r, enabled: newVal } : r)))
    try {
      await updateEmailNotificationSetting(setting.id, { enabled: newVal })
      toast({ title: newVal ? 'Enabled' : 'Disabled', description: `${keyLabel(setting.notification_key)} ${newVal ? 'enabled' : 'disabled'}.` })
    } catch (err) {
      setSettings(prev)
      toast({ variant: 'destructive', title: 'Error', description: err.message })
    }
  }, [settings, toast])

  const handleClearCache = useCallback(async () => {
    setClearingCache(true)
    try {
      await clearEmailNotificationCache()
      toast({ title: 'Cache cleared', description: 'Email notification cache has been cleared.' })
    } catch (err) {
      toast({ variant: 'destructive', title: 'Error', description: err.message })
    } finally {
      setClearingCache(false)
    }
  }, [toast])

  const handleSendTest = useCallback(async () => {
    if (!testEmail || !testNotificationKey) return
    setSendingTest(true)
    try {
      await sendTestEmail({ email: testEmail, notification_key: testNotificationKey })
      toast({ title: 'Test email sent', description: `Test email sent to ${testEmail}.` })
      setTestDialogOpen(false)
      setTestEmail('')
      setTestNotificationKey('')
    } catch (err) {
      toast({ variant: 'destructive', title: 'Error', description: err.message })
    } finally {
      setSendingTest(false)
    }
  }, [testEmail, testNotificationKey, toast])

  const notificationKeys = useMemo(
    () => settings.map((s) => s.notification_key).filter(Boolean),
    [settings],
  )

  const settingsTotalPages = Math.max(1, Math.ceil(settings.length / settingsPerPage))
  const paginatedSettings = useMemo(() => {
    const start = (settingsPage - 1) * settingsPerPage
    return settings.slice(start, start + settingsPerPage)
  }, [settings, settingsPage, settingsPerPage])
  const settingsStart = settings.length === 0 ? 0 : (settingsPage - 1) * settingsPerPage + 1
  const settingsEnd = Math.min(settings.length, settingsPage * settingsPerPage)

  useEffect(() => {
    if (settingsPage > settingsTotalPages) {
      setSettingsPage(settingsTotalPages)
    }
  }, [settingsPage, settingsTotalPages])

  if (loading) {
    return (
      <div className="flex items-center justify-center py-20">
        <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  if (error) {
    return (
      <div className="flex flex-col items-center justify-center gap-3 py-20 text-muted-foreground">
        <AlertCircle className="h-8 w-8 text-destructive" />
        <p className="text-sm">{error}</p>
        <Button variant="outline" size="sm" onClick={fetchSettings}>
          <RotateCcw className="mr-2 h-4 w-4" /> Retry
        </Button>
      </div>
    )
  }

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center justify-end gap-2">
        <Button
          variant="outline"
          size="sm"
          className="h-10 rounded-lg border-border/70 bg-card px-4 text-xs font-bold text-foreground shadow-sm hover:border-brand/45 hover:bg-brand/5 hover:text-brand dark:bg-card dark:hover:bg-brand/10"
          onClick={() => setTestDialogOpen(true)}
        >
          <Send className="mr-2 h-4 w-4" /> Send Test Email
        </Button>
        <Button
          variant="outline"
          size="sm"
          className="h-10 rounded-lg border-brand/25 bg-brand/5 px-4 text-xs font-bold text-brand shadow-sm hover:border-brand/50 hover:bg-brand/10 hover:text-brand dark:border-brand/35 dark:bg-brand/10"
          onClick={handleClearCache}
          disabled={clearingCache}
        >
          {clearingCache ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <RefreshCcw className="mr-2 h-4 w-4" />}
          Clear Cache
        </Button>
      </div>

      <Card className="overflow-hidden rounded-2xl border border-border/70 bg-card py-0 shadow-[0_1px_0_rgba(15,23,42,0.03),0_16px_36px_rgba(15,23,42,0.06)] dark:border-border dark:bg-card dark:shadow-[0_18px_44px_rgba(0,0,0,0.32)]">
        <CardContent className="p-0">
          <Table>
            <TableHeader className="bg-card dark:bg-card">
              <TableRow className="border-b border-border/60 hover:bg-transparent">
                <TableHead className="h-12 min-w-[270px] bg-card pl-8 text-xs font-extrabold uppercase tracking-wide text-foreground dark:bg-card">Notification Type</TableHead>
                <TableHead className="h-12 w-[110px] bg-card text-center text-xs font-extrabold uppercase tracking-wide text-foreground dark:bg-card">Enabled</TableHead>
                <TableHead className="h-12 w-[170px] bg-card text-xs font-extrabold uppercase tracking-wide text-foreground dark:bg-card">Recipient Type</TableHead>
                <TableHead className="h-12 w-[120px] bg-card text-xs font-extrabold uppercase tracking-wide text-foreground dark:bg-card">Queue</TableHead>
                <TableHead className="h-12 w-[130px] bg-card text-center text-xs font-extrabold uppercase tracking-wide text-foreground dark:bg-card">Retry Attempts</TableHead>
                <TableHead className="h-12 w-[220px] bg-card text-xs font-extrabold uppercase tracking-wide text-foreground dark:bg-card">Custom Email</TableHead>
                <TableHead className="h-12 w-[100px] bg-card pr-8 text-right text-xs font-extrabold uppercase tracking-wide text-foreground dark:bg-card">Actions</TableHead>
              </TableRow>
            </TableHeader>
            <TableBody>
              {settings.length === 0 && (
                <TableRow>
                  <TableCell colSpan={7} className="h-24 text-center text-muted-foreground">
                    No notification settings found.
                  </TableCell>
                </TableRow>
              )}
              {paginatedSettings.map((s) => {
                const isEditing = editingId === s.id
                return (
                  <TableRow
                    key={s.id}
                    className="border-b border-border/50 bg-card transition-colors hover:bg-muted/25 dark:border-border/60 dark:bg-card dark:hover:bg-muted/20"
                  >
                    <TableCell className="py-3.5 pl-8">
                      <div className="flex items-center gap-3">
                        <span className="flex size-9 shrink-0 items-center justify-center rounded-xl border border-brand/15 bg-brand/8 text-brand dark:border-brand/25 dark:bg-brand/12">
                          <Mail className="size-4" />
                        </span>
                        <div className="min-w-0">
                          <p className="truncate text-sm font-extrabold leading-tight text-foreground">{keyLabel(s.notification_key)}</p>
                          <p className="mt-1 truncate text-[11px] leading-tight text-muted-foreground">{notificationDescription(s.notification_key)}</p>
                        </div>
                      </div>
                    </TableCell>
                    <TableCell className="py-3.5 text-center">
                      {isEditing ? (
                        <Switch
                          className="data-[state=checked]:bg-brand"
                          checked={editDraft.enabled}
                          onCheckedChange={(v) => setEditDraft((d) => ({ ...d, enabled: v }))}
                        />
                      ) : (
                        <Switch className="data-[state=checked]:bg-brand" checked={s.enabled} onCheckedChange={() => handleToggle(s)} />
                      )}
                    </TableCell>
                    <TableCell className="py-3.5">
                      {isEditing ? (
                        <Select value={editDraft.recipient_type} onValueChange={(v) => setEditDraft((d) => ({ ...d, recipient_type: v }))}>
                          <SelectTrigger className="h-9 rounded-lg border-border/70 bg-background text-xs shadow-inner dark:bg-background/70">
                            <SelectValue />
                          </SelectTrigger>
                          <SelectContent>
                            {Object.entries(RECIPIENT_TYPE_LABELS).map(([k, v]) => (
                              <SelectItem key={k} value={k}>{v}</SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      ) : (
                        <Badge
                          variant="outline"
                          className={cn(
                            'rounded-md px-2.5 py-1 text-[11px] font-bold',
                            RECIPIENT_BADGE_CLASS[s.recipient_type] ?? RECIPIENT_BADGE_CLASS.hr_admin,
                          )}
                        >
                          {RECIPIENT_TYPE_LABELS[s.recipient_type] ?? s.recipient_type}
                        </Badge>
                      )}
                    </TableCell>
                    <TableCell className="py-3.5">
                      {isEditing ? (
                        <Input
                          className="h-9 rounded-lg border-border/70 bg-background text-xs shadow-inner dark:bg-background/70"
                          value={editDraft.queue_name}
                          onChange={(e) => setEditDraft((d) => ({ ...d, queue_name: e.target.value }))}
                        />
                      ) : (
                        <code className="rounded-md bg-muted/60 px-2.5 py-1 text-[11px] font-semibold text-muted-foreground dark:bg-muted/30">{s.queue_name ?? 'emails'}</code>
                      )}
                    </TableCell>
                    <TableCell className="py-3.5 text-center">
                      {isEditing ? (
                        <Input
                          type="number"
                          min={0}
                          max={10}
                          className="mx-auto h-9 w-20 rounded-lg border-border/70 bg-background text-center text-xs shadow-inner dark:bg-background/70"
                          value={editDraft.retry_attempts}
                          onChange={(e) => setEditDraft((d) => ({ ...d, retry_attempts: parseInt(e.target.value, 10) || 0 }))}
                        />
                      ) : (
                        <span className="text-sm font-semibold text-foreground">{s.retry_attempts ?? 3}</span>
                      )}
                    </TableCell>
                    <TableCell className="py-3.5">
                      {isEditing && editDraft.recipient_type === 'custom' ? (
                        <Input
                          type="email"
                          placeholder="email@example.com"
                          className="h-9 rounded-lg border-border/70 bg-background text-xs shadow-inner dark:bg-background/70"
                          value={editDraft.custom_recipient_email}
                          onChange={(e) => setEditDraft((d) => ({ ...d, custom_recipient_email: e.target.value }))}
                        />
                      ) : (
                        <span
                          className={cn(
                            'text-xs font-semibold',
                            s.recipient_type === 'custom'
                              ? 'text-brand dark:text-brand'
                              : 'text-muted-foreground',
                          )}
                        >
                          {s.recipient_type === 'custom' ? (s.custom_recipient_email || 'Custom Email') : 'Default Template'}
                        </span>
                      )}
                    </TableCell>
                    <TableCell className="py-3.5 pr-8 text-right">
                      {isEditing ? (
                        <div className="flex items-center justify-end gap-1">
                          <Button variant="outline" size="icon" className="size-9 rounded-lg border-border/70 bg-card" onClick={cancelEdit}>
                            <X className="h-4 w-4" />
                          </Button>
                          <Button variant="outline" size="icon" className="size-9 rounded-lg border-brand/40 bg-brand/5 text-brand hover:bg-brand/10 hover:text-brand" onClick={handleSave} disabled={saving}>
                            {saving ? <Loader2 className="h-4 w-4 animate-spin" /> : <Check className="h-4 w-4" />}
                          </Button>
                        </div>
                      ) : (
                        <Button variant="outline" size="icon" className="size-9 rounded-lg border-border/70 bg-card text-foreground shadow-sm hover:border-brand/45 hover:bg-brand/5 hover:text-brand dark:bg-card dark:hover:bg-brand/10" onClick={() => startEdit(s)}>
                          <Pencil className="h-4 w-4" />
                        </Button>
                      )}
                    </TableCell>
                  </TableRow>
                )
              })}
            </TableBody>
          </Table>
          <div className="flex flex-col gap-3 border-t border-border/60 bg-card px-5 py-4 text-xs text-muted-foreground dark:bg-card sm:flex-row sm:items-center sm:justify-between">
            <span>
              Showing {settingsStart} to {settingsEnd} of {settings.length} entries
            </span>
            <div className="flex flex-wrap items-center gap-3 sm:justify-end">
              <div className="flex items-center gap-2">
                <span className="font-medium text-foreground">Rows per page:</span>
                <Select
                  value={String(settingsPerPage)}
                  onValueChange={(value) => {
                    setSettingsPerPage(Number(value))
                    setSettingsPage(1)
                  }}
                >
                  <SelectTrigger className="h-9 w-[74px] rounded-lg border-border/70 bg-background text-xs shadow-inner dark:bg-background/70">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {[10, 15, 20].map((value) => (
                      <SelectItem key={value} value={String(value)}>{value}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="flex items-center gap-1.5">
                <Button variant="outline" size="icon" className="size-9 rounded-lg border-border/70 bg-card" disabled={settingsPage <= 1} onClick={() => setSettingsPage((page) => Math.max(1, page - 1))}>
                  <ChevronLeft className="h-4 w-4" />
                </Button>
                {Array.from({ length: settingsTotalPages }, (_, index) => index + 1).map((page) => (
                  <Button
                    key={page}
                    variant={settingsPage === page ? 'default' : 'outline'}
                    size="icon"
                    className={cn(
                      'size-9 rounded-lg text-xs font-bold',
                      settingsPage === page
                        ? 'bg-brand text-brand-foreground hover:bg-brand-strong'
                        : 'border-border/70 bg-card text-foreground hover:border-brand/45 hover:bg-brand/5 hover:text-brand',
                    )}
                    onClick={() => setSettingsPage(page)}
                  >
                    {page}
                  </Button>
                ))}
                <Button variant="outline" size="icon" className="size-9 rounded-lg border-border/70 bg-card" disabled={settingsPage >= settingsTotalPages} onClick={() => setSettingsPage((page) => Math.min(settingsTotalPages, page + 1))}>
                  <ChevronRight className="h-4 w-4" />
                </Button>
              </div>
            </div>
          </div>
        </CardContent>
      </Card>

      {/* Test Email Dialog */}
      <Dialog open={testDialogOpen} onOpenChange={setTestDialogOpen}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Send Test Email</DialogTitle>
            <DialogDescription>Choose a notification type and provide a recipient email address.</DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-2">
            <div className="space-y-2">
              <Label>Notification Type</Label>
              <Select value={testNotificationKey} onValueChange={setTestNotificationKey}>
                <SelectTrigger>
                  <SelectValue placeholder="Select notification type" />
                </SelectTrigger>
                <SelectContent>
                  {notificationKeys.map((k) => (
                    <SelectItem key={k} value={k}>{keyLabel(k)}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label>Recipient Email</Label>
              <Input
                type="email"
                placeholder="test@example.com"
                value={testEmail}
                onChange={(e) => setTestEmail(e.target.value)}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setTestDialogOpen(false)}>Cancel</Button>
            <Button onClick={handleSendTest} disabled={sendingTest || !testEmail || !testNotificationKey}>
              {sendingTest ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Send className="mr-2 h-4 w-4" />}
              Send
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

/* ────────────────────────────── Templates Tab ───────────────────────────── */

function TemplatesTab() {
  const { toast } = useToast()
  const [templates, setTemplates] = useState([])
  const [settings, setSettings] = useState([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [editingTemplate, setEditingTemplate] = useState(null)
  const [previewTemplate, setPreviewTemplate] = useState(null)
  const [previewHtml, setPreviewHtml] = useState('')
  const [previewSubject, setPreviewSubject] = useState('')
  const [previewLoading, setPreviewLoading] = useState(false)
  const [draft, setDraft] = useState({ subject: '', body_html: '', body_text: '' })
  const [saving, setSaving] = useState(false)
  const [togglingKey, setTogglingKey] = useState(null)
  const [clearingCache, setClearingCache] = useState(false)
  const [testDialogOpen, setTestDialogOpen] = useState(false)
  const [testEmail, setTestEmail] = useState('')
  const [testNotificationKey, setTestNotificationKey] = useState('')
  const [sendingTest, setSendingTest] = useState(false)
  const [searchQuery, setSearchQuery] = useState('')
  const [recipientFilter, setRecipientFilter] = useState('all')
  const [eventFilter, setEventFilter] = useState('all')
  const [viewMode, setViewMode] = useState('grid')
  const [templatesPage, setTemplatesPage] = useState(1)
  const templatesPerPage = 16
  const abortRef = useRef(null)

  const fetchTemplates = useCallback(() => {
    abortRef.current?.abort()
    const ac = new AbortController()
    abortRef.current = ac
    setLoading(true)
    setError(null)
    Promise.all([
      getEmailTemplates({ signal: ac.signal }),
      getEmailNotificationSettings({ signal: ac.signal }),
    ])
      .then(([templatesData, settingsData]) => {
        setTemplates(templatesData.templates ?? templatesData.data ?? [])
        setSettings(settingsData.settings ?? settingsData.data ?? [])
        setTemplatesPage(1)
        setLoading(false)
      })
      .catch((err) => {
        if (err.name === 'AbortError') return
        setError(err.message)
        setLoading(false)
      })
  }, [])

  useEffect(() => {
    fetchTemplates()
    return () => abortRef.current?.abort()
  }, [fetchTemplates])

  const openEdit = useCallback((tpl) => {
    setEditingTemplate(tpl)
    setDraft({
      subject: tpl.subject ?? '',
      body_html: tpl.body_html ?? '',
      body_text: tpl.body_text ?? '',
    })
  }, [])

  const openPreview = useCallback((tpl) => {
    setPreviewTemplate(tpl)
    setPreviewHtml('')
    setPreviewSubject(tpl.subject ?? '')
    setPreviewLoading(true)
    previewEmailTemplate(tpl.id)
      .then((data) => {
        setPreviewSubject(data.subject ?? tpl.subject ?? '')
        setPreviewHtml(data.body_html ?? '')
      })
      .catch((err) => {
        toast({ variant: 'destructive', title: 'Preview failed', description: err.message })
        setPreviewTemplate(null)
      })
      .finally(() => setPreviewLoading(false))
  }, [toast])

  const handleSave = useCallback(async () => {
    if (!editingTemplate) return
    setSaving(true)
    try {
      await updateEmailTemplate(editingTemplate.id, draft)
      setEditingTemplate(null)
      toast({ title: 'Template updated', description: 'Email template saved successfully.' })
      fetchTemplates()
    } catch (err) {
      toast({ variant: 'destructive', title: 'Error', description: err.message })
    } finally {
      setSaving(false)
    }
  }, [editingTemplate, draft, toast, fetchTemplates])

  const insertVariable = useCallback((variable) => {
    setDraft((d) => ({ ...d, body_html: d.body_html + variable }))
  }, [])

  const settingsByKey = useMemo(() => {
    return new Map(settings.map((setting) => [setting.notification_key, setting]))
  }, [settings])

  const notificationKeys = useMemo(
    () => templates.map((tpl) => tpl.template_key).filter(Boolean),
    [templates],
  )

  const recipientOptions = useMemo(() => {
    const recipients = new Set()
    settings.forEach((setting) => {
      if (setting.recipient_type) recipients.add(setting.recipient_type)
    })
    return Array.from(recipients)
  }, [settings])

  const filteredTemplates = useMemo(() => {
    const query = searchQuery.trim().toLowerCase()
    return templates.filter((tpl) => {
      const setting = settingsByKey.get(tpl.template_key)
      const recipient = setting?.recipient_type || ''
      const matchesSearch = !query || [
        keyLabel(tpl.template_key),
        notificationDescription(tpl.template_key),
        tpl.subject,
        tpl.body_text,
      ].filter(Boolean).some((value) => String(value).toLowerCase().includes(query))
      const matchesRecipient = recipientFilter === 'all' || recipient === recipientFilter
      const matchesEvent = eventFilter === 'all' || tpl.template_key === eventFilter
      return matchesSearch && matchesRecipient && matchesEvent
    })
  }, [templates, settingsByKey, searchQuery, recipientFilter, eventFilter])

  const templatesTotalPages = Math.max(1, Math.ceil(filteredTemplates.length / templatesPerPage))
  const paginatedTemplates = useMemo(() => {
    const start = (templatesPage - 1) * templatesPerPage
    return filteredTemplates.slice(start, start + templatesPerPage)
  }, [filteredTemplates, templatesPage])
  const templatesStart = filteredTemplates.length === 0 ? 0 : (templatesPage - 1) * templatesPerPage + 1
  const templatesEnd = Math.min(filteredTemplates.length, templatesPage * templatesPerPage)

  useEffect(() => {
    setTemplatesPage(1)
  }, [searchQuery, recipientFilter, eventFilter])

  useEffect(() => {
    if (templatesPage > templatesTotalPages) {
      setTemplatesPage(templatesTotalPages)
    }
  }, [templatesPage, templatesTotalPages])

  const handleTemplateToggle = useCallback(async (tpl) => {
    const setting = settingsByKey.get(tpl.template_key)
    if (!setting) return
    const nextEnabled = !setting.enabled
    const previousSettings = settings
    setTogglingKey(tpl.template_key)
    setSettings((current) => current.map((item) => (
      item.id === setting.id ? { ...item, enabled: nextEnabled } : item
    )))
    try {
      await updateEmailNotificationSetting(setting.id, { enabled: nextEnabled })
      toast({
        title: nextEnabled ? 'Template enabled' : 'Template disabled',
        description: `${keyLabel(tpl.template_key)} ${nextEnabled ? 'enabled' : 'disabled'}.`,
      })
    } catch (err) {
      setSettings(previousSettings)
      toast({ variant: 'destructive', title: 'Error', description: err.message })
    } finally {
      setTogglingKey(null)
    }
  }, [settings, settingsByKey, toast])

  const handleClearCache = useCallback(async () => {
    setClearingCache(true)
    try {
      await clearEmailNotificationCache()
      toast({ title: 'Cache cleared', description: 'Email notification cache has been cleared.' })
    } catch (err) {
      toast({ variant: 'destructive', title: 'Error', description: err.message })
    } finally {
      setClearingCache(false)
    }
  }, [toast])

  const handleSendTest = useCallback(async () => {
    if (!testEmail || !testNotificationKey) return
    setSendingTest(true)
    try {
      await sendTestEmail({ email: testEmail, notification_key: testNotificationKey })
      toast({ title: 'Test email sent', description: `Test email sent to ${testEmail}.` })
      setTestDialogOpen(false)
      setTestEmail('')
      setTestNotificationKey('')
    } catch (err) {
      toast({ variant: 'destructive', title: 'Error', description: err.message })
    } finally {
      setSendingTest(false)
    }
  }, [testEmail, testNotificationKey, toast])

  if (loading) {
    return (
      <div className="flex items-center justify-center py-20">
        <Loader2 className="h-6 w-6 animate-spin text-muted-foreground" />
      </div>
    )
  }

  if (error) {
    return (
      <div className="flex flex-col items-center justify-center gap-3 py-20 text-muted-foreground">
        <AlertCircle className="h-8 w-8 text-destructive" />
        <p className="text-sm">{error}</p>
        <Button variant="outline" size="sm" onClick={fetchTemplates}>
          <RotateCcw className="mr-2 h-4 w-4" /> Retry
        </Button>
      </div>
    )
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
        <div className="relative w-full xl:max-w-[24rem]">
          <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
          <Input
            className="h-10 rounded-lg border-border/70 bg-card pl-9 text-xs shadow-inner placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-brand/25 dark:bg-card"
            placeholder="Search templates..."
            value={searchQuery}
            onChange={(e) => setSearchQuery(e.target.value)}
          />
        </div>

        <div className="flex flex-wrap items-center gap-2 xl:justify-end">
          <Select value={recipientFilter} onValueChange={setRecipientFilter}>
            <SelectTrigger className="h-10 w-[180px] rounded-lg border-border/70 bg-card text-xs font-semibold shadow-sm dark:bg-card">
              <SelectValue placeholder="All Recipients" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Recipients</SelectItem>
              {recipientOptions.map((recipient) => (
                <SelectItem key={recipient} value={recipient}>{RECIPIENT_TYPE_LABELS[recipient] ?? recipient}</SelectItem>
              ))}
            </SelectContent>
          </Select>
          <Select value={eventFilter} onValueChange={setEventFilter}>
            <SelectTrigger className="h-10 w-[180px] rounded-lg border-border/70 bg-card text-xs font-semibold shadow-sm dark:bg-card">
              <SelectValue placeholder="All Events" />
            </SelectTrigger>
            <SelectContent>
              <SelectItem value="all">All Events</SelectItem>
              {notificationKeys.map((key) => (
                <SelectItem key={key} value={key}>{keyLabel(key)}</SelectItem>
              ))}
            </SelectContent>
          </Select>
          <div className="flex h-10 items-center rounded-lg border border-border/70 bg-card p-1 shadow-sm dark:bg-card">
            <Button
              type="button"
              variant="ghost"
              size="icon"
              className={cn(
                'size-8 rounded-md text-muted-foreground hover:bg-brand/8 hover:text-brand',
                viewMode === 'grid' && 'bg-brand/10 text-brand',
              )}
              onClick={() => setViewMode('grid')}
              title="Grid view"
            >
              <Grid2X2 className="size-4" />
            </Button>
            <Button
              type="button"
              variant="ghost"
              size="icon"
              className={cn(
                'size-8 rounded-md text-muted-foreground hover:bg-brand/8 hover:text-brand',
                viewMode === 'list' && 'bg-brand/10 text-brand',
              )}
              onClick={() => setViewMode('list')}
              title="List view"
            >
              <List className="size-4" />
            </Button>
          </div>
          <Button
            variant="outline"
            size="sm"
            className="h-10 rounded-lg border-border/70 bg-card px-4 text-xs font-bold text-foreground shadow-sm hover:border-brand/45 hover:bg-brand/5 hover:text-brand dark:bg-card dark:hover:bg-brand/10"
            onClick={() => setTestDialogOpen(true)}
          >
            <Send className="mr-2 h-4 w-4" /> Send Test Email
          </Button>
          <Button
            variant="outline"
            size="sm"
            className="h-10 rounded-lg border-brand/25 bg-brand/5 px-4 text-xs font-bold text-brand shadow-sm hover:border-brand/50 hover:bg-brand/10 hover:text-brand dark:border-brand/35 dark:bg-brand/10"
            onClick={handleClearCache}
            disabled={clearingCache}
          >
            {clearingCache ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <RefreshCcw className="mr-2 h-4 w-4" />}
            Clear Cache
          </Button>
        </div>
      </div>

      {filteredTemplates.length === 0 ? (
        <Card className="rounded-2xl border-border/70 bg-card py-0 dark:bg-card">
          <CardContent className="flex flex-col items-center justify-center gap-2 py-20 text-muted-foreground">
            <Mail className="h-8 w-8 text-brand" />
            <p className="text-sm">No email templates found.</p>
          </CardContent>
        </Card>
      ) : (
        <div
          className={cn(
            viewMode === 'grid'
              ? 'grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4'
              : 'grid gap-3',
          )}
        >
          {paginatedTemplates.map((tpl) => {
            const setting = settingsByKey.get(tpl.template_key)
            const recipient = setting?.recipient_type || 'employee'
            const enabled = setting?.enabled ?? true
            return (
              <Card
                key={tpl.id}
                className={cn(
                  'group overflow-hidden rounded-2xl border border-border/70 bg-card py-0 shadow-[0_1px_0_rgba(15,23,42,0.03),0_12px_30px_rgba(15,23,42,0.05)] transition-all hover:-translate-y-0.5 hover:border-brand/35 hover:shadow-[0_18px_38px_rgba(15,23,42,0.08)] dark:border-border dark:bg-card dark:shadow-[0_18px_44px_rgba(0,0,0,0.24)] dark:hover:border-brand/35',
                  viewMode === 'list' && 'rounded-xl',
                )}
              >
                <CardContent
                  className={cn(
                    'p-4',
                    viewMode === 'list' && 'flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between',
                  )}
                >
                  <div className={cn('flex min-w-0 items-start gap-3', viewMode === 'grid' && 'min-h-[116px]')}>
                    <span className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-xl border border-brand/15 bg-brand/8 text-brand dark:border-brand/25 dark:bg-brand/12">
                      <Mail className="size-4" />
                    </span>
                    <div className="min-w-0 flex-1">
                      <h3 className="truncate text-sm font-extrabold leading-tight text-foreground">{keyLabel(tpl.template_key)}</h3>
                      <p className="mt-2 line-clamp-3 text-xs leading-relaxed text-muted-foreground">
                        {notificationDescription(tpl.template_key)}
                      </p>
                      <Badge
                        variant="outline"
                        className={cn(
                          'mt-3 rounded-md px-2.5 py-1 text-[11px] font-bold',
                          RECIPIENT_BADGE_CLASS[recipient] ?? RECIPIENT_BADGE_CLASS.employee,
                        )}
                      >
                        {RECIPIENT_TYPE_LABELS[recipient] ?? recipient}
                      </Badge>
                    </div>
                  </div>

                  <div className={cn('flex items-center justify-between gap-3', viewMode === 'list' && 'shrink-0')}>
                    <Switch
                      className="data-[state=checked]:bg-brand"
                      checked={enabled}
                      disabled={!setting || togglingKey === tpl.template_key}
                      onCheckedChange={() => handleTemplateToggle(tpl)}
                    />
                    <div className="flex items-center gap-1">
                      <Button
                        variant="ghost"
                        size="icon"
                        className="size-8 rounded-lg text-foreground hover:bg-brand/8 hover:text-brand"
                        onClick={() => openEdit(tpl)}
                        title="Edit template"
                      >
                        <Pencil className="size-4" />
                      </Button>
                      <Button
                        variant="ghost"
                        size="icon"
                        className="size-8 rounded-lg text-foreground hover:bg-brand/8 hover:text-brand"
                        onClick={() => openPreview(tpl)}
                        title="Preview template"
                      >
                        <Eye className="size-4" />
                      </Button>
                    </div>
                  </div>
                </CardContent>
              </Card>
            )
          })}
        </div>
      )}

      <div className="flex flex-col gap-3 px-1 text-xs text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
        <span>
          Showing {templatesStart} to {templatesEnd} of {filteredTemplates.length} templates
        </span>
        <div className="flex items-center gap-1.5 sm:justify-end">
          <Button variant="outline" size="icon" className="size-9 rounded-lg border-border/70 bg-card" disabled={templatesPage <= 1} onClick={() => setTemplatesPage((page) => Math.max(1, page - 1))}>
            <ChevronLeft className="h-4 w-4" />
          </Button>
          {Array.from({ length: templatesTotalPages }, (_, index) => index + 1).map((page) => (
            <Button
              key={page}
              variant={templatesPage === page ? 'default' : 'outline'}
              size="icon"
              className={cn(
                'size-9 rounded-lg text-xs font-bold',
                templatesPage === page
                  ? 'bg-brand text-brand-foreground hover:bg-brand-strong'
                  : 'border-border/70 bg-card text-foreground hover:border-brand/45 hover:bg-brand/5 hover:text-brand',
              )}
              onClick={() => setTemplatesPage(page)}
            >
              {page}
            </Button>
          ))}
          <Button variant="outline" size="icon" className="size-9 rounded-lg border-border/70 bg-card" disabled={templatesPage >= templatesTotalPages} onClick={() => setTemplatesPage((page) => Math.min(templatesTotalPages, page + 1))}>
            <ChevronRight className="h-4 w-4" />
          </Button>
        </div>
      </div>

      {/* Edit Template Dialog */}
      <Dialog open={!!editingTemplate} onOpenChange={(open) => !open && setEditingTemplate(null)}>
        <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
          <DialogHeader>
            <DialogTitle>Edit Template — {editingTemplate ? keyLabel(editingTemplate.template_key) : ''}</DialogTitle>
            <DialogDescription>Modify the email template subject and body. Use template variables below.</DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-2">
            <div className="space-y-2">
              <Label>Subject</Label>
              <Input
                value={draft.subject}
                onChange={(e) => setDraft((d) => ({ ...d, subject: e.target.value }))}
                placeholder="Email subject line"
              />
            </div>
            <div className="space-y-2">
              <Label>Body HTML</Label>
              <Textarea
                className="min-h-[200px] font-mono text-xs"
                value={draft.body_html}
                onChange={(e) => setDraft((d) => ({ ...d, body_html: e.target.value }))}
                placeholder="<html>...</html>"
              />
            </div>
            <div className="space-y-2">
              <Label>Body Text <span className="text-muted-foreground">(optional plain-text fallback)</span></Label>
              <Textarea
                className="min-h-[100px] font-mono text-xs"
                value={draft.body_text}
                onChange={(e) => setDraft((d) => ({ ...d, body_text: e.target.value }))}
                placeholder="Plain text version..."
              />
            </div>
            <div className="space-y-2">
              <Label>Available Variables</Label>
              <div className="flex flex-wrap gap-1.5">
                {TEMPLATE_VARIABLES.map((v) => (
                  <Badge
                    key={v}
                    variant="secondary"
                    className="cursor-pointer font-mono text-xs transition-colors hover:bg-primary hover:text-primary-foreground"
                    onClick={() => insertVariable(v)}
                  >
                    {v}
                  </Badge>
                ))}
              </div>
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setEditingTemplate(null)}>Cancel</Button>
            <Button onClick={handleSave} disabled={saving}>
              {saving ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Save className="mr-2 h-4 w-4" />}
              Save Template
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Preview Template Dialog */}
      <Dialog open={!!previewTemplate} onOpenChange={(open) => {
        if (!open) {
          setPreviewTemplate(null)
          setPreviewHtml('')
          setPreviewSubject('')
        }
      }}>
        <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
          <DialogHeader>
            <DialogTitle className="flex items-center gap-2">
              <Mail className="size-5 text-brand" />
              Preview Template
            </DialogTitle>
            <DialogDescription>{previewTemplate ? keyLabel(previewTemplate.template_key) : ''}</DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-2">
            <div className="rounded-xl border border-border/70 bg-muted/25 p-4 dark:bg-muted/10">
              <p className="text-xs font-bold uppercase tracking-wide text-muted-foreground">Subject</p>
              <p className="mt-2 text-sm font-semibold text-foreground">{previewSubject || previewTemplate?.subject || 'No subject'}</p>
            </div>
            <div className="overflow-hidden rounded-xl border border-border/70 bg-[#eceff3]">
              {previewLoading ? (
                <div className="flex min-h-[320px] items-center justify-center bg-card">
                  <Loader2 className="size-6 animate-spin text-muted-foreground" />
                </div>
              ) : (
                <iframe
                  title="Email template preview"
                  srcDoc={previewHtml}
                  className="min-h-[420px] w-full border-0 bg-[#eceff3]"
                  sandbox=""
                />
              )}
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setPreviewTemplate(null)}>Close</Button>
            <Button onClick={() => {
              const template = previewTemplate
              setPreviewTemplate(null)
              openEdit(template)
            }}>
              <Pencil className="mr-2 h-4 w-4" />
              Edit Template
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* Test Email Dialog */}
      <Dialog open={testDialogOpen} onOpenChange={setTestDialogOpen}>
        <DialogContent className="sm:max-w-md">
          <DialogHeader>
            <DialogTitle>Send Test Email</DialogTitle>
            <DialogDescription>Choose a template event and provide a recipient email address.</DialogDescription>
          </DialogHeader>
          <div className="space-y-4 py-2">
            <div className="space-y-2">
              <Label>Template Event</Label>
              <Select value={testNotificationKey} onValueChange={setTestNotificationKey}>
                <SelectTrigger>
                  <SelectValue placeholder="Select template event" />
                </SelectTrigger>
                <SelectContent>
                  {notificationKeys.map((key) => (
                    <SelectItem key={key} value={key}>{keyLabel(key)}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-2">
              <Label>Recipient Email</Label>
              <Input
                type="email"
                placeholder="test@example.com"
                value={testEmail}
                onChange={(e) => setTestEmail(e.target.value)}
              />
            </div>
          </div>
          <DialogFooter>
            <Button variant="outline" onClick={() => setTestDialogOpen(false)}>Cancel</Button>
            <Button onClick={handleSendTest} disabled={sendingTest || !testEmail || !testNotificationKey}>
              {sendingTest ? <Loader2 className="mr-2 h-4 w-4 animate-spin" /> : <Send className="mr-2 h-4 w-4" />}
              Send
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}

/* ──────────────────────────────── Logs Tab ──────────────────────────────── */

function LogsTab() {
  const { toast } = useToast()
  const [logs, setLogs] = useState([])
  const [meta, setMeta] = useState({ current_page: 1, last_page: 1, total: 0 })
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState(null)
  const [retryingId, setRetryingId] = useState(null)

  const [filters, setFilters] = useState({
    status: 'all',
    notification_key: 'all',
    date_from: '',
    date_to: '',
    search: '',
    page: 1,
    per_page: 10,
  })

  const abortRef = useRef(null)
  const intervalRef = useRef(null)

  const fetchLogs = useCallback((params) => {
    abortRef.current?.abort()
    const ac = new AbortController()
    abortRef.current = ac
    setLoading(true)
    setError(null)
    getEmailLogs(params, { signal: ac.signal })
      .then((data) => {
        setLogs(data.data ?? [])
        if (data.meta) {
          setMeta({ current_page: data.meta.current_page, last_page: data.meta.last_page, total: data.meta.total })
        } else if (data.current_page != null) {
          setMeta({ current_page: data.current_page, last_page: data.last_page, total: data.total })
        }
        setLoading(false)
      })
      .catch((err) => {
        if (err.name === 'AbortError') return
        setError(err.message)
        setLoading(false)
      })
  }, [])

  useEffect(() => {
    fetchLogs(filters)
    return () => abortRef.current?.abort()
  }, [filters, fetchLogs])

  useEffect(() => {
    intervalRef.current = setInterval(() => {
      const ac = new AbortController()
      getEmailLogs(filters, { signal: ac.signal })
        .then((data) => {
          setLogs(data.data ?? [])
          if (data.meta) {
            setMeta({ current_page: data.meta.current_page, last_page: data.meta.last_page, total: data.meta.total })
          } else if (data.current_page != null) {
            setMeta({ current_page: data.current_page, last_page: data.last_page, total: data.total })
          }
        })
        .catch(() => {})
    }, 30_000)
    return () => clearInterval(intervalRef.current)
  }, [filters])

  const updateFilter = useCallback((key, value) => {
    setFilters((f) => ({ ...f, [key]: value, page: key === 'page' ? value : 1 }))
  }, [])

  const handleRetry = useCallback(async (logId) => {
    setRetryingId(logId)
    try {
      await retryEmailLog(logId)
      toast({ title: 'Email queued', description: 'The email has been queued for retry.' })
      fetchLogs(filters)
    } catch (err) {
      toast({ variant: 'destructive', title: 'Error', description: err.message })
    } finally {
      setRetryingId(null)
    }
  }, [filters, fetchLogs, toast])

  const statusIcon = useCallback((status) => {
    if (status === 'sent') return <MailCheck className="h-3.5 w-3.5" />
    if (status === 'delivered') return <MailCheck className="h-3.5 w-3.5" />
    if (status === 'failed') return <MailX className="h-3.5 w-3.5" />
    return <Mail className="h-3.5 w-3.5" />
  }, [])

  const handleExportLogs = useCallback(() => {
    const headers = ['Recipient', 'Email', 'Type', 'Subject', 'Status', 'Sent At', 'Failed Reason']
    const rows = logs.map((log) => [
      recipientName(log),
      recipientEmail(log),
      keyLabel(log.notification_key),
      log.subject ?? '',
      STATUS_BADGE_MAP[log.status]?.label ?? log.status ?? 'Queued',
      formatLogDate(log.sent_at ?? log.created_at),
      log.error_message ?? '',
    ])
    const csv = [headers, ...rows].map((row) => row.map(escapeCsvValue).join(',')).join('\n')
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' })
    const url = URL.createObjectURL(blob)
    const anchor = document.createElement('a')
    anchor.href = url
    anchor.download = `email-notification-logs-${new Date().toISOString().slice(0, 10)}.csv`
    anchor.click()
    setTimeout(() => URL.revokeObjectURL(url), 1000)
  }, [logs])

  const pageNumbers = useMemo(() => {
    const lastPage = Number(meta.last_page || 1)
    const currentPage = Number(meta.current_page || 1)
    const pages = []
    const start = Math.max(1, Math.min(currentPage - 2, lastPage - 4))
    const end = Math.min(lastPage, start + 4)
    for (let page = start; page <= end; page += 1) pages.push(page)
    return pages
  }, [meta.current_page, meta.last_page])

  const logsStart = meta.total === 0 ? 0 : ((meta.current_page || 1) - 1) * (filters.per_page || 10) + 1
  const logsEnd = Math.min(meta.total || logs.length, (meta.current_page || 1) * (filters.per_page || 10))

  return (
    <div className="space-y-3">
      <Card className="rounded-2xl border border-border/70 bg-card py-0 shadow-[0_1px_0_rgba(15,23,42,0.03),0_12px_30px_rgba(15,23,42,0.05)] dark:border-border dark:bg-card dark:shadow-[0_18px_44px_rgba(0,0,0,0.24)]">
        <CardContent className="p-4">
          <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-[minmax(10rem,1fr)_minmax(12rem,1fr)_minmax(10rem,1fr)_minmax(10rem,1fr)_minmax(14rem,1.4fr)_auto] xl:items-end">
            <div className="space-y-1.5">
              <Label className="text-xs font-bold text-foreground">Status</Label>
              <Select value={filters.status} onValueChange={(v) => updateFilter('status', v)}>
                <SelectTrigger className="h-10 rounded-lg border-border/70 bg-background text-xs font-semibold shadow-inner dark:bg-background/70">
                  <SelectValue placeholder="All statuses" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All statuses</SelectItem>
                  <SelectItem value="queued">Queued</SelectItem>
                  <SelectItem value="sent">Sent</SelectItem>
                  <SelectItem value="delivered">Delivered</SelectItem>
                  <SelectItem value="failed">Failed</SelectItem>
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1.5">
              <Label className="text-xs font-bold text-foreground">Notification Type</Label>
              <Select value={filters.notification_key} onValueChange={(v) => updateFilter('notification_key', v)}>
                <SelectTrigger className="h-10 rounded-lg border-border/70 bg-background text-xs font-semibold shadow-inner dark:bg-background/70">
                  <SelectValue placeholder="All types" />
                </SelectTrigger>
                <SelectContent>
                  <SelectItem value="all">All types</SelectItem>
                  {Object.entries(NOTIFICATION_KEY_LABELS).map(([k, v]) => (
                    <SelectItem key={k} value={k}>{v}</SelectItem>
                  ))}
                </SelectContent>
              </Select>
            </div>
            <div className="space-y-1.5">
              <Label className="text-xs font-bold text-foreground">From</Label>
              <Input
                type="date"
                className="h-10 rounded-lg border-border/70 bg-background text-xs font-semibold shadow-inner dark:bg-background/70"
                value={filters.date_from}
                onChange={(e) => updateFilter('date_from', e.target.value)}
              />
            </div>
            <div className="space-y-1.5">
              <Label className="text-xs font-bold text-foreground">To</Label>
              <Input
                type="date"
                className="h-10 rounded-lg border-border/70 bg-background text-xs font-semibold shadow-inner dark:bg-background/70"
                value={filters.date_to}
                onChange={(e) => updateFilter('date_to', e.target.value)}
              />
            </div>
            <div className="space-y-1.5">
              <Label className="text-xs font-bold text-foreground">Search</Label>
              <div className="relative">
                <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                  className="h-10 rounded-lg border-border/70 bg-background pl-9 text-xs font-semibold shadow-inner placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-brand/25 dark:bg-background/70"
                  placeholder="Recipient or subject..."
                  value={filters.search}
                  onChange={(e) => updateFilter('search', e.target.value)}
                />
              </div>
            </div>
            <Button
              type="button"
              variant="outline"
              className="h-10 rounded-lg border-border/70 bg-card px-4 text-xs font-bold text-foreground shadow-sm hover:border-brand/45 hover:bg-brand/5 hover:text-brand dark:bg-card dark:hover:bg-brand/10"
              onClick={handleExportLogs}
              disabled={logs.length === 0}
            >
              <Download className="mr-2 size-4" />
              Export Logs
            </Button>
          </div>
        </CardContent>
      </Card>

      {error ? (
        <Card className="rounded-2xl border-border/70 bg-card py-0 dark:bg-card">
          <CardContent className="flex flex-col items-center justify-center gap-3 py-20 text-muted-foreground">
          <AlertCircle className="h-8 w-8 text-destructive" />
          <p className="text-sm">{error}</p>
          <Button variant="outline" size="sm" onClick={() => fetchLogs(filters)}>
            <RotateCcw className="mr-2 h-4 w-4" /> Retry
          </Button>
          </CardContent>
        </Card>
      ) : (
        <Card className="overflow-hidden rounded-2xl border border-border/70 bg-card py-0 shadow-[0_1px_0_rgba(15,23,42,0.03),0_16px_36px_rgba(15,23,42,0.06)] dark:border-border dark:bg-card dark:shadow-[0_18px_44px_rgba(0,0,0,0.32)]">
          <CardContent className="p-0">
          <Table>
            <TableHeader className="bg-card dark:bg-card">
              <TableRow className="border-b border-border/60 hover:bg-transparent">
                <TableHead className="h-12 min-w-[220px] bg-card pl-5 text-xs font-extrabold text-foreground dark:bg-card">Recipient</TableHead>
                <TableHead className="h-12 min-w-[190px] bg-card text-xs font-extrabold text-foreground dark:bg-card">Type</TableHead>
                <TableHead className="h-12 min-w-[220px] bg-card text-xs font-extrabold text-foreground dark:bg-card">Subject</TableHead>
                <TableHead className="h-12 w-[120px] bg-card text-xs font-extrabold text-foreground dark:bg-card">Status</TableHead>
                <TableHead className="h-12 min-w-[180px] bg-card text-xs font-extrabold text-foreground dark:bg-card">Sent At</TableHead>
                <TableHead className="h-12 min-w-[180px] bg-card text-xs font-extrabold text-foreground dark:bg-card">Failed Reason</TableHead>
                <TableHead className="h-12 w-[100px] bg-card text-xs font-extrabold text-foreground dark:bg-card">Retry</TableHead>
                <TableHead className="h-12 w-[64px] bg-card pr-5 text-right text-xs font-extrabold text-foreground dark:bg-card" aria-label="Actions" />
              </TableRow>
            </TableHeader>
            <TableBody>
              {loading ? (
                <TableRow>
                  <TableCell colSpan={8} className="h-24 text-center">
                    <Loader2 className="mx-auto h-5 w-5 animate-spin text-muted-foreground" />
                  </TableCell>
                </TableRow>
              ) : logs.length === 0 ? (
                <TableRow>
                  <TableCell colSpan={8} className="h-24 text-center text-muted-foreground">
                    No email logs found.
                  </TableCell>
                </TableRow>
              ) : (
                logs.map((log) => {
                  const badgeStyle = STATUS_BADGE_MAP[log.status] ?? STATUS_BADGE_MAP.queued
                  return (
                    <TableRow
                      key={log.id}
                      className="border-b border-border/50 bg-card transition-colors hover:bg-muted/25 dark:border-border/60 dark:bg-card dark:hover:bg-muted/20"
                    >
                      <TableCell className="py-3.5 pl-5">
                        <div className="min-w-0">
                          <p className="truncate text-sm font-bold leading-tight text-foreground">{recipientName(log)}</p>
                          <p className="mt-1 truncate text-[11px] leading-tight text-muted-foreground">{recipientEmail(log)}</p>
                        </div>
                      </TableCell>
                      <TableCell className="py-3.5">
                        <div className="flex min-w-0 items-center gap-2">
                          <span className="flex size-7 shrink-0 items-center justify-center rounded-lg border border-brand/15 bg-brand/8 text-brand dark:border-brand/25 dark:bg-brand/12">
                            <Mail className="size-3.5" />
                          </span>
                          <span className="truncate text-xs font-semibold text-foreground">{keyLabel(log.notification_key)}</span>
                        </div>
                      </TableCell>
                      <TableCell className="max-w-[280px] truncate py-3.5 text-sm font-medium text-foreground">{log.subject ?? '—'}</TableCell>
                      <TableCell className="py-3.5">
                        <Badge variant="outline" className={cn('gap-1 rounded-md px-2.5 py-1 text-[11px] font-bold', badgeStyle.className)}>
                          {statusIcon(log.status)}
                          {badgeStyle.label ?? log.status}
                        </Badge>
                      </TableCell>
                      <TableCell className="py-3.5 text-xs font-medium text-muted-foreground">{formatLogDate(log.sent_at ?? log.created_at)}</TableCell>
                      <TableCell className={cn('max-w-[220px] truncate py-3.5 text-xs font-medium', log.error_message ? 'text-destructive' : 'text-muted-foreground')}>
                        {log.error_message ?? '—'}
                      </TableCell>
                      <TableCell className="py-3.5">
                        {log.status === 'failed' && (
                          <Button
                            variant="ghost"
                            size="sm"
                            className="h-8 rounded-lg px-2 text-xs font-bold text-brand hover:bg-brand/8 hover:text-brand"
                            onClick={() => handleRetry(log.id)}
                            disabled={retryingId === log.id}
                          >
                            {retryingId === log.id ? <Loader2 className="mr-1.5 size-3.5 animate-spin" /> : <RefreshCcw className="mr-1.5 size-3.5" />}
                            Retry
                          </Button>
                        )}
                        {log.status !== 'failed' && <span className="text-xs font-semibold text-muted-foreground">—</span>}
                      </TableCell>
                      <TableCell className="py-3.5 pr-5 text-right">
                        <Button variant="ghost" size="icon" className="size-8 rounded-lg text-muted-foreground hover:bg-muted/70 hover:text-foreground">
                          <MoreVertical className="size-4" />
                        </Button>
                      </TableCell>
                    </TableRow>
                  )
                })
              )}
            </TableBody>
          </Table>
          <div className="flex flex-col gap-3 border-t border-border/60 bg-card px-5 py-4 text-xs text-muted-foreground dark:bg-card sm:flex-row sm:items-center sm:justify-between">
            <span>
              Showing {logsStart} to {logsEnd} of {meta.total || logs.length} logs
            </span>
            <div className="flex flex-wrap items-center gap-3 sm:justify-end">
              <div className="flex items-center gap-2">
                <span className="font-medium text-foreground">Rows per page:</span>
                <Select
                  value={String(filters.per_page)}
                  onValueChange={(value) => updateFilter('per_page', Number(value))}
                >
                  <SelectTrigger className="h-9 w-[74px] rounded-lg border-border/70 bg-background text-xs shadow-inner dark:bg-background/70">
                    <SelectValue />
                  </SelectTrigger>
                  <SelectContent>
                    {[10, 15, 20, 25].map((value) => (
                      <SelectItem key={value} value={String(value)}>{value}</SelectItem>
                    ))}
                  </SelectContent>
                </Select>
              </div>
              <div className="flex items-center gap-1.5">
                <Button variant="outline" size="icon" className="size-9 rounded-lg border-border/70 bg-card" disabled={meta.current_page <= 1} onClick={() => updateFilter('page', meta.current_page - 1)}>
                  <ChevronLeft className="h-4 w-4" />
                </Button>
                {pageNumbers.map((page) => (
                  <Button
                    key={page}
                    variant={meta.current_page === page ? 'default' : 'outline'}
                    size="icon"
                    className={cn(
                      'size-9 rounded-lg text-xs font-bold',
                      meta.current_page === page
                        ? 'bg-brand text-brand-foreground hover:bg-brand-strong'
                        : 'border-border/70 bg-card text-foreground hover:border-brand/45 hover:bg-brand/5 hover:text-brand',
                    )}
                    onClick={() => updateFilter('page', page)}
                  >
                    {page}
                  </Button>
                ))}
                <Button variant="outline" size="icon" className="size-9 rounded-lg border-border/70 bg-card" disabled={meta.current_page >= meta.last_page} onClick={() => updateFilter('page', meta.current_page + 1)}>
                  <ChevronRight className="h-4 w-4" />
                </Button>
              </div>
            </div>
          </div>
          </CardContent>
        </Card>
      )}
    </div>
  )
}

/* ──────────────────────────── Main Page Component ───────────────────────── */

export default function AdminEmailNotifications() {
  return (
    <div className="w-full space-y-5 p-4 text-foreground md:p-6">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex min-w-0 items-start gap-3">
          <span className="mt-0.5 flex size-11 shrink-0 items-center justify-center rounded-2xl border border-brand/15 bg-brand/8 text-brand shadow-sm dark:border-brand/25 dark:bg-brand/12">
            <Mail className="size-5" />
          </span>
          <div className="min-w-0">
            <h1 className="text-[26px] font-extrabold leading-tight tracking-tight text-foreground md:text-[30px]">Email Notifications</h1>
            <p className="mt-1 text-sm leading-relaxed text-muted-foreground">Manage notification settings, email templates, and view delivery logs.</p>
          </div>
        </div>
      </div>

      <Tabs defaultValue="settings" className="space-y-3">
        <TabsList variant="line" className="h-11 gap-5 border-b border-border/60 p-0">
          <TabsTrigger value="settings" className="h-11 rounded-none px-0 text-xs font-bold data-[state=active]:text-brand group-data-[variant=line]/tabs-list:data-[state=active]:after:bg-brand">
            <Settings className="h-4 w-4" />
            Settings
          </TabsTrigger>
          <TabsTrigger value="templates" className="h-11 rounded-none px-0 text-xs font-bold data-[state=active]:text-brand group-data-[variant=line]/tabs-list:data-[state=active]:after:bg-brand">
            <FileText className="h-4 w-4" />
            Templates
          </TabsTrigger>
          <TabsTrigger value="logs" className="h-11 rounded-none px-0 text-xs font-bold data-[state=active]:text-brand group-data-[variant=line]/tabs-list:data-[state=active]:after:bg-brand">
            <Mail className="h-4 w-4" />
            Logs
          </TabsTrigger>
        </TabsList>

        <TabsContent value="settings">
          <SettingsTab />
        </TabsContent>

        <TabsContent value="templates">
          <TemplatesTab />
        </TabsContent>

        <TabsContent value="logs">
          <LogsTab />
        </TabsContent>
      </Tabs>
    </div>
  )
}
