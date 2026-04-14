import {
  Bell,
  Archive,
  Clock3,
  MoreHorizontal,
  ClipboardCheck,
  Activity,
  AlertTriangle,
  RefreshCcw,
  MessageSquare,
} from 'lucide-react'

const TYPE_STYLES = {
  Approval: 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-200/80',
  Alert: 'bg-rose-50 text-rose-700 ring-1 ring-rose-200/80',
  Update: 'bg-sky-50 text-sky-700 ring-1 ring-sky-200/80',
  Reminder: 'bg-amber-50 text-amber-700 ring-1 ring-amber-200/80',
}

const CATEGORY_ICON = {
  approvals: ClipboardCheck,
  attendance: Activity,
  status: RefreshCcw,
  mentions: MessageSquare,
  alerts: AlertTriangle,
}

export function NotificationCard({ item, isNew = false, onToggleRead, onArchive, onSnooze }) {
  const TypeIcon = CATEGORY_ICON[item.category] || Bell

  return (
    <li
      className={`group rounded-2xl border bg-white p-4 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md ${
        item.unread ? 'border-emerald-200 ring-1 ring-emerald-100/90' : 'border-slate-200'
      } ${isNew ? 'animate-[pulse_1.2s_ease-in-out_1]' : ''}`}
    >
      <div className="flex items-start gap-3">
        <div className="relative shrink-0">
          <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-slate-100 text-sm font-semibold text-slate-700">
            {item.avatar}
          </div>
          {item.unread && <span className="absolute -left-1 -top-1 h-2.5 w-2.5 rounded-full bg-emerald-500 ring-2 ring-white" />}
        </div>

        <div className="min-w-0 flex-1">
          <div className="mb-1 flex items-start justify-between gap-2">
            <p className="line-clamp-2 text-sm font-semibold leading-5 text-slate-900">{item.title}</p>
            <button type="button" className="rounded-md p-1 text-slate-400 opacity-0 transition hover:bg-slate-100 hover:text-slate-600 group-hover:opacity-100">
              <MoreHorizontal className="h-4 w-4" />
            </button>
          </div>

          <p className="line-clamp-2 text-sm leading-5 text-slate-600">{item.description}</p>

          <div className="mt-3 flex flex-wrap items-center gap-2">
            <span className={`inline-flex items-center rounded-full px-2.5 py-1 text-[11px] font-semibold ${TYPE_STYLES[item.type] || 'bg-slate-100 text-slate-700'}`}>
              <TypeIcon className="mr-1 h-3 w-3" />
              {item.type}
            </span>
            <span className="text-xs text-slate-500">{item.time}</span>
          </div>

          <div className="mt-3 flex flex-wrap items-center gap-2">
            {(item.actions || []).map((action) => {
              const emphasized = /approve/i.test(action)
              return (
                <button
                  key={action}
                  type="button"
                  className={`rounded-lg px-3 py-1.5 text-xs font-semibold transition ${
                    emphasized
                      ? 'bg-emerald-600 text-white hover:bg-emerald-700'
                      : 'border border-slate-200 bg-white text-slate-700 hover:bg-slate-50'
                  }`}
                >
                  {action}
                </button>
              )
            })}

            <button type="button" onClick={onSnooze} title="Snooze" className="rounded-lg p-1.5 text-slate-500 transition hover:bg-slate-100 hover:text-slate-700">
              <Clock3 className="h-4 w-4" />
            </button>
            <button type="button" onClick={onArchive} title="Archive" className="rounded-lg p-1.5 text-slate-500 transition hover:bg-slate-100 hover:text-slate-700">
              <Archive className="h-4 w-4" />
            </button>

            <button
              type="button"
              onClick={onToggleRead}
              className="ml-auto rounded-lg px-2 py-1 text-[11px] font-semibold text-emerald-700 transition hover:bg-emerald-50"
            >
              {item.unread ? 'Mark read' : 'Mark unread'}
            </button>
          </div>
        </div>
      </div>
    </li>
  )
}

