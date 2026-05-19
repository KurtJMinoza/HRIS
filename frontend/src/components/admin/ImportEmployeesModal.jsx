import { Fragment, useMemo, useRef, useState } from 'react'
import ExcelJS from 'exceljs'
import { AnimatePresence, motion } from 'framer-motion'
import {
  AlertTriangle,
  ChevronRight,
  CheckCircle2,
  Download,
  FileSpreadsheet,
  FileText,
  FileUp,
  FolderOpen,
  Loader2,
  Lightbulb,
  UploadCloud,
} from 'lucide-react'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Progress } from '@/components/ui/progress'
import { importEmployees, previewEmployeeImport, rollbackEmployeeImport } from '@/api'
import { cn } from '@/lib/utils'

const MotionDiv = motion.div
const MotionSpan = motion.span

const IMPORT_WIZARD_STEPS = [
  { id: 1, title: 'Upload', hint: 'Add file', Icon: UploadCloud },
  { id: 2, title: 'Preview', hint: 'Validate rows', Icon: FileSpreadsheet },
  { id: 3, title: 'Import', hint: 'Save to HR', Icon: FileUp },
]

const ACCEPTED_IMPORT_EXTENSIONS = ['.xlsx', '.xls', '.csv']
const MAX_IMPORT_FILE_BYTES = 10 * 1024 * 1024

const EMPLOYEE_IMPORT_TEMPLATE_HEADERS = [
  'Employee ID',
  'First Name',
  'Middle Name',
  'Last Name',
  'Suffix',
  'Date of Birth',
  'Gender',
  'Marital Status',
  'Nationality',
  'Email',
  'Username',
  'Phone Number',
  'Home Address',
  'Street',
  'Barangay',
  'City',
  'Province',
  'Postal Code',
  'Employment Type',
  'Employment Status',
  'Employment Status Effective Date',
  'Date Hired',
  'Contract Start Date',
  'Contract End Date',
  'Position',
  'Department',
  'Branch',
  'Company',
  'Supervisor',
  'Working Schedule',
  'Working Time In',
  'Working Time Out',
  'Rest Days',
  'Pay Schedule',
  'Basic Salary',
  'Monthly Rate',
  'Daily Rate',
  'Hourly Rate',
  'Salary Effectivity Date',
  'Rice Allowance',
  'Transportation Allowance',
  'Other Pay Components (Active)',
  'Allowances (Active)',
  'Compensation Deductions (Active)',
  'Automated Deductions/Loans (Active)',
  'SSS Number',
  'PhilHealth Number',
  'Pag-IBIG Number',
  'TIN Number',
  'Tax Regime',
  'Withholding Method',
  'Dependents',
  'Active Account',
]

const HEADER_ALIASES = {
  full_name: ['full_name', 'fullname', 'name'],
  first_name: ['first_name', 'firstname', 'given_name'],
  middle_name: ['middle_name', 'middlename', 'middle'],
  last_name: ['last_name', 'lastname', 'surname'],
  suffix: ['suffix', 'name_suffix', 'employee_suffix'],
  email: ['email', 'email_address'],
  department: ['department', 'department_name'],
  branch: ['branch', 'branch_name'],
  company: ['company', 'company_name'],
}

const HEADER_GROUPS = [
  {
    id: 'personal',
    label: 'Personal Information',
    keys: [
      'full_name', 'name', 'first_name', 'middle_name', 'last_name', 'suffix', 'name_suffix',
      'date_of_birth', 'dob', 'birth_date', 'gender', 'sex', 'legal_sex', 'legal_gender', 'marital_status', 'civil_status', 'nationality', 'citizenship',
      'email', 'email_address', 'phone_number', 'phone', 'mobile',
      'home_address', 'address', 'full_address', 'complete_address', 'street', 'street_address', 'municipality', 'town', 'barangay', 'brgy', 'city', 'province', 'postal_code', 'zip_code', 'zip', 'postcode',
      'active', 'is_active',
    ],
  },
  {
    id: 'employment',
    label: 'Employment Information',
    keys: [
      'employment_type', 'employment_status', 'employment_status_effective_date',
      'date_hired', 'hire_date', 'contract_start_date', 'contract_end_date',
      'position', 'job_title', 'department', 'department_name', 'branch', 'branch_name', 'company', 'company_name',
      'supervisor', 'supervisor_name', 'working_schedule', 'schedule',
      'working_time_in', 'time_in', 'working_time_out', 'time_out', 'rest_days', 'rest_day', 'pay_schedule',
    ],
  },
  {
    id: 'salary',
    label: 'Salary & Compensation',
    keys: [
      'basic_salary', 'monthly_salary', 'monthly_rate', 'daily_rate', 'hourly_rate',
      'salary_effectivity_date', 'rice_allowance', 'transportation_allowance', 'transport_allowance',
      'other_pay_components', 'allowances', 'compensation_deductions', 'comp_deductions',
      'automated_deductions', 'automated_deductions_loans',
    ],
  },
  {
    id: 'gov',
    label: 'Government IDs',
    keys: [
      'sss_number', 'sss', 'philhealth_number', 'philhealth', 'pagibig_number', 'pag_ibig_number', 'pagibig',
      'tin_number', 'tin', 'tax_regime', 'withholding_method', 'dependents',
    ],
  },
]

