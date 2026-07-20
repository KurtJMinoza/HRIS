import { useState, useEffect, useMemo, useRef, useLayoutEffect, useCallback } from 'react'
import { Link, NavLink, Outlet, useNavigate, useLocation } from 'react-router-dom'
import {
  Sheet,
  SheetContent,
  SheetHeader,
  SheetTitle,
  SheetTrigger,
} from '@/components/ui/sheet'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import {
  DropdownMenu,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { useAuth } from '@/contexts/AuthContext'
import { useNotifications } from '@/contexts/NotificationsContext'
import { useTheme } from '@/contexts/useTheme'
import { cn } from '@/lib/utils'
import { hrPanelPath } from '@/lib/hrRoutes'
import { getEmployees, prefetchLeaveRequestReview } from '@/api'
import { employeeAvatarSrc, getEmployeeAvatarColorClass } from '@/lib/employeeAvatar'
import { formatEmployeeName } from '@/lib/employeeSort'
import { markLoginSplashShown } from '@/lib/loginSplash'
import { dispatchDismissOverlays, scheduleRadixModalLockReset } from '@/lib/radixModalLock'
import { prefetchOrgModule } from '@/lib/orgModuleNav'
import { useSidebarNavRescue } from '@/hooks/useSidebarNavRescue'
import { useEmployeeActivityTracking } from '@/hooks/useEmployeeActivityTracking'
import { AgcBrandLogo } from '@/components/AgcBrandLogo'
import { UserAccountMenuContent } from '@/components/layout/UserAccountMenuContent'
import { AtSign, Bell, CalendarClock, Banknote, CheckCheck, ChevronDown, ChevronRight, Clock, FileText, LayoutDashboard, LogOut, Menu, PanelLeftClose, PanelLeft, Search, Settings, User, Loader2, Sun, Moon } from 'lucide-react'

const SIDEBAR_COLLAPSED_KEY = 'smartdtr_sidebar_collapsed'

const getItemKey = (item, parentKey = '') => item.to || `${parentKey}::${item.label || 'item'}`

function flattenNavItems(items = []) {
  const out = []
  for (const item of items) {
    if (item?.to) {
      out.push({
        type: 'page',
        id: item.to,
        label: item.label,
        to: item.to,
        icon: item.icon ?? null,
      })
    }
    if (Array.isArray(item?.children) && item.children.length > 0) {
      out.push(...flattenNavItems(item.children))
    }
  }
  return out
}

function getFirstLeafTo(item) {
  if (item?.to) return item.to
  const children = Array.isArray(item?.children) ? item.children : []
  for (const child of children) {
    const leafTo = getFirstLeafTo(child)
    if (leafTo) return leafTo
  }
  return null
}

function isItemActive(item, pathname) {
  if (!item?.to) return false
  if (item.end) return pathname === item.to
  return pathname === item.to || pathname.startsWith(`${item.to}/`)
}

function hasActiveDescendant(item, pathname) {
  const children = Array.isArray(item?.children) ? item.children : []
  if (children.some((child) => isItemActive(child, pathname) || hasActiveDescendant(child, pathname))) return true
  return false
}

const MOCK_NOTIFICATIONS = [
  {
    id: '1',
    actor: 'Attendance',
    body: 'Your clock-in for today was recorded.',
    time: 'Just now',
    detail: '8:02 AM',
    unread: true,
    mention: false,
    type: 'attendance',
    avatar: null,
  },
  {
    id: '2',
    actor: 'HR Team',
    body: 'Your leave request is pending approval.',
    time: '2h ago',
    unread: true,
    mention: true,
    type: 'leave',
    avatar: 'HR',
  },
  {
    id: '3',
    actor: 'Payroll',
    body: 'Your January 2026 payslip is ready to view.',
    time: '1d ago',
    unread: false,
    mention: false,
    type: 'payslip',
    avatar: 'PR',
  },
  {
    id: '4',
    actor: 'Marcus Lee',
    body: 'mentioned you on an overtime correction thread.',
    time: '3h ago',
    unread: true,
    mention: true,
    type: 'mention',
    avatar: 'ML',
  },
]

const NOTIFICATION_TABS = [
  { id: 'all', label: 'All' },
  { id: 'unread', label: 'Unread' },
]

function notificationTimeLabel(value) {
  if (!value) return ''
  const diff = Date.now() - new Date(value).getTime()
  if (!Number.isFinite(diff)) return ''
  const minutes = Math.max(0, Math.floor(diff / 60000))
  if (minutes < 1) return 'Just now'
  if (minutes < 60) return `${minutes}m ago`
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return `${hours}h ago`
  return `${Math.floor(hours / 24)}d ago`
}

function mapNotificationItem(item) {
  const title = item?.title || 'Notification'
  const message = item?.message || ''
  return {
    id: item.id,
    actor: title,
    body: message,
    time: notificationTimeLabel(item.created_at),
    detail: item.priority === 'high' ? 'High priority' : null,
    unread: !item.read_at,
    mention: false,
    type: item.module || item.type || 'notification',
    avatar: null,
    actionUrl: item.action_url || null,
  }
}

function leaveReviewIdFromActionUrl(actionUrl) {
  if (!actionUrl || typeof actionUrl !== 'string') return null
  try {
    const parsed = new URL(actionUrl, window.location.origin)
    const path = parsed.pathname.toLowerCase()
    if (!path.includes('/leave')) return null
    const id = parsed.searchParams.get('review_id') || parsed.searchParams.get('reviewRequestId') || parsed.searchParams.get('request_id')
    return id && /^\d+$/.test(String(id)) ? String(id) : null
  } catch {
    return null
  }
}

function navNotificationModule(item) {
  const haystack = `${item?.to || ''} ${item?.label || ''}`.toLowerCase()
  if (haystack.includes('overtime')) return 'overtime'
  if (haystack.includes('correction') || haystack.includes('presence')) return 'attendance_correction'
  if (haystack.includes('payslip')) return 'payslip'
  if (haystack.includes('payroll')) return 'payroll'
  if (haystack.includes('attendance')) return 'attendance'
  if (haystack.includes('leave') || haystack.includes('request')) return 'leave'
  if (haystack.includes('dashboard')) return 'dashboard'
  if (haystack.includes('report')) return 'reports'
  return null
}

function navNotificationModules(item) {
  const modules = new Set()
  const ownModule = navNotificationModule(item)
  if (ownModule) modules.add(ownModule)
  const children = Array.isArray(item?.children) ? item.children : []
  children.forEach((child) => {
    navNotificationModules(child).forEach((module) => modules.add(module))
  })
  return modules
}

function SidebarContent({
  navItems,
  homePath,
  profilePath,
  user,
  currentUserDisplayName,
  initials,
  onLogout,
  onNavClick,
  onNavIntent,
  collapsed,
  onToggleCollapse,
  pathname,
  moduleCounts = {},
  navRef,
}) {
  const sidebarAvatarSrc = employeeAvatarSrc(user)
  const [manualExpanded, setManualExpanded] = useState({})
  const autoExpanded = useMemo(() => {
    const next = {}
    function walk(items = [], parentKey = '') {
      items.forEach((item) => {
        const key = getItemKey(item, parentKey)
        if (Array.isArray(item?.children) && item.children.length > 0) {
          if (hasActiveDescendant(item, pathname) || isItemActive(item, pathname)) {
            next[key] = true
          }
          walk(item.children, key)
        }
      })
    }
    walk(navItems)
    return next
  }, [navItems, pathname])

  useEffect(() => {
    if (collapsed) return
    const nav = navRef?.current
    if (!nav) return
    const activeEl = nav.querySelector(`[data-hr-sidebar-href="${CSS.escape(pathname)}"]`)
    activeEl?.scrollIntoView({ block: 'nearest', behavior: 'smooth' })
  }, [pathname, navItems, collapsed, autoExpanded, manualExpanded, navRef])

  const handleNavIntent = useCallback((to) => {
    onNavIntent?.(to)
  }, [onNavIntent])

  function renderItem(item, depth = 0, parentKey = '') {
    const key = getItemKey(item, parentKey)
    const children = Array.isArray(item?.children) ? item.children : []
    const hasChildren = children.length > 0
    const active = isItemActive(item, pathname) || hasActiveDescendant(item, pathname)
    const leafTo = getFirstLeafTo(item)
    const moduleKeys = navNotificationModules(item)
    const badgeCount = Array.from(moduleKeys).reduce((sum, module) => sum + Number(moduleCounts[module] || 0), 0)

    if (collapsed) {
      const to = item.to || leafTo
      if (!to) return null
      return (
        <Link
          key={key}
          to={to}
          data-hr-sidebar-nav=""
          data-hr-sidebar-href={to}
          className={cn(
            'relative mx-auto flex size-10 items-center justify-center rounded-md text-sm font-medium transition-all duration-200',
            active
              ? 'bg-orange-50 text-orange-600 ring-1 ring-orange-100 dark:bg-orange-500/15 dark:text-orange-300 dark:ring-orange-500/25'
              : 'text-muted-foreground hover:bg-sidebar-accent hover:text-foreground'
          )}
          onClick={() => {
            handleNavIntent(to)
            onNavClick?.()
          }}
          title={item.label}
        >
          {item.icon && <item.icon className="size-5 shrink-0" />}
          {badgeCount > 0 ? (
            <span className="absolute right-0.5 top-0.5 flex size-4 items-center justify-center rounded-full bg-destructive text-[9px] font-bold text-destructive-foreground ring-1 ring-background">
              {badgeCount > 9 ? '9+' : badgeCount}
            </span>
          ) : null}
        </Link>
      )
    }

    if (hasChildren) {
      const defaultOpen = item.label === 'Administration' ? true : !!autoExpanded[key]
      const isOpen = manualExpanded[key] ?? defaultOpen
      return (
        <div key={key} className="space-y-1">
          <button
            type="button"
            className={cn(
              'flex w-full items-start gap-0 rounded-md px-3 py-2.5 text-left text-sm font-medium transition-all duration-200',
              depth > 0 && 'ml-4',
              active
                ? 'bg-orange-50 text-orange-600 dark:bg-orange-500/15 dark:text-orange-300'
                : 'text-muted-foreground hover:bg-sidebar-accent hover:text-foreground'
            )}
            onClick={() => setManualExpanded((prev) => ({ ...prev, [key]: !isOpen }))}
          >
            {item.icon ? <item.icon className="mr-3 mt-0.5 size-5 shrink-0" /> : <span className="mr-3 mt-0.5 inline-block size-5 shrink-0" />}
            <span className="min-w-0 flex-1 whitespace-normal wrap-break-word leading-snug">{item.label}</span>
            {badgeCount > 0 ? (
              <span className="ml-2 mt-0.5 inline-flex min-w-5 shrink-0 items-center justify-center rounded-full bg-destructive px-1.5 py-0.5 text-[10px] font-bold text-destructive-foreground">
                {badgeCount > 99 ? '99+' : badgeCount}
              </span>
            ) : null}
            {isOpen ? <ChevronDown className="mt-1 size-4 shrink-0" /> : <ChevronRight className="mt-1 size-4 shrink-0" />}
          </button>
          {isOpen && (
            <div className="space-y-1">
              {children.map((child) => renderItem(child, depth + 1, key))}
            </div>
          )}
        </div>
      )
    }

    if (!item.to) return null

    return (
      <NavLink
        key={key}
        to={item.to}
        end={item.end}
        data-hr-sidebar-nav=""
        data-hr-sidebar-href={item.to}
        className={({ isActive }) =>
          cn(
            'flex items-start gap-3 rounded-md px-3 py-2.5 text-sm font-medium transition-all duration-200',
            isActive
              ? 'border-l-2 border-orange-500 bg-orange-50 text-orange-600 shadow-sm ring-1 ring-orange-100 dark:bg-orange-500/15 dark:text-orange-300 dark:ring-orange-500/25'
              : 'border-l-2 border-transparent text-muted-foreground hover:bg-sidebar-accent hover:text-foreground'
          )
        }
        onClick={() => {
          handleNavIntent(item.to)
          onNavClick?.()
        }}
        onMouseEnter={() => prefetchOrgModule(item.to)}
        style={depth > 0 ? { paddingLeft: `${12 + depth * 16}px` } : undefined}
      >
        {item.icon ? <item.icon className="mt-0.5 size-4 shrink-0" /> : <span className="mt-0.5 inline-block size-4 shrink-0" />}
        <span className="min-w-0 flex-1 whitespace-normal wrap-break-word leading-snug">{item.label}</span>
        {badgeCount > 0 ? (
          <span className="ml-auto inline-flex min-w-5 shrink-0 items-center justify-center rounded-full bg-destructive px-1.5 py-0.5 text-[10px] font-bold text-destructive-foreground">
            {badgeCount > 99 ? '99+' : badgeCount}
          </span>
        ) : null}
      </NavLink>
    )
  }

  return (
    <>
      <div
        className={cn(
          'flex h-16 min-h-16 items-center border-b border-border/40',
          collapsed ? 'justify-center px-1.5' : 'px-4'
        )}
      >
        <Link
          to={homePath}
          className={cn(
            'flex min-w-0 overflow-hidden rounded-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring',
            collapsed
              ? 'h-full max-h-15 w-full items-center justify-center py-2'
              : 'items-center justify-start'
          )}
          title={collapsed ? 'HRIS home' : undefined}
        >
          <AgcBrandLogo
            className={cn(
              collapsed
                ? 'mx-auto max-h-11.5 w-full max-w-full object-contain object-center'
                : 'h-9 w-auto max-w-44 object-left'
            )}
          />
          <span className="sr-only">HRIS — home</span>
        </Link>
      </div>
      <nav ref={navRef} className={cn('flex flex-1 flex-col gap-1 overflow-y-auto p-3', collapsed && 'px-2')}>
        {navItems.map((item) => renderItem(item))}
      </nav>
      <div className={cn('border-t border-border/40 p-2', collapsed ? 'space-y-1' : 'space-y-2')}>
        {collapsed ? (
          <div className="space-y-1">
            <Link
              to={profilePath}
              className="mx-auto flex size-10 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-sidebar-accent hover:text-foreground"
              onClick={onNavClick}
              title="Profile"
            >
              <User className="size-5" />
            </Link>
            <button
              type="button"
              className="mx-auto flex size-10 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-sidebar-accent hover:text-destructive"
              onClick={async () => {
                await onLogout?.()
                onNavClick?.()
              }}
              title="Log out"
            >
              <LogOut className="size-5" />
            </button>
          </div>
        ) : (
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <button
                type="button"
                className="w-full rounded-xl bg-muted/30 p-2 text-left transition-colors hover:bg-muted/50"
              >
                <div className="flex items-center gap-2">
                  <Avatar
                    key={`${user?.id ?? 'sidebar-u'}-${sidebarAvatarSrc ?? ''}-${user?.updated_at ?? ''}`}
                    className="size-8 rounded-full ring-1 ring-border/40"
                  >
                    {sidebarAvatarSrc ? (
                      <AvatarImage src={sidebarAvatarSrc} alt="" className="object-cover" />
                    ) : null}
                    <AvatarFallback
                      className={cn(
                        'rounded-full text-xs font-bold',
                        getEmployeeAvatarColorClass(user?.id, currentUserDisplayName),
                      )}
                    >
                      {initials}
                    </AvatarFallback>
                  </Avatar>
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-xs font-semibold text-foreground">{currentUserDisplayName}</p>
                    <p className="truncate text-[11px] text-muted-foreground">{user?.email ?? ''}</p>
                  </div>
                  <ChevronDown className="size-4 text-muted-foreground" />
                </div>
              </button>
            </DropdownMenuTrigger>
            <UserAccountMenuContent
              align="end"
              side="top"
              user={user}
              displayName={currentUserDisplayName}
              initials={initials}
              avatarSrc={sidebarAvatarSrc}
              homePath={homePath}
              profilePath={profilePath}
              onLogout={async () => {
                await onLogout?.()
                onNavClick?.()
              }}
              onNavClick={onNavClick}
            />
          </DropdownMenu>
        )}
        {collapsed ? (
          <>
            {onToggleCollapse && (
              <Button
                variant="ghost"
                size="icon"
                className="mx-auto size-9"
                onClick={onToggleCollapse}
                title="Expand sidebar"
              >
                <PanelLeft className="size-5" />
              </Button>
            )}
            <p className="text-center text-[10px] text-muted-foreground" title="HRIS v1.0">v1</p>
          </>
        ) : (
          <div className="flex items-center justify-between px-1">
            <p className="text-xs text-muted-foreground">HRIS v1.0</p>
            {onToggleCollapse && (
              <Button
                variant="ghost"
                size="icon"
                className="size-8"
                onClick={onToggleCollapse}
                title="Collapse sidebar"
              >
                <PanelLeftClose className="size-4" />
              </Button>
            )}
          </div>
        )}
      </div>
    </>
  )
}

export function DashboardLayout({ navItems, role, hrBasePath = '/admin' }) {
  useEmployeeActivityTracking(true)
  const location = useLocation()
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  useSidebarNavRescue()

  const handleSidebarNavIntent = useCallback((to) => {
    dispatchDismissOverlays()
    scheduleRadixModalLockReset()
    prefetchOrgModule(to)
  }, [])

  useLayoutEffect(() => {
    dispatchDismissOverlays()
  }, [location.pathname])

  useEffect(() => {
    scheduleRadixModalLockReset()
  }, [location.pathname])
  const [sheetOpen, setSheetOpen] = useState(false)
  const [sidebarCollapsed, setSidebarCollapsed] = useState(() => {
    try {
      return localStorage.getItem(SIDEBAR_COLLAPSED_KEY) === '1'
    } catch {
      return false
    }
  })
  const {
    items: notificationItems,
    unreadCount: notificationCount,
    moduleCounts: notificationModuleCounts,
    markRead: markNotificationReadBackend,
    markAllRead: markAllNotificationsReadBackend,
  } = useNotifications()
  const [notificationTab, setNotificationTab] = useState('all')

  useEffect(() => {
    try {
      localStorage.setItem(SIDEBAR_COLLAPSED_KEY, sidebarCollapsed ? '1' : '0')
    } catch {
      // ignore
    }
  }, [sidebarCollapsed])

  async function handleLogout() {
    await logout()
    markLoginSplashShown()
    navigate('/login', { replace: true, state: { skipPreloader: true } })
  }

  const currentUserDisplayName = formatEmployeeName(user, 'User')
  const initials = currentUserDisplayName
    ? currentUserDisplayName
        .trim()
        .split(/\s+/)
        .map((n) => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2)
    : '?'

  const headerAvatarSrc = employeeAvatarSrc(user)

  const notifications = useMemo(
    () => notificationItems.map(mapNotificationItem),
    [notificationItems],
  )

  const filteredNotifications = useMemo(() => {
    if (notificationTab === 'unread') return notifications.filter((n) => n.unread)
    return notifications
  }, [notifications, notificationTab])

  const markAllNotificationsRead = () => {
    markAllNotificationsReadBackend().catch(() => {})
  }

  const prefetchNotificationTarget = (notification) => {
    const leaveReviewId = leaveReviewIdFromActionUrl(notification?.actionUrl)
    if (leaveReviewId) {
      prefetchLeaveRequestReview(leaveReviewId)?.catch(() => {})
    }
  }

  const markNotificationRead = (id) => {
    const item = notifications.find((n) => n.id === id)
    markNotificationReadBackend(id).catch(() => {})
    if (item?.actionUrl) {
      prefetchNotificationTarget(item)
      navigate(item.actionUrl)
    } else {
      navigate(role === 'employee' ? `/employee/notifications?notification=${encodeURIComponent(String(id))}` : `${hrPanelPath(hrBasePath, 'notifications')}?notification=${encodeURIComponent(String(id))}`)
    }
  }

  const { theme, cycleTheme } = useTheme()

  const homePath =
    role === 'employee' ? '/employee/dashboard' : hrPanelPath(hrBasePath, 'dashboard')
  const profilePath =
    role === 'employee' ? '/employee/profile' : hrPanelPath(hrBasePath, 'profile')

  const treatAsHrPanel = role === 'admin' || role === 'super_admin' || role === 'manager'

  const panelPrefixes = ['/admin', '/company', '/branch', '/department']

  const isDashboardRoute =
    treatAsHrPanel &&
    panelPrefixes.some((prefix) => location.pathname === `${prefix}/dashboard`)

  // Global HR panel header search (pages + employee records)
  const isHrPanelSearch = treatAsHrPanel
  const [searchOpen, setSearchOpen] = useState(false)
  const [searchQuery, setSearchQuery] = useState('')
  const [searchActiveIndex, setSearchActiveIndex] = useState(0)
  const [employeeResults, setEmployeeResults] = useState([])
  const [employeesLoading, setEmployeesLoading] = useState(false)
  const requestSeqRef = useRef(0)
  const closeTimeoutRef = useRef(null)
  const sidebarNavRef = useRef(null)

  const trimmedQuery = searchQuery.trim()

  useEffect(() => {
    if (!treatAsHrPanel) return
    const activeLeaf = flattenNavItems(navItems).find(
      (item) => location.pathname === item.to || location.pathname.startsWith(`${item.to}/`),
    )
    if (!activeLeaf?.to) return
    const isNestedActive = navItems.some(
      (group) => Array.isArray(group.children) && group.children.some((child) => child.to === activeLeaf.to),
    )
    if (isNestedActive && sidebarCollapsed) {
      setSidebarCollapsed(false)
    }
  }, [location.pathname, navItems, sidebarCollapsed, treatAsHrPanel])

  const pageSuggestions = useMemo(() => {
    if (!isHrPanelSearch) return { pages: [], settings: [] }
    if (!trimmedQuery) return { pages: [], settings: [] }
    const q = trimmedQuery.toLowerCase()
    const all = flattenNavItems(navItems || [])
      .filter((it) => (it.label || '').toLowerCase().includes(q))

    const settings = all.filter((it) => it.to.includes('profile') || it.to.includes('schedules') || it.to.includes('departments'))
    const pages = all.filter((it) => !settings.some((s) => s.id === it.id))
    return { pages, settings }
  }, [isHrPanelSearch, navItems, trimmedQuery])

  const flattenedResults = useMemo(() => {
    if (!isHrPanelSearch || !trimmedQuery) return []
    const employees = (employeeResults || []).map((e) => ({
      type: 'employee',
      id: String(e.id),
      label: formatEmployeeName(e, 'Employee'),
      meta: [e.department, e.position, e.email].filter(Boolean).join(' • '),
      to: `${hrPanelPath(hrBasePath, 'employees/list')}?q=${encodeURIComponent(trimmedQuery)}`,
    }))
    return [
      ...pageSuggestions.pages,
      ...pageSuggestions.settings.map((s) => ({ ...s, type: 'setting' })),
      ...employees,
    ]
  }, [isHrPanelSearch, hrBasePath, trimmedQuery, employeeResults, pageSuggestions.pages, pageSuggestions.settings])

  useEffect(() => {
    if (!isHrPanelSearch) return
    if (closeTimeoutRef.current) {
      clearTimeout(closeTimeoutRef.current)
      closeTimeoutRef.current = null
    }
    if (!trimmedQuery) {
      setEmployeeResults([])
      setEmployeesLoading(false)
      setSearchActiveIndex(0)
      return
    }
    // Fetch employee suggestions (debounced) for query >= 2 chars
    if (trimmedQuery.length < 2) {
      setEmployeeResults([])
      setEmployeesLoading(false)
      setSearchActiveIndex(0)
      return
    }
    const seq = ++requestSeqRef.current
    setEmployeesLoading(true)
    const t = setTimeout(async () => {
      try {
        const res = await getEmployees({ q: trimmedQuery, per_page: 5, page: 1 })
        if (requestSeqRef.current !== seq) return
        setEmployeeResults(Array.isArray(res.employees) ? res.employees : [])
      } catch {
        if (requestSeqRef.current !== seq) return
        setEmployeeResults([])
      } finally {
        if (requestSeqRef.current === seq) setEmployeesLoading(false)
      }
    }, 180)
    return () => clearTimeout(t)
  }, [isHrPanelSearch, trimmedQuery])

  useEffect(() => {
    // Keep active index valid when results change
    setSearchActiveIndex((idx) => {
      const max = Math.max(0, flattenedResults.length - 1)
      return Math.min(idx, max)
    })
  }, [flattenedResults.length])

  function handleSelectResult(item) {
    if (!item?.to) return
    setSearchOpen(false)
    setSearchQuery('')
    setEmployeeResults([])
    setEmployeesLoading(false)
    navigate(item.to)
  }

  function handleSearchKeyDown(e) {
    if (!isHrPanelSearch) return
    if (!searchOpen && (e.key === 'ArrowDown' || e.key === 'ArrowUp')) {
      setSearchOpen(true)
      return
    }
    if (e.key === 'Escape') {
      setSearchOpen(false)
      return
    }
    if (e.key === 'ArrowDown') {
      e.preventDefault()
      setSearchActiveIndex((i) => Math.min(i + 1, Math.max(0, flattenedResults.length - 1)))
    } else if (e.key === 'ArrowUp') {
      e.preventDefault()
      setSearchActiveIndex((i) => Math.max(i - 1, 0))
    } else if (e.key === 'Enter') {
      if (!trimmedQuery) return
      e.preventDefault()
      const item = flattenedResults[searchActiveIndex]
      if (item) {
        handleSelectResult(item)
        return
      }
      // Fallback: go to Employees with search query
      handleSelectResult({
        to: `${hrPanelPath(hrBasePath, 'employees/list')}?q=${encodeURIComponent(trimmedQuery)}`,
      })
    }
  }

  return (
    <div className="flex min-h-screen flex-col bg-white dark:bg-background md:flex-row">
      {/* Desktop sidebar – collapsible, no border */}
      <aside
        data-hr-sidebar=""
        className={cn(
          'hidden flex-col border-r border-sidebar-border/70 bg-sidebar text-sidebar-foreground shadow-[4px_0_24px_-18px_rgba(15,23,42,0.18)] transition-[width] duration-200 ease-in-out dark:border-sidebar-border/50 dark:shadow-[4px_0_36px_-18px_rgba(0,0,0,0.5)] md:flex',
          sidebarCollapsed ? 'w-16' : 'w-64'
        )}
      >
        <SidebarContent
          navItems={navItems}
          homePath={homePath}
          profilePath={profilePath}
          user={user}
          currentUserDisplayName={currentUserDisplayName}
          initials={initials}
          onLogout={handleLogout}
          onNavClick={() => {}}
          onNavIntent={handleSidebarNavIntent}
          collapsed={sidebarCollapsed}
          onToggleCollapse={() => setSidebarCollapsed((c) => !c)}
          pathname={location.pathname}
          moduleCounts={notificationModuleCounts}
          navRef={sidebarNavRef}
        />
      </aside>

      {/* @container: layout padding/title use container breakpoints so zoom + expanded sidebar
          (narrow main column) still get “small canvas” styles; viewport-only md:/lg: would misfire. */}
      <div
        className={cn(
          '@container flex min-h-0 flex-1 flex-col min-w-0 w-full bg-white dark:bg-background',
          'dark:dashboard-content-canvas'
        )}
      >
        {/* Mobile: sheet menu — inside content canvas so dark gradient matches the sticky bar */}
        <header
          className={cn(
            'flex h-14 items-center gap-3 border-b border-border/40 bg-white px-3 sm:px-4 dark:bg-card md:hidden',
            'dashboard-header-glass dark:border-border/40 dark:shadow-none'
          )}
        >
        <Sheet open={sheetOpen} onOpenChange={setSheetOpen}>
          <SheetTrigger asChild>
            <Button variant="ghost" size="icon" aria-label="Open menu">
              <Menu className="size-5" />
            </Button>
          </SheetTrigger>
          <SheetContent side="left" className="w-72 max-w-[85vw] p-0" data-hr-sidebar="">
            <SheetHeader className="sr-only">
              <SheetTitle>Menu</SheetTitle>
            </SheetHeader>
            <SidebarContent
              navItems={navItems}
              homePath={homePath}
              profilePath={profilePath}
              user={user}
              currentUserDisplayName={currentUserDisplayName}
              initials={initials}
              onLogout={handleLogout}
              onNavClick={() => setSheetOpen(false)}
              onNavIntent={handleSidebarNavIntent}
              pathname={location.pathname}
              moduleCounts={notificationModuleCounts}
            />
          </SheetContent>
        </Sheet>
        <Link to={homePath} className="flex min-w-0 max-w-[60%] items-center">
          <AgcBrandLogo className="h-8 w-auto max-w-full" />
          <span className="sr-only">HRIS — home</span>
        </Link>
      </header>

        {/* Top bar: toggle + search in one row (min-w-0 keeps Popover/search from collapsing the layout). */}
        <header
          className={cn(
            'sticky top-0 z-10 grid h-16 shrink-0 grid-cols-[minmax(0,1fr)_auto] items-center gap-3 border-b border-border/40 px-3 py-3 shadow-sm shadow-black/4 backdrop-blur-xl @sm:px-4 @lg:px-5',
            /* Light: flat white strip flush with main canvas */
            'bg-white supports-backdrop-filter:bg-white/95',
            /* Dark: no card gradient — glass tint matches .dashboard-content-canvas (see index.css) */
            'dashboard-header-glass dark:border-border/40 dark:shadow-none dark:bg-none dark:backdrop-blur-xl dark:supports-backdrop-filter:bg-transparent'
          )}
        >
          <div className="flex min-h-10 min-w-0 items-center gap-3">
            <Button
              variant="ghost"
              size="icon"
              className="hidden shrink-0 md:flex size-9"
              onClick={() => setSidebarCollapsed((c) => !c)}
              title={sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'}
              aria-label={sidebarCollapsed ? 'Expand sidebar' : 'Collapse sidebar'}
            >
              {sidebarCollapsed ? (
                <PanelLeft className="size-5 text-muted-foreground" />
              ) : (
                <PanelLeftClose className="size-5 text-muted-foreground" />
              )}
            </Button>
            <div className="min-w-0 w-full max-w-md flex-1 @md:max-w-lg">
            {isHrPanelSearch ? (
              <Popover open={searchOpen} onOpenChange={setSearchOpen}>
                <PopoverTrigger asChild>
                  <div className="relative w-full">
                    <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground pointer-events-none" />
                    <Input
                      type="search"
                      placeholder="Search employees, pages..."
                      className="h-10 w-full rounded-md border-border/60 bg-muted/45 pl-9 pr-4 text-sm shadow-inner placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-brand/25"
                      aria-label="Global search"
                      role="combobox"
                      aria-expanded={searchOpen}
                      value={searchQuery}
                      onChange={(e) => {
                        setSearchQuery(e.target.value)
                        setSearchOpen(true)
                      }}
                      onKeyDown={handleSearchKeyDown}
                      onFocus={() => setSearchOpen(true)}
                      onBlur={() => {
                        closeTimeoutRef.current = setTimeout(() => setSearchOpen(false), 120)
                      }}
                    />
                  </div>
                </PopoverTrigger>
                <PopoverContent
                  align="start"
                  sideOffset={8}
                  className="w-[--radix-popover-trigger-width] max-w-[calc(100vw-2rem)] p-0"
                  onOpenAutoFocus={(e) => e.preventDefault()}
                  onInteractOutside={() => setSearchOpen(false)}
                >
                  <div className="max-h-[320px] overflow-auto py-2">
                    {!trimmedQuery ? (
                      <div className="px-3 py-6 text-center text-sm text-muted-foreground">
                        Start typing to search across pages and employees.
                      </div>
                    ) : (
                      <>
                        {(pageSuggestions.pages.length > 0 || pageSuggestions.settings.length > 0) && (
                          <div className="px-3 pb-1 pt-1 text-[11px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">
                            Pages
                          </div>
                        )}
                        {pageSuggestions.pages.map((item) => {
                          const Icon = item.icon
                          const idx = flattenedResults.findIndex((r) => r.id === item.id && r.type === 'page')
                          const active = idx === searchActiveIndex
                          return (
                            <button
                              key={item.id}
                              type="button"
                              className={cn(
                                'flex w-full items-center gap-2 px-3 py-2 text-left text-sm transition-colors',
                                active ? 'bg-muted' : 'hover:bg-muted/60'
                              )}
                              onMouseDown={(e) => e.preventDefault()}
                              onClick={() => handleSelectResult(item)}
                            >
                              {Icon ? <Icon className="size-4 text-muted-foreground" /> : <LayoutDashboard className="size-4 text-muted-foreground" />}
                              <span className="flex-1 truncate">{item.label}</span>
                              <span className="text-xs text-muted-foreground">Go</span>
                            </button>
                          )
                        })}

                        {pageSuggestions.settings.length > 0 && (
                          <div className="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">
                            Settings
                          </div>
                        )}
                        {pageSuggestions.settings.map((item) => {
                          const Icon = item.icon
                          const idx = flattenedResults.findIndex((r) => r.id === item.id && r.type === 'setting')
                          const active = idx === searchActiveIndex
                          return (
                            <button
                              key={item.id}
                              type="button"
                              className={cn(
                                'flex w-full items-center gap-2 px-3 py-2 text-left text-sm transition-colors',
                                active ? 'bg-muted' : 'hover:bg-muted/60'
                              )}
                              onMouseDown={(e) => e.preventDefault()}
                              onClick={() => handleSelectResult(item)}
                            >
                              {Icon ? <Icon className="size-4 text-muted-foreground" /> : <User className="size-4 text-muted-foreground" />}
                              <span className="flex-1 truncate">{item.label}</span>
                              <span className="text-xs text-muted-foreground">Go</span>
                            </button>
                          )
                        })}

                        <div className="px-3 pb-1 pt-2 text-[11px] font-semibold uppercase tracking-[0.12em] text-muted-foreground">
                          Employees
                        </div>
                        {employeesLoading ? (
                          <div className="flex items-center gap-2 px-3 py-2 text-sm text-muted-foreground">
                            <Loader2 className="size-4 animate-spin" />
                            Searching employees…
                          </div>
                        ) : employeeResults.length === 0 ? (
                          <div className="px-3 py-2 text-sm text-muted-foreground">No employee results</div>
                        ) : (
                          employeeResults.map((emp) => {
                            const item = {
                              type: 'employee',
                              id: String(emp.id),
                              label: formatEmployeeName(emp, 'Employee'),
                              meta: [emp.department, emp.position, emp.email].filter(Boolean).join(' • '),
                              to: `${hrPanelPath(hrBasePath, 'employees/list')}?q=${encodeURIComponent(trimmedQuery)}`,
                            }
                            const idx = flattenedResults.findIndex((r) => r.type === 'employee' && r.id === item.id)
                            const active = idx === searchActiveIndex
                            return (
                              <button
                                key={item.id}
                                type="button"
                                className={cn(
                                  'flex w-full flex-col items-start gap-0.5 px-3 py-2 text-left transition-colors',
                                  active ? 'bg-muted' : 'hover:bg-muted/60'
                                )}
                                onMouseDown={(e) => e.preventDefault()}
                                onClick={() => handleSelectResult(item)}
                              >
                                <span className="text-sm font-medium text-foreground">{item.label}</span>
                                {item.meta && (
                                  <span className="text-xs text-muted-foreground">{item.meta}</span>
                                )}
                              </button>
                            )
                          })
                        )}

                        {pageSuggestions.pages.length === 0 &&
                          pageSuggestions.settings.length === 0 &&
                          !employeesLoading &&
                          employeeResults.length === 0 && (
                            <div className="px-3 py-6 text-center text-sm text-muted-foreground">
                              No results found.
                            </div>
                          )}
                      </>
                    )}
                  </div>
                </PopoverContent>
              </Popover>
            ) : (
              <div className="relative w-full">
                <Search className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground pointer-events-none" />
                <Input
                  type="search"
                  placeholder="Search..."
                  className="h-10 w-full rounded-md border-border/60 bg-muted/45 pl-9 pr-4 text-sm shadow-inner placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-brand/25"
                  aria-label="Search"
                />
              </div>
            )}
            </div>
          </div>
          <div className="flex items-center gap-2 shrink-0">
            <Button
              variant="ghost"
              size="icon"
              className="rounded-full"
              onClick={cycleTheme}
              title={
                theme === 'light'
                  ? 'Theme: Light — click for Dark'
                  : 'Theme: Dark — click for Light'
              }
              aria-label="Cycle theme"
            >
              {theme === 'dark' ? (
                <Moon className="size-[18px] text-muted-foreground" />
              ) : (
                <Sun className="size-[18px] text-muted-foreground" />
              )}
            </Button>
            <Popover>
              <PopoverTrigger asChild>
                <Button
                  variant="ghost"
                  size="icon"
                  className="relative rounded-full data-[state=open]:text-foreground data-[state=open]:after:absolute data-[state=open]:after:-bottom-2 data-[state=open]:after:left-1/2 data-[state=open]:after:h-1 data-[state=open]:after:w-9 data-[state=open]:after:-translate-x-1/2 data-[state=open]:after:rounded-full data-[state=open]:after:bg-brand"
                  aria-label="Notifications"
                >
                  <Bell className="size-5 text-foreground" />
                  {notificationCount > 0 && (
                    <span className="absolute -right-1 -top-1 flex size-5 items-center justify-center rounded-full bg-destructive text-[11px] font-bold text-destructive-foreground ring-2 ring-background">
                      {notificationCount > 9 ? '9+' : notificationCount}
                    </span>
                  )}
                </Button>
              </PopoverTrigger>
              <PopoverContent
                align="end"
                collisionPadding={12}
                className="flex w-[min(calc(100vw-1rem),18.75rem)] max-h-[min(calc(100dvh-4.5rem),24rem)] max-w-[calc(100vw-1rem)] flex-col overflow-hidden rounded-2xl border border-border/70 bg-card p-0 text-card-foreground shadow-xl shadow-black/10 ring-1 ring-black/5 sm:w-[min(calc(100vw-1.5rem),22rem)] sm:max-h-[min(calc(100dvh-5rem),28rem)] sm:max-w-none sm:rounded-3xl dark:border-border/60 dark:ring-white/10"
                sideOffset={8}
              >
                <div className="shrink-0 border-b border-border/60 bg-card px-3 pt-3 sm:px-4 sm:pt-4">
                  <div className="flex flex-col gap-2.5 sm:flex-row sm:items-start sm:justify-between sm:gap-3">
                    <div className="flex min-w-0 items-center gap-2.5 sm:items-start sm:gap-3">
                      <span className="flex size-8 shrink-0 items-center justify-center rounded-xl bg-brand/10 text-brand sm:size-10 sm:rounded-2xl">
                        <Bell className="size-4 sm:size-5" />
                      </span>
                      <div className="min-w-0">
                        <h2 id="notifications-popover-title" className="text-base font-extrabold tracking-tight text-foreground sm:text-lg">
                          Notifications
                        </h2>
                        <p className="mt-0.5 hidden text-xs leading-snug text-muted-foreground sm:block">
                          Recent updates from your workspace
                        </p>
                      </div>
                    </div>
                    <div className="flex min-w-0 items-center justify-between gap-2 sm:shrink-0 sm:justify-end">
                      {notificationCount > 0 ? (
                        <span className="inline-flex shrink-0 items-center rounded-lg bg-brand px-2 py-0.5 text-[10px] font-extrabold uppercase tracking-wide text-brand-foreground shadow-sm sm:px-2.5 sm:py-1 sm:text-[11px]">
                          {notificationCount} new
                        </span>
                      ) : null}
                      <button
                        type="button"
                        onClick={markAllNotificationsRead}
                        disabled={notificationCount === 0}
                        className="inline-flex min-w-0 items-center gap-1 rounded-lg px-1.5 py-1 text-[11px] font-semibold text-brand/90 underline-offset-2 transition hover:bg-brand/10 hover:text-brand hover:underline focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/30 disabled:pointer-events-none disabled:text-muted-foreground/70 disabled:no-underline sm:text-xs"
                      >
                        <CheckCheck className="size-3 shrink-0 sm:size-3.5" aria-hidden />
                        <span className="truncate">Mark all read</span>
                      </button>
                    </div>
                  </div>

                  <div
                    className="mt-3 flex gap-1 rounded-full bg-muted/70 p-0.5 dark:bg-muted/40 sm:mt-4 sm:p-1"
                    role="tablist"
                    aria-label="Filter notifications"
                  >
                    {NOTIFICATION_TABS.map((tab) => {
                      const selected = notificationTab === tab.id
                      const tabCount =
                        tab.id === 'unread'
                          ? notificationCount
                          : notifications.length
                      return (
                        <button
                          key={tab.id}
                          type="button"
                          role="tab"
                          aria-selected={selected}
                          onClick={() => setNotificationTab(tab.id)}
                          className={cn(
                            'min-w-0 flex flex-1 items-center justify-center gap-1 rounded-full px-2 py-1.5 text-center text-xs font-semibold transition-colors sm:gap-1.5 sm:px-3 sm:py-2 sm:text-sm',
                            selected
                              ? 'bg-brand text-brand-foreground shadow-md shadow-brand/20'
                              : 'text-muted-foreground hover:bg-muted hover:text-foreground'
                          )}
                        >
                          {tab.label}
                          <span
                            className={cn(
                              'rounded-full px-1 py-0.5 text-[9px] font-bold sm:px-1.5 sm:text-[10px]',
                              selected ? 'bg-brand-foreground/20 text-brand-foreground' : 'bg-background text-muted-foreground'
                            )}
                          >
                            {tabCount}
                          </span>
                        </button>
                      )
                    })}
                  </div>
                </div>

                <div
                  className="min-h-0 flex-1 overflow-y-auto overscroll-contain"
                  role="tabpanel"
                  aria-labelledby="notifications-popover-title"
                >
                  {filteredNotifications.length === 0 ? (
                    <div className="px-3 py-8 text-center text-sm text-muted-foreground sm:px-4 sm:py-10">
                      {notificationTab === 'unread'
                          ? "You're all caught up."
                          : 'No notifications.'}
                    </div>
                  ) : (
                    filteredNotifications.map((n) => {
                      const typeConfig = {
                        attendance: {
                          icon: Clock,
                          iconBg: 'bg-muted text-muted-foreground',
                        },
                        leave: {
                          icon: CalendarClock,
                          iconBg: 'bg-muted text-muted-foreground',
                        },
                        payslip: {
                          icon: Banknote,
                          iconBg: 'bg-muted text-muted-foreground',
                        },
                        mention: {
                          icon: AtSign,
                          iconBg: 'bg-muted text-muted-foreground',
                        },
                      }
                      const config = typeConfig[n.type] ?? {
                        icon: Bell,
                        iconBg: 'bg-muted text-muted-foreground',
                      }
                      const Icon = config.icon

                      return (
                        <button
                          key={n.id}
                          type="button"
                          onClick={() => markNotificationRead(n.id)}
                          onFocus={() => prefetchNotificationTarget(n)}
                          onMouseEnter={() => prefetchNotificationTarget(n)}
                          title={n.actionUrl ? 'Open notification details' : 'Open notification center'}
                          className={cn(
                            'relative flex w-full gap-0 border-b border-border/55 text-left transition-colors hover:bg-muted/40 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/30 focus-visible:ring-offset-2 focus-visible:ring-offset-card dark:border-white/10 dark:hover:bg-muted/20',
                            n.unread ? 'bg-brand/7 dark:bg-brand/10' : 'bg-card'
                          )}
                        >
                          {n.unread ? (
                            <span
                              className="absolute left-0 top-3 bottom-3 w-1 rounded-r-full bg-brand"
                              aria-hidden
                            />
                          ) : null}
                          <div className="flex min-w-0 flex-1 items-start gap-2.5 py-3 pr-3 pl-3 sm:gap-3 sm:py-4 sm:pr-4 sm:pl-4">
                            {n.avatar ? (
                              <Avatar className="size-9 shrink-0 ring-1 ring-border/50 sm:size-11">
                                <AvatarFallback
                                  className={cn(
                                    'rounded-full text-[10px] font-bold sm:text-[11px]',
                                    getEmployeeAvatarColorClass(n.id, n.actor),
                                  )}
                                >
                                  {n.avatar}
                                </AvatarFallback>
                              </Avatar>
                            ) : (
                              <span
                                className={cn(
                                  'flex size-9 shrink-0 items-center justify-center rounded-full ring-1 ring-border/40 sm:size-11',
                                  n.unread ? 'bg-brand/10 text-brand ring-brand/10' : config.iconBg
                                )}
                              >
                                <Icon className="size-4 sm:size-5" strokeWidth={2} aria-hidden />
                              </span>
                            )}
                            <div className="min-w-0 flex-1">
                              <p className="line-clamp-1 text-[13px] font-bold leading-snug text-foreground sm:text-sm">
                                {n.actor}
                              </p>
                              {n.body ? (
                                <p className="mt-0.5 line-clamp-2 text-[12px] leading-snug text-muted-foreground sm:mt-1 sm:text-sm">{n.body}</p>
                              ) : null}
                              {n.detail ? (
                                <p className="mt-0.5 line-clamp-1 text-[11px] italic text-muted-foreground sm:mt-1 sm:text-xs">{n.detail}</p>
                              ) : null}
                              <p className="mt-1.5 text-[11px] text-muted-foreground sm:mt-2 sm:text-xs">{n.time}</p>
                            </div>
                            {n.unread ? (
                              <span
                                className="mt-1 size-2 shrink-0 rounded-full bg-brand sm:mt-1.5 sm:size-2.5"
                                title="Unread"
                                aria-hidden
                              />
                            ) : null}
                          </div>
                        </button>
                      )
                    })
                  )}
                </div>

                <div className="shrink-0 border-t border-border/60 bg-card px-3 py-2.5 sm:px-4 sm:py-3">
                  <button
                    type="button"
                    onClick={() => navigate(role === 'employee' ? '/employee/notifications' : hrPanelPath(hrBasePath, 'notifications'))}
                    className="flex w-full items-center justify-center gap-1.5 rounded-xl border border-transparent py-1.5 text-center text-xs font-semibold text-brand transition hover:border-brand/20 hover:bg-brand/5 hover:text-brand-strong sm:gap-2 sm:text-sm"
                  >
                    <FileText className="size-3.5 sm:size-4" />
                    View all notifications
                    <ChevronRight className="size-3.5 sm:size-4" />
                  </button>
                </div>
              </PopoverContent>
            </Popover>
            <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" className="relative size-9 rounded-full ring-1 ring-border/50">
                <Avatar
                  key={`${user?.id ?? 'u'}-${headerAvatarSrc ?? ''}-${user?.updated_at ?? ''}`}
                  className="size-8 rounded-full shadow-sm ring-2 ring-border/20"
                >
                  {headerAvatarSrc ? (
                    <AvatarImage src={headerAvatarSrc} alt="" className="object-cover" />
                  ) : null}
                  <AvatarFallback
                    className={cn(
                      'rounded-full text-sm font-bold',
                      getEmployeeAvatarColorClass(user?.id, currentUserDisplayName),
                    )}
                  >
                    {initials}
                  </AvatarFallback>
                </Avatar>
              </Button>
            </DropdownMenuTrigger>
            <UserAccountMenuContent
              user={user}
              displayName={currentUserDisplayName}
              initials={initials}
              avatarSrc={headerAvatarSrc}
              homePath={homePath}
              profilePath={profilePath}
              onLogout={handleLogout}
            />
            </DropdownMenu>
          </div>
        </header>

        <main
          className={cn(
            /* Tight horizontal inset so main content sits closer to the sidebar on md+; vertical rhythm unchanged */
            'flex-1 overflow-visible px-3 py-4 @sm:px-4 @md:py-5 @lg:px-5 @lg:py-6',
            isDashboardRoute && 'py-2 @md:py-3 @lg:py-3',
            'bg-white dark:bg-background'
          )}
        >
          <div className="mx-0 w-full min-w-0 max-w-none">
            <div key={location.pathname} className="min-w-0 w-full @container">
              <Outlet />
            </div>
          </div>
        </main>
      </div>
    </div>
  )
}
