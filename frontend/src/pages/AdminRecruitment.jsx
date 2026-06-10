import { createElement, useEffect, useMemo, useRef, useState } from 'react'
import { useLocation, useNavigate } from 'react-router-dom'
import {
  AlertTriangle,
  BadgeCheck,
  BriefcaseBusiness,
  CalendarDays,
  CircleUserRound,
  ClipboardList,
  Download,
  Eye,
  FileCheck2,
  FileText,
  GraduationCap,
  Mail,
  MoreHorizontal,
  Pencil,
  Phone,
  Plus,
  RefreshCw,
  Save,
  Search,
  Send,
  Sparkles,
  Trash2,
  UserCheck,
  UserPlus,
  Users,
  X,
} from 'lucide-react'
import {
  assignRecruitmentExam,
  deleteRecruitmentApplicant,
  downloadRecruitmentDocument,
  getRecruitmentApplicant,
  getRecruitmentApplicants,
  getRecruitmentExamAssignments,
  getRecruitmentExamTemplates,
  getRecruitmentMeta,
  recruitmentHiringAction,
  saveRecruitmentApplicant,
  saveRecruitmentExamTemplate,
  saveRecruitmentInterview,
  uploadRecruitmentDocument,
} from '@/api'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Badge } from '@/components/ui/badge'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuLabel,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu'
import { useToast } from '@/components/ui/use-toast'
import { cn } from '@/lib/utils'

const TABS = [
  ['applicants', 'Applicants', Users],
  ['initial', 'Initial Interviews', ClipboardList],
  ['exams', 'Exams', GraduationCap],
  ['final', 'Final Interviews', UserCheck],
  ['requirements', 'Requirements', FileCheck2],
  ['hiring', 'Hiring Decision', BadgeCheck],
]

const TAB_ROUTES = {
  applicants: '/admin/recruitment/applicants',
  initial: '/admin/recruitment/initial-interviews',
  exams: '/admin/recruitment/exams',
  final: '/admin/recruitment/final-interviews',
  requirements: '/admin/recruitment/requirements',
  hiring: '/admin/recruitment/hiring-decision',
}

function tabFromPath(pathname) {
  const match = Object.entries(TAB_ROUTES).find(([, route]) => pathname === route)
  return match?.[0] || 'applicants'
}

const APPLICANT_STATUSES = [
  'New',
  'For Initial Interview',
  'Initial Interview Passed',
  'For Exam',
  'Exam Passed',
  'For Final Interview',
  'Final Interview Passed',
  'For Requirements',
  'Hired',
  'Rejected',
]

const DEFAULT_META = {
  statuses: APPLICANT_STATUSES,
  document_types: ['Resume', 'Portfolio', 'NBI Clearance', 'Government ID', 'Diploma / TOR', 'Certificates', 'Birth Certificate', 'Medical', 'Other Documents'],
  document_statuses: ['Pending', 'Verified', 'Rejected'],
  interview_modes: ['Onsite', 'Online', 'Phone'],
  initial_results: ['Passed', 'Failed', 'Reschedule'],
  final_results: ['Passed', 'Failed', 'Hold'],
  question_types: ['Multiple Choice', 'True / False', 'Short Answer', 'Essay', 'File Upload'],
  departments: [],
  interviewers: [],
}

function blankApplicant() {
  return {
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    applied_position_id: '',
    applied_position: '',
    department_id: '',
    source: '',
    status: 'New',
    date_applied: new Date().toISOString().slice(0, 10),
  }
}

function SelectBox({ className = '', children, ...props }) {
  return (
    <select
      className={cn('h-9 w-full rounded-md border border-slate-200 bg-white px-3 text-xs text-slate-800 shadow-sm outline-none focus:border-orange-500 dark:border-border dark:bg-background dark:text-foreground', className)}
      {...props}
    >
      {children}
    </select>
  )
}

function statusTone(status) {
  if (status === 'Hired' || status === 'Verified' || status === 'Passed') return 'bg-emerald-100 text-emerald-700 hover:bg-emerald-100'
  if (status === 'Rejected' || status === 'Failed') return 'bg-red-100 text-red-700 hover:bg-red-100'
  if (/Pending|Review|Requirements|Exam|Interview|New/i.test(status || '')) return 'bg-amber-100 text-amber-700 hover:bg-amber-100'
  return 'bg-slate-100 text-slate-700 hover:bg-slate-100'
}

function formatDate(value) {
  if (!value) return '-'
  const d = new Date(value)
  return Number.isNaN(d.getTime()) ? String(value) : d.toLocaleDateString('en-PH')
}