function normalizeKey(value) {
  return String(value || '')
    .toLowerCase()
    .replace(/[-()/.]/g, ' ')
    .replace(/\s+/g, '_')
    .replace(/^_+|_+$/g, '')
}

function resolveHeaderGroup(header) {
  const key = normalizeKey(header)
  const group = HEADER_GROUPS.find((g) => g.keys.some((k) => normalizeKey(k) === key))
  return group?.id || 'other'
}

function valueFromRow(rawRow, aliases) {
  const entries = Object.entries(rawRow || {})
  for (const alias of aliases) {
    const target = normalizeKey(alias)
    const hit = entries.find(([key]) => normalizeKey(key) === target)
    if (hit) return hit[1]
  }
  return null
}

function parseSimpleCsv(text) {
  // Keep every line so row count matches Excel/backend (blank rows become empty preview rows).
  let lines = text.split(/\r?\n/)
  while (lines.length > 0 && lines[lines.length - 1] === '') {
    lines.pop()
  }
  if (lines.length === 0) return []
  const splitCsvLine = (line) => {
    const result = []
    let cur = ''
    let inQuotes = false
    for (let i = 0; i < line.length; i++) {
      const ch = line[i]
      if (ch === '"' && inQuotes && line[i + 1] === '"') {
        cur += '"'
        i++
        continue
      }
      if (ch === '"') {
        inQuotes = !inQuotes
        continue
      }
      if (ch === ',' && !inQuotes) {
        result.push(cur.trim())
        cur = ''
        continue
      }
      cur += ch
    }
    result.push(cur.trim())
    return result
  }

  const headers = splitCsvLine(lines[0]).map((h, idx) => (h != null && String(h).trim() !== '' ? String(h).trim() : `column_${idx + 1}`))
  return lines.slice(1).map((line) => {
    const cells = splitCsvLine(line)
    const row = {}
    headers.forEach((header, idx) => {
      row[header] = cells[idx] ?? ''
    })
    return row
  })
}

async function parseUploadFile(file) {
  const name = file.name.toLowerCase()
  if (name.endsWith('.csv')) {
    const text = await file.text()
    const rows = parseSimpleCsv(text)
    return {
      rows,
      headers: rows.length > 0 ? Object.keys(rows[0]) : [],
    }
  }

  const workbook = new ExcelJS.Workbook()
  const buffer = await file.arrayBuffer()
  await workbook.xlsx.load(buffer)
  const worksheet = workbook.worksheets[0]
  if (!worksheet) return { rows: [], headers: [] }

  // Use columnCount so trailing empty columns are still preserved in preview.
  const totalColumns = Math.max(worksheet.columnCount || 0, worksheet.getRow(1).cellCount || 0)
  const headers = Array.from({ length: totalColumns }, (_, idx) => {
    const colNumber = idx + 1
    const raw = String(worksheet.getRow(1).getCell(colNumber).text || '').trim()
    return raw || `column_${colNumber}`
  })

  // `eachRow` without options skips rows with no values; `includeEmpty: true` walks the full row index range
  // (matches backend / Excel row count when the sheet has blank spacer rows).
  const rows = []
  worksheet.eachRow({ includeEmpty: true }, (row, rowNumber) => {
    if (rowNumber === 1) return
    const obj = {}
    headers.forEach((header, idx) => {
      const cellText = String(row.getCell(idx + 1).text ?? '')
      obj[header] = cellText
    })
    rows.push(obj)
  })
  return { rows, headers }
}

function inferNamePartsFromFullName(raw) {
  const full = String(
    valueFromRow(raw, ['full_name', 'fullname', 'name', 'employee_name', 'complete_name']) || ''
  ).trim()
  if (!full) return { first: '', last: '' }
  const parts = full.split(/\s+/).filter(Boolean)
  if (parts.length === 1) return { first: parts[0], last: '' }
  if (parts.length === 2) return { first: parts[0], last: parts[1] }
  return { first: parts[0], last: parts[parts.length - 1] }
}

function formatPreviewEmployeeName({ first, middle, last, suffix, legacy }) {
  const cleanFirst = String(first || '').trim()
  const cleanMiddle = String(middle || '').trim()
  const cleanLast = String(last || '').trim()
  const cleanSuffix = String(suffix || '').trim()
  const cleanLegacy = String(legacy || '').trim()
  const given = [cleanFirst, cleanMiddle].filter(Boolean).join(' ')
  const base = cleanLast && given ? `${cleanLast}, ${given}` : cleanLast || given || cleanLegacy
  return [base, cleanSuffix].filter(Boolean).join(' ').trim()
}

