import { useMemo, useState } from 'react'
import { useNavigate, useSearchParams } from 'react-router-dom'
import { Bell, CheckCheck, ExternalLink, Trash2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { useNotifications } from '@/contexts/NotificationsContext'
import { cn } from '@/lib/utils'
import { prefetchLeaveRequestReview } from '@/api'

const MODULES = [
  ['all', 'All'],
  ['leave', 'Leave'],
  ['overtime', 'Overtime'],
  ['attendance_correction', 'Corrections'],
  ['payroll', 'Payroll'],
  ['payslip', 'Payslips'],
  ['attendance', 'Attendance'],
]

function timeLabel(value) {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  return date.toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' })
}

function leaveReviewIdFromActionUrl(actionUrl) {
  if (!actionUrl || typeof actionUrl !== 'string') return null
  try {
    const parsed = new URL(actionUrl, window.location.origin)
    if (!parsed.pathname.toLowerCase().includes('/leave')) return null
    const id = parsed.searchParams.get('review_id') || parsed.searchParams.get('reviewRequestId') || parsed.searchParams.get('request_id')
    return id && /^\d+$/.test(String(id)) ? String(id) : null
  } catch {
    return null
  }
}

export default function NotificationsCenter() {
  const navigate = useNavigate()
  const [searchParams, setSearchParams] = useSearchParams()
  const { items, moduleCounts, unreadCount, markRead, markAllRead, dismiss } = useNotifications()
  const [module, setModule] = useState('all')
  const [status, setStatus] = useState('all')
  const selectedId = searchParams.get('notification')

  const filtered = useMemo(() => {
    return items.filter((item) => {
      if (module !== 'all' && item.module !== module) return false
      if (status === 'unread' && item.read_at) return false
      if (status === 'read' && !item.read_at) return false
      return true
    })
  }, [items, module, status])

  const selectedNotification = useMemo(
    () => items.find((item) => String(item.id) === String(selectedId)) || null,
    [items, selectedId],
  )

  async function openNotification(item) {
    if (!item?.id) return
    const leaveReviewId = leaveReviewIdFromActionUrl(item.action_url)
    if (leaveReviewId) prefetchLeaveRequestReview(leaveReviewId)?.catch(() => {})
    await markRead(item.id).catch(() => {})
    if (item.action_url) navigate(item.action_url)
    else setSearchParams({ notification: String(item.id) })
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-bold tracking-tight text-foreground">Notifications</h1>
          <p className="text-sm text-muted-foreground">{unreadCount} unread updates</p>
        </div>
        <Button variant="outline" onClick={() => markAllRead(module === 'all' ? undefined : module)}>
          <CheckCheck className="mr-2 size-4" />
          Mark all read
        </Button>
      </div>

      <div className="flex flex-wrap gap-2">
        {MODULES.map(([key, label]) => (
          <button
            key={key}
            type="button"
            onClick={() => setModule(key)}
            className={cn(
              'inline-flex items-center gap-2 rounded-md border px-3 py-1.5 text-sm font-medium',
              module === key ? 'border-brand bg-brand text-brand-foreground' : 'border-border bg-background text-muted-foreground hover:bg-muted'
            )}
          >
            {label}
            {key !== 'all' && moduleCounts[key] > 0 ? (
              <span className="rounded-full bg-destructive px-1.5 py-0.5 text-[10px] font-bold text-destructive-foreground">
                {moduleCounts[key]}
              </span>
            ) : null}
          </button>
        ))}
      </div>

      <div className="flex gap-2">
        {['all', 'unread', 'read'].map((key) => (
          <button
            key={key}
            type="button"
            onClick={() => setStatus(key)}
            className={cn(
              'rounded-md px-3 py-1.5 text-sm font-semibold capitalize',
              status === key ? 'bg-muted text-foreground' : 'text-muted-foreground hover:bg-muted/60'
            )}
          >
            {key}
          </button>
        ))}
      </div>

      {selectedNotification ? (
        <section className="rounded-2xl border border-brand/20 bg-brand/5 p-4 shadow-sm">
          <div className="flex items-start gap-3">
            <span className="mt-1 flex size-10 shrink-0 items-center justify-center rounded-full bg-brand/10 text-brand">
              <Bell className="size-5" />
            </span>
            <div className="min-w-0 flex-1">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <p className="text-xs font-semibold uppercase tracking-wide text-brand">Notification details</p>
                  <h2 className="mt-1 text-lg font-bold text-foreground">{selectedNotification.title}</h2>
                </div>
                {selectedNotification.action_url ? (
                  <Button size="sm" onClick={() => openNotification(selectedNotification)}>
                    <ExternalLink className="mr-2 size-4" />
                    Open
                  </Button>
                ) : null}
              </div>
              {selectedNotification.message ? <p className="mt-2 text-sm text-muted-foreground">{selectedNotification.message}</p> : null}
              <div className="mt-3 flex flex-wrap gap-2 text-xs text-muted-foreground">
                <span>{timeLabel(selectedNotification.created_at)}</span>
                {selectedNotification.module ? <span className="rounded-full bg-background px-2 py-0.5 font-medium capitalize">{selectedNotification.module.replace(/_/g, ' ')}</span> : null}
              </div>
            </div>
          </div>
        </section>
      ) : null}

      <div className="overflow-hidden rounded-md border border-border bg-card">
        {filtered.length === 0 ? (
          <div className="px-4 py-12 text-center text-sm text-muted-foreground">No notifications.</div>
        ) : (
          filtered.map((item) => (
            <div key={item.id} className={cn('flex items-start gap-3 border-b border-border/60 p-4 last:border-b-0', !item.read_at && 'bg-brand/5')}>
              <span className="mt-1 flex size-9 shrink-0 items-center justify-center rounded-full bg-muted text-muted-foreground">
                <Bell className="size-4" />
              </span>
              <button type="button" className="min-w-0 flex-1 text-left" onClick={() => openNotification(item)}>
                <div className="flex flex-wrap items-center gap-2">
                  <p className="font-semibold text-foreground">{item.title}</p>
                  {!item.read_at ? <span className="rounded-full bg-brand px-2 py-0.5 text-[10px] font-bold text-brand-foreground">Unread</span> : null}
                </div>
                {item.message ? <p className="mt-1 text-sm text-muted-foreground">{item.message}</p> : null}
                <p className="mt-2 text-xs text-muted-foreground">{timeLabel(item.created_at)}</p>
              </button>
              <Button variant="ghost" size="icon" onClick={() => dismiss(item.id)} title="Dismiss">
                <Trash2 className="size-4" />
              </Button>
            </div>
          ))
        )}
      </div>
    </div>
  )
}