export default function AdminRecruitment() {
  const { toast } = useToast()
  const location = useLocation()
  const navigate = useNavigate()
  const [activeTab, setActiveTab] = useState(() => tabFromPath(location.pathname))
  const [meta, setMeta] = useState(DEFAULT_META)
  const [applicants, setApplicants] = useState([])
  const [selectedApplicant, setSelectedApplicant] = useState(null)
  const [form, setForm] = useState(blankApplicant)
  const [applicantModalOpen, setApplicantModalOpen] = useState(false)
  const [editingApplicant, setEditingApplicant] = useState(null)
  const [documentModalOpen, setDocumentModalOpen] = useState(false)
  const [documentApplicant, setDocumentApplicant] = useState(null)
  const [deleteModalOpen, setDeleteModalOpen] = useState(false)
  const [applicantToDelete, setApplicantToDelete] = useState(null)
  const [deleting, setDeleting] = useState(false)
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [templates, setTemplates] = useState([])
  const [assignments, setAssignments] = useState([])
  const initializedRef = useRef(false)
  const applicantDataLoadedRef = useRef(false)
  const interviewerMetaLoadedRef = useRef(false)
  const examDataLoadedRef = useRef(false)

  const selectedInitial = useMemo(
    () => selectedApplicant?.interviews?.find((item) => item.interview_type === 'initial') || null,
    [selectedApplicant],
  )
  const selectedFinal = useMemo(
    () => selectedApplicant?.interviews?.find((item) => item.interview_type === 'final') || null,
    [selectedApplicant],
  )

  function mergeApplicant(applicant, prepend = false) {
    if (!applicant?.id) return
    setApplicants((rows) => {
      const existingIndex = rows.findIndex((row) => row.id === applicant.id)
      if (existingIndex === -1) return prepend ? [applicant, ...rows] : rows
      return rows.map((row) => (row.id === applicant.id ? { ...row, ...applicant } : row))
    })
  }

  function applyApplicant(applicant, prepend = false) {
    if (!applicant) return
    setSelectedApplicant(applicant)
    setForm(formFromApplicant(applicant))
    mergeApplicant(applicant, prepend)
    if (documentApplicant?.id === applicant.id) {
      setDocumentApplicant(applicant)
    }
  }

  async function loadMeta({ includeInterviewers = false } = {}) {
    const metaData = await getRecruitmentMeta(includeInterviewers ? { include_interviewers: 1 } : {})
    setMeta((current) => ({ ...DEFAULT_META, ...current, ...metaData }))
    if (includeInterviewers) interviewerMetaLoadedRef.current = true
  }

  async function loadApplicants() {
    const applicantData = await getRecruitmentApplicants({ q: search, status: statusFilter, per_page: 100 })
    setApplicants(applicantData.applicants || [])
    applicantDataLoadedRef.current = true
  }

  async function loadExamData() {
    const [templateData, assignmentData] = await Promise.all([
      getRecruitmentExamTemplates(),
      getRecruitmentExamAssignments(),
    ])
    setTemplates(templateData.templates || [])
    setAssignments(assignmentData.assignments || [])
    examDataLoadedRef.current = true
  }

  async function loadTabDependencies(tab, force = false) {
    if (tab === 'applicants' && (force || !applicantDataLoadedRef.current)) {
      await loadApplicants()
    }
    if ((tab === 'initial' || tab === 'final') && (force || !interviewerMetaLoadedRef.current)) {
      await loadMeta({ includeInterviewers: true })
    }
    if (tab === 'exams' && (force || !examDataLoadedRef.current)) {
      await loadExamData()
    }
  }

  async function refreshSelectedApplicant() {
    if (!selectedApplicant?.id) return
    const fresh = await getRecruitmentApplicant(selectedApplicant.id)
    applyApplicant(fresh.applicant)
  }

  async function load() {
    setLoading(true)
    try {
      const tasks = []
      if (activeTab === 'applicants') {
        tasks.push(loadApplicants(), loadMeta())
      } else {
        tasks.push(loadTabDependencies(activeTab, true))
        if (selectedApplicant?.id) tasks.push(refreshSelectedApplicant())
      }
      await Promise.all(tasks)
    } catch (error) {
      toast({ title: 'Failed to refresh recruitment', description: error.message, variant: 'error' })
    } finally {
      setLoading(false)
    }
  }

  useEffect(() => {
    const initialTab = tabFromPath(location.pathname)
    setLoading(true)
    const initialTasks = [
      loadMeta({ includeInterviewers: initialTab === 'initial' || initialTab === 'final' }),
    ]
    if (initialTab === 'applicants') initialTasks.push(loadApplicants())
    if (initialTab === 'exams') initialTasks.push(loadExamData())
    Promise.all(initialTasks)
      .catch((error) => toast({ title: 'Failed to load recruitment', description: error.message, variant: 'error' }))
      .finally(() => {
        initializedRef.current = true
        setLoading(false)
      })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  useEffect(() => {
    const nextTab = tabFromPath(location.pathname)
    setActiveTab(nextTab)
    if (!initializedRef.current) return
    setLoading(true)
    loadTabDependencies(nextTab)
      .catch((error) => toast({ title: 'Failed to load recruitment tab', description: error.message, variant: 'error' }))
      .finally(() => setLoading(false))
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [location.pathname])

  function openTab(key) {
    setActiveTab(key)
    navigate(TAB_ROUTES[key] || TAB_ROUTES.applicants)
  }

  function formFromApplicant(applicant) {
    return {
      first_name: applicant?.first_name || '',
      last_name: applicant?.last_name || '',
      email: applicant?.email || '',
      phone: applicant?.phone || '',
      applied_position_id: applicant?.applied_position_id || '',
      applied_position: applicant?.applied_position || '',
      department_id: applicant?.department_id || '',
      source: applicant?.source || '',
      status: applicant?.status || 'New',
      date_applied: applicant?.date_applied || new Date().toISOString().slice(0, 10),
    }
  }

  async function selectApplicant(applicant) {
    if (Array.isArray(applicant?.interviews)) {
      applyApplicant(applicant)
      return
    }
    try {
      const data = await getRecruitmentApplicant(applicant.id)
      applyApplicant(data.applicant)
    } catch (error) {
      toast({ title: 'Failed to open applicant', description: error.message, variant: 'error' })
    }
  }

  async function saveApplicant() {
    setSaving(true)
    try {
      const data = await saveRecruitmentApplicant(form, editingApplicant?.id || null)
      applyApplicant(data.applicant, !editingApplicant?.id)
      setEditingApplicant(data.applicant)
      setApplicantModalOpen(false)
      toast({ title: editingApplicant?.id ? 'Applicant updated' : 'Applicant created', variant: 'success' })
    } catch (error) {
      toast({ title: 'Save failed', description: error.message, variant: 'error' })
    } finally {
      setSaving(false)
    }
  }

  function removeApplicant(applicant) {
    setApplicantToDelete(applicant)
    setDeleteModalOpen(true)
  }

  async function confirmDeleteApplicant() {
    if (!applicantToDelete) return
    setDeleting(true)
    try {
      await deleteRecruitmentApplicant(applicantToDelete.id)
      if (selectedApplicant?.id === applicantToDelete.id) {
        setSelectedApplicant(null)
        setForm(blankApplicant())
      }
      setApplicants((rows) => rows.filter((row) => row.id !== applicantToDelete.id))
      setDeleteModalOpen(false)
      setApplicantToDelete(null)
      toast({ title: 'Applicant deleted', variant: 'success' })
    } catch (error) {
      toast({ title: 'Delete failed', description: error.message, variant: 'error' })
    } finally {
      setDeleting(false)
    }
  }

  function startNewApplicant() {
    setEditingApplicant(null)
    setForm(blankApplicant())
    setApplicantModalOpen(true)
    openTab('applicants')
  }

  function editApplicant(applicant) {
    setEditingApplicant(applicant)
    setForm(formFromApplicant(applicant))
    setApplicantModalOpen(true)
  }

  async function openDocumentModal(applicant) {
    try {
      const data = await getRecruitmentApplicant(applicant.id)
      applyApplicant(data.applicant)
      setDocumentApplicant(data.applicant)
      setDocumentModalOpen(true)
    } catch (error) {
      toast({ title: 'Failed to open documents', description: error.message, variant: 'error' })
    }
  }

  async function refreshDocumentApplicant(applicant) {
    const data = await getRecruitmentApplicant(applicant.id)
    setDocumentApplicant(data.applicant)
    applyApplicant(data.applicant)
  }

  return (
    <div className="space-y-4 text-slate-950 dark:text-foreground">
      <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="flex items-center gap-3 text-[22px] font-extrabold tracking-tight">
            <span className="flex size-9 items-center justify-center rounded-lg bg-orange-50 text-orange-600 dark:bg-orange-500/10">
              <BriefcaseBusiness className="size-5" strokeWidth={2.2} />
            </span>
            Recruitment {TABS.find(([key]) => key === activeTab)?.[1]}
          </h1>
          <p className="mt-1 pl-12 text-xs text-slate-500 dark:text-muted-foreground">
            Review and manage applicants across every stage of the recruitment workflow.
          </p>
        </div>
        <div className="flex flex-wrap gap-2">
          <Button variant="outline" className="h-9 gap-2 border-slate-200 bg-white px-4 text-xs shadow-sm" onClick={load} disabled={loading}>
            <RefreshCw className={cn('size-4', loading && 'animate-spin')} />
            Refresh
          </Button>
          <Button className="h-9 gap-2 bg-gradient-to-r from-orange-600 to-orange-500 px-4 text-xs text-white shadow-[0_6px_16px_-7px_rgba(234,88,12,0.8)] hover:from-orange-700 hover:to-orange-600" onClick={startNewApplicant}>
            <Plus className="size-4" />
            New Applicant
          </Button>
        </div>
      </div>

      <div className="flex gap-1 overflow-x-auto rounded-xl border border-slate-200 bg-white p-1.5 shadow-sm dark:border-border dark:bg-card">
        {TABS.map(([key, label, icon]) => (
          <button
            key={key}
            type="button"
            onClick={() => openTab(key)}
            className={cn(
              'inline-flex shrink-0 items-center gap-2 rounded-lg px-3 py-2 text-xs font-semibold text-slate-500 hover:bg-orange-50 hover:text-orange-700 dark:text-muted-foreground dark:hover:bg-orange-500/10',
              activeTab === key && 'bg-orange-50 text-orange-600 shadow-sm dark:bg-orange-500/15 dark:text-orange-300',
            )}
          >
            {createElement(icon, { className: 'size-4' })}
            {label}
          </button>
        ))}
      </div>

      <div className="grid gap-4">
        <section className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm dark:border-border dark:bg-card">
          {activeTab === 'applicants' && (
            <ApplicantsPanel
              applicants={applicants}
              meta={meta}
              search={search}
              setSearch={setSearch}
              statusFilter={statusFilter}
              setStatusFilter={setStatusFilter}
              onSearch={load}
              onSelect={selectApplicant}
              onEdit={editApplicant}
              onDocuments={openDocumentModal}
              onDelete={removeApplicant}
              onNew={startNewApplicant}
            />
          )}
          {activeTab === 'initial' && <InterviewPanel key={`${selectedApplicant?.id || 'none'}-initial-${selectedInitial?.id || 'new'}`} applicant={selectedApplicant} interview={selectedInitial} meta={meta} type="initial" onSaved={selectApplicant} />}
          {activeTab === 'exams' && (
            <ExamsPanel
              applicant={selectedApplicant}
              meta={meta}
              templates={templates}
              assignments={assignments}
              onReload={() => loadExamData()}
              onApplicantUpdated={applyApplicant}
            />
          )}
          {activeTab === 'final' && <InterviewPanel key={`${selectedApplicant?.id || 'none'}-final-${selectedFinal?.id || 'new'}`} applicant={selectedApplicant} interview={selectedFinal} meta={meta} type="final" onSaved={selectApplicant} />}
          {activeTab === 'requirements' && <RequirementsPanel applicant={selectedApplicant} onUpdated={selectApplicant} />}
          {activeTab === 'hiring' && <HiringPanel applicant={selectedApplicant} onUpdated={selectApplicant} />}
        </section>
      </div>
      <ApplicantModal
        open={applicantModalOpen}
        onOpenChange={setApplicantModalOpen}
        form={form}
        setForm={setForm}
        meta={meta}
        saving={saving}
        editingApplicant={editingApplicant}
        onSave={saveApplicant}
      />
      <DocumentModal
        open={documentModalOpen}
        onOpenChange={setDocumentModalOpen}
        applicant={documentApplicant}
        meta={meta}
        onUploaded={refreshDocumentApplicant}
      />
      <DeleteApplicantModal
        open={deleteModalOpen}
        onOpenChange={(nextOpen) => {
          if (deleting) return
          setDeleteModalOpen(nextOpen)
          if (!nextOpen) setApplicantToDelete(null)
        }}
        applicant={applicantToDelete}
        deleting={deleting}
        onConfirm={confirmDeleteApplicant}
      />
    </div>
  )
}

function DeleteApplicantModal({ open, onOpenChange, applicant, deleting, onConfirm }) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className="max-w-md rounded-[22px] border-slate-200 bg-white shadow-[0_24px_70px_-24px_rgba(15,23,42,0.45)] dark:border-border dark:bg-card"
        innerClassName="gap-0 p-0 pr-0"
        showCloseButton={!deleting}
        closeButtonClassName="right-5 top-5 size-9 rounded-xl border-slate-200 bg-white text-slate-500 hover:bg-slate-100 dark:bg-card"
        overlayClassName="bg-slate-950/55 backdrop-blur-[3px]"
      >
        <div className="px-6 pb-5 pt-6 pr-20">
          <span className="flex size-12 items-center justify-center rounded-2xl bg-red-50 text-red-600 ring-1 ring-red-100 dark:bg-red-500/10 dark:ring-red-500/20">
            <AlertTriangle className="size-5.5" />
          </span>
          <DialogHeader className="mt-4">
            <DialogTitle className="text-xl font-extrabold text-slate-950 dark:text-foreground">Delete applicant?</DialogTitle>
            <DialogDescription className="mt-1 text-sm leading-6">
              This permanently deletes <span className="font-bold text-slate-800 dark:text-foreground">{applicant?.full_name || 'this applicant'}</span> and their recruitment records.
            </DialogDescription>
          </DialogHeader>
          {applicant ? (
            <div className="mt-4 rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 dark:border-border dark:bg-muted/20">
              <p className="text-xs font-bold text-slate-900 dark:text-foreground">{applicant.applicant_no || 'Applicant record'}</p>
              <p className="mt-1 text-[11px] text-slate-500">{applicant.applied_position || 'No applied position'}</p>
            </div>
          ) : null}
        </div>
        <DialogFooter className="border-t border-slate-100 bg-slate-50/80 px-6 py-4 dark:border-border dark:bg-muted/20">
          <Button type="button" variant="outline" className="h-10 rounded-xl px-5 shadow-none" onClick={() => onOpenChange(false)} disabled={deleting}>
            Cancel
          </Button>
          <Button type="button" variant="destructive" className="h-10 gap-2 rounded-xl px-5" onClick={onConfirm} disabled={deleting}>
            {deleting ? <RefreshCw className="size-4 animate-spin" /> : <Trash2 className="size-4" />}
            {deleting ? 'Deleting...' : 'Delete applicant'}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  )
}

function ApplicantModal({ open, onOpenChange, form, setForm, meta, saving, editingApplicant, onSave }) {
  const fieldClass = 'h-11 rounded-xl border-slate-200 bg-slate-50/60 pl-10 text-sm shadow-none transition placeholder:text-slate-400 hover:border-slate-300 focus-visible:border-orange-500 focus-visible:bg-white focus-visible:ring-orange-500/15 dark:border-border dark:bg-input/20 dark:focus-visible:border-orange-400 dark:focus-visible:bg-background'
  const selectClass = 'h-11 appearance-none rounded-xl border-slate-200 bg-slate-50/60 pl-10 pr-10 text-sm shadow-none transition hover:border-slate-300 focus:border-orange-500 focus:bg-white focus:ring-4 focus:ring-orange-500/10 dark:border-border dark:bg-input/20 dark:focus:border-orange-400 dark:focus:bg-background'

  function submit(event) {
    event.preventDefault()
    onSave()
  }

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className="max-h-[94vh] max-w-[1120px] rounded-[24px] border-slate-200 bg-white shadow-[0_28px_80px_-24px_rgba(15,23,42,0.38)] sm:max-h-[92vh] dark:border-border dark:bg-card"
        innerClassName="gap-0 p-0 pr-0"
        closeButtonClassName="right-5 top-5 size-10 rounded-xl border-slate-200 bg-white text-slate-500 shadow-sm hover:border-orange-200 hover:bg-orange-50 hover:text-orange-700 dark:bg-card"
        overlayClassName="bg-slate-950/50 backdrop-blur-[3px]"
      >
        <form onSubmit={submit} className="flex min-h-0 flex-1 flex-col">
          <DialogHeader className="relative border-b border-slate-100 px-6 py-6 pr-20 sm:px-8 sm:py-7 dark:border-border">
            <div className="flex items-start gap-4">
              <span className="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-orange-600 text-white shadow-[0_10px_24px_-10px_rgba(234,88,12,0.75)]">
                <UserPlus className="size-5.5" />
              </span>
              <div className="min-w-0">
                <div className="mb-1 text-[10px] font-bold uppercase tracking-[0.18em] text-orange-600">
                  Recruitment intake
                </div>
                <DialogTitle className="text-2xl font-extrabold tracking-tight text-slate-950 dark:text-foreground">
                  {editingApplicant ? 'Edit applicant' : 'Add new applicant'}
                </DialogTitle>
                <DialogDescription className="mt-1 max-w-xl text-xs leading-5 sm:text-sm">
                  Capture the applicant&apos;s core details now. Interviews, exams, documents, and hiring decisions can be managed after saving.
                </DialogDescription>
              </div>
            </div>
          </DialogHeader>

          <div className="min-h-0 flex-1 overflow-y-auto px-6 py-5 sm:px-8 sm:py-6">
            <div className="mb-6 flex items-start gap-3 rounded-2xl border border-orange-200/80 bg-gradient-to-r from-orange-50 to-amber-50/60 px-4 py-3.5 dark:border-orange-500/20 dark:from-orange-500/10 dark:to-amber-500/5">
              <span className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-xl bg-white text-orange-600 shadow-sm dark:bg-card">
                <Sparkles className="size-4" />
              </span>
              <div>
                <p className="text-xs font-bold text-slate-900 dark:text-foreground">Start with the essentials</p>
                <p className="mt-0.5 text-[11px] leading-4.5 text-slate-600 dark:text-muted-foreground">
                  Creating this record adds the applicant to the screening pipeline. You can complete the rest of the workflow from Recruitment.
                </p>
              </div>
            </div>

            <section>
              <div className="mb-4 flex items-end justify-between gap-4 border-b border-slate-100 pb-3 dark:border-border">
                <div>
                  <h3 className="text-sm font-extrabold text-slate-950 dark:text-foreground">Applicant information</h3>
                  <p className="mt-1 text-[11px] text-slate-500">Contact details and the role being applied for.</p>
                </div>
                <span className="shrink-0 text-[10px] font-medium text-slate-400"><span className="text-orange-600">*</span> Required</span>
              </div>

              <div className="grid gap-x-5 gap-y-4 sm:grid-cols-2">
                <ApplicantField label="First name" required icon={CircleUserRound}>
                  <Input id="applicant-first-name" className={fieldClass} placeholder="e.g. Maria" autoComplete="given-name" required value={form.first_name} onChange={(e) => setForm((s) => ({ ...s, first_name: e.target.value }))} />
                </ApplicantField>
                <ApplicantField label="Last name" required icon={CircleUserRound}>
                  <Input id="applicant-last-name" className={fieldClass} placeholder="e.g. Santos" autoComplete="family-name" required value={form.last_name} onChange={(e) => setForm((s) => ({ ...s, last_name: e.target.value }))} />
                </ApplicantField>
                <ApplicantField label="Email address" required icon={Mail}>
                  <Input id="applicant-email" className={fieldClass} type="email" placeholder="name@example.com" autoComplete="email" required value={form.email} onChange={(e) => setForm((s) => ({ ...s, email: e.target.value }))} />
                </ApplicantField>
                <ApplicantField label="Phone number" required icon={Phone}>
                  <Input id="applicant-phone" className={fieldClass} type="tel" placeholder="+63 9XX XXX XXXX" autoComplete="tel" required value={form.phone} onChange={(e) => setForm((s) => ({ ...s, phone: e.target.value }))} />
                </ApplicantField>
                <ApplicantField label="Applied position" required icon={BriefcaseBusiness}>
                  <Input id="applicant-position" className={fieldClass} placeholder="e.g. Software Developer" required value={form.applied_position} onChange={(e) => setForm((s) => ({ ...s, applied_position: e.target.value }))} />
                </ApplicantField>
                <ApplicantField label="Application source" icon={Send}>
                  <SelectBox id="applicant-source" className={selectClass} value={form.source} onChange={(e) => setForm((s) => ({ ...s, source: e.target.value }))}>
                    <option value="">Select source</option>
                    <option value="Job Portal">Job Portal</option>
                    <option value="Referral">Referral</option>
                    <option value="Company Website">Company Website</option>
                    <option value="LinkedIn">LinkedIn</option>
                    <option value="Walk-in">Walk-in</option>
                    <option value="Other">Other</option>
                  </SelectBox>
                </ApplicantField>
                <ApplicantField label="Date applied" icon={CalendarDays}>
                  <Input id="applicant-date" className={fieldClass} type="date" value={form.date_applied} onChange={(e) => setForm((s) => ({ ...s, date_applied: e.target.value }))} />
                </ApplicantField>
                <ApplicantField label="Pipeline status" icon={BadgeCheck}>
                  <SelectBox id="applicant-status" className={selectClass} value={form.status} onChange={(e) => setForm((s) => ({ ...s, status: e.target.value }))}>
                    {meta.statuses.map((status) => <option key={status} value={status}>{status}</option>)}
                  </SelectBox>
                </ApplicantField>
              </div>
            </section>
          </div>

          <DialogFooter className="items-center border-t border-slate-100 bg-slate-50/80 px-6 py-4 sm:px-8 dark:border-border dark:bg-muted/20">
            <p className="mr-auto hidden text-[11px] text-slate-500 sm:block">You can edit these details at any time.</p>
            <Button type="button" variant="outline" className="h-10 rounded-xl border-slate-300 px-5 shadow-none" onClick={() => onOpenChange(false)}>
              Cancel
            </Button>
            <Button type="submit" className="h-10 gap-2 rounded-xl bg-gradient-to-r from-orange-600 to-orange-500 px-5 text-white shadow-[0_8px_18px_-9px_rgba(234,88,12,0.9)] hover:from-orange-700 hover:to-orange-600" disabled={saving}>
              {saving ? <RefreshCw className="size-4 animate-spin" /> : <Save className="size-4" />}
              {saving ? 'Saving applicant...' : editingApplicant ? 'Save changes' : 'Create applicant'}
            </Button>
          </DialogFooter>
        </form>
      </DialogContent>
    </Dialog>
  )
}