function validatePreviewRows(rows) {
  const emailSet = new Set()
  return rows.map((raw, index) => {
    const normalized = {}
    Object.keys(HEADER_ALIASES).forEach((key) => {
      normalized[key] = valueFromRow(raw, HEADER_ALIASES[key]) ?? ''
    })

    let first = String(normalized.first_name || '').trim()
    let last = String(normalized.last_name || '').trim()
    const middle = String(normalized.middle_name || '').trim()
    const suffix = String(normalized.suffix || '').trim()
    const legacyName = String(
      normalized.full_name || valueFromRow(raw, ['employee_name', 'complete_name']) || ''
    ).trim()
    if (!first || !last) {
      const inferred = inferNamePartsFromFullName(raw)
      if (!first) first = inferred.first
      if (!last) last = inferred.last
    }
    const displayName = formatPreviewEmployeeName({ first, middle, last, suffix, legacy: legacyName })

    const hints = []
    if (!first && !last) {
      hints.push(`No name columns — will import as Unknown / Employee-${index + 1}`)
    } else {
      if (!first) hints.push('Missing first name — backend uses "Unknown"')
      if (!last) hints.push('Missing last name — backend uses a numbered placeholder')
    }

    const email = String(normalized.email || '').trim().toLowerCase()
    if (!email) {
      hints.push('No email — still imports with blank email')
    } else {
      const emailOk = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)
      if (!emailOk) {
        hints.push('Invalid email text — stored as blank on import')
      } else {
        if (emailSet.has(email)) {
          hints.push('Duplicate email in file — import may clear contact fields on some rows')
        }
        emailSet.add(email)
      }
    }

    return {
      id: `preview-${index}`,
      index: index + 1,
      row: raw,
      normalized,
      displayName,
      issues: hints,
      isValid: true,
    }
  })
}

