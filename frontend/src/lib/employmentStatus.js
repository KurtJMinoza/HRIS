/**
 * Canonical employment status labels for non–Admin (HR) viewers.
 * Must match backend {@see EmploymentStatus::normalizeToCanonicalLabel}.
 */
const CANONICAL_BY_KEY = {
  probationary: 'Probationary',
  regular: 'Regular',
  contractual: 'Contractual',
  project_based: 'Project-based',
  consultant: 'Consultant',
  separated: 'Separated',
}

const ALIAS_TO_KEY = {
  probation: 'probationary',
  probational: 'probationary',
  active: 'regular',
  contract: 'contractual',
  project: 'project_based',
  projectbased: 'project_based',
  consultancy: 'consultant',
  inactive: 'separated',
  resigned: 'separated',
  terminated: 'separated',
}

function normalizeEmploymentKey(raw) {
  if (raw == null || raw === '') return null
  return String(raw)
    .trim()
    .toLowerCase()
    .replace(/-/g, '_')
    .replace(/\s+/g, '_')
}

/**
 * @param {string|null|undefined} raw
 * @param {string|null|undefined} label
 * @param {boolean} viewerIsAdminHr
 */
export function formatEmploymentStatusForViewer(raw, label, viewerIsAdminHr) {
  if (viewerIsAdminHr) {
    return label || raw || '—'
  }
  const key = normalizeEmploymentKey(raw)
  if (!key) return label || '—'
  if (ALIAS_TO_KEY[key]) {
    return CANONICAL_BY_KEY[ALIAS_TO_KEY[key]] || '—'
  }
  if (CANONICAL_BY_KEY[key]) {
    return CANONICAL_BY_KEY[key]
  }
  return label || '—'
}

/** Resolve stored value to canonical enum key (probationary, regular, …) or null. */
function resolveCanonicalEmploymentKey(raw) {
  const key = normalizeEmploymentKey(raw)
  if (!key) return null
  if (ALIAS_TO_KEY[key]) return ALIAS_TO_KEY[key]
  if (Object.prototype.hasOwnProperty.call(CANONICAL_BY_KEY, key)) return key
  return null
}

/**
 * Tailwind classes for employment status badges (admin lists, aligned with Leave credits / correction style).
 */
export function employmentStatusBadgeClassName(raw) {
  const canonical = resolveCanonicalEmploymentKey(raw)
  const base =
    'inline-flex max-w-full items-center rounded-full border px-2.5 py-0.5 text-[11px] font-semibold tracking-wide whitespace-nowrap'
  switch (canonical) {
    case 'probationary':
      return `${base} border-amber-500/45 bg-amber-500/12 text-amber-900 dark:border-amber-500/35 dark:bg-amber-500/14 dark:text-amber-200`
    case 'regular':
      return `${base} border-emerald-500/40 bg-emerald-500/12 text-emerald-900 dark:border-emerald-500/35 dark:bg-emerald-500/14 dark:text-emerald-200`
    case 'contractual':
      return `${base} border-sky-500/40 bg-sky-500/12 text-sky-900 dark:border-sky-500/35 dark:bg-sky-500/14 dark:text-sky-200`
    case 'project_based':
      return `${base} border-violet-500/40 bg-violet-500/12 text-violet-900 dark:border-violet-500/35 dark:bg-violet-500/14 dark:text-violet-200`
    case 'consultant':
      return `${base} border-amber-500/45 bg-amber-500/12 text-amber-900 dark:border-amber-500/35 dark:bg-amber-500/14 dark:text-amber-200`
    case 'separated':
      return `${base} border-rose-500/40 bg-rose-500/10 text-rose-950 dark:border-rose-500/35 dark:bg-rose-500/12 dark:text-rose-100`
    default:
      return `${base} border-border/60 bg-muted/50 text-muted-foreground`
  }
}
