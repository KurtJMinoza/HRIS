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
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
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
import { useTheme } from '@/contexts/ThemeContext'
import { cn } from '@/lib/utils'
import { hrPanelPath } from '@/lib/hrRoutes'
import { RoleBadge } from '@/components/RoleBadge'
import { getEmployees } from '@/api'
import { employeeAvatarSrc, getEmployeeAvatarColorClass } from '@/lib/employeeAvatar'
import { Bell, CalendarClock, Banknote, ChevronDown, ChevronRight, Clock, LayoutDashboard, LogOut, Menu, PanelLeftClose, PanelLeft, Search, User, Loader2, Sun, Moon } from 'lucide-react'

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
    body: 'Time in: 8:02 AM',
    time: 'Just now',
    unread: true,
    type: 'attendance',
  },
  {
    id: '2',
    title: 'Leave request submitted',
    body: 'Pending approval',
    time: '2h ago',
    unread: true,
    type: 'leave',
  },
  {
    id: '3',
    title: 'Payslip available',
    body: 'January 2026',
    time: '1d ago',
    unread: false,
    type: 'payslip',
  },
]

function SidebarContent({ navItems, homePath, onNavClick, collapsed, onToggleCollapse, pathname }) {
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
              ? 'bg-black text-white ring-1 ring-black/20 dark:bg-black dark:text-white dark:ring-white/15'
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
                ? 'bg-black text-white dark:bg-black dark:text-white'
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
              ? 'border-l-2 border-white bg-black text-white shadow-sm dark:bg-black dark:text-white'
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
      <div className={cn('flex h-16 items-center border-b border-border/40', collapsed ? 'justify-center px-0' : 'px-4')}>
        <Link
          to={homePath}
          className={cn('flex items-center font-semibold tracking-tight text-foreground', collapsed ? 'gap-0' : 'gap-2')}
          title={collapsed ? 'SmartDTR' : undefined}
        >
          <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-primary text-sm font-bold text-primary-foreground">
            DTR
          </span>
          {!collapsed && <span className="truncate">SmartDTR</span>}
        </Link>
      </div>
      <nav className={cn('flex flex-1 flex-col gap-1 p-3', collapsed && 'px-2')}>
        {navItems.map((item) => renderItem(item))}
      </nav>
      <div className={cn('border-t border-border/40 p-2', collapsed ? 'space-y-1' : 'space-y-2')}>
        {onToggleCollapse && (
          <Button
            variant="ghost"
            size="icon"
            className={cn('w-full', collapsed && 'mx-auto size-9')}
            onClick={onToggleCollapse}
            title={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}
          >
            {collapsed ? <PanelLeft className="size-5" /> : <PanelLeftClose className="size-5" />}
          </Button>
        )}
        {collapsed ? (
          <p className="text-center text-[10px] text-muted-foreground" title="SmartDTR v1.0">v1</p>
        ) : (
          <p className="px-3 text-xs text-muted-foreground">SmartDTR v1.0</p>
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
    <div className="flex min-h-screen flex-col bg-background md:flex-row">
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
          onNavClick={() => {}}
          collapsed={sidebarCollapsed}
          onToggleCollapse={() => setSidebarCollapsed((c) => !c)}
          pathname={location.pathname}
        />
      </aside>

      {/* @container: layout padding/title use container breakpoints so zoom + expanded sidebar
          (narrow main column) still get “small canvas” styles; viewport-only md:/lg: would misfire. */}
      <div
        className={cn(
          '@container flex min-h-0 flex-1 flex-col min-w-0 w-full',
          'dark:dashboard-content-canvas'
        )}
      >
        {/* Mobile: sheet menu — inside content canvas so dark gradient matches the sticky bar */}
        <header
          className={cn(
            'flex h-14 items-center gap-3 border-b border-border/40 bg-card px-3 sm:px-4 md:hidden',
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
              onNavClick={() => setSheetOpen(false)}
              pathname={location.pathname}
            />
          </SheetContent>
        </Sheet>
        <Link to={homePath} className="flex items-center gap-2 font-semibold">
          <span className="flex size-8 items-center justify-center rounded-xl bg-linear-to-br from-teal-600 to-teal-700 text-xs font-bold text-white shadow-md ring-1 ring-white/15 dark:from-teal-500 dark:to-teal-800">
            DTR
          </span>
          SmartDTR
        </Link>
      </header>

        {/* Top bar: toggle + search in one row (min-w-0 keeps Popover/search from collapsing the layout). */}
        <header
          className={cn(
            'sticky top-0 z-10 grid h-16 shrink-0 grid-cols-[minmax(0,1fr)_auto] items-center gap-3 border-b border-border/50 px-3 py-3 shadow-sm shadow-black/5 backdrop-blur-xl @sm:px-4 @lg:px-5',
            /* Light: elevated card strip */
            'bg-linear-to-b from-card/98 to-card/90 supports-backdrop-filter:bg-card/80',
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
                <Button variant="ghost" size="icon" className="relative rounded-full" aria-label="Notifications">
                  <Bell className="size-5 text-muted-foreground" />
                  {notificationCount > 0 && (
                    <span className="absolute -right-0.5 -top-0.5 flex size-4 items-center justify-center rounded-full bg-destructive text-[10px] font-bold text-destructive-foreground">
                      {notificationCount > 9 ? '9+' : notificationCount}
                    </span>
                  )}
                </Button>
              </PopoverTrigger>
              <PopoverContent
                align="end"
                className="w-80 overflow-hidden rounded-xl border border-border bg-card p-0 shadow-[0_20px_40px_-12px_rgba(0,0,0,0.12),inset_0_1px_0_0_rgba(255,255,255,0.05)] dark:shadow-[0_20px_40px_-12px_rgba(0,0,0,0.4),inset_0_1px_0_0_rgba(255,255,255,0.03)] ring-1 ring-black/5 dark:ring-white/5"
                sideOffset={8}
              >
                <div className="flex items-center justify-between gap-3 border-b border-border bg-muted/50 px-4 py-2.5">
                  <div className="flex min-w-0 flex-col gap-0.5">
                    <span className="text-[15px] font-bold tracking-tight">Notifications</span>
                    <span className="text-[11px] text-muted-foreground">
                      Stay up to date with activity.
                    </span>
                  </div>
                  <div className="flex shrink-0 items-center gap-2">
                    {notificationCount > 0 && (
                      <>
                        <button
                          type="button"
                          onClick={markAllNotificationsDone}
                          className="text-[11px] font-medium text-muted-foreground transition-colors duration-200 hover:text-foreground"
                        >
                          Mark all done
                        </button>
                        <Badge variant="secondary" className="text-[11px] font-semibold">
                          {notificationCount} new
                        </Badge>
                      </>
                    )}
                  </div>
                </div>
                <div className="max-h-[300px] space-y-px overflow-y-auto py-1">
                  {notifications.length === 0 ? (
                    <div className="px-4 py-5 text-center text-xs text-muted-foreground">
                      You&apos;re all caught up.
                    </div>
                  ) : (
                    notifications.map((n) => {
                      const typeConfig = {
                        attendance: {
                          dot: 'bg-blue-500',
                          accent: 'border-l-blue-500',
                          icon: Clock,
                          iconBg: 'bg-blue-500/15 text-blue-600 dark:text-blue-400',
                        },
                        leave: {
                          dot: 'bg-amber-500',
                          accent: 'border-l-amber-500',
                          icon: CalendarClock,
                          iconBg: 'bg-amber-500/15 text-amber-600 dark:text-amber-400',
                        },
                        payslip: {
                          dot: 'bg-emerald-500',
                          accent: 'border-l-emerald-500',
                          icon: Banknote,
                          iconBg: 'bg-emerald-500/15 text-emerald-600 dark:text-emerald-400',
                        },
                      }
                      const config = typeConfig[n.type] ?? {
                        dot: 'bg-muted-foreground',
                        accent: 'border-l-muted-foreground',
                        icon: Bell,
                        iconBg: 'bg-muted text-muted-foreground',
                      }
                      const Icon = config.icon
                      return (
                        <div
                          key={n.id}
                          className={cn(
                            'flex w-full items-start gap-3 border-l-2 border-transparent px-3 py-2 text-left text-sm transition-all duration-200',
                            'hover:bg-muted hover:shadow-sm hover:-translate-y-0.5 focus-within:bg-muted/90',
                            n.unread ? 'bg-primary/5' : 'bg-transparent',
                            config.accent
                          )}
                        >
                          <div className="relative mt-0.5 shrink-0">
                            <div
                              className={cn(
                                'flex size-9 items-center justify-center rounded-full',
                                config.iconBg
                              )}
                            >
                              <Icon className="size-4" />
                            </div>
                            {n.unread && (
                              <span
                                className={cn(
                                  'absolute -right-0.5 -top-0.5 inline-flex size-2 rounded-full ring-2 ring-background',
                                  config.dot
                                )}
                              />
                            )}
                          </div>
                          <div className="flex min-w-0 flex-1 flex-col gap-1">
                            <div className="flex items-start justify-between gap-2">
                              <div className="min-w-0">
                                <p className="truncate text-[13px] font-medium">
                                  {n.title}
                                </p>
                                <p className="truncate text-[12px] text-muted-foreground">
                                  {n.body}
                                </p>
                              </div>
                              <span className="shrink-0 text-[11px] font-medium text-foreground/70">
                                {n.time}
                              </span>
                            </div>
                          </div>
                        </div>
                      )
                    })
                  )}
                </div>
                <div className="flex border-t border-border px-3 py-1.5">
                  <Button
                    variant="ghost"
                    size="sm"
                    className="h-8 flex-1 text-[11px] font-medium text-muted-foreground transition-colors duration-200 hover:bg-muted/70 hover:text-foreground"
                  >
                    View all
                  </Button>
                </div>
              </PopoverContent>
            </Popover>
            <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" className="relative size-9 rounded-full ring-1 ring-border/50">
                <Avatar key={user?.id ?? 'u'} className="size-8 rounded-full shadow-sm ring-2 ring-border/20">
                  <AvatarImage src={employeeAvatarSrc(user) || undefined} alt="" className="object-cover" />
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
            'bg-background'
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
