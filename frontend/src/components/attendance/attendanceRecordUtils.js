export function attendanceRecordRef(employeeId, dateStr) {
  const d = String(dateStr || '').replace(/-/g, '')
  const id = employeeId != null ? String(employeeId) : '0'
  return `ATT-${id}-${d || '00000000'}`
}

export function formatShortDate(isoDate) {
  if (!isoDate) return '—'
  try {
    const d = new Date(`${isoDate}T12:00:00`)
    return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
  } catch {
    return isoDate
  }
}

export function formatDateTimeReadable(value) {
  if (!value) return '—'
  try {
    const d = new Date(value)
    if (Number.isNaN(d.getTime())) return String(value)
    return d.toLocaleString(undefined, {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    })
  } catch {
    return String(value)
  }
}

/** Clock display for admin rows (plain HH:MM) or ISO timestamps from APIs. */
export function displayAttendanceTime(value) {
  if (value == null || value === '') {
    return null
  }
  if (typeof value === 'string') {
    let t = value.trim()

    // Treat pure em dash / placeholder as "no time".
    if (t === '—' || t === '--') {
      return null
    }

    // Some backends/old data may send strings like "—01:00" or "--08:00".
    // Strip leading non-digits before parsing to avoid showing the broken prefix.
    t = t.replace(/^[^\d]*/, '')

    // Already normalized form: "HH:MM:SS"
    if (/^\d{1,2}:\d{2}:\d{2}$/.test(t)) {
      const [hStr, mStr, sStr] = t.split(':')
      const h = Number(hStr)
      if (Number.isNaN(h)) return t
      return `${String(h).padStart(2, '0')}:${mStr}:${sStr}`
    }

    // "HH:MM" -> normalize to "HH:MM:00" for consistent shift display.
    if (/^\d{1,2}:\d{2}$/.test(t)) {
      const [hStr, mStr] = t.split(':')
      const h = Number(hStr)
      if (Number.isNaN(h)) return t
      return `${String(h).padStart(2, '0')}:${mStr}:00`
    }
  }

  // Fallback: shared ISO/Date formatter with seconds.
  return formatTimeHhMmSs(value)
}

export function mutedTimeCell(value) {
  const d = displayAttendanceTime(value)
  if (d == null || d === '—') {
    return { text: '—', muted: true }
  }
  return { text: d, muted: false }
}

export function tableLateMinutes(row) {
  const n = row?.late_minutes
  if (typeof n === 'number' && n > 0) return n
  return null
}

export function tableUndertimeMinutes(row) {
  const n = row?.undertime_minutes
  if (typeof n === 'number' && n > 0) return n
  return null
}

export function tableOvertimeMinutes(row) {
  if (typeof row?.overtime_minutes === 'number' && row.overtime_minutes > 0) {
    return row.overtime_minutes
  }
  const h = row?.rendered_overtime_hours
  if (typeof h === 'number' && h > 0) {
    return Math.round(h * 60)
  }
  return null
}

export function minutesCellText(n) {
  if (n == null || typeof n !== 'number' || n <= 0) return '—'
  return String(Math.round(n))
}

export function formatTimeHhMm(value) {
  if (!value) return '—'
  if (typeof value === 'string') {
    const raw = value.trim()
    // Admin / employee summary APIs: wall-clock "HH:MM" in company timezone (single source of truth).
    if (/^\d{1,2}:\d{2}$/.test(raw)) {
      // Requirement: display in 24-hour format for clarity (08:00–17:00).
      const [hStr, mStr] = raw.split(':')
      const h = Number(hStr)
      if (Number.isNaN(h)) return raw
      return `${String(h).padStart(2, '0')}:${mStr}`
    }
    // Full ISO timestamps: always interpret as an instant — do not use T(HH:MM) substring (breaks Z/UTC vs display TZ).
    if (/^\d{4}-\d{2}-\d{2}T/.test(raw)) {
      const d = new Date(raw)
      if (!Number.isNaN(d.getTime())) {
        // Keep ISO timestamps readable but stable; still output 24-hour HH:MM.
        return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', hour12: false })
      }
    }
  }
  try {
    const d = new Date(value)
    if (Number.isNaN(d.getTime())) return String(value)
    return d.toLocaleTimeString(undefined, { hour: '2-digit', minute: '2-digit', hour12: false })
  } catch {
    return String(value)
  }
}

/**
 * Like {@link formatTimeHhMm} but always includes seconds: "HH:MM:SS".
 * Used for shift-time displays so "08:00:00 – 17:00:00" stays consistent.
 */
