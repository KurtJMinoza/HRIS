import { useMemo, useState } from 'react'
import { Bell } from 'lucide-react'
import { NotificationDrawer } from '@/components/notifications/NotificationDrawer'

const initialNotifications = [
  {
    id: 'n-1001',
    unread: true,
    type: 'Approval',
    category: 'approvals',
    group: 'Today',
    avatar: 'MB',
    title: 'Michael Biscocho requested attendance correction',
    description: 'Clock-in only for Mar 26, 2026 needs your review.',
    time: '2m ago',
    actions: ['Review', 'Approve'],
  },
  {
    id: 'n-1002',
    unread: true,
    type: 'Reminder',
    category: 'status',
    group: 'Today',
    avatar: 'AC',
    title: 'Status change is coming up',
    description: 'Angela Cruz will transition to Regular in 3 days.',
    time: '18m ago',
    actions: ['View details'],
  },
  {
    id: 'n-1003',
    unread: true,
    type: 'Alert',
    category: 'attendance',
    group: 'Today',
    avatar: 'HR',
    title: '3 late clock-ins detected',
    description: 'Attendance anomalies were flagged in Branch Makati.',
    time: '43m ago',
    actions: ['Open attendance'],
  },
  {
    id: 'n-1004',
    unread: false,
    type: 'Update',
    category: 'approvals',
    group: 'Yesterday',
    avatar: 'HR',
    title: 'Leave approvals were synced',
    description: 'Approved leave records are now reflected in reports.',
    time: 'Yesterday',
    actions: ['Open report'],
  },
]

export function NotificationBell() {
  const [open, setOpen] = useState(false)
  const [items, setItems] = useState(initialNotifications)

  const unreadCount = useMemo(() => items.filter((n) => n.unread).length, [items])

  const onMarkAllRead = () => {
    setItems((prev) => prev.map((n) => ({ ...n, unread: false })))
  }

  const onToggleRead = (id) => {
    setItems((prev) => prev.map((n) => (n.id === id ? { ...n, unread: !n.unread } : n)))
  }

  const onArchive = (id) => {
    setItems((prev) => prev.filter((n) => n.id !== id))
  }

  const onSnooze = (id) => {
    setItems((prev) => prev.map((n) => (n.id === id ? { ...n, unread: false, time: 'Snoozed' } : n)))
  }

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        aria-label="Open notifications"
        className="relative inline-flex h-10 w-10 items-center justify-center rounded-xl border border-slate-200 bg-white text-slate-700 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md"
      >
        <Bell className="h-[18px] w-[18px]" />
        {unreadCount > 0 ? (
          <span className="absolute -right-1 -top-1 inline-flex min-h-5 min-w-5 items-center justify-center rounded-full bg-emerald-500 px-1.5 text-[11px] font-bold leading-none text-white ring-2 ring-white">
            {unreadCount > 99 ? '99+' : unreadCount}
          </span>
        ) : (
          <span className="absolute -right-0.5 -top-0.5 h-2.5 w-2.5 rounded-full bg-slate-300 ring-2 ring-white" />
        )}
      </button>

      <NotificationDrawer
        open={open}
        onClose={() => setOpen(false)}
        notifications={items}
        onMarkAllRead={onMarkAllRead}
        onToggleRead={onToggleRead}
        onArchive={onArchive}
        onSnooze={onSnooze}
      />
    </>
  )
}

export default NotificationBell

