import { profileImageUrl, userProfileImageSrc } from '@/api'

/** Matches Admin → Employees list: deterministic pastel chip per employee. */
const AVATAR_COLORS = [
  'bg-sky-500/20 text-sky-800 dark:bg-sky-400/25 dark:text-sky-100',
  'bg-violet-500/20 text-violet-800 dark:bg-violet-400/25 dark:text-violet-100',
  'bg-emerald-500/20 text-emerald-700 dark:bg-emerald-400/25 dark:text-emerald-200',
  'bg-amber-500/20 text-amber-700 dark:bg-amber-400/25 dark:text-amber-200',
  'bg-rose-500/20 text-rose-700 dark:bg-rose-400/25 dark:text-rose-200',
  'bg-cyan-500/20 text-cyan-700 dark:bg-cyan-400/25 dark:text-cyan-200',
  'bg-orange-500/20 text-orange-700 dark:bg-orange-400/25 dark:text-orange-200',
  'bg-fuchsia-500/20 text-fuchsia-700 dark:bg-fuchsia-400/25 dark:text-fuchsia-200',
]

/**
 * Tailwind classes for AvatarFallback — same hash as AdminEmployees `getAvatarColor`.
 * @param {number|string|null|undefined} id
 * @param {string|null|undefined} name
 */
export function getEmployeeAvatarColorClass(id, name) {
  let h = typeof id === 'number' ? id : 0
  const s = `${id ?? ''}-${name ?? ''}`
  for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) | 0
  return AVATAR_COLORS[Math.abs(h) % AVATAR_COLORS.length]
}

/**
 * Resolved image URL for avatars: prefers `profile_image_url`, then storage path in `profile_image`.
 * @param {{ profile_image?: string | null, profile_image_url?: string | null } | null | undefined} user
 */
export function employeeAvatarSrc(user) {
  return userProfileImageSrc(user)
}

/**
 * Kiosk attendance success modal / feed: same resolution as Employee Profile — `profile_image_url`
 * plus optional storage `profile_image` path. Normalizes relative URLs via `profileImageUrl`
 * (matches Admin Dashboard / media proxy behavior).
 *
 * @param {{ employeeProfileImageUrl?: string | null, employeeProfileImage?: string | null } | null | undefined} row
 * @returns {string | undefined}
 */
export function kioskAttendanceAvatarSrc(row) {
  if (!row || typeof row !== 'object') return undefined
  const userLike = {
    profile_image_url: row.employeeProfileImageUrl ?? null,
    profile_image: row.employeeProfileImage ?? null,
  }
  const raw = userProfileImageSrc(userLike)
  if (raw == null || typeof raw !== 'string') return undefined
  const t = raw.trim()
  if (!t) return undefined
  return profileImageUrl(t) || t
}