export function formatTimeHhMmSs(value) {
  if (!value) return '—'
  if (typeof value === 'string') {
    const raw = value.trim()

    // ISO timestamps: extract time portion directly to avoid timezone shifts.
    // Matches "...T08:00:00Z" or "...T08:00:00+08:00"
    const isoWithSeconds = raw.match(/T(\d{2}):(\d{2}):(\d{2})/)
    if (isoWithSeconds) {
      const [, hh, mm, ss] = isoWithSeconds
      return `${hh}:${mm}:${ss}`
    }
    // Matches "...T08:00Z" (rare) -> seconds default to 00
    const isoWithoutSeconds = raw.match(/T(\d{2}):(\d{2})(?:Z|[+-]\d{2}:?\d{2})?/)
    if (isoWithoutSeconds) {
      const [, hh, mm] = isoWithoutSeconds
      return `${hh}:${mm}:00`
    }

    if (/^\d{1,2}:\d{2}:\d{2}$/.test(raw)) {
      const [hStr, mStr, sStr] = raw.split(':')
      const h = Number(hStr)
      if (Number.isNaN(h)) return raw
      return `${String(h).padStart(2, '0')}:${mStr}:${sStr}`
    }

    if (/^\d{1,2}:\d{2}$/.test(raw)) {
      const [hStr, mStr] = raw.split(':')
      const h = Number(hStr)
      if (Number.isNaN(h)) return raw
      return `${String(h).padStart(2, '0')}:${mStr}:00`
    }
  }

  try {
    const d = value instanceof Date ? value : new Date(value)
    if (Number.isNaN(d.getTime())) return String(value)
    const hh = String(d.getHours()).padStart(2, '0')
    const mm = String(d.getMinutes()).padStart(2, '0')
    const ss = String(d.getSeconds()).padStart(2, '0')
    return `${hh}:${mm}:${ss}`
  } catch {
    return String(value)
  }
}

/** Human-readable shift window for table column (uses schedule_in / schedule_out when present). */
export function formatScheduleRange(row) {
  const a = row?.schedule_in
  const b = row?.schedule_out
  if (!a && !b) {
    if (typeof row?.scheduled_regular_hours === 'number' && row.scheduled_regular_hours > 0) {
      return `${Number(row.scheduled_regular_hours).toFixed(2)}h scheduled`
    }
    return '—'
  }
  const toIso = (t) => {
    if (!t || typeof t !== 'string') return null
    const s = t.trim()
    if (/^\d{1,2}:\d{2}$/.test(s)) return `2000-01-01T${s}:00`
    if (/^\d{1,2}:\d{2}:\d{2}$/.test(s)) return `2000-01-01T${s}`
    return t
  }
  const left = formatTimeHhMmSs(toIso(a))
  const right = formatTimeHhMmSs(toIso(b))
  if (left === '—' && right === '—') return '—'
  const otEnd = row?.approved_ot_end_time
  if (otEnd && otEnd !== b) {
    const otRight = formatTimeHhMmSs(toIso(otEnd))
    return `${left} – ${right} → ${otRight} (OT)`
  }
  return `${left} – ${right}`
}

export function adminActivityLine(row) {
  const dateLabel = formatShortDate(row.date)
  const last = row.time_out || row.time_in
  if (!last) return `${dateLabel} · —`
  const timeLabel = formatTimeHhMm(last)
  return `${dateLabel} · ${timeLabel}${row.time_out_next_day ? ' (+1)' : ''}`
}

export function employeeActivityLine(row) {
  const dateLabel = formatShortDate(row.date)
  const v = row.time_out || row.time_in
  if (!v) return `${dateLabel} · —`
  const timeLabel = formatTimeHhMm(v)
  return `${dateLabel} · ${timeLabel}${row.time_out_next_day ? ' (+1)' : ''}`
}

/**
 * Table column: total clocked / rendered hours for the day (net in–out, incl. overtime span).
 * Matches API `total_rendered_hours` / `total_hours`.
 */
export function tableRenderedHoursLabel(row) {
  const r = row.total_rendered_hours ?? row.total_hours
  if (typeof r === 'number' && r > 0 && !Number.isNaN(r)) {
    return `${Number(r).toFixed(2)}h`
  }
  return '—'
}

/** @deprecated Use tableRenderedHoursLabel — kept for import compatibility. */
export function tableDurationLabel(row) {
  return tableRenderedHoursLabel(row)
}

function appendOvertimeDetailParts(parts, row) {
  const approved =
    typeof row.approved_overtime_hours === 'number' && row.approved_overtime_hours > 0
      ? row.approved_overtime_hours
      : typeof row.overtime_hours === 'number' && row.overtime_hours > 0
        ? row.overtime_hours
        : null
  const rendered =
    typeof row.rendered_overtime_hours === 'number' && row.rendered_overtime_hours > 0
      ? row.rendered_overtime_hours
      : typeof row.overtime_minutes === 'number' && row.overtime_minutes > 0
        ? Math.round((row.overtime_minutes / 60) * 100) / 100
        : null
  if (approved !== null) {
    parts.push(`Approved OT ${approved}h`)
    if (rendered !== null && rendered > approved + 0.05) {
      parts.push(`Rendered OT ${rendered}h`)
    }
  } else if (rendered !== null) {
    parts.push(`OT rendered ${rendered}h (pending approval)`)
  }
}

