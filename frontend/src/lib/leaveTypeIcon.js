import {
  AlertTriangle,
  Briefcase,
  CalendarClock,
  Clock,
  HeartPulse,
  Palmtree,
} from 'lucide-react'

/** Lucide icon component for a leave type (shared by badges, selects, lists). */
export function getLeaveTypeIcon(type) {
  const t = String(type || '').toLowerCase()
  if (t === 'vacation') return Palmtree
  if (t === 'sick') return HeartPulse
  if (t === 'emergency') return AlertTriangle
  if (t === 'undertime') return Clock
  if (t === 'half_day') return CalendarClock
  return Briefcase
}
