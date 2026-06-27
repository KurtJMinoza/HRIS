/** HR panel organization module paths (`/admin/organizations/*`). */
export const ORG_MODULE_SEGMENTS = [
  'companies',
  'areas',
  'branches',
  'divisions',
  'departments',
  'sections',
]

const LEGACY_SECTION_SEGMENT = 'sections-units'

export function orgModulePath(basePath, segment) {
  const base = (basePath || '/admin').replace(/\/$/, '')
  const seg = String(segment || '').replace(/^\//, '')
  return `${base}/organizations/${seg}`
}

export function resolveOrgModuleFromPathname(pathname) {
  if (!pathname || typeof pathname !== 'string') return null
  const normalized = pathname.replace(/\/$/, '')
  for (const segment of ORG_MODULE_SEGMENTS) {
    if (normalized.endsWith(`/organizations/${segment}`)) return segment
  }
  if (normalized.endsWith(`/${LEGACY_SECTION_SEGMENT}`)) return 'sections'
  for (const segment of ORG_MODULE_SEGMENTS) {
    if (normalized === `/admin/${segment}` || normalized.endsWith(`/admin/${segment}`)) return segment
  }
  if (normalized.endsWith(`/admin/${LEGACY_SECTION_SEGMENT}`)) return 'sections'
  return null
}

export function isOrgModulePathname(pathname) {
  return resolveOrgModuleFromPathname(pathname) != null
}
