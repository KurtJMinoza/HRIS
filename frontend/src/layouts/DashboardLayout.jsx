import { useState, useEffect, useMemo, useRef } from 'react'
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
import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import { Badge } from '@/components/ui/badge'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover'
import { useAuth } from '@/contexts/AuthContext'
import { useTheme } from '@/contexts/useTheme'
import { cn } from '@/lib/utils'
import { hrPanelPath } from '@/lib/hrRoutes'
import { RoleBadge } from '@/components/RoleBadge'
import { getEmployees } from '@/api'
import { getEmployeeAvatarColorClass } from '@/lib/employeeAvatar'
import { AgcBrandLogo } from '@/components/AgcBrandLogo'
import { Bell, CalendarClock, Banknote, ChevronDown, ChevronRight, Clock, Eye, LayoutDashboard, LogOut, Menu, PanelLeftClose, PanelLeft, Search, Settings, User, Loader2, Sun, Moon } from 'lucide-react'

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
    title: 'Attendance recorded',
    body: 'Your attendance for today has been recorded.',
    time: 'Just now',
    detail: '8:02 AM',
    unread: true,
    type: 'attendance',
  },
  {
    id: '2',
    title: 'Leave request submitted',
    body: 'Your leave request is pending approval.',
    time: '2h ago',
    unread: true,
    type: 'leave',
  },
  {
    id: '3',
    title: 'Payslip available',
    body: 'Your payslip for January 2026 is ready.',
    time: '1d ago',
    unread: false,
    type: 'payslip',
  },
]

