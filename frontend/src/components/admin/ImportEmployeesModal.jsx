import { Fragment, useMemo, useRef, useState } from 'react'
import ExcelJS from 'exceljs'
import { AnimatePresence, motion } from 'framer-motion'
import { UploadCloud, FileSpreadsheet, AlertTriangle, CheckCircle2, Loader2, FileUp } from 'lucide-react'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Progress } from '@/components/ui/progress'
import { importEmployees, previewEmployeeImport, rollbackEmployeeImport } from '@/api'
import { cn } from '@/lib/utils'

const IMPORT_WIZARD_STEPS = [
  { id: 1, title: 'Upload', hint: 'Add file', Icon: UploadCloud },
  { id: 2, title: 'Preview', hint: 'Validate rows', Icon: FileSpreadsheet },
  { id: 3, title: 'Import', hint: 'Save to HR', Icon: FileUp },
]

const HEADER_ALIASES = {
  full_name: ['full_name', 'fullname', 'name'],
  first_name: ['first_name', 'firstname', 'given_name'],
  middle_name: ['middle_name', 'middlename', 'middle'],
  last_name: ['last_name', 'lastname', 'surname'],
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
      'full_name', 'name', 'first_name', 'middle_name', 'last_name',
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

function validatePreviewRows(rows) {
  const emailSet = new Set()
  return rows.map((raw, index) => {
    const normalized = {}
    Object.keys(HEADER_ALIASES).forEach((key) => {
      normalized[key] = valueFromRow(raw, HEADER_ALIASES[key]) ?? ''
    })

    let first = String(normalized.first_name || '').trim()
    let last = String(normalized.last_name || '').trim()
    if (!first || !last) {
      const inferred = inferNamePartsFromFullName(raw)
      if (!first) first = inferred.first
      if (!last) last = inferred.last
    }

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
      issues: hints,
      isValid: true,
    }
  })
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
    if (!lower.endsWith('.csv') && !lower.endsWith('.xlsx')) {
      toast?.({ title: 'Unsupported file', description: 'Only .csv and .xlsx are supported.', variant: 'destructive' })
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
        className={`${modalSizeClass} overflow-hidden border-0 bg-white p-0 text-black shadow-xl transition-[width,max-width] duration-300 ease-out`}
      >
        <motion.div
          initial={{ opacity: 0, scale: 0.985, y: 8 }}
          animate={{ opacity: 1, scale: 1, y: 0 }}
          exit={{ opacity: 0, scale: 0.985, y: 8 }}
          transition={{ duration: 0.22, ease: [0.22, 1, 0.36, 1] }}
          className="flex h-full max-h-[92vh] flex-col"
        >
          <DialogHeader className="bg-white px-5 py-4">
            <div className="flex items-start justify-between gap-4">
              <div>
                <DialogTitle className="text-xl font-semibold tracking-tight">Import Employees</DialogTitle>
                <DialogDescription className="mt-1 text-sm">
                  Upload, review, and import employee records with full-column preview and validation checks.
                </DialogDescription>
              </div>
              <Badge variant="outline" className="border-primary/40 bg-primary/10 text-primary">
                Bulk Import
              </Badge>
            </div>
          </DialogHeader>

          <div className="border-b border-slate-100 bg-gradient-to-b from-slate-50/80 to-white px-4 py-4 sm:px-5">
            <div className="flex items-stretch gap-0 sm:gap-1">
              {IMPORT_WIZARD_STEPS.map((item, index) => {
                const isDone = step > item.id
                const isCurrent = step === item.id
                const Icon = item.Icon
                return (
                  <Fragment key={item.id}>
                    <motion.div
                      layout
                      className="relative z-[1] flex min-w-0 flex-1 flex-col"
                      initial={false}
                      animate={{ opacity: 1 }}
                      transition={{ layout: { type: 'spring', stiffness: 380, damping: 34 } }}
                    >
                      <motion.div
                        layout
                        className={cn(
                          'relative flex flex-col items-center gap-2 rounded-2xl border-2 px-2 py-3 text-center sm:px-3 sm:py-3.5',
                          isDone && 'border-emerald-400/50 bg-gradient-to-b from-emerald-50/95 to-white text-slate-900 shadow-sm',
                          isCurrent && !isDone && 'border-primary bg-gradient-to-b from-primary/[0.12] to-white text-slate-900 shadow-md ring-2 ring-primary/15',
                          !isDone && !isCurrent && 'border-slate-200/90 bg-white text-slate-500 shadow-sm',
                        )}
                        animate={
                          isCurrent
                            ? {
                                boxShadow: [
                                  '0 4px 14px -2px rgba(99, 102, 241, 0.18)',
                                  '0 8px 22px -4px rgba(99, 102, 241, 0.28)',
                                  '0 4px 14px -2px rgba(99, 102, 241, 0.18)',
                                ],
                              }
                            : { boxShadow: '0 1px 3px rgba(15, 23, 42, 0.06)' }
                        }
                        transition={
                          isCurrent
                            ? { duration: 2.4, repeat: Infinity, ease: 'easeInOut' }
                            : { duration: 0.22 }
                        }
                      >
                        {isCurrent && (
                          <motion.span
                            layoutId="import-step-glow"
                            className="pointer-events-none absolute inset-0 rounded-2xl bg-primary/[0.06]"
                            initial={{ opacity: 0 }}
                            animate={{ opacity: [0.45, 0.75, 0.45] }}
                            transition={{ duration: 2.2, repeat: Infinity, ease: 'easeInOut' }}
                          />
                        )}
                        <span className="absolute left-2 top-2 rounded-md bg-slate-900/90 px-1.5 py-0.5 text-[10px] font-semibold tabular-nums text-white shadow-sm sm:left-2.5 sm:top-2.5 sm:text-[11px]">
                          {item.id}
                        </span>
                        <div className="relative flex items-center justify-center pt-1">
                          <motion.div
                            className={cn(
                              'flex size-10 items-center justify-center rounded-full border sm:size-11',
                              isDone && 'border-emerald-200 bg-emerald-500 text-white shadow-inner',
                              isCurrent && !isDone && 'border-primary/35 bg-primary text-white shadow-md',
                              !isDone && !isCurrent && 'border-slate-200 bg-slate-50 text-slate-400',
                            )}
                            animate={isCurrent ? { scale: [1, 1.06, 1] } : { scale: 1 }}
                            transition={{ duration: 1.8, repeat: isCurrent ? Infinity : 0, ease: 'easeInOut' }}
                          >
                            {isDone ? (
                              <motion.span
                                initial={{ scale: 0.5, opacity: 0 }}
                                animate={{ scale: 1, opacity: 1 }}
                                transition={{ type: 'spring', stiffness: 420, damping: 22 }}
                              >
                                <CheckCircle2 className="size-5 sm:size-[1.35rem]" aria-hidden />
                              </motion.span>
                            ) : (
                              <Icon className="size-5 sm:size-[1.35rem]" aria-hidden strokeWidth={isCurrent ? 2.25 : 2} />
                            )}
                          </motion.div>
                        </div>
                        <div className="relative space-y-0.5">
                          <p className="text-xs font-semibold tracking-tight sm:text-sm">{item.title}</p>
                          <p className="hidden text-[10px] text-muted-foreground sm:block sm:text-[11px]">{item.hint}</p>
                        </div>
                      </motion.div>
                    </motion.div>
                    {index < IMPORT_WIZARD_STEPS.length - 1 && (
                      <div className="relative z-0 flex min-w-[10px] max-w-[72px] flex-[0.22] items-center self-center py-8 sm:flex-[0.18] sm:py-9">
                        <div className="h-[3px] w-full overflow-hidden rounded-full bg-slate-200/95">
                          <motion.div
                            className="h-full rounded-full bg-gradient-to-r from-primary via-indigo-500 to-emerald-500"
                            initial={false}
                            animate={{ width: step > item.id ? '100%' : '0%' }}
                            transition={{ type: 'spring', stiffness: 260, damping: 30 }}
                          />
                        </div>
                      </div>
                    )}
                  </Fragment>
                )
              })}
            </div>
          </div>

          <div className="min-h-0 flex-1 overflow-hidden px-4 py-4">
            <AnimatePresence mode="wait">
              {step === 1 && (
                <motion.div
                  key="step-upload"
                  initial={{ opacity: 0, y: 14, scale: 0.995 }}
                  animate={{ opacity: 1, y: 0, scale: 1 }}
                  exit={{ opacity: 0, y: -10, scale: 0.995 }}
                  transition={{ duration: 0.2, ease: [0.22, 1, 0.36, 1] }}
                  className="mx-auto w-full max-w-2xl space-y-5"
                >
            <button
              type="button"
              onClick={() => fileInputRef.current?.click()}
              onDragOver={(e) => { e.preventDefault(); setIsDragging(true) }}
              onDragLeave={() => setIsDragging(false)}
              onDrop={(e) => {
                e.preventDefault()
                setIsDragging(false)
                handleFilePicked(e.dataTransfer.files?.[0])
              }}
                    className={`w-full rounded-3xl border border-dashed px-8 py-16 text-center transition-all duration-300 ${
                      isDragging
                        ? 'border-primary bg-primary/12 shadow-[0_0_0_6px_rgba(99,102,241,0.08)]'
                        : 'border-slate-300/80 bg-white hover:border-primary/30 hover:bg-slate-50'
              }`}
            >
                    <div className="mx-auto mb-5 flex size-20 items-center justify-center rounded-full bg-primary/10 shadow-inner">
                      <UploadCloud className="size-10 text-primary" />
              </div>
                    <p className="text-xl font-semibold text-foreground">Drag and drop your XLSX/CSV file</p>
                    <p className="mt-2 text-sm text-muted-foreground">Supported formats: `.xlsx`, `.csv` · Max size: 10 MB</p>
                    <div className="mt-6">
                      <span className="inline-flex items-center rounded-md border border-slate-300 bg-white px-6 py-2.5 text-sm font-medium shadow-sm">
                  Browse files
                </span>
              </div>
            </button>

            <input
              ref={fileInputRef}
              type="file"
              accept=".csv,.xlsx"
              className="hidden"
              onChange={(e) => handleFilePicked(e.target.files?.[0])}
            />

            {file && (
                    <div className="flex items-center gap-2 rounded-lg border border-slate-300 bg-white px-4 py-2.5 text-sm">
                <FileSpreadsheet className="size-4 text-primary" />
                <span className="truncate">{file.name}</span>
                {parsing && <Loader2 className="ml-auto size-4 animate-spin text-muted-foreground" />}
              </div>
            )}
                </motion.div>
              )}

              {step === 2 && (
                <motion.div
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
              {metrics.withNotes > 0 ? (
                <Badge variant="outline">Notes on {metrics.withNotes} row(s)</Badge>
              ) : null}
              <Badge variant="outline">Estimated success: {metrics.successRate}%</Badge>
            </div>

                  <div className="h-[64vh] max-h-[64vh] overflow-auto rounded-xl border border-slate-200 bg-white overscroll-contain">
                    <table className="w-max min-w-[2600px] border-collapse text-sm text-foreground">
                <thead className="sticky top-0 z-20">
                        {groupedHeaderSegments.length > 0 && (
                          <tr className="border-b border-slate-200">
                            <th className="sticky left-0 z-30 w-14 bg-white px-4 py-3 text-left text-xs font-semibold text-slate-500">Sections</th>
                            {groupedHeaderSegments.map((seg, idx) => (
                              <th
                                key={`${seg.groupId}-${idx}`}
                                colSpan={seg.span}
                                className="bg-slate-50 px-4 py-3 text-center text-xs font-semibold uppercase tracking-wide text-slate-600"
                              >
                                {seg.label}
                              </th>
                            ))}
                            <th className="bg-slate-50 px-4 py-3 text-center text-xs font-semibold uppercase tracking-wide text-slate-600">Validation</th>
                          </tr>
                        )}
                  <tr className="border-b border-slate-200 bg-white">
                          <th className="sticky left-0 z-30 w-14 bg-white px-4 py-3 text-left">#</th>
                    {previewHeaders.map((header) => (
                            <th key={header} className="whitespace-nowrap bg-white px-4 py-3 text-left font-semibold">{header}</th>
                    ))}
                          <th className="min-w-72 bg-white px-4 py-3 text-left font-semibold">Validation</th>
                  </tr>
                </thead>
                <tbody>
                  {previewRows.map((item) => (
                    <tr key={item.id} className="border-b border-slate-100">
                            <td className="sticky left-0 z-10 bg-white px-4 py-2.5">{item.index}</td>
                      {previewHeaders.map((header) => {
                        const value = String(item.row[header] ?? '').trim()
                        return (
                          <td key={`${item.id}-${header}`} className="px-4 py-2.5">
                            {value || <span className="text-muted-foreground">—</span>}
                          </td>
                        )
                      })}
                      <td className="px-4 py-2.5">
                        <div className="space-y-1">
                          <span className="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-300">
                            <CheckCircle2 className="size-4" /> Ready
                          </span>
                          {item.issues.length > 0 && (
                            <p className="max-w-xs text-xs leading-snug text-muted-foreground">
                              {item.issues.join(' · ')}
                            </p>
                          )}
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <p className="text-xs text-muted-foreground">Scroll vertically for rows and horizontally for all columns.</p>
                </motion.div>
              )}

              {step === 3 && (
                <motion.div
                  key="step-import"
                  initial={{ opacity: 0, y: 14, scale: 0.995 }}
                  animate={{ opacity: 1, y: 0, scale: 1 }}
                  exit={{ opacity: 0, y: -10, scale: 0.995 }}
                  transition={{ duration: 0.2, ease: [0.22, 1, 0.36, 1] }}
                  className="mx-auto w-full max-w-xl space-y-4"
                >
            <div className="relative overflow-hidden rounded-xl border border-slate-200 bg-slate-50/80 p-5 shadow-sm">
              <div className="mb-4 flex items-center gap-3">
                <motion.div
                  className={`flex size-11 shrink-0 items-center justify-center rounded-full ${
                    importing
                      ? 'bg-primary/15 text-primary'
                      : importHasFailures
                        ? 'bg-amber-100 text-amber-700'
                        : backendResult
                          ? 'bg-emerald-100 text-emerald-700'
                          : 'bg-primary/15 text-primary'
                  }`}
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
                </motion.div>
                <div>
                  <p className="text-sm font-semibold text-foreground">
                    {importing ? 'Importing employees' : backendResult ? 'Import finished' : 'Import status'}
                  </p>
                  <p className="text-xs text-muted-foreground">
                    {importing ? importingLabel : backendResult ? 'Review the summary below.' : 'Waiting…'}
                  </p>
                </div>
              </div>

              <div className="relative h-3 w-full overflow-hidden rounded-full bg-slate-200/90">
                {importing && (
                  <motion.div
                    className="pointer-events-none absolute inset-y-0 w-1/3 rounded-full bg-gradient-to-r from-transparent via-white/70 to-transparent"
                    initial={{ x: '-40%' }}
                    animate={{ x: ['-40%', '140%'] }}
                    transition={{ duration: 1.35, repeat: Infinity, ease: 'linear' }}
                    aria-hidden
                  />
                )}
                <motion.div
                  className="absolute inset-y-0 left-0 rounded-full bg-primary"
                  initial={false}
                  animate={{ width: `${Math.min(100, Math.max(0, progress))}%` }}
                  transition={{ type: 'spring', stiffness: 120, damping: 22 }}
                />
              </div>
              <div className="mt-2 flex items-center justify-between text-xs text-muted-foreground">
                <span>{importing ? 'Please keep this window open…' : ' '}</span>
                <span className="font-mono font-medium tabular-nums text-foreground">{Math.round(progress)}%</span>
              </div>
              <Progress value={progress} className="sr-only" indicatorClassName="bg-primary" />
            </div>

            {backendResult && (
              <div className="space-y-2 rounded-md border border-slate-300 bg-white p-4">
                <div className="flex flex-wrap gap-2 text-sm">
                  <Badge className="bg-emerald-600 text-white hover:bg-emerald-600">Success: {backendResult.imported || 0}</Badge>
                  <Badge variant="destructive">Failed: {backendResult.failed || 0}</Badge>
                  <Badge variant="secondary">Skipped: {Math.max((metrics.total || 0) - (backendResult.total_rows || 0), 0)}</Badge>
                </div>
                {Array.isArray(backendResult.errors) && backendResult.errors.length > 0 && (
                  <div className="max-h-40 overflow-auto rounded border border-slate-300 bg-white p-2 text-xs text-muted-foreground">
                    {backendResult.errors.slice(0, 30).map((err, idx) => (
                      <p key={`${err.row || 0}-${idx}`}>Row {err.row || '-'}: {err.message}</p>
                    ))}
                  </div>
                )}
                {canUndoImport &&
                  backendResult.import_batch_id &&
                  (backendResult.imported || 0) > 0 &&
                  !importing && (
                    <div className="flex flex-col gap-2 border-t border-slate-200 pt-3 sm:flex-row sm:items-center sm:justify-between">
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
                            Removing…
                          </>
                        ) : (
                          <>Remove this import ({backendResult.imported || 0})</>
                        )}
                      </Button>
                    </div>
                  )}
              </div>
            )}
                </motion.div>
              )}
            </AnimatePresence>
          </div>

          <DialogFooter className="bg-white px-5 py-3">
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
                <Button type="button" className="bg-[#0f172a] text-white hover:bg-[#111827]" onClick={startImport} disabled={importing || !(file instanceof File)}>
                {importing ? <Loader2 className="mr-1.5 size-4 animate-spin" /> : null}
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
        </motion.div>
      </DialogContent>
    </Dialog>
  )
}
