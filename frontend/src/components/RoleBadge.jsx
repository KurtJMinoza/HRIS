import { AlertTriangle } from 'lucide-react'
import { cn } from '@/lib/utils'
import { resolveRoleBadgeProps } from '@/lib/roleDisplay' 

const SIZE_CLASS = {
  xs: 'max-w-[200px] truncate px-1.5 py-px text-[10px] leading-tight',
  sm: 'max-w-[220px] truncate px-2 py-0.5 text-[11px] leading-tight',
  md: 'max-w-[240px] truncate px-2.5 py-1 text-xs',
}

/**
 * Role pill aligned with Users & Permissions / API `hr_role` + `hr_role_label`.
 * Pass `user` or explicit `hr_role` / `hr_role_label` (e.g. employee list rows).
 */
export function RoleBadge({ user, hr_role, hr_role_label, management_role, className, size = 'sm' }) {
  const p = resolveRoleBadgeProps({ user, hr_role, hr_role_label, management_role })
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1 rounded-full border font-medium',
        SIZE_CLASS[size] ?? SIZE_CLASS.sm,
        p.className,
        className,
      )}
      title={p.warning ? 'No HR role on record — showing Employee. Ask an administrator to assign a role if this is wrong.' : p.label}
    >
      {p.warning && (
        <AlertTriangle className="size-3 shrink-0 text-amber-600 dark:text-amber-400" aria-hidden />
      )}
      <span className="min-w-0 truncate">{p.label}</span>
      {p.warning && <span className="sr-only">Role assignment may be missing; defaulted to Employee.</span>}
    </span>
  )
}