function ApplicantField({ label, required = false, icon, children }) {
  return (
    <Label className="block space-y-1.5 text-xs font-bold text-slate-700 dark:text-foreground">
      <span>
        {label}
        {required ? <span className="ml-0.5 text-orange-600">*</span> : null}
      </span>
      <span className="relative block">
        <span className="pointer-events-none absolute left-3.5 top-1/2 z-10 -translate-y-1/2 text-slate-400">
          {createElement(icon, { className: 'size-4' })}
        </span>
        {children}
      </span>
    </Label>
  )
}

function ApplicantsPanel({ applicants, meta, search, setSearch, statusFilter, setStatusFilter, onSearch, onSelect, onEdit, onDocuments, onDelete, onNew }) {
  return (
    <div className="space-y-4">
      <div className="rounded-2xl border border-slate-200 bg-slate-50/60 p-3 dark:border-border dark:bg-muted/20 sm:p-4">
        <div className="flex flex-col gap-3 lg:flex-row lg:items-center">
          <div className="relative min-w-0 flex-1">
            <Search className="pointer-events-none absolute left-3.5 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
            <Input
              className="h-11 rounded-xl border-slate-200 bg-white pl-10 shadow-none focus-visible:border-orange-500 focus-visible:ring-orange-500/15 dark:bg-background"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === 'Enter') onSearch()
              }}
              placeholder="Search applicant number, name, email, phone, or position..."
            />
          </div>
          <SelectBox className="h-11 rounded-xl border-slate-200 bg-white lg:w-64 dark:bg-background" value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
            <option value="">All pipeline statuses</option>
            {meta.statuses.map((status) => <option key={status} value={status}>{status}</option>)}
          </SelectBox>
          <Button className="h-11 rounded-xl bg-slate-950 px-6 text-white shadow-sm hover:bg-black dark:bg-orange-600 dark:hover:bg-orange-700" onClick={onSearch}>
            Search
          </Button>
        </div>
      </div>

      <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-[0_8px_24px_-20px_rgba(15,23,42,0.45)] dark:border-border dark:bg-card">
        <div className="flex flex-col gap-3 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between dark:border-border">
          <div>
            <h2 className="text-base font-extrabold text-slate-950 dark:text-foreground">Applicant directory</h2>
            <p className="mt-1 text-xs text-slate-500">Track candidates, review progress, and manage recruitment records.</p>
          </div>
          <div className="flex items-center gap-2">
            <span className="rounded-full border border-orange-100 bg-orange-50 px-3 py-1 text-[11px] font-bold text-orange-700 dark:border-orange-500/20 dark:bg-orange-500/10 dark:text-orange-300">
              {applicants.length} {applicants.length === 1 ? 'applicant' : 'applicants'}
            </span>
            <Button size="sm" className="h-8 rounded-lg bg-orange-600 text-white hover:bg-orange-700" onClick={onNew}>
              <Plus className="size-3.5" /> Add applicant
            </Button>
          </div>
        </div>
        <div className="overflow-x-auto">
        <table className="w-full min-w-[1240px] table-fixed text-left text-xs">
          <thead className="border-b border-slate-100 bg-slate-50/90 text-[10px] uppercase tracking-[0.06em] text-slate-500 dark:border-border dark:bg-muted/40 dark:text-muted-foreground">
            <tr>
              <th className="w-[11%] px-5 py-3 font-bold">Applicant No.</th>
              <th className="w-[20%] px-4 py-3 font-bold">Applicant</th>
              <th className="w-[14%] px-4 py-3 font-bold">Contact</th>
              <th className="w-[14%] px-4 py-3 font-bold">Applied Role</th>
              <th className="w-[9%] px-4 py-3 font-bold">Status</th>
              <th className="w-[19%] px-4 py-3 font-bold">Recruitment Progress</th>
              <th className="w-[8%] px-4 py-3 font-bold">Applied</th>
              <th className="w-[5%] px-4 py-3 text-center font-bold">Actions</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-slate-100 dark:divide-border">
            {applicants.map((applicant, index) => (
              <tr key={applicant.id} className="group transition-colors hover:bg-orange-50/40 dark:hover:bg-orange-500/5">
                <td className="px-5 py-4 align-middle">
                  <div className="font-mono text-[11px] font-bold text-slate-700 dark:text-foreground">{applicant.applicant_no}</div>
                  <div className="mt-1 text-[10px] text-slate-400">Candidate record</div>
                </td>
                <td className="px-4 py-4 align-middle">
                  <div className="flex items-center gap-3">
                    <span className={cn(
                      'flex size-9 shrink-0 items-center justify-center rounded-full text-[11px] font-extrabold text-white shadow-sm',
                      ['bg-slate-700', 'bg-orange-600', 'bg-blue-600', 'bg-violet-600', 'bg-emerald-600'][index % 5],
                    )}>
                      {`${applicant.first_name?.[0] || ''}${applicant.last_name?.[0] || ''}`.toUpperCase() || 'AP'}
                    </span>
                    <div className="min-w-0">
                      <div className="truncate text-[12px] font-extrabold text-slate-950 dark:text-foreground">{applicant.full_name}</div>
                      <div className="mt-1 truncate text-[10px] text-slate-500">{applicant.source || 'Source not specified'}</div>
                    </div>
                  </div>
                </td>
                <td className="px-4 py-4 align-middle">
                  <div className="truncate text-[11px] font-semibold text-slate-700 dark:text-foreground">{applicant.email || 'No email'}</div>
                  <div className="mt-1 truncate text-[10px] text-slate-500">{applicant.phone || 'No phone number'}</div>
                </td>
                <td className="px-4 py-4 align-middle">
                  <div className="text-[11px] font-bold text-slate-900 dark:text-foreground">{applicant.applied_position || 'Unassigned role'}</div>
                  <div className="mt-1 text-[10px] text-slate-500">{applicant.department_name || 'No department'}</div>
                </td>
                <td className="px-4 py-4 align-middle"><Badge className={cn('rounded-full px-2.5 py-1 text-[10px] font-bold shadow-none', statusTone(applicant.status))}>{applicant.status}</Badge></td>
                <td className="px-4 py-4 align-middle">
                  <div className="grid grid-cols-2 gap-1.5">
                    <ApplicantProgress label="Initial" value={applicant.initial_interview_status} />
                    <ApplicantProgress label="Exam" value={applicant.exam_status} />
                    <ApplicantProgress label="Final" value={applicant.final_interview_status} />
                    <ApplicantProgress label="Requirements" value={applicant.requirements_status} />
                  </div>
                </td>
                <td className="px-4 py-4 align-middle">
                  <div className="text-[11px] font-bold text-slate-700 dark:text-foreground">{formatDate(applicant.date_applied)}</div>
                </td>
                <td className="px-4 py-4 text-center align-middle">
                  <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                      <Button variant="outline" size="icon-sm" className="rounded-lg border-slate-200 bg-white text-slate-500 shadow-none hover:border-orange-200 hover:bg-orange-50 hover:text-orange-700 dark:bg-card" aria-label={`Actions for ${applicant.full_name}`}>
                        <MoreHorizontal className="size-4" />
                      </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" className="w-52 rounded-xl p-1.5 shadow-xl">
                      <DropdownMenuLabel className="px-2 py-1.5 text-[10px] font-bold uppercase tracking-wider text-slate-400">Applicant actions</DropdownMenuLabel>
                      <DropdownMenuItem className="rounded-lg py-2 text-xs" onClick={() => onSelect(applicant)}>
                        <Eye className="size-4 text-slate-500" /> Open workflow
                      </DropdownMenuItem>
                      <DropdownMenuItem className="rounded-lg py-2 text-xs" onClick={() => onDocuments(applicant)}>
                        <FileText className="size-4 text-orange-600" /> Manage documents
                      </DropdownMenuItem>
                      <DropdownMenuItem className="rounded-lg py-2 text-xs" onClick={() => onEdit(applicant)}>
                        <Pencil className="size-4 text-blue-600" /> Edit applicant
                      </DropdownMenuItem>
                      <DropdownMenuSeparator />
                      <DropdownMenuItem variant="destructive" className="rounded-lg py-2 text-xs" onClick={() => onDelete(applicant)}>
                        <Trash2 className="size-4" /> Delete applicant
                      </DropdownMenuItem>
                    </DropdownMenuContent>
                  </DropdownMenu>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        </div>
        {applicants.length === 0 ? (
          <div className="rounded-md border border-dashed border-slate-200 p-10 text-center text-sm text-slate-500">
            <div className="mx-auto mb-3 flex size-16 items-center justify-center rounded-xl bg-orange-50 text-orange-600">
              <BriefcaseBusiness className="size-8" />
            </div>
            <p className="font-bold text-slate-950">No applicants yet.</p>
            <p className="mt-1">Start by creating a new applicant.</p>
            <Button variant="outline" className="mt-4 border-orange-200 text-orange-700 hover:bg-orange-50" onClick={onNew}>
              <Plus className="mr-2 size-4" />
              New Applicant
            </Button>
          </div>
        ) : null}
      </div>
    </div>
  )
}

function ApplicantProgress({ label, value }) {
  const displayValue = value || 'Pending'
  const complete = /Passed|Completed|Verified|Hired/i.test(displayValue)
  const failed = /Failed|Rejected/i.test(displayValue)
  return (
    <div className={cn(
      'min-w-0 rounded-lg border px-2 py-1.5',
      complete && 'border-emerald-100 bg-emerald-50/80 dark:border-emerald-500/20 dark:bg-emerald-500/10',
      failed && 'border-red-100 bg-red-50/80 dark:border-red-500/20 dark:bg-red-500/10',
      !complete && !failed && 'border-slate-100 bg-slate-50 dark:border-border dark:bg-muted/30',
    )}>
      <div className="truncate text-[8px] font-bold uppercase tracking-wide text-slate-400">{label}</div>
      <div className={cn(
        'mt-0.5 truncate text-[9px] font-bold',
        complete && 'text-emerald-700 dark:text-emerald-300',
        failed && 'text-red-700 dark:text-red-300',
        !complete && !failed && 'text-slate-600 dark:text-muted-foreground',
      )}>{displayValue}</div>
    </div>
  )
}

function DocumentModal({ open, onOpenChange, applicant, meta, onUploaded }) {
  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent
        className="max-h-[96dvh] rounded-[24px] border-slate-200 bg-white shadow-[0_28px_80px_-24px_rgba(15,23,42,0.4)] dark:border-border dark:bg-card"
        innerClassName="gap-0 overflow-hidden p-0 pr-0"
        closeButtonClassName="right-5 top-5 size-10 rounded-xl border-slate-200 bg-white text-slate-500 shadow-sm hover:border-orange-200 hover:bg-orange-50 hover:text-orange-700 dark:bg-card"
        overlayClassName="bg-slate-950/50 backdrop-blur-[3px]"
        containerClassName="p-2 sm:p-3"
        surfaceStyle={{
          width: 'min(1360px, calc(100vw - 24px))',
          height: 'min(900px, calc(100dvh - 24px))',
          maxWidth: 'calc(100vw - 24px)',
        }}
      >
        <DialogHeader className="shrink-0 border-b border-slate-100 px-5 py-5 pr-20 sm:px-8 sm:py-6 dark:border-border">
          <div className="flex items-start gap-4">
            <span className="flex size-12 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-orange-500 to-orange-600 text-white shadow-[0_10px_24px_-10px_rgba(234,88,12,0.75)]">
              <FileText className="size-5.5" />
            </span>
            <div>
              <div className="mb-1 text-[10px] font-bold uppercase tracking-[0.18em] text-orange-600">Applicant record</div>
              <DialogTitle className="text-2xl font-extrabold tracking-tight text-slate-950 dark:text-foreground">
                Applicant Documents
              </DialogTitle>
              <DialogDescription className="mt-1 max-w-3xl text-xs leading-5 sm:text-sm">
                Upload, review, verify, and download the recruitment requirements submitted by {applicant?.full_name || 'this applicant'}.
              </DialogDescription>
            </div>
          </div>
        </DialogHeader>
        <div className="min-h-0 flex-1 overflow-y-auto px-5 py-5 sm:px-8 sm:py-6">
          <DocumentsPanel applicant={applicant} meta={meta} onUploaded={onUploaded} />
        </div>
      </DialogContent>
    </Dialog>
  )
}