export function employeeHoursDetailSummary(row) {
  const parts = []
  if (typeof row.scheduled_regular_hours === 'number' && row.scheduled_regular_hours > 0) {
    parts.push(`Scheduled ${row.scheduled_regular_hours}h`)
  }
  const rend = row.total_rendered_hours ?? row.total_hours
  if (typeof rend === 'number' && rend > 0) {
    parts.push(`Rendered ${rend}h`)
  }
  appendOvertimeDetailParts(parts, row)
  if (typeof row.night_hours === 'number' && row.night_hours > 0) {
    parts.push(`ND ${row.night_hours}h`)
  }
  return parts.filter(Boolean).join(' · ') || '—'
}

export function adminHoursDetailSummary(row, { showPayroll } = {}) {
  const parts = []
  const sched = row.scheduled_regular_hours
  const rend = row.total_rendered_hours ?? row.total_hours
  if (typeof sched === 'number' && sched > 0) {
    parts.push(`Scheduled ${sched}h`)
  }
  if (typeof rend === 'number' && rend > 0) {
    parts.push(`Rendered ${rend}h`)
  }
  const ut = row.undertime_minutes
  if (typeof ut === 'number' && ut > 0) {
    parts.push(`${ut}m undertime${row.is_approved_undertime ? ' (approved)' : ''}`)
  }
  appendOvertimeDetailParts(parts, row)
  if (showPayroll && typeof row.night_hours === 'number' && row.night_hours > 0) {
    parts.push(`ND ${row.night_hours}h`)
  }
  if (showPayroll && row.premium_description) {
    parts.push(row.premium_description)
  }
  return parts.filter(Boolean).join(' · ') || '—'
}

/** Same as table column: total rendered hours (exports, employee history). */
export function employeeDurationLabel(row) {
  return tableRenderedHoursLabel(row)
}

export function adminTypeReasonLabel(row) {
  if (row.status === 'leave' || row.status === 'halfday') return 'Leave'
  if (row.has_correction) return 'Correction'
  if (row.presence_issue === 'correction_pending') return 'Late filing'
  if (row.status === 'late') return 'Late'
  if (row.status === 'undertime') return 'Undertime'
  return 'Regular'
}

export function employeeTypeReasonLabel(row) {
  if (row.presence_filing?.status === 'pending') return 'Correction · Pending'
  if (row.presence_filing?.status === 'approved') return 'Correction · Approved'
  if (row.presence_filing?.status === 'rejected') return 'Correction · Rejected'
  if (row.status === 'leave') {
    if (row.leave_pay_status === 'paid') return 'Paid leave'
    if (row.leave_pay_status === 'unpaid') return 'Unpaid leave'
    return 'Leave'
  }
  if (row.status === 'halfday') return 'Half day'
  if (row.status === 'undertime') return 'Undertime'
  if (row.status === 'late') return 'Late filing'
  return 'Regular'
}

export function resolveAdminStatusLabel(row) {
  const rawStatus = row.status || ''
  if (rawStatus === 'late') return row.late_label || 'Late'
  if (rawStatus === 'undertime') {
    return row.is_approved_undertime ? 'Undertime (Approved)' : 'Undertime (Unfiled)'
  }
  if (rawStatus === 'absent') return 'Absent'
  if (rawStatus === 'present') return row.has_approved_overtime ? 'Present + OT' : 'Present'
  if (rawStatus === 'halfday') return row.late_label || 'Half Day'
  if (rawStatus === 'incomplete') return 'Present (Incomplete)'
  if (rawStatus === 'leave') return 'On Leave'
  if (row.presence_label) return row.presence_label
  return rawStatus || '—'
}

export function resolveEmployeeStatusLabel(row) {
  if (row.status === 'leave') {
    if (row.leave_pay_status === 'paid') return 'Paid leave'
    if (row.leave_pay_status === 'unpaid') return 'Unpaid leave'
    return 'Leave'
  }
  if (row.presence_label) return row.presence_label
  if (row.status === '—') return '—'
  if (row.status === 'upcoming') return 'Upcoming'
  if (row.status === 'late' && row.late_label) return row.late_label
  if (row.status === 'halfday') return row.late_label || 'Half Day'
  if (row.status === 'absent') return 'Absent'
  if (row.status === 'incomplete') return 'Present (Incomplete)'
  if (row.status === 'present') return row.late_label || 'Present'
  if (row.status === 'undertime') return 'Undertime'
  if (row.status === 'clocked_in') return row.late_label || 'Clocked in'
  return row.status || '—'
}

export function isPendingAttentionRow(row) {
  if (row.status === 'incomplete') return true
  if (row.presence_issue === 'correction_pending') return true
  if (row.has_correction && row.correction_approved === false && row.correction_id) return true
  return false
}

export function isPendingEmployeeRow(row) {
  if (row.status === 'incomplete') return true
  if (row.presence_issue === 'correction_pending') return true
  if (row.presence_filing?.status === 'pending') return true
  return false
}
