import { useMemo, useState } from 'react'
import { Bell, CheckCheck, Settings, Search, Sparkles, X } from 'lucide-react'
import { NotificationCard } from '@/components/notifications/NotificationCard'

const NOTIFICATION_TABS = [
  { key: 'all', label: 'All' },
  { key: 'unread', label: 'Unread' },
  { key: 'approvals', label: 'Approvals' },
  { key: 'attendance', label: 'Attendance' },
]

export function NotificationDrawer({
  open,
  onClose,
  notifications,
  onMarkAllRead,
  onToggleRead,
  onArchive,
  onSnooze,
}) {
  const [tab, setTab] = useState('all')
  const [query, setQuery] = useState('')

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase()
    return notifications.filter((n) => {
      if (tab === 'unread' && !n.unread) return false
      if (tab !== 'all' && tab !== 'unread' && n.category !== tab) return false
      if (!q) return true
      return `${n.title} ${n.description}`.toLowerCase().includes(q)
    })
  }, [notifications, tab, query])

  const grouped = useMemo(() => {
    const map = new Map()
    for (const item of filtered) {
      if (!map.has(item.group)) map.set(item.group, [])
      map.get(item.group).push(item)
    }
    return Array.from(map.entries())
  }, [filtered])

  return (
    <div className={`fixed inset-0 z-50 ${open ? 'pointer-events-auto' : 'pointer-events-none'}`}>
      <div
        onClick={onClose}
        className={`absolute inset-0 bg-slate-900/30 backdrop-blur-[2px] transition-opacity duration-300 ${open ? 'opacity-100' : 'opacity-0'}`}
      />

      <aside
        className={`absolute right-0 top-0 h-full w-full max-w-[100vw] transform border-l border-slate-200 bg-[#F8FAFC] shadow-2xl transition-transform duration-300 sm:max-w-[32rem] ${
          open ? 'translate-x-0' : 'translate-x-full'
        }`}
      >
        <div className="flex h-full flex-col">
          <header className="border-b border-slate-200 bg-white/85 px-5 py-4 backdrop-blur">
            <div className="mb-4 flex items-center justify-between">
              <div className="flex items-center gap-2">
                <span className="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-emerald-50 text-emerald-600">
                  <Sparkles className="h-4 w-4" />
                </span>
                <h2 className="text-lg font-semibold tracking-tight text-slate-900">Notifications</h2>
              </div>
              <div className="flex items-center gap-1">
                <button
                  type="button"
                  onClick={onMarkAllRead}
                  className="inline-flex items-center rounded-lg px-2.5 py-1.5 text-xs font-semibold text-emerald-700 transition hover:bg-emerald-50"
                >
                  <CheckCheck className="mr-1.5 h-3.5 w-3.5" />
                  Mark all as read
                </button>
                <button type="button" className="rounded-lg p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-700">
                  <Settings className="h-4 w-4" />
                </button>
                <button type="button" onClick={onClose} className="rounded-lg p-2 text-slate-500 transition hover:bg-slate-100 hover:text-slate-700">
                  <X className="h-4 w-4" />
                </button>
              </div>
            </div>

            <div className="relative mb-3">
              <Search className="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-slate-400" />
              <input
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder="Search notifications"
                className="h-10 w-full rounded-xl border border-slate-200 bg-white pl-9 pr-3 text-sm text-slate-700 outline-none ring-emerald-400/40 transition focus:ring"
              />
            </div>

            <div className="rounded-xl bg-slate-100 p-1">
              <div className="flex gap-1">
                {NOTIFICATION_TABS.map((t) => (
                  <button
                    key={t.key}
                    type="button"
                    onClick={() => setTab(t.key)}
                    className={`flex-1 rounded-lg px-2 py-2 text-xs font-semibold transition ${
                      tab === t.key ? 'bg-white text-slate-900 shadow-sm' : 'text-slate-600 hover:text-slate-900'
                    }`}
                  >
                    {t.label}
                  </button>
                ))}
              </div>
            </div>
          </header>

          <div className="flex-1 overflow-y-auto p-4">
            {grouped.length === 0 ? (
              <div className="mt-16 rounded-2xl border border-dashed border-slate-200 bg-white px-6 py-10 text-center shadow-sm">
                <div className="mx-auto mb-3 inline-flex h-12 w-12 items-center justify-center rounded-xl bg-emerald-50 text-emerald-600">
                  <Bell className="h-5 w-5" />
                </div>
                <p className="text-base font-semibold text-slate-900">You&apos;re all caught up</p>
                <p className="mt-1 text-sm text-slate-500">No pending HR updates right now. New activity will appear here in real time.</p>
              </div>
            ) : (
              <div className="space-y-5">
                {grouped.map(([groupLabel, items]) => (
                  <section key={groupLabel}>
                    <div className="mb-2 px-1 text-[11px] font-semibold uppercase tracking-wider text-slate-500">{groupLabel}</div>
                    <ul className="space-y-3">
                      {items.map((item, idx) => (
                        <NotificationCard
                          key={item.id}
                          item={item}
                          isNew={idx === 0 && item.unread}
                          onToggleRead={() => onToggleRead(item.id)}
                          onArchive={() => onArchive(item.id)}
                          onSnooze={() => onSnooze(item.id)}
                        />
                      ))}
                    </ul>
                  </section>
                ))}
              </div>
            )}
          </div>
        </div>
      </aside>
    </div>
  )
}

