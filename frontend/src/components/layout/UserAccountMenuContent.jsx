import { Link } from 'react-router-dom'
import { LayoutDashboard, LogOut, User } from 'lucide-react'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import {
  DropdownMenuContent,
  DropdownMenuItem,
} from '@/components/ui/dropdown-menu'
import { RoleBadge } from '@/components/RoleBadge'
import { cn } from '@/lib/utils'
import { getEmployeeAvatarColorClass } from '@/lib/employeeAvatar'

const menuItemClass =
  'gap-2.5 rounded-lg px-2.5 py-2 text-sm font-medium text-foreground focus:bg-muted/70 data-[variant=destructive]:focus:bg-destructive/10'

export function UserAccountMenuContent({
  align = 'end',
  side,
  sideOffset = 8,
  user,
  displayName,
  initials,
  avatarSrc,
  homePath,
  profilePath,
  onLogout,
  onNavClick,
  className,
}) {
  return (
    <DropdownMenuContent
      align={align}
      side={side}
      sideOffset={sideOffset}
      className={cn(
        'w-[min(calc(100vw-1.5rem),15rem)] overflow-hidden rounded-2xl border border-border/55 bg-card/95 p-0 shadow-lg shadow-black/6 ring-1 ring-black/5 backdrop-blur-md dark:border-border/50 dark:bg-card/90 dark:shadow-black/30 dark:ring-white/5 sm:w-60',
        className,
      )}
    >
      <div className="flex items-start gap-3 px-3.5 py-3.5">
        <Avatar className="size-9 shrink-0 rounded-full ring-2 ring-background shadow-sm">
          {avatarSrc ? <AvatarImage src={avatarSrc} alt="" className="object-cover" /> : null}
          <AvatarFallback
            className={cn(
              'rounded-full text-xs font-bold',
              getEmployeeAvatarColorClass(user?.id, displayName),
            )}
          >
            {initials}
          </AvatarFallback>
        </Avatar>
        <div className="min-w-0 flex-1">
          <p className="truncate text-sm font-semibold leading-snug text-foreground">{displayName}</p>
          <p className="mt-0.5 truncate text-xs text-muted-foreground">{user?.email}</p>
          <div className="mt-2">
            <RoleBadge user={user} size="sm" />
          </div>
        </div>
      </div>

      <div className="space-y-0.5 px-1.5 pb-1.5">
        <DropdownMenuItem asChild className={menuItemClass}>
          <Link to={homePath} onClick={onNavClick}>
            <LayoutDashboard className="size-4 text-muted-foreground" />
            Dashboard
          </Link>
        </DropdownMenuItem>
        <DropdownMenuItem asChild className={menuItemClass}>
          <Link to={profilePath} onClick={onNavClick}>
            <User className="size-4 text-muted-foreground" />
            Profile
          </Link>
        </DropdownMenuItem>
      </div>

      <div className="border-t border-border/35 px-1.5 py-1.5">
        <DropdownMenuItem
          onClick={onLogout}
          className={cn(menuItemClass, 'text-destructive focus:text-destructive')}
        >
          <LogOut className="size-4" />
          Log out
        </DropdownMenuItem>
      </div>
    </DropdownMenuContent>
  )
}