function csvCell(value) {
  const text = String(value ?? '')
  return /[",\r\n]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text
}

function downloadEmployeeImportTemplate() {
  const exampleRow = [
    'EMP-0001',
    'Juan',
    'Santos',
    'Dela Cruz',
    'Jr.',
    '1995-01-31',
    'Male',
    'Single',
    'Filipino',
    'juan.delacruz@example.com',
    'juan.delacruz',
    "'09171234567",
    '123 Sample Street, Quezon City',
    'Sample Street',
    'Bagong Pag-asa',
    'Quezon City',
    'Metro Manila',
    '1105',
    'Full-time',
    'Probationary',
    '2026-01-01',
    '2026-01-01',
    '',
    '',
    'HR Staff',
    'Human Resources',
    'Main Branch',
    'AGCTEK',
    '',
    'Regular Day Shift',
    '08:00',
    '17:00',
    'sunday',
    'Semi-monthly',
    '25000.00',
    '25000.00',
    '',
    '',
    '2026-01-01',
    '1500.00',
    '1000.00',
    '',
    'Rice Allowance:1500.00 | Transportation Allowance:1000.00',
    '',
    '',
    '',
    '',
    '',
    '',
    'compensation',
    'monthly',
    '0',
    '1',
  ]
  const csv = [EMPLOYEE_IMPORT_TEMPLATE_HEADERS, exampleRow]
    .map((row) => row.map(csvCell).join(','))
    .join('\r\n')
  const blob = new Blob([`\uFEFF${csv}\r\n`], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = 'employee_import_template.csv'
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}

export default function ImportEmployeesModal({ open, onOpenChange, onImported, toast, canUndoImport = false }) {
  const [step, setStep] = useState(1)
  const [file, setFile] = useState(null)
  const [isDragging, setIsDragging] = useState(false)
  const [parsing, setParsing] = useState(false)
  const [importing, setImporting] = useState(false)
  const [rollingBack, setRollingBack] = useState(false)
  const [progress, setProgress] = useState(0)
  const [backendResult, setBackendResult] = useState(null)
  const [previewRows, setPreviewRows] = useState([])
  const [previewHeaders, setPreviewHeaders] = useState([])
  const fileInputRef = useRef(null)

  const metrics = useMemo(() => {
    const total = previewRows.length
    const withNotes = previewRows.filter((r) => r.issues.length > 0).length
    const successRate = total > 0 ? 100 : 0
    return { total, withNotes, successRate }
  }, [previewRows])

  const groupedHeaderSegments = useMemo(() => {
    if (!Array.isArray(previewHeaders) || previewHeaders.length === 0) return []
    const segments = []
    for (const header of previewHeaders) {
      const groupId = resolveHeaderGroup(header)
      const groupMeta = HEADER_GROUPS.find((g) => g.id === groupId)
      const label = groupMeta?.label || 'Other'
      const last = segments[segments.length - 1]
      if (last && last.groupId === groupId) {
        last.span += 1
      } else {
        segments.push({ groupId, label, span: 1 })
      }
    }
    return segments
  }, [previewHeaders])

  const importingLabel = useMemo(() => {
    const total = Math.max(1, metrics.total || backendResult?.total_rows || 1)
    const current = Math.max(1, Math.round((progress / 100) * total))
    return `Importing ${Math.min(current, total)} of ${total} employees...`
  }, [progress, metrics.total, backendResult?.total_rows])

  // Wide only while previewing data; compact on upload and during/after import.
  const isWideLayout = step === 2
  const modalSizeClass = isWideLayout
    ? 'max-h-[94vh] !w-[96vw] !max-w-[96vw]'
    : 'max-h-[88vh] !w-full !max-w-3xl'

  const importHasFailures = Boolean(backendResult && (backendResult.failed || 0) > 0)

  const resetState = () => {
    setStep(1)
    setFile(null)
    setIsDragging(false)
    setParsing(false)
    setImporting(false)
    setRollingBack(false)
    setProgress(0)
    setBackendResult(null)
    setPreviewRows([])
    setPreviewHeaders([])
  }

  const closeModal = (nextOpen) => {
    if (!nextOpen && (importing || rollingBack)) return
    if (!nextOpen) resetState()
    onOpenChange(nextOpen)
  }

  const handleFilePicked = async (nextFile) => {
    if (!(nextFile instanceof File)) return
    const lower = nextFile.name.toLowerCase()
    if (!ACCEPTED_IMPORT_EXTENSIONS.some((ext) => lower.endsWith(ext))) {
      toast?.({ title: 'Unsupported file', description: 'Only .csv, .xls, and .xlsx files are supported.', variant: 'destructive' })
      return
    }
    if (nextFile.size > MAX_IMPORT_FILE_BYTES) {
      toast?.({ title: 'File too large', description: 'Employee imports must be 10 MB or smaller.', variant: 'destructive' })
      return
    }

    setFile(nextFile)
    setParsing(true)
    setBackendResult(null)
    setPreviewRows([])
    setPreviewHeaders([])
    try {
      let rows = []
      let headers = []
      try {
        const data = await previewEmployeeImport(nextFile)
        rows = Array.isArray(data?.rows) ? data.rows : []
        headers = Array.isArray(data?.headers) ? data.headers : []
        const expected = Number(data?.row_count)
        if (Number.isFinite(expected) && expected > 0 && rows.length < expected) {
          // Rare payload/reader mismatch: still show one preview line per data row the server counted.
          while (rows.length < expected) {
            rows.push({})
          }
        }
      } catch (previewErr) {
        try {
          const parsed = await parseUploadFile(nextFile)
          rows = parsed.rows || []
          headers = parsed.headers || []
        } catch {
          throw previewErr
        }
      }
      if (!headers.length && rows.length > 0) {
        headers = Object.keys(rows[0])
      }
      const validated = validatePreviewRows(rows)
      setPreviewRows(validated)
      setPreviewHeaders(headers)
      setStep(2)
    } catch (e) {
      toast?.({ title: 'Cannot read file', description: e?.message || 'Failed to parse this file.', variant: 'destructive' })
    } finally {
      setParsing(false)
    }
  }

  const startImport = async () => {
    if (!(file instanceof File)) return
    setStep(3)
    setImporting(true)
    setProgress(5)
    setBackendResult(null)

    const tick = window.setInterval(() => {
      setProgress((p) => (p >= 92 ? p : p + 7))
    }, 320)

    try {
      const result = await importEmployees(file)
      window.clearInterval(tick)
      setProgress(100)
      setBackendResult(result)
      toast?.({
        title: 'Import completed',
        description: `${result.imported || 0} success, ${result.failed || 0} failed.`,
      })
      onImported?.(result)
    } catch (e) {
      window.clearInterval(tick)
      setProgress(0)
      setBackendResult({ imported: 0, failed: metrics.total, total_rows: metrics.total, errors: [{ row: 0, message: e?.message || 'Import failed.' }] })
      toast?.({ title: 'Import failed', description: e?.message || 'Failed to import employees.', variant: 'destructive' })
    } finally {
      setImporting(false)
    }
  }

  const undoLastImport = async () => {
    const batchId = backendResult?.import_batch_id
    if (!batchId || rollingBack) return
    setRollingBack(true)
    try {
      const data = await rollbackEmployeeImport(batchId)
      toast?.({
        title: 'Import removed',
        description: data?.message || `${data?.deleted_count ?? 0} employee(s) deleted.`,
      })
      setBackendResult((prev) =>
        prev
          ? {
              ...prev,
              imported: 0,
              import_batch_id: null,
              created_user_ids: [],
            }
          : prev,
      )
      onImported?.({ rolledBack: true, ...data })
    } catch (e) {
      toast?.({
        title: 'Could not remove import',
        description: e?.message || 'Rollback failed.',
        variant: 'destructive',
      })
    } finally {
      setRollingBack(false)
    }
  }

  return (
    <Dialog open={open} onOpenChange={closeModal}>
      <DialogContent
        overlayClassName="bg-black/60 backdrop-blur-[3px]"
        innerClassName="overflow-hidden p-0"
        closeButtonClassName="right-4 top-4 border-border/70 bg-background/95 text-foreground hover:bg-muted"
        className={cn(
          modalSizeClass,
          'overflow-hidden rounded-[1.75rem] border border-border/70 bg-card p-0 text-card-foreground shadow-2xl transition-[width,max-width] duration-300 ease-out',
        )}
      >
        <MotionDiv
          initial={{ opacity: 0, scale: 0.985, y: 8 }}
          animate={{ opacity: 1, scale: 1, y: 0 }}
          exit={{ opacity: 0, scale: 0.985, y: 8 }}
          transition={{ duration: 0.22, ease: [0.22, 1, 0.36, 1] }}
          className="flex h-full max-h-[92vh] min-h-0 flex-col bg-card"
        >
          <DialogHeader className="border-b border-border/60 bg-card px-5 py-5 pr-16 sm:px-8 sm:py-6">
            <div className="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
              <div className="flex gap-4">
                <div className="hidden size-14 shrink-0 items-center justify-center rounded-full bg-brand/10 text-brand shadow-inner sm:flex">
                  <UploadCloud className="size-7" aria-hidden />
                </div>
                <div>
                  <DialogTitle className="text-2xl font-bold tracking-tight text-foreground">Import Employees</DialogTitle>
                  <DialogDescription className="mt-1 max-w-xl text-sm text-muted-foreground">
                    Upload, review, and import employee records with full-column preview and validation checks.
                  </DialogDescription>
                </div>
              </div>
              <Button
                type="button"
                variant="outline"
                className="w-fit border-brand/40 bg-brand/5 text-brand hover:bg-brand/10 hover:text-brand dark:bg-brand/10 dark:hover:bg-brand/15"
                onClick={() => fileInputRef.current?.click()}
                disabled={parsing || importing || rollingBack}
              >
                <Download className="size-4" aria-hidden />
                Bulk Import
              </Button>
            </div>
          </DialogHeader>

          <div className="border-b border-border/60 bg-linear-to-b from-muted/35 to-card px-4 py-4 sm:px-8">
            <div className="flex items-stretch gap-2 sm:gap-5">
              {IMPORT_WIZARD_STEPS.map((item, index) => {
                const isDone = step > item.id
                const isCurrent = step === item.id
                const Icon = item.Icon
                return (
                  <Fragment key={item.id}>
                    <MotionDiv
                      layout
                      className="relative z-1 flex min-w-0 flex-1 flex-col"
                      transition={{ layout: { type: 'spring', stiffness: 380, damping: 34 } }}
                    >
                      <MotionDiv
                        layout
                        className={cn(
                          'relative flex min-h-28 flex-col items-center justify-center gap-2 rounded-2xl border px-2 py-4 text-center transition-colors sm:min-h-32 sm:px-4',
                          isDone && 'border-emerald-400/50 bg-emerald-500/10 text-foreground shadow-sm',
                          isCurrent && !isDone && 'border-brand/70 bg-brand/5 text-foreground shadow-md ring-2 ring-brand/10',
                          !isDone && !isCurrent && 'border-border bg-background/65 text-muted-foreground shadow-sm',
                        )}
                        animate={
                          isCurrent
                            ? {
                                boxShadow: [
                                  '0 10px 30px -18px rgba(249, 115, 22, 0.35)',
                                  '0 18px 44px -20px rgba(249, 115, 22, 0.5)',
                                  '0 10px 30px -18px rgba(249, 115, 22, 0.35)',
                                ],
                              }
                            : { boxShadow: '0 1px 3px rgba(15, 23, 42, 0.06)' }
                        }
                        transition={isCurrent ? { duration: 2.2, repeat: Infinity, ease: 'easeInOut' } : { duration: 0.22 }}
                      >
                        <span
                          className={cn(
                            'absolute left-3 top-3 flex size-6 items-center justify-center rounded-full text-xs font-bold tabular-nums shadow-sm',
                            isCurrent ? 'bg-brand text-brand-foreground' : isDone ? 'bg-emerald-500 text-white' : 'bg-muted text-muted-foreground',
                          )}
                        >
                          {item.id}
                        </span>
                        <div
                          className={cn(
                            'flex size-14 items-center justify-center rounded-full transition-colors sm:size-16',
                            isDone && 'bg-emerald-500 text-white',
                            isCurrent && !isDone && 'bg-brand text-brand-foreground shadow-lg shadow-brand/20',
                            !isDone && !isCurrent && 'bg-muted text-muted-foreground',
                          )}
                        >
                          {isDone ? <CheckCircle2 className="size-7" aria-hidden /> : <Icon className="size-7" aria-hidden />}
                        </div>
                        <div>
                          <p className="text-sm font-bold tracking-tight sm:text-base">{item.title}</p>
                          <p className="text-xs text-muted-foreground">{item.hint}</p>
                        </div>
                      </MotionDiv>
                    </MotionDiv>
                    {index < IMPORT_WIZARD_STEPS.length - 1 && (
                      <div className="hidden min-w-5 max-w-10 items-center justify-center text-muted-foreground sm:flex">
                        <MotionSpan
                          initial={false}
                          animate={{ color: step > item.id ? 'var(--brand)' : 'var(--muted-foreground)' }}
                          className="leading-none"
                        >
                          <ChevronRight className="size-7" aria-hidden />
                        </MotionSpan>
                      </div>
                    )}
                  </Fragment>
                )
              })}
            </div>
          </div>

          <input
            ref={fileInputRef}
            type="file"
            accept=".csv,.xlsx,.xls"
            className="hidden"
            onChange={(e) => {
              const picked = e.target.files?.[0]
              e.target.value = ''
              handleFilePicked(picked)
            }}
          />

          <div className="min-h-0 flex-1 overflow-hidden px-4 py-5 sm:px-8">
            <AnimatePresence mode="wait">
              {step === 1 && (
                <MotionDiv
                  key="step-upload"
                  initial={{ opacity: 0, y: 14, scale: 0.995 }}
                  animate={{ opacity: 1, y: 0, scale: 1 }}
                  exit={{ opacity: 0, y: -10, scale: 0.995 }}
                  transition={{ duration: 0.2, ease: [0.22, 1, 0.36, 1] }}
                  className="mx-auto flex h-full w-full max-w-5xl flex-col gap-5"
                >
                  <button
                    type="button"
                    onClick={() => fileInputRef.current?.click()}
                    onDragOver={(e) => {
                      e.preventDefault()
                      setIsDragging(true)
                    }}
                    onDragLeave={() => setIsDragging(false)}
                    onDrop={(e) => {
                      e.preventDefault()
                      setIsDragging(false)
                      handleFilePicked(e.dataTransfer.files?.[0])
                    }}
                    disabled={parsing}
                    className={cn(
                      'group flex min-h-72 w-full flex-1 flex-col items-center justify-center rounded-3xl border border-dashed px-6 py-10 text-center transition-all duration-300',
                      'focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2 focus-visible:ring-offset-card',
                      isDragging
                        ? 'border-brand bg-brand/10 shadow-[0_0_0_6px_rgba(249,115,22,0.10)]'
                        : 'border-brand/45 bg-background/55 hover:border-brand/70 hover:bg-brand/5',
                    )}
                  >
                    <div className="mb-5 flex size-20 items-center justify-center rounded-full bg-brand/10 text-brand shadow-inner transition-transform group-hover:scale-105">
                      {parsing ? <Loader2 className="size-9 animate-spin" aria-hidden /> : <UploadCloud className="size-10" aria-hidden />}
                    </div>
                    <p className="text-xl font-bold tracking-tight text-foreground">Drag and drop your XLSX/CSV file</p>
                    <p className="mt-2 text-sm text-muted-foreground">
                      Supported formats: .xlsx, .xls, .csv <span className="mx-1 text-brand" aria-hidden>&bull;</span> Max size: 10 MB
                    </p>
                    <span className="mt-6 inline-flex items-center gap-2 rounded-lg bg-brand px-6 py-3 text-sm font-semibold text-brand-foreground shadow-lg shadow-brand/20 transition-colors group-hover:bg-brand-strong">
                      <FolderOpen className="size-4" aria-hidden />
                      Browse files
                    </span>
                  </button>

                  {file && (
                    <div className="flex items-center gap-3 rounded-xl border border-border bg-background/75 px-4 py-3 text-sm shadow-sm">
                      <FileSpreadsheet className="size-5 shrink-0 text-brand" aria-hidden />
                      <div className="min-w-0">
                        <p className="truncate font-medium text-foreground">{file.name}</p>
                        <p className="text-xs text-muted-foreground">{(file.size / 1024 / 1024).toFixed(2)} MB</p>
                      </div>
                      {parsing && <Loader2 className="ml-auto size-4 animate-spin text-muted-foreground" aria-hidden />}
                    </div>
                  )}

                  <div className="flex flex-col gap-3 rounded-2xl border border-brand/15 bg-brand/5 px-5 py-4 text-sm sm:flex-row sm:items-center sm:justify-between">
                    <div className="flex items-start gap-3 text-muted-foreground">
                      <Lightbulb className="mt-0.5 size-5 shrink-0 text-brand" aria-hidden />
                      <p>
                        <span className="font-semibold text-foreground">Tip:</span> Download our sample template to ensure correct formatting.
                      </p>
                    </div>
                    <Button
                      type="button"
                      variant="ghost"
                      className="justify-start text-brand hover:bg-brand/10 hover:text-brand sm:justify-center"
                      onClick={downloadEmployeeImportTemplate}
                    >
                      <Download className="size-4" aria-hidden />
                      Download template
                    </Button>
                  </div>
                </MotionDiv>
              )}

              {step === 2 && (
                <MotionDiv
                  key="step-preview"
                  initial={{ opacity: 0, y: 14, scale: 0.995 }}
                  animate={{ opacity: 1, y: 0, scale: 1 }}
                  exit={{ opacity: 0, y: -10, scale: 0.995 }}
                  transition={{ duration: 0.2, ease: [0.22, 1, 0.36, 1] }}
                  className="flex h-full min-h-0 flex-col space-y-4"
                >
                  <div className="flex flex-wrap items-center gap-2">
                    <Badge variant="secondary">Total: {metrics.total}</Badge>
                    <Badge className="bg-emerald-600 text-white hover:bg-emerald-600">All rows eligible to import</Badge>
                    {metrics.withNotes > 0 ? <Badge variant="outline">Notes on {metrics.withNotes} row(s)</Badge> : null}
                    <Badge variant="outline">Estimated success: {metrics.successRate}%</Badge>
                    {file ? (
                      <Badge variant="outline" className="max-w-full gap-1">
                        <FileText className="size-3" aria-hidden />
                        <span className="truncate">{file.name}</span>
                      </Badge>
                    ) : null}
                  </div>

                  <div className="h-[64vh] max-h-[64vh] overflow-auto rounded-2xl border border-border bg-background overscroll-contain shadow-sm">
                    <table className="w-max min-w-[2600px] border-collapse text-sm text-foreground">
                      <thead className="sticky top-0 z-20">
                        {groupedHeaderSegments.length > 0 && (
                          <tr className="border-b border-border">
                            <th className="sticky left-0 z-30 w-14 bg-background px-4 py-3 text-left text-xs font-semibold text-muted-foreground">Sections</th>
                            {groupedHeaderSegments.map((seg, idx) => (
                              <th
                                key={`${seg.groupId}-${idx}`}
                                colSpan={seg.span}
                                className="bg-muted px-4 py-3 text-center text-xs font-semibold uppercase tracking-wide text-muted-foreground"
                              >
                                {seg.label}
                              </th>
                            ))}
                            <th className="bg-muted px-4 py-3 text-center text-xs font-semibold uppercase tracking-wide text-muted-foreground">Display Name</th>
                            <th className="bg-muted px-4 py-3 text-center text-xs font-semibold uppercase tracking-wide text-muted-foreground">Validation</th>
                          </tr>
                        )}
                        <tr className="border-b border-border">
                          <th className="sticky left-0 z-30 w-14 bg-background px-4 py-3 text-left">#</th>
                          {previewHeaders.map((header) => (
                            <th key={header} className="whitespace-nowrap bg-background px-4 py-3 text-left font-semibold">
                              {header}
                            </th>
                          ))}
                          <th className="min-w-64 bg-background px-4 py-3 text-left font-semibold">Display Name</th>
                          <th className="min-w-72 bg-background px-4 py-3 text-left font-semibold">Validation</th>
                        </tr>
                      </thead>
                      <tbody>
                        {previewRows.length > 0 ? (
                          previewRows.map((item) => (
                            <tr key={item.id} className="border-b border-border/70 hover:bg-muted/35">
                              <td className="sticky left-0 z-10 bg-background px-4 py-2.5">{item.index}</td>
                              {previewHeaders.map((header) => {
                                const value = String(item.row[header] ?? '').trim()
                                return (
                                  <td key={`${item.id}-${header}`} className="px-4 py-2.5">
                                    {value || <span className="text-muted-foreground">-</span>}
                                  </td>
                                )
                              })}
                              <td className="px-4 py-2.5 font-medium text-foreground">
                                {item.displayName || <span className="text-muted-foreground">-</span>}
                              </td>
                              <td className="px-4 py-2.5">
                                <div className="space-y-1">
                                  <span className="inline-flex items-center gap-1 font-medium text-emerald-600 dark:text-emerald-300">
                                    <CheckCircle2 className="size-4" aria-hidden /> Ready
                                  </span>
                                  {item.issues.length > 0 && (
                                    <p className="max-w-xs text-xs leading-snug text-muted-foreground">
                                      {item.issues.join(' / ')}
                                    </p>
                                  )}
                                </div>
                              </td>
                            </tr>
                          ))
                        ) : (
                          <tr>
                            <td className="px-4 py-12 text-center text-muted-foreground" colSpan={Math.max(3, previewHeaders.length + 3)}>
                              No employee rows were found in this file.
                            </td>
                          </tr>
                        )}
                      </tbody>
                    </table>
                  </div>
                  <p className="text-xs text-muted-foreground">Scroll vertically for rows and horizontally for all columns.</p>
                </MotionDiv>
              )}

              {step === 3 && (
                <MotionDiv
                  key="step-import"
                  initial={{ opacity: 0, y: 14, scale: 0.995 }}
                  animate={{ opacity: 1, y: 0, scale: 1 }}
                  exit={{ opacity: 0, y: -10, scale: 0.995 }}
                  transition={{ duration: 0.2, ease: [0.22, 1, 0.36, 1] }}
                  className="mx-auto w-full max-w-2xl space-y-4"
                >
                  <div className="relative overflow-hidden rounded-2xl border border-border bg-background/75 p-5 shadow-sm">
                    <div className="mb-4 flex items-center gap-3">
                      <MotionDiv
                        className={cn(
                          'flex size-12 shrink-0 items-center justify-center rounded-full',
                          importing && 'bg-brand/10 text-brand',
                          !importing && importHasFailures && 'bg-amber-500/10 text-amber-600 dark:text-amber-300',
                          !importing && backendResult && !importHasFailures && 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-300',
                          !importing && !backendResult && 'bg-muted text-muted-foreground',
                        )}
                        animate={importing ? { scale: [1, 1.06, 1] } : { scale: 1 }}
                        transition={{ duration: 1.2, repeat: importing ? Infinity : 0, ease: 'easeInOut' }}
                      >
                        {importing ? (
                          <Loader2 className="size-5 animate-spin" aria-hidden />
                        ) : importHasFailures ? (
                          <AlertTriangle className="size-5" aria-hidden />
                        ) : backendResult ? (
                          <CheckCircle2 className="size-5" aria-hidden />
                        ) : (
                          <Loader2 className="size-5 text-muted-foreground" aria-hidden />
                        )}
                      </MotionDiv>
                      <div>
                        <p className="text-sm font-semibold text-foreground">
                          {importing ? 'Importing employees' : backendResult ? 'Import finished' : 'Import status'}
                        </p>
                        <p className="text-xs text-muted-foreground">
                          {importing ? importingLabel : backendResult ? 'Review the summary below.' : 'Waiting...'}
                        </p>
                      </div>
                    </div>

                    <div className="relative h-3 w-full overflow-hidden rounded-full bg-muted">
                      {importing && (
                        <MotionDiv
                          className="pointer-events-none absolute inset-y-0 w-1/3 rounded-full bg-linear-to-r from-transparent via-white/70 to-transparent dark:via-white/25"
                          initial={{ x: '-40%' }}
                          animate={{ x: ['-40%', '140%'] }}
                          transition={{ duration: 1.35, repeat: Infinity, ease: 'linear' }}
                          aria-hidden
                        />
                      )}
                      <MotionDiv
                        className="absolute inset-y-0 left-0 rounded-full bg-brand"
                        initial={false}
                        animate={{ width: `${Math.min(100, Math.max(0, progress))}%` }}
                        transition={{ type: 'spring', stiffness: 120, damping: 22 }}
                      />
                    </div>
                    <div className="mt-2 flex items-center justify-between text-xs text-muted-foreground">
                      <span>{importing ? 'Please keep this window open...' : ' '}</span>
                      <span className="font-mono font-medium tabular-nums text-foreground">{Math.round(progress)}%</span>
                    </div>
                    <Progress value={progress} className="sr-only" indicatorClassName="bg-brand" />
                  </div>

                  {backendResult && (
                    <div className="space-y-3 rounded-2xl border border-border bg-background/75 p-4 shadow-sm">
                      <div className="flex flex-wrap gap-2 text-sm">
                        <Badge className="bg-emerald-600 text-white hover:bg-emerald-600">Success: {backendResult.imported || 0}</Badge>
                        <Badge variant="destructive">Failed: {backendResult.failed || 0}</Badge>
                        <Badge variant="secondary">Skipped: {Math.max((metrics.total || 0) - (backendResult.total_rows || 0), 0)}</Badge>
                      </div>
                      {Array.isArray(backendResult.errors) && backendResult.errors.length > 0 && (
                        <div className="max-h-40 overflow-auto rounded-xl border border-border bg-card p-3 text-xs text-muted-foreground">
                          {backendResult.errors.slice(0, 30).map((err, idx) => (
                            <p key={`${err.row || 0}-${idx}`}>Row {err.row || '-'}: {err.message}</p>
                          ))}
                        </div>
                      )}
                      {canUndoImport &&
                        backendResult.import_batch_id &&
                        (backendResult.imported || 0) > 0 &&
                        !importing && (
                          <div className="flex flex-col gap-2 border-t border-border pt-3 sm:flex-row sm:items-center sm:justify-between">
                            <p className="text-xs text-muted-foreground">
                              Remove every employee record created in this import run (same as deleting each one).
                            </p>
                            <Button
                              type="button"
                              variant="destructive"
                              size="sm"
                              className="shrink-0"
                              disabled={rollingBack}
                              onClick={undoLastImport}
                            >
                              {rollingBack ? (
                                <>
                                  <Loader2 className="mr-2 size-4 animate-spin" aria-hidden />
                                  Removing...
                                </>
                              ) : (
                                <>Remove this import ({backendResult.imported || 0})</>
                              )}
                            </Button>
                          </div>
                        )}
                    </div>
                  )}
                </MotionDiv>
              )}
            </AnimatePresence>
          </div>

          <DialogFooter className="border-t border-border/60 bg-card px-5 py-4 sm:px-8">
            {step === 1 && (
              <Button type="button" variant="outline" onClick={() => closeModal(false)}>
                Cancel
              </Button>
            )}
            {step === 2 && (
              <>
                <Button type="button" variant="outline" onClick={() => setStep(1)}>
                  Back
                </Button>
                <Button
                  type="button"
                  className="bg-brand text-brand-foreground hover:bg-brand-strong"
                  onClick={startImport}
                  disabled={importing || !(file instanceof File) || metrics.total === 0}
                >
                  {importing ? <Loader2 className="mr-1.5 size-4 animate-spin" aria-hidden /> : null}
                  Import Now
                </Button>
              </>
            )}
            {step === 3 && (
              <Button type="button" variant="outline" onClick={() => closeModal(false)} disabled={importing}>
                {backendResult ? 'Done' : 'Close'}
              </Button>
            )}
          </DialogFooter>
        </MotionDiv>
      </DialogContent>
    </Dialog>
  )
}