function DocumentsPanel({ applicant, meta, onUploaded }) {
  const { toast } = useToast()
  const [documentType, setDocumentType] = useState('Resume')
  const [status, setStatus] = useState('Pending')
  const [remarks, setRemarks] = useState('')
  const [file, setFile] = useState(null)
  const [downloadingId, setDownloadingId] = useState(null)

  if (!applicant) return <EmptySelectApplicant />

  async function upload(documentId = null) {
    try {
      await uploadRecruitmentDocument(applicant.id, { document_type: documentType, status, remarks, file }, documentId)
      setFile(null)
      setRemarks('')
      toast({ title: documentId ? 'Document replaced' : 'Document uploaded', variant: 'success' })
      await onUploaded(applicant)
    } catch (error) {
      toast({ title: 'Document failed', description: error.message, variant: 'error' })
    }
  }

  async function openDocument(document) {
    setDownloadingId(document.id)
    try {
      const blob = await downloadRecruitmentDocument(applicant.id, document.id)
      const objectUrl = URL.createObjectURL(blob)
      const link = window.document.createElement('a')
      link.href = objectUrl
      link.target = '_blank'
      link.rel = 'noopener noreferrer'
      link.download = document.file_name || 'applicant-document'
      window.document.body.appendChild(link)
      link.click()
      link.remove()
      window.setTimeout(() => URL.revokeObjectURL(objectUrl), 60000)
    } catch (error) {
      toast({ title: 'Download failed', description: error.message, variant: 'error' })
    } finally {
      setDownloadingId(null)
    }
  }

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 className="text-lg font-extrabold text-slate-950 dark:text-foreground">{applicant.full_name}</h2>
          <p className="mt-1 text-xs text-slate-500">{applicant.applied_position || 'Position not specified'}</p>
        </div>
        <span className="rounded-full border border-orange-100 bg-orange-50 px-3 py-1 text-[11px] font-bold text-orange-700 dark:border-orange-500/20 dark:bg-orange-500/10 dark:text-orange-300">
          {(applicant.documents || []).length} {(applicant.documents || []).length === 1 ? 'document' : 'documents'}
        </span>
      </div>

      <div className="rounded-2xl border border-slate-200 bg-slate-50/70 p-4 dark:border-border dark:bg-muted/20 sm:p-6">
        <div className="mb-4 border-b border-slate-200/80 pb-3 dark:border-border">
          <h3 className="text-sm font-extrabold text-slate-950 dark:text-foreground">Upload a document</h3>
          <p className="mt-1 text-[11px] text-slate-500">Choose the requirement type, set its review status, and attach the applicant&apos;s file.</p>
        </div>
        <div className="grid gap-x-5 gap-y-4 md:grid-cols-2 xl:grid-cols-12">
          <div className="min-w-0 xl:col-span-4">
            <Label htmlFor="recruitment-document-type" className="mb-2 block text-xs font-bold text-slate-700 dark:text-foreground">Document type</Label>
            <SelectBox id="recruitment-document-type" className="h-11 rounded-xl bg-white px-3 dark:bg-background" value={documentType} onChange={(e) => setDocumentType(e.target.value)}>
              {meta.document_types.map((type) => <option key={type}>{type}</option>)}
            </SelectBox>
          </div>
          <div className="min-w-0 xl:col-span-3">
            <Label htmlFor="recruitment-document-status" className="mb-2 block text-xs font-bold text-slate-700 dark:text-foreground">Review status</Label>
            <SelectBox id="recruitment-document-status" className="h-11 rounded-xl bg-white px-3 dark:bg-background" value={status} onChange={(e) => setStatus(e.target.value)}>
              {meta.document_statuses.map((value) => <option key={value}>{value}</option>)}
            </SelectBox>
          </div>
          <div className="min-w-0 md:col-span-2 xl:col-span-5">
            <Label htmlFor="recruitment-document-remarks" className="mb-2 block text-xs font-bold text-slate-700 dark:text-foreground">Remarks</Label>
            <Input id="recruitment-document-remarks" className="h-11 rounded-xl bg-white shadow-none dark:bg-background" placeholder="Optional review notes" value={remarks} onChange={(e) => setRemarks(e.target.value)} />
          </div>
          <div className="min-w-0 md:col-span-2 xl:col-span-9">
            <Label htmlFor="recruitment-document-file" className="mb-2 block text-xs font-bold text-slate-700 dark:text-foreground">Select file</Label>
            <Input id="recruitment-document-file" className="h-11 rounded-xl bg-white py-1.5 shadow-none file:mr-3 file:rounded-lg file:bg-orange-50 file:px-3 file:text-orange-700 dark:bg-background" type="file" onChange={(e) => setFile(e.target.files?.[0] || null)} />
          </div>
          <div className="flex items-end md:col-span-2 xl:col-span-3">
            <Button className="h-11 w-full gap-2 rounded-xl bg-gradient-to-r from-orange-600 to-orange-500 px-5 text-white shadow-[0_8px_18px_-9px_rgba(234,88,12,0.9)] hover:from-orange-700 hover:to-orange-600" onClick={() => upload()} disabled={!file}>
              <FileText className="size-4" /> Upload document
            </Button>
          </div>
        </div>
      </div>

      <div className="overflow-hidden rounded-2xl border border-slate-200 bg-white dark:border-border dark:bg-card">
        <div className="border-b border-slate-100 px-4 py-3 dark:border-border">
          <h3 className="text-sm font-extrabold">Submitted documents</h3>
          <p className="mt-0.5 text-[11px] text-slate-500">All files currently attached to this applicant.</p>
        </div>
        <div className="hidden max-h-[42vh] overflow-auto xl:block">
          <table className="w-full table-fixed text-left text-xs">
            <thead className="sticky top-0 z-10 bg-slate-50 text-[10px] uppercase tracking-wide text-slate-600 dark:bg-muted">
              <tr>
                <th className="w-[15%] px-4 py-3 font-bold">Document Type</th>
                <th className="w-[20%] px-4 py-3 font-bold">File Name</th>
                <th className="w-[14%] px-4 py-3 font-bold">Uploaded By</th>
                <th className="w-[13%] px-4 py-3 font-bold">Uploaded Date</th>
                <th className="w-[10%] px-4 py-3 font-bold">Status</th>
                <th className="w-[15%] px-4 py-3 font-bold">Remarks</th>
                <th className="w-[13%] px-4 py-3 font-bold">Actions</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-slate-100 dark:divide-border">
              {(applicant.documents || []).map((doc) => (
                <tr key={doc.id} className="hover:bg-orange-50/30 dark:hover:bg-orange-500/5">
                  <td className="px-4 py-3 font-bold text-slate-900 dark:text-foreground">{doc.document_type}</td>
                  <td className="max-w-60 truncate px-4 py-3">{doc.file_name}</td>
                  <td className="px-4 py-3">{doc.uploaded_by || '-'}</td>
                  <td className="whitespace-nowrap px-4 py-3">{formatDate(doc.uploaded_date)}</td>
                  <td className="px-4 py-3"><Badge className={statusTone(doc.status)}>{doc.status}</Badge></td>
                  <td className="max-w-64 truncate px-4 py-3">{doc.remarks || '-'}</td>
                  <td className="whitespace-nowrap px-4 py-3">
                    <button type="button" className="inline-flex items-center gap-1.5 font-semibold text-orange-700 hover:underline disabled:cursor-wait disabled:opacity-60" onClick={() => openDocument(doc)} disabled={downloadingId === doc.id}>
                      {downloadingId === doc.id ? <RefreshCw className="size-3.5 animate-spin" /> : <Download className="size-3.5" />}
                      {downloadingId === doc.id ? 'Opening...' : 'View / Download'}
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        <div className="divide-y divide-slate-100 xl:hidden dark:divide-border">
          {(applicant.documents || []).map((doc) => (
            <div key={doc.id} className="space-y-3 p-4">
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <p className="text-xs font-extrabold text-slate-900 dark:text-foreground">{doc.document_type}</p>
                  <p className="mt-1 truncate text-[11px] text-slate-500">{doc.file_name}</p>
                </div>
                <Badge className={statusTone(doc.status)}>{doc.status}</Badge>
              </div>
              <div className="grid grid-cols-2 gap-3 text-[11px]">
                <div><span className="block text-slate-400">Uploaded by</span><span className="mt-0.5 block font-semibold">{doc.uploaded_by || '-'}</span></div>
                <div><span className="block text-slate-400">Uploaded date</span><span className="mt-0.5 block font-semibold">{formatDate(doc.uploaded_date)}</span></div>
              </div>
              {doc.remarks ? <p className="rounded-lg bg-slate-50 px-3 py-2 text-[11px] text-slate-600 dark:bg-muted/30 dark:text-muted-foreground">{doc.remarks}</p> : null}
              <button type="button" className="inline-flex items-center gap-1.5 text-xs font-bold text-orange-700 hover:underline disabled:cursor-wait disabled:opacity-60" onClick={() => openDocument(doc)} disabled={downloadingId === doc.id}>
                {downloadingId === doc.id ? <RefreshCw className="size-3.5 animate-spin" /> : <Download className="size-3.5" />}
                {downloadingId === doc.id ? 'Opening...' : 'View / Download'}
              </button>
            </div>
          ))}
        </div>
        {(applicant.documents || []).length === 0 ? (
          <div className="px-6 py-12 text-center text-sm text-slate-500">No documents have been uploaded for this applicant.</div>
        ) : null}
      </div>
    </div>
  )
}

function InterviewPanel({ applicant, interview, meta, type, onSaved }) {
  const { toast } = useToast()
  const isFinal = type === 'final'
  const [form, setForm] = useState(() => ({
    interview_type: type,
    interviewer_id: interview?.interviewer_id || '',
    interview_date: interview?.interview_date ? String(interview.interview_date).slice(0, 16) : '',
    mode: interview?.mode || 'Onsite',
    score: interview?.score ?? '',
    notes: interview?.notes || '',
    result: interview?.result || '',
    next_step: interview?.next_step || '',
    evaluation: interview?.evaluation || {},
  }))

  if (!applicant) return <EmptySelectApplicant />

  const evaluationFields = isFinal
    ? ['Technical Fit', 'Culture Fit', 'Role Understanding', 'Compensation Alignment', 'Availability', 'Final Decision']
    : ['Communication', 'Work Experience', 'Attitude', 'Availability', 'Salary Expectation', 'Overall Recommendation']

  async function save() {
    try {
      const data = await saveRecruitmentInterview(applicant.id, form, interview?.id || null)
      toast({ title: `${isFinal ? 'Final' : 'Initial'} interview saved`, variant: 'success' })
      await onSaved(data.applicant)
    } catch (error) {
      toast({ title: 'Interview failed', description: error.message, variant: 'error' })
    }
  }

  return (
    <div className="space-y-4">
      <h2 className="text-lg font-bold">{isFinal ? 'Final Interview' : 'Initial Interview'} - {applicant.full_name}</h2>
      <div className="grid gap-3 md:grid-cols-3">
        <Label className="text-xs font-semibold">Interview Date<Input className="mt-1 h-9" type="datetime-local" value={form.interview_date} onChange={(e) => setForm((s) => ({ ...s, interview_date: e.target.value }))} /></Label>
        <Label className="text-xs font-semibold">Interviewer<SelectBox className="mt-1" value={form.interviewer_id} onChange={(e) => setForm((s) => ({ ...s, interviewer_id: e.target.value }))}><option value="">Select interviewer</option>{meta.interviewers.map((user) => <option key={user.id} value={user.id}>{user.name}</option>)}</SelectBox></Label>
        <Label className="text-xs font-semibold">Mode<SelectBox className="mt-1" value={form.mode} onChange={(e) => setForm((s) => ({ ...s, mode: e.target.value }))}>{meta.interview_modes.map((mode) => <option key={mode}>{mode}</option>)}</SelectBox></Label>
        <Label className="text-xs font-semibold">Score<Input className="mt-1 h-9" type="number" value={form.score} onChange={(e) => setForm((s) => ({ ...s, score: e.target.value }))} /></Label>
        <Label className="text-xs font-semibold">Result<SelectBox className="mt-1" value={form.result} onChange={(e) => setForm((s) => ({ ...s, result: e.target.value }))}><option value="">Pending</option>{(isFinal ? meta.final_results : meta.initial_results).map((result) => <option key={result}>{result}</option>)}</SelectBox></Label>
        <Label className="text-xs font-semibold">Next Step<Input className="mt-1 h-9" value={form.next_step} onChange={(e) => setForm((s) => ({ ...s, next_step: e.target.value }))} /></Label>
        <Label className="text-xs font-semibold md:col-span-3">Notes<Textarea className="mt-1" value={form.notes} onChange={(e) => setForm((s) => ({ ...s, notes: e.target.value }))} /></Label>
      </div>
      <div className="grid gap-3 md:grid-cols-2">
        {evaluationFields.map((field) => (
          <Label key={field} className="text-xs font-semibold">
            {field}
            <Input className="mt-1 h-9" value={form.evaluation?.[field] || ''} onChange={(e) => setForm((s) => ({ ...s, evaluation: { ...(s.evaluation || {}), [field]: e.target.value } }))} />
          </Label>
        ))}
      </div>
      <Button className="gap-2 bg-orange-600 text-white hover:bg-orange-700" onClick={save}><Save className="size-4" /> Save Interview</Button>
    </div>
  )
}

function ExamsPanel({ applicant, meta, templates, assignments, onReload, onApplicantUpdated }) {
  const { toast } = useToast()
  const [templateForm, setTemplateForm] = useState({ title: '', position_id: '', duration_minutes: 60, passing_score: 1, status: 'Active', questions: [] })
  const [assignTemplateId, setAssignTemplateId] = useState('')

  function addQuestion() {
    setTemplateForm((s) => ({ ...s, questions: [...s.questions, { question_type: 'Multiple Choice', question: '', choices: [], correct_answer: '', points: 1 }] }))
  }

  async function saveTemplate() {
    try {
      await saveRecruitmentExamTemplate(templateForm)
      setTemplateForm({ title: '', position_id: '', duration_minutes: 60, passing_score: 1, status: 'Active', questions: [] })
      toast({ title: 'Exam template saved', variant: 'success' })
      await onReload()
    } catch (error) {
      toast({ title: 'Template failed', description: error.message, variant: 'error' })
    }
  }

  async function assign() {
    if (!applicant || !assignTemplateId) return
    try {
      const data = await assignRecruitmentExam(applicant.id, assignTemplateId)
      onApplicantUpdated(data.applicant)
      toast({ title: 'Exam assigned', variant: 'success' })
      await onReload()
    } catch (error) {
      toast({ title: 'Assign failed', description: error.message, variant: 'error' })
    }
  }

  return (
    <div className="space-y-5">
      <h2 className="text-lg font-bold">Exams</h2>
      <div className="grid gap-4 xl:grid-cols-3">
        <div className="rounded-lg border border-slate-200 p-3 xl:col-span-1">
          <h3 className="font-bold">Exam Templates</h3>
          <div className="mt-3 grid gap-2">
            <Input placeholder="Exam Title" value={templateForm.title} onChange={(e) => setTemplateForm((s) => ({ ...s, title: e.target.value }))} />
            <SelectBox value={templateForm.position_id} onChange={(e) => setTemplateForm((s) => ({ ...s, position_id: e.target.value }))}><option value="">Any position</option>{meta.departments.map((d) => <option key={d.id} value={d.id}>{d.name}</option>)}</SelectBox>
            <Input type="number" placeholder="Duration minutes" value={templateForm.duration_minutes} onChange={(e) => setTemplateForm((s) => ({ ...s, duration_minutes: Number(e.target.value) }))} />
            <Input type="number" placeholder="Passing score" value={templateForm.passing_score} onChange={(e) => setTemplateForm((s) => ({ ...s, passing_score: Number(e.target.value) }))} />
            {templateForm.questions.map((question, index) => (
              <div key={index} className="rounded-md border border-slate-200 p-2">
                <SelectBox value={question.question_type} onChange={(e) => setTemplateForm((s) => ({ ...s, questions: s.questions.map((q, i) => i === index ? { ...q, question_type: e.target.value } : q) }))}>{meta.question_types.map((type) => <option key={type}>{type}</option>)}</SelectBox>
                <Textarea className="mt-2" placeholder="Question" value={question.question} onChange={(e) => setTemplateForm((s) => ({ ...s, questions: s.questions.map((q, i) => i === index ? { ...q, question: e.target.value } : q) }))} />
                <Input className="mt-2" placeholder="Choices, comma-separated" onChange={(e) => setTemplateForm((s) => ({ ...s, questions: s.questions.map((q, i) => i === index ? { ...q, choices: e.target.value.split(',').map((v) => v.trim()).filter(Boolean) } : q) }))} />
                <Input className="mt-2" placeholder="Correct answer" value={question.correct_answer} onChange={(e) => setTemplateForm((s) => ({ ...s, questions: s.questions.map((q, i) => i === index ? { ...q, correct_answer: e.target.value } : q) }))} />
              </div>
            ))}
            <Button variant="outline" onClick={addQuestion}>Add Question</Button>
            <Button className="bg-orange-600 text-white hover:bg-orange-700" onClick={saveTemplate}>Save Template</Button>
          </div>
        </div>
        <div className="rounded-lg border border-slate-200 p-3 xl:col-span-2">
          <h3 className="font-bold">Assigned Exams / Results</h3>
          <div className="mt-3 flex gap-2">
            <SelectBox value={assignTemplateId} onChange={(e) => setAssignTemplateId(e.target.value)} disabled={!applicant}>
              <option value="">Select template to assign</option>
              {templates.map((template) => <option key={template.id} value={template.id}>{template.title}</option>)}
            </SelectBox>
            <Button className="gap-2" onClick={assign} disabled={!applicant || !assignTemplateId}><Send className="size-4" /> Assign</Button>
          </div>
          <div className="mt-4 overflow-x-auto">
            <table className="w-full text-left text-xs">
              <thead className="bg-slate-50"><tr>{['Applicant', 'Exam', 'Status', 'Score', 'Result', 'Link'].map((h) => <th key={h} className="px-3 py-2">{h}</th>)}</tr></thead>
              <tbody className="divide-y divide-slate-200">
                {assignments.map((assignment) => (
                  <tr key={assignment.id}>
                    <td className="px-3 py-2">{assignment.applicant_name || '-'}</td>
                    <td className="px-3 py-2">{assignment.exam_title}</td>
                    <td className="px-3 py-2">{assignment.status}</td>
                    <td className="px-3 py-2">{assignment.score ?? '-'}</td>
                    <td className="px-3 py-2">{assignment.result || '-'}</td>
                    <td className="px-3 py-2"><a className="text-orange-700 hover:underline" href={`/recruitment/exam/${assignment.exam_link_token}`} target="_blank" rel="noreferrer">Open link</a></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </div>
  )
}

function RequirementsPanel({ applicant, onUpdated }) {
  const { toast } = useToast()
  if (!applicant) return <EmptySelectApplicant />

  async function moveRequirements() {
    try {
      const data = await recruitmentHiringAction(applicant.id, 'move_requirements')
      toast({ title: 'Applicant moved to requirements', variant: 'success' })
      await onUpdated(data.applicant)
    } catch (error) {
      toast({ title: 'Update failed', description: error.message, variant: 'error' })
    }
  }

  return (
    <div className="space-y-4">
      <h2 className="text-lg font-bold">Requirements</h2>
      <p className="text-sm text-slate-500">Verify uploaded requirements from the applicant&apos;s Documents modal in the Applicants tab. Current requirements status: <b>{applicant.requirements_status || 'Pending'}</b></p>
      <Button onClick={moveRequirements}>Move to Requirements</Button>
    </div>
  )
}

function HiringPanel({ applicant, onUpdated }) {
  const { toast } = useToast()
  if (!applicant) return <EmptySelectApplicant />

  async function action(kind) {
    try {
      const data = await recruitmentHiringAction(applicant.id, kind)
      toast({
        title: kind === 'create_employee' ? 'Employee record created' : 'Hiring decision saved',
        description: data.employee?.temporary_password ? `Temporary password: ${data.employee.temporary_password}` : undefined,
        variant: 'success',
      })
      await onUpdated(data.applicant)
    } catch (error) {
      toast({ title: 'Hiring action failed', description: error.message, variant: 'error' })
    }
  }

  return (
    <div className="space-y-4">
      <h2 className="text-lg font-bold">Hiring Decision</h2>
      <p className="text-sm text-slate-500">After final interview, choose the next action. Create Employee Record transfers name, email, phone, position, department, and uploaded requirements.</p>
      <div className="flex flex-wrap gap-2">
        <Button className="gap-2 bg-emerald-600 text-white hover:bg-emerald-700" onClick={() => action('mark_hired')}>Mark as Hired</Button>
        <Button variant="destructive" onClick={() => action('reject')}>Reject Applicant</Button>
        <Button variant="outline" onClick={() => action('move_requirements')}>Move to Requirements</Button>
        <Button className="gap-2 bg-orange-600 text-white hover:bg-orange-700" onClick={() => action('create_employee')} disabled={Boolean(applicant.created_employee_id)}>
          <UserPlus className="size-4" />
          Create Employee Record
        </Button>
      </div>
    </div>
  )
}

function EmptySelectApplicant() {
  return <p className="rounded-md border border-dashed border-slate-200 p-4 text-sm text-slate-500">Select an applicant from the Applicants tab first.</p>
}