function SidebarContent({
  navItems,
  homePath,
  profilePath,
  user,
  initials,
  onLogout,
  onNavClick,
  collapsed,
  onToggleCollapse,
  pathname,
  role,
}) {
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

  function renderItem(item, depth = 0, parentKey = '') {
    const key = getItemKey(item, parentKey)
    const children = Array.isArray(item?.children) ? item.children : []
    const hasChildren = children.length > 0
    const active = isItemActive(item, pathname) || hasActiveDescendant(item, pathname)
    const leafTo = getFirstLeafTo(item)

    if (collapsed) {
      const to = item.to || leafTo
      if (!to) return null
      return (
        <Link
          key={key}
          to={to}
          className={cn(
            'mx-auto flex size-10 items-center justify-center rounded-xl text-sm font-medium transition-all duration-200',
            active
              ? 'bg-orange-50 text-orange-600 ring-1 ring-orange-100 dark:bg-orange-500/15 dark:text-orange-300 dark:ring-orange-500/25'
              : 'text-muted-foreground hover:bg-sidebar-accent hover:text-foreground'
          )}
          onClick={onNavClick}
          title={item.label}
        >
          {item.icon && <item.icon className="size-5 shrink-0" />}
        </Link>
      )
    }

    if (hasChildren) {
      const isOpen = manualExpanded[key] ?? !!autoExpanded[key]
      return (
        <div key={key} className="space-y-1">
          <button
            type="button"
            className={cn(
              'flex w-full items-center rounded-xl px-3 py-2.5 text-left text-sm font-medium transition-all duration-200',
              depth > 0 && 'ml-4',
              active
                ? 'bg-orange-50 text-orange-600 dark:bg-orange-500/15 dark:text-orange-300'
                : 'text-muted-foreground hover:bg-sidebar-accent hover:text-foreground'
            )}
            onClick={() => setManualExpanded((prev) => ({ ...prev, [key]: !isOpen }))}
          >
            {item.icon ? <item.icon className="mr-3 size-5 shrink-0" /> : <span className="mr-3 inline-block size-5 shrink-0" />}
            <span className="flex-1 truncate">{item.label}</span>
            {isOpen ? <ChevronDown className="size-4 shrink-0" /> : <ChevronRight className="size-4 shrink-0" />}
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
        className={({ isActive }) =>
          cn(
            'flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium transition-all duration-200',
            isActive
              ? 'bg-orange-50 text-orange-600 shadow-sm ring-1 ring-orange-100 dark:bg-orange-500/15 dark:text-orange-300 dark:ring-orange-500/25'
              : 'border-l-2 border-transparent text-muted-foreground hover:bg-sidebar-accent hover:text-foreground'
          )
        }
        onClick={onNavClick}
        style={depth > 0 ? { paddingLeft: `${12 + depth * 16}px` } : undefined}
      >
        {item.icon ? <item.icon className="size-4 shrink-0" /> : <span className="inline-block size-4 shrink-0" />}
        <span className="truncate">{item.label}</span>
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
      <nav className={cn('flex flex-1 flex-col gap-1 p-3', collapsed && 'px-2')}>
        {navItems.map((item) => renderItem(item))}
      </nav>
      <div className={cn('border-t border-border/40 p-2', collapsed ? 'space-y-1' : 'space-y-2')}>
        {collapsed ? (
          <div className="space-y-1">
            <Link
              to={profilePath}
              className="mx-auto flex size-10 items-center justify-center rounded-xl text-muted-foreground transition-colors hover:bg-sidebar-accent hover:text-foreground"
              onClick={onNavClick}
              title="Profile"
            >
              <User className="size-5" />
            </Link>
            <button
              type="button"
              className="mx-auto flex size-10 items-center justify-center rounded-xl text-muted-foreground transition-colors hover:bg-sidebar-accent hover:text-destructive"
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
                className="w-full rounded-xl border border-border/60 bg-background/70 p-2 text-left transition-colors hover:bg-muted/40"
              >
                <div className="flex items-center gap-2">
                  <Avatar key={user?.id ?? 'sidebar-u'} className="size-8 rounded-full ring-1 ring-border/40">
                    <AvatarFallback
                      className={cn(
                        'rounded-full text-xs font-bold',
                        getEmployeeAvatarColorClass(user?.id, user?.name),
                      )}
                    >
                      {initials}
                    </AvatarFallback>
                  </Avatar>
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-xs font-semibold text-foreground">{user?.name ?? 'User'}</p>
                    <p className="truncate text-[11px] text-muted-foreground">{user?.email ?? ''}</p>
                  </div>
                  <ChevronDown className="size-4 text-muted-foreground" />
                </div>
              </button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" side="top" className="w-56">
              <DropdownMenuLabel>
                <div className="flex flex-col">
                  <span>{user?.name ?? 'User'}</span>
                  <span className="text-xs font-normal text-muted-foreground">{user?.email}</span>
                  <span className="mt-1">
                    <RoleBadge user={user} size="sm" />
                  </span>
                </div>
              </DropdownMenuLabel>
              <DropdownMenuSeparator />
              <DropdownMenuItem asChild>
                <Link to={homePath} onClick={onNavClick}>
                  <LayoutDashboard className="mr-2 size-4" />
                  Dashboard
                </Link>
              </DropdownMenuItem>
              <DropdownMenuItem asChild>
                <Link to={profilePath} onClick={onNavClick}>
                  <User className="mr-2 size-4" />
                  Profile
                </Link>
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuItem
                onClick={async () => {
                  await onLogout?.()
                  onNavClick?.()
                }}
                className="text-destructive focus:text-destructive"
              >
                <LogOut className="mr-2 size-4" />
                Log out
              </DropdownMenuItem>
            </DropdownMenuContent>
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
  const location = useLocation()
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const [sheetOpen, setSheetOpen] = useState(false)
  const [sidebarCollapsed, setSidebarCollapsed] = useState(() => {
    try {
      return localStorage.getItem(SIDEBAR_COLLAPSED_KEY) === '1'
    } catch {
      return false
    }
  })
  const [notifications, setNotifications] = useState(MOCK_NOTIFICATIONS)

  useEffect(() => {
    try {
      localStorage.setItem(SIDEBAR_COLLAPSED_KEY, sidebarCollapsed ? '1' : '0')
    } catch {
      // ignore
    }
  }, [sidebarCollapsed])

  async function handleLogout() {
    await logout()
    navigate('/login', { replace: true })
  }

  const initials = user?.name
    ? user.name
        .trim()
        .split(/\s+/)
        .map((n) => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2)
    : '?'

  const notificationCount = notifications.filter((n) => n.unread).length
  const { theme, cycleTheme } = useTheme()

  const homePath =
    role === 'employee' ? '/employee/dashboard' : hrPanelPath(hrBasePath, 'dashboard')
  const profilePath =
    role === 'employee' ? '/employee/profile' : hrPanelPath(hrBasePath, 'profile')

  const treatAsHrPanel = role === 'admin' || role === 'manager'

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

  const trimmedQuery = searchQuery.trim()

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
      label: e.name ?? 'Employee',
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

  function markAllNotificationsDone() {
    setNotifications((prev) => prev.map((n) => ({ ...n, unread: false })))
  }

  return (
    <div className="flex min-h-screen flex-col bg-white dark:bg-background md:flex-row">
      {/* Desktop sidebar – collapsible, no border */}
      <aside
        className={cn(
          'hidden flex-col border-r border-sidebar-border/70 bg-linear-to-b from-sidebar via-sidebar to-sidebar/95 text-sidebar-foreground shadow-[4px_0_32px_-16px_rgba(15,23,42,0.12)] transition-[width] duration-200 ease-in-out dark:border-sidebar-border/50 dark:shadow-[4px_0_40px_-12px_rgba(0,0,0,0.45)] md:flex',
          sidebarCollapsed ? 'w-16' : 'w-64'
        )}
      >
        <SidebarContent
          navItems={navItems}
          homePath={homePath}
          profilePath={profilePath}
          user={user}
          initials={initials}
          onLogout={handleLogout}
          onNavClick={() => {}}
          collapsed={sidebarCollapsed}
          onToggleCollapse={() => setSidebarCollapsed((c) => !c)}
          pathname={location.pathname}
          role={role}
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
          <SheetContent side="left" className="w-64 p-0">
            <SheetHeader className="sr-only">
              <SheetTitle>Menu</SheetTitle>
            </SheetHeader>
            <SidebarContent
              navItems={navItems}
              homePath={homePath}
              profilePath={profilePath}
              user={user}
              initials={initials}
              onLogout={handleLogout}
              onNavClick={() => setSheetOpen(false)}
              pathname={location.pathname}
              role={role}
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
            'sticky top-0 z-10 grid h-16 shrink-0 grid-cols-[minmax(0,1fr)_auto] items-center gap-3 border-b border-border/50 px-3 py-3 shadow-sm shadow-black/5 backdrop-blur-xl @sm:px-4 @lg:px-5',
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
                      className="h-10 w-full rounded-xl border-border/60 bg-muted/40 pl-9 pr-4 text-sm shadow-inner placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-teal-500/25"
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
                              label: emp.name ?? 'Employee',
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
                  className="h-10 w-full rounded-xl border-border/60 bg-muted/40 pl-9 pr-4 text-sm shadow-inner placeholder:text-muted-foreground focus-visible:ring-2 focus-visible:ring-teal-500/25"
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
                className="relative w-[min(92vw,46rem)] overflow-hidden rounded-2xl border border-border/80 bg-card p-0 text-card-foreground shadow-[0_24px_70px_-28px_rgba(15,23,42,0.55)] ring-1 ring-black/5 dark:border-white/10 dark:bg-card dark:shadow-[0_24px_70px_-26px_rgba(0,0,0,0.85)] dark:ring-white/5"
                sideOffset={14}
              >
                <span className="absolute -top-2 right-16 size-4 rotate-45 border-l border-t border-border/80 bg-card dark:border-white/10" aria-hidden />

                <div className="flex flex-col gap-5 border-b border-border/70 bg-card px-7 py-7 sm:flex-row sm:items-center sm:justify-between sm:px-9">
                  <div className="flex min-w-0 items-center gap-5">
                    <span className="flex size-16 shrink-0 items-center justify-center rounded-full bg-brand/10 text-brand dark:bg-brand/15">
                      <Bell className="size-8" strokeWidth={2.2} aria-hidden />
                    </span>
                    <div className="min-w-0">
                      <h2 className="text-2xl font-bold tracking-tight text-foreground">Notifications</h2>
                      <p className="mt-1 text-lg text-muted-foreground">Stay up to date with activity.</p>
                    </div>
                  </div>
                  <div className="flex shrink-0 items-center gap-4 self-start sm:self-center">
                    {notificationCount > 0 && (
                      <>
                        <button
                          type="button"
                          onClick={markAllNotificationsDone}
                          className="rounded-lg px-2 py-1 text-lg font-bold text-brand transition-colors duration-200 hover:bg-brand/10 hover:text-brand-strong"
                        >
                          Mark all as done
                        </button>
                        <Badge className="rounded-xl border border-brand/10 bg-brand/10 px-4 py-2 text-lg font-bold text-brand shadow-none hover:bg-brand/10 dark:border-brand/20 dark:bg-brand/15">
                          {notificationCount} new
                        </Badge>
                      </>
                    )}
                  </div>
                </div>
                <div className="max-h-[min(58vh,32rem)] overflow-y-auto">
                  {notifications.length === 0 ? (
                    <div className="px-8 py-12 text-center text-sm text-muted-foreground">
                      You&apos;re all caught up.
                    </div>
                  ) : (
                    notifications.map((n) => {
                      const typeConfig = {
                        attendance: {
                          rail: 'bg-brand',
                          dot: 'bg-brand',
                          icon: Clock,
                          iconBg: 'bg-brand/10 text-brand dark:bg-brand/15',
                          rowBg: 'bg-brand/[0.04] dark:bg-brand/[0.08]',
                          badge: 'New',
                        },
                        leave: {
                          rail: 'bg-amber-500',
                          dot: 'bg-amber-500',
                          icon: CalendarClock,
                          iconBg: 'bg-amber-500/10 text-amber-600 dark:bg-amber-500/15 dark:text-amber-300',
                          rowBg: 'bg-card',
                        },
                        payslip: {
                          rail: 'bg-emerald-500',
                          dot: 'bg-emerald-500',
                          icon: Banknote,
                          iconBg: 'bg-emerald-500/10 text-emerald-600 dark:bg-emerald-500/15 dark:text-emerald-300',
                          rowBg: 'bg-card',
                        },
                      }
                      const config = typeConfig[n.type] ?? {
                        rail: 'bg-muted-foreground',
                        dot: 'bg-muted-foreground',
                        icon: Bell,
                        iconBg: 'bg-muted text-muted-foreground',
                        rowBg: 'bg-card',
                      }
                      const Icon = config.icon
                      return (
                        <button
                          key={n.id}
                          type="button"
                          className={cn(
                            'group relative grid w-full grid-cols-[auto_1fr_auto] items-center gap-5 border-b border-border/60 px-7 py-7 text-left transition-colors hover:bg-muted/45 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand/30 dark:border-white/10 dark:hover:bg-muted/20 sm:px-9',
                            n.unread ? config.rowBg : 'bg-card'
                          )}
                        >
                          <span className={cn('absolute left-0 top-3 bottom-3 w-1.5 rounded-r-full', config.rail)} aria-hidden />
                          <div className="relative shrink-0">
                            <div
                              className={cn(
                                'flex size-16 items-center justify-center rounded-full',
                                config.iconBg
                              )}
                            >
                              <Icon className="size-8" strokeWidth={2.2} aria-hidden />
                            </div>
                            {n.unread && (
                              <span
                                className={cn(
                                  'absolute -right-1 -top-1 inline-flex size-3 rounded-full ring-4 ring-card',
                                  config.dot
                                )}
                              />
                            )}
                          </div>
                          <div className="min-w-0">
                            <h3 className="truncate text-xl font-bold tracking-tight text-foreground">{n.title}</h3>
                            <p className="mt-1 line-clamp-2 text-lg leading-snug text-muted-foreground">{n.body}</p>
                            {n.detail ? (
                              <span className="mt-3 inline-flex items-center gap-2 rounded-lg border border-border/70 bg-background px-3 py-1 text-sm font-medium text-muted-foreground shadow-sm dark:border-white/10 dark:bg-background/40">
                                <Clock className="size-4" aria-hidden />
                                {n.detail}
                              </span>
                            ) : null}
                          </div>
                          <div className="flex shrink-0 items-center gap-5">
                            <div className="hidden min-w-20 flex-col items-end gap-3 sm:flex">
                              {n.unread && config.badge ? (
                                <span className="rounded-xl bg-brand/10 px-4 py-2 text-lg font-bold text-brand dark:bg-brand/15">
                                  {config.badge}
                                </span>
                              ) : null}
                              <span className="text-lg font-medium text-muted-foreground">{n.time}</span>
                            </div>
                            <ChevronRight className="size-7 text-muted-foreground transition-transform group-hover:translate-x-0.5" aria-hidden />
                          </div>
                        </button>
                      )
                    })
                  )}
                </div>
                <div className="border-t border-border/70 bg-card px-7 py-5 sm:px-9">
                  <button
                    type="button"
                    className="mx-auto flex items-center justify-center gap-5 rounded-xl px-5 py-3 text-lg font-semibold text-foreground transition hover:bg-brand/10"
                  >
                    <span className="flex size-12 items-center justify-center rounded-xl bg-brand/10 text-brand dark:bg-brand/15">
                      <Eye className="size-6" aria-hidden />
                    </span>
                    View all notifications
                    <span className="text-brand">
                      <ChevronRight className="size-7" aria-hidden />
                    </span>
                  </button>

                  <button
                    type="button"
                    className="mt-4 grid w-full grid-cols-[auto_1fr_auto] items-center gap-5 rounded-xl bg-muted/40 px-5 py-5 text-left transition hover:bg-muted/60 dark:bg-muted/20 dark:hover:bg-muted/30"
                  >
                    <Settings className="size-8 text-muted-foreground" aria-hidden />
                    <span>
                      <span className="block text-lg font-bold text-foreground">Notification settings</span>
                      <span className="mt-1 block text-base text-muted-foreground">Manage how you receive notifications.</span>
                    </span>
                    <ChevronRight className="size-6 text-muted-foreground" aria-hidden />
                  </button>
                </div>
              </PopoverContent>
            </Popover>
            <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" className="relative size-9 rounded-full ring-1 ring-border/50">
                <Avatar key={user?.id ?? 'u'} className="size-8 rounded-full shadow-sm ring-2 ring-border/20">
                  <AvatarFallback
                    className={cn(
                      'rounded-full text-sm font-bold',
                      getEmployeeAvatarColorClass(user?.id, user?.name),
                    )}
                  >
                    {initials}
                  </AvatarFallback>
                </Avatar>
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-56">
              <DropdownMenuLabel>
                <div className="flex flex-col">
                  <span>{user?.name ?? 'User'}</span>
                  <span className="text-xs font-normal text-muted-foreground">{user?.email}</span>
                  <span className="mt-1">
                    <RoleBadge user={user} size="sm" />
                  </span>
                </div>
              </DropdownMenuLabel>
              <DropdownMenuSeparator />
              <DropdownMenuItem asChild>
                <Link to={homePath}>
                  <LayoutDashboard className="mr-2 size-4" />
                  Dashboard
                </Link>
              </DropdownMenuItem>
              <DropdownMenuItem asChild>
                <Link to={profilePath}>
                  <User className="mr-2 size-4" />
                  Profile
                </Link>
              </DropdownMenuItem>
              <DropdownMenuSeparator />
              <DropdownMenuItem onClick={handleLogout} className="text-destructive focus:text-destructive">
                <LogOut className="mr-2 size-4" />
                Log out
              </DropdownMenuItem>
            </DropdownMenuContent>
            </DropdownMenu>
          </div>
        </header>

        <main
          className={cn(
            /* Tight horizontal inset so main content sits closer to the sidebar on md+; vertical rhythm unchanged */
            'flex-1 px-3 py-4 @sm:px-4 @md:py-5 @lg:px-5 @lg:py-6',
            isDashboardRoute && 'py-4 @md:py-5 @lg:py-6',
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
