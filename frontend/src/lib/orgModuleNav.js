import {
  getAreas,
  getBranches,
  getCompanies,
  getDepartments,
  getDivisions,
  getSectionsOrUnits,
} from '@/api'
import { isOrgModulePathname, resolveOrgModuleFromPathname } from '@/lib/orgRoutes'

const ORG_MODULE_PREFETCH = {
  companies: () => getCompanies().catch(() => {}),
  areas: () => getAreas().catch(() => {}),
  branches: () => getBranches().catch(() => {}),
  divisions: () => getDivisions().catch(() => {}),
  departments: () => getDepartments({ fresh: true }).catch(() => {}),
  sections: () => getSectionsOrUnits({ fresh: true }).catch(() => {}),
}

const ORG_MODULE_CHUNK = {
  companies: () => import('@/pages/AdminCompanies'),
  areas: () => import('@/pages/AdminAreas'),
  branches: () => import('@/pages/AdminBranches'),
  divisions: () => import('@/pages/AdminDivisions'),
  departments: () => import('@/pages/AdminDepartments'),
  sections: () => import('@/pages/AdminSectionUnits'),
}

export function isOrgModulePath(path) {
  return isOrgModulePathname(path)
}

export function prefetchOrgModule(path) {
  const module = resolveOrgModuleFromPathname(path)
  if (!module) return
  ORG_MODULE_PREFETCH[module]?.()
  ORG_MODULE_CHUNK[module]?.().catch(() => {})
}
