import { useSyncExternalStore } from 'react'

const STORAGE_KEY = 'smartdtr_sidebar_collapsed'
const SIDEBAR_WIDTH_COLLAPSED = '4rem'
const SIDEBAR_WIDTH_EXPANDED = '16rem'

function readInitial() {
  try {
    return localStorage.getItem(STORAGE_KEY) === '1'
  } catch {
    return false
  }
}

function sidebarWidthCssValue(isCollapsed = collapsed) {
  return isCollapsed ? SIDEBAR_WIDTH_COLLAPSED : SIDEBAR_WIDTH_EXPANDED
}

function syncSidebarWidthCss(isCollapsed = collapsed) {
  if (typeof document === 'undefined') return
  document.documentElement.style.setProperty('--hr-sidebar-width', sidebarWidthCssValue(isCollapsed))
}

let collapsed = readInitial()
const listeners = new Set()

syncSidebarWidthCss()

function persist() {
  try {
    localStorage.setItem(STORAGE_KEY, collapsed ? '1' : '0')
  } catch {
    // ignore
  }
}

function emit() {
  for (const listener of listeners) {
    listener()
  }
  persist()
  syncSidebarWidthCss()
}

export const sidebarCollapseStore = {
  getSnapshot() {
    return collapsed
  },
  subscribe(listener) {
    listeners.add(listener)
    return () => listeners.delete(listener)
  },
  getCollapsed() {
    return collapsed
  },
  setCollapsed(next) {
    const value = Boolean(next)
    if (value === collapsed) return
    collapsed = value
    emit()
  },
  toggle() {
    collapsed = !collapsed
    emit()
  },
}

export function useSidebarCollapsed() {
  return useSyncExternalStore(
    sidebarCollapseStore.subscribe,
    sidebarCollapseStore.getSnapshot,
    sidebarCollapseStore.getSnapshot,
  )
}
