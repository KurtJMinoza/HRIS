import { useState, useEffect, useCallback, useMemo, useRef } from 'react'
import { motion as Motion } from 'framer-motion'
import {
  ClipboardCheck, FileSpreadsheet, Plus, Loader2, Trash2, Pencil, Eye,
  XCircle, Clock, Star, Users, FileText, RefreshCw, Search, TrendingUp,
  List, Building2, CalendarClock, IdCard, CheckCircle2, ChevronRight,
} from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select'
import {
  Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogDescription,
} from '@/components/ui/dialog'
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar'
import { useToast } from '@/components/ui/use-toast'
import {
  getEvaluationForms, createEvaluationForm, updateEvaluationForm, deleteEvaluationForm,
  getEvaluationEmployees,
  getEvaluations, createEvaluation, submitEvaluation, deleteEvaluation,
  reviewEvaluation, completeEvaluation,
  profileImageUrl, getEvaluationDashboardSummary,
  getEvaluationBootstrap,
} from '@/api'
import {
  ADMIN_FORM_DIALOG_BODY_CLASS,
  ADMIN_FORM_DIALOG_DESC_CLASS,
  ADMIN_FORM_DIALOG_FOOTER_CLASS,
  ADMIN_FORM_DIALOG_HEADER_INNER_CLASS,
  ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS,
  ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS,
  ADMIN_FORM_DIALOG_TITLE_CLASS,
  adminFormDialogContentClass,
  ADMIN_FORM_DIALOG_MAX_W_LG,
} from '@/lib/adminFormDialogStyles'
import { cn } from '@/lib/utils'
import { useAuth } from '@/contexts/AuthContext'
import { AgcBrandLogo } from '@/components/AgcBrandLogo'

const SECTION_MAX_WEIGHT = 100
const EMPLOYEES_PER_PAGE = 20
const EVALUATED_STATUSES = ['submitted', 'under_review', 'completed']

const evalCardClass =
  'rounded-[18px] border border-border/70 bg-card shadow-[0_12px_34px_-24px_rgba(15,23,42,0.55),0_2px_10px_-7px_rgba(15,23,42,0.25)] dark:border-white/10 dark:bg-card/95 dark:shadow-[0_18px_44px_-24px_rgba(0,0,0,0.75)]'
const evalPrimaryButtonClass =
  'h-11 gap-2 rounded-lg bg-brand px-5 text-sm font-semibold text-brand-foreground shadow-[0_12px_22px_-14px_rgba(234,88,12,0.9)] transition hover:bg-brand-strong dark:shadow-[0_12px_24px_-16px_rgba(251,146,60,0.75)]'
const evalOutlineButtonClass =
  'h-11 gap-2 rounded-lg border-border/80 bg-card px-5 text-sm font-semibold text-foreground shadow-sm transition hover:border-brand/45 hover:bg-brand/10 hover:text-brand dark:border-white/10 dark:bg-card/80 dark:hover:bg-brand/12'

const STATUS_COLORS = {
  draft: { bg: 'bg-gray-100 text-gray-700 dark:bg-gray-800/70 dark:text-gray-300', icon: Clock },
  submitted: { bg: 'bg-blue-100 text-blue-700 dark:bg-blue-900/50 dark:text-blue-200', icon: FileText },
  under_review: { bg: 'bg-amber-100 text-amber-700 dark:bg-amber-900/50 dark:text-amber-200', icon: Clock },
  completed: { bg: 'bg-emerald-100 text-emerald-700 dark:bg-emerald-900/50 dark:text-emerald-200', icon: ClipboardCheck },
}

const STATUS_META = {
  draft: { dot: 'bg-gray-400', label: 'Draft' },
  submitted: { dot: 'bg-blue-500', label: 'Submitted' },
  under_review: { dot: 'bg-amber-500', label: 'Under Review' },
  completed: { dot: 'bg-emerald-500', label: 'Completed' },
}

const RATING_STYLES = {
  'Outstanding': { text: 'text-emerald-700 dark:text-emerald-400', bar: 'bg-emerald-500', ring: 'ring-emerald-500/25', chip: 'bg-emerald-50 dark:bg-emerald-500/10', pct: 100 },
  'Excellent': { text: 'text-teal-700 dark:text-teal-400', bar: 'bg-teal-500', ring: 'ring-teal-500/25', chip: 'bg-teal-50 dark:bg-teal-500/10', pct: 92 },
  'Very Good': { text: 'text-sky-700 dark:text-sky-400', bar: 'bg-sky-500', ring: 'ring-sky-500/25', chip: 'bg-sky-50 dark:bg-sky-500/10', pct: 84 },
  'Good': { text: 'text-indigo-700 dark:text-indigo-400', bar: 'bg-indigo-500', ring: 'ring-indigo-500/25', chip: 'bg-indigo-50 dark:bg-indigo-500/10', pct: 77 },
  'Satisfactory': { text: 'text-amber-700 dark:text-amber-400', bar: 'bg-amber-500', ring: 'ring-amber-500/25', chip: 'bg-amber-50 dark:bg-amber-500/10', pct: 71 },
  'Needs Improvement': { text: 'text-rose-700 dark:text-rose-400', bar: 'bg-rose-500', ring: 'ring-rose-500/25', chip: 'bg-rose-50 dark:bg-rose-500/10', pct: 55 },
}

function initials(name) {
  if (!name) return '?'
  return name.split(' ').map(w => w[0]).join('').toUpperCase().slice(0, 2)
}

function statusBadge(status) {
  const cfg = STATUS_COLORS[status] || STATUS_COLORS.draft
  const Icon = cfg.icon
  const label = status ? status.replace(/_/g, ' ').replace(/\b\w/g, c => c.toUpperCase()) : 'Draft'
  return (
    <Badge className={cn('gap-1 rounded-full border-0 px-3 py-1 font-semibold', cfg.bg)}>
      <Icon className="h-3 w-3" />
      {label}
    </Badge>
  )
}

function formatDate(dateStr) {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })
}

function formatDateFull(dateStr) {
  if (!dateStr) return '—'
  return new Date(dateStr).toLocaleDateString('en-PH', {
    weekday: 'short', month: 'short', day: 'numeric', year: 'numeric',
  })
}

export default function AdminEvaluation() {
  const { user } = useAuth()
  const { toast } = useToast()
  const [forms, setForms] = useState([])
  const [formsLoading, setFormsLoading] = useState(true)
  const [formDialog, setFormDialog] = useState(null)
  const [savingForm, setSavingForm] = useState(false)

  const [companies, setCompanies] = useState([])
  const [employees, setEmployees] = useState([])
  const [selectedCompany, setSelectedCompany] = useState('')
  const [employeeSearch, setEmployeeSearch] = useState('')
  const [empPage, setEmpPage] = useState(1)
  const [evaluations, setEvaluations] = useState([])
  const [evalsLoading, setEvalsLoading] = useState(false)
  const [evalDialog, setEvalDialog] = useState(null)
  const [formPicker, setFormPicker] = useState(null)
  const [savingEval, setSavingEval] = useState(false)
  const [viewDialog, setViewDialog] = useState(null)
  const [dashboardSummary, setDashboardSummary] = useState(null)
  const [evalSearch, setEvalSearch] = useState('')
  const [scopeMeta, setScopeMeta] = useState(null)

  // Role-based access: backend scopeMeta is the single source of truth
  const canManageTemplates = scopeMeta?.can_manage_templates ?? false
  const canReview = scopeMeta?.can_review ?? false
  const hrRole = scopeMeta?.hr_role
  const isOrgHead = hrRole && !['admin', 'super_admin'].includes(hrRole)
  const [activeTab, setActiveTab] = useState('evaluate')
  const [scopeCompanyId, setScopeCompanyId] = useState(null)

  // When scopeMeta loads, switch non-org-head admin users to the 'forms' tab by default;
  // for org heads (including admin_hr), auto-load their scoped employees
  useEffect(() => {
    if (scopeMeta?.can_manage_templates && !isOrgHead && activeTab === 'evaluate') {
      setActiveTab('forms')
    }
  }, [scopeMeta, isOrgHead, activeTab])

  // For org heads: determine their company from scope meta or their user record
  useEffect(() => {
    if (scopeMeta && isOrgHead) {
      const scope = scopeMeta.scope
      if (scope?.company_ids?.length === 1) {
        setScopeCompanyId(scope.company_ids[0])
      } else if (user?.company_id) {
        setScopeCompanyId(user.company_id)
      }
    }
  }, [scopeMeta, isOrgHead, user])

  const bootstrappedRef = useRef(false)
  const lastEmployeeKeyRef = useRef(null)

  // Single round-trip that resolves the (expensive) data scope once and returns
  // scope meta, forms, companies, evaluations, dashboard, and the initial
  // employee set together — replacing 5 separate on-mount requests.
  const loadBootstrap = useCallback(async () => {
    setFormsLoading(true)
    setEvalsLoading(true)
    try {
      const data = await getEvaluationBootstrap()
      if (data.scope_meta) setScopeMeta(data.scope_meta)
      setForms(data.forms || [])
      setCompanies(data.companies || [])
      setEvaluations(data.evaluations?.data || [])
      setDashboardSummary(data.dashboard ?? null)
      if (Array.isArray(data.employees)) setEmployees(data.employees)
      bootstrappedRef.current = true
    } catch (e) {
      toast({ variant: 'destructive', title: 'Error', description: e.message })
    } finally {
      setFormsLoading(false)
      setEvalsLoading(false)
    }
  }, [toast])

  const loadForms = useCallback(async () => {
    setFormsLoading(true)
    try {
      const data = await getEvaluationForms()
      setForms(data.forms || [])
    } catch (e) {
      toast({ variant: 'destructive', title: 'Error', description: e.message })
    } finally {
      setFormsLoading(false)
    }
  }, [toast])

  const loadEmployees = useCallback(async (companyId) => {
    try {
      const data = await getEvaluationEmployees(companyId)
      setEmployees(data.employees || [])
    } catch (e) {
      toast({ variant: 'destructive', title: 'Error', description: e.message })
    }
  }, [toast])

  const loadEvaluations = useCallback(async () => {
    setEvalsLoading(true)
    try {
      const data = await getEvaluations({ per_page: 50 })
      setEvaluations(data.data || [])
    } catch (e) {
      toast({ variant: 'destructive', title: 'Error', description: e.message })
    } finally {
      setEvalsLoading(false)
    }
  }, [toast])

  const loadDashboard = useCallback(async () => {
    try {
      const data = await getEvaluationDashboardSummary()
      setDashboardSummary(data)
    } catch {
      // non-critical
    }
  }, [])

  useEffect(() => { loadBootstrap() }, [loadBootstrap])

  // Employees for the initial scope come from the bootstrap payload. This effect
  // only refetches when the selection genuinely changes afterwards (e.g. an
  // Admin HR user picks a specific company), avoiding a redundant scope recompute.
  useEffect(() => {
    if (!bootstrappedRef.current) return

    let key, run
    if (selectedCompany) {
      key = `company:${selectedCompany}`
      run = () => loadEmployees(selectedCompany)
    } else if (isOrgHead && scopeMeta?.scope?.kind === 'all') {
      key = 'scope:all'
      run = () => loadEmployees(null)
    } else if (isOrgHead && scopeCompanyId) {
      key = `scope:${scopeCompanyId}`
      run = () => loadEmployees(scopeCompanyId)
    } else {
      key = 'none'
      run = () => setEmployees([])
    }

    // Bootstrap already populated the default (non-company-selected) employee set.
    if (lastEmployeeKeyRef.current === null && !selectedCompany) {
      lastEmployeeKeyRef.current = key
      return
    }
    if (lastEmployeeKeyRef.current === key) return
    lastEmployeeKeyRef.current = key
    run()
  }, [selectedCompany, loadEmployees, isOrgHead, scopeCompanyId, scopeMeta])

  const handleSaveForm = async () => {
    if (!formDialog.title?.trim() || !formDialog.sections?.length) {
      toast({ variant: 'destructive', title: 'Validation', description: 'Title and at least one section are required.' })
      return
    }
    setSavingForm(true)
    try {
      const payload = {
        company_id: formDialog.company_id,
        title: formDialog.title,
        description: formDialog.description || '',
        sections: formDialog.sections,
      }
      if (formDialog.id) {
        await updateEvaluationForm(formDialog.id, payload)
        toast({ title: 'Form updated' })
      } else {
        await createEvaluationForm(payload)
        toast({ title: 'Form created' })
      }
      setFormDialog(null)
      loadForms()
    } catch (e) {
      toast({ variant: 'destructive', title: 'Error', description: e.message })
    } finally {
      setSavingForm(false)
    }
  }

  const handleDeleteForm = async (id) => {
    try {
      await deleteEvaluationForm(id)
      toast({ title: 'Form deleted' })
      loadForms()
    } catch (e) {
      toast({ variant: 'destructive', title: 'Error', description: e.message })
    }
  }

  const openFormDialog = (form = null) => {
    if (form) {
      setFormDialog({
        id: form.id,
        company_id: form.company_id,
        title: form.title,
        description: form.description || '',
        sections: form.sections || [],
      })
    } else {
      setFormDialog({
        id: null,
        company_id: '',
        title: '',
        description: '',
        sections: [{ title: '', weight: 0, questions: [{ title: '', type: 'rating', max: 5 }] }],
      })
    }
  }

  const updateSection = (idx, field, value) => {
    setFormDialog(prev => {
      const sections = [...prev.sections]
      sections[idx] = { ...sections[idx], [field]: value }
      return { ...prev, sections }
    })
  }

  const updateQuestion = (sIdx, qIdx, field, value) => {
    setFormDialog(prev => {
      const sections = [...prev.sections]
      const questions = [...sections[sIdx].questions]
      questions[qIdx] = { ...questions[qIdx], [field]: value }
      sections[sIdx] = { ...sections[sIdx], questions }
      return { ...prev, sections }
    })
  }

  const addSection = () => {
    setFormDialog(prev => ({
      ...prev,
      sections: [...prev.sections, { title: '', weight: 0, questions: [{ title: '', type: 'rating', max: 5 }] }],
    }))
  }

  const removeSection = (idx) => {
    setFormDialog(prev => ({
      ...prev,
      sections: prev.sections.filter((_, i) => i !== idx),
    }))
  }

  const addQuestion = (sIdx) => {
    setFormDialog(prev => {
      const sections = [...prev.sections]
      sections[sIdx] = {
        ...sections[sIdx],
        questions: [...sections[sIdx].questions, { title: '', type: 'rating', max: 5 }],
      }
      return { ...prev, sections }
    })
  }

  const removeQuestion = (sIdx, qIdx) => {
    setFormDialog(prev => {
      const sections = [...prev.sections]
      sections[sIdx] = {
        ...sections[sIdx],
        questions: sections[sIdx].questions.filter((_, i) => i !== qIdx),
      }
      return { ...prev, sections }
    })
  }

  const handleEvaluateEmployee = (employee) => {
    const empEvals = evaluationsByEmployee[employee.id]
    if (empEvals?.some(e => EVALUATED_STATUSES.includes(e.status))) {
      toast({ variant: 'destructive', title: 'Already evaluated', description: 'This employee has already been evaluated and cannot be evaluated again.' })
      return
    }
    const companyId = selectedCompany || scopeCompanyId
    if (!companyId) {
      toast({ variant: 'destructive', title: 'Company required', description: 'Please select a company first.' })
      return
    }
    const activeForms = forms.filter(f => f.is_active !== false)
    if (activeForms.length === 0) {
      toast({ variant: 'destructive', title: 'No forms', description: 'No active evaluation forms available.' })
      return
    }
    setFormPicker(employee)
  }

  const handlePickForm = (form) => {
    if (!formPicker) return
    const companyId = selectedCompany || scopeCompanyId
    setEvalDialog({
      company_id: Number(companyId),
      evaluation_form_id: form.id,
      employee_id: formPicker.id,
      form,
      scores: { sections: {} },
      remarks: '',
    })
    setFormPicker(null)
  }

  const updateScore = (sectionTitle, questionTitle, value) => {
    setEvalDialog(prev => {
      const sections = { ...prev.scores.sections }
      if (!sections[sectionTitle]) sections[sectionTitle] = {}
      sections[sectionTitle] = { ...sections[sectionTitle], [questionTitle]: value }
      return { ...prev, scores: { sections } }
    })
  }

  const handleSaveEvaluation = async (status) => {
    setSavingEval(true)
    try {
      const payload = {
        company_id: evalDialog.company_id,
        evaluation_form_id: evalDialog.evaluation_form_id,
        employee_id: evalDialog.employee_id,
        scores: evalDialog.scores,
        remarks: evalDialog.remarks,
        status,
      }
      await createEvaluation(payload)
      toast({ title: status === 'submitted' ? 'Evaluation submitted' : 'Draft saved' })
      setEvalDialog(null)
      loadEvaluations()
      loadDashboard()
    } catch (e) {
      toast({ variant: 'destructive', title: 'Error', description: e.message })
    } finally {
      setSavingEval(false)
    }
  }

  const handleSubmitEvaluation = async (id) => {
    try {
      await submitEvaluation(id)
      toast({ title: 'Evaluation submitted' })
      loadEvaluations()
      loadDashboard()
    } catch (e) {
      toast({ variant: 'destructive', title: 'Error', description: e.message })
    }
  }

  const handleDeleteEvaluation = async (id) => {
    try {
      await deleteEvaluation(id)
      toast({ title: 'Evaluation deleted' })
      loadEvaluations()
    } catch (e) {
      toast({ variant: 'destructive', title: 'Error', description: e.message })
    }
  }

  const handleReviewEvaluation = async (id) => {
    try {
      await reviewEvaluation(id)
      toast({ title: 'Evaluation moved to review' })
      loadEvaluations()
    } catch (e) {
      toast({ variant: 'destructive', title: 'Error', description: e.message })
    }
  }

  const handleCompleteEvaluation = async (id) => {
    try {
      await completeEvaluation(id)
      toast({ title: 'Evaluation completed' })
      loadEvaluations()
      loadDashboard()
    } catch (e) {
      toast({ variant: 'destructive', title: 'Error', description: e.message })
    }
  }

  const permissionCheck = (perm) => {
    if (!user) return false
    if (user.role === 'admin' || user.role === 'superadmin') return true
    const perms = user.permissions || []
    if (perm === 'evaluate' && (perms.includes('evaluations.create') || perms.includes('evaluate'))) return true
    if (perm === 'review' && (perms.includes('evaluations.review') || perms.includes('review_evaluations'))) return true
    return false
  }

  const filteredEvaluations = useMemo(() => {
    return evaluations.filter(ev => {
      if (!evalSearch.trim()) return true
      const q = evalSearch.toLowerCase()
      const emp = ev.employee || {}
      const name = `${emp.first_name || ''} ${emp.last_name || ''}`.toLowerCase()
      const formTitle = (ev.evaluation_form?.title || '').toLowerCase()
      const evaluator = ev.evaluator || {}
      const evaluatorName = `${evaluator.first_name || ''} ${evaluator.last_name || ''}`.toLowerCase()
      return name.includes(q) || formTitle.includes(q) || evaluatorName.includes(q)
    })
  }, [evaluations, evalSearch])

  const evaluationsByEmployee = useMemo(() => {
    const map = {}
    for (const ev of evaluations) {
      const eid = ev.employee_id ?? ev.employee?.id
      if (!eid) continue
      if (!map[eid]) map[eid] = []
      map[eid].push(ev)
    }
    return map
  }, [evaluations])

  const filteredEmployees = useMemo(() => {
    const q = employeeSearch.trim().toLowerCase()
    if (!q) return employees
    return employees.filter(e => {
      const name = `${e.first_name || ''} ${e.middle_name || ''} ${e.last_name || ''} ${e.suffix || ''}`.toLowerCase()
      const pos = (e.position || '').toLowerCase()
      return name.includes(q) || pos.includes(q)
    })
  }, [employees, employeeSearch])

  const totalEmpPages = Math.max(1, Math.ceil(filteredEmployees.length / EMPLOYEES_PER_PAGE))

  useEffect(() => {
    setEmpPage(1)
  }, [employeeSearch, selectedCompany])

  useEffect(() => {
    if (empPage > totalEmpPages) setEmpPage(totalEmpPages)
  }, [empPage, totalEmpPages])

  const pagedEmployees = useMemo(() => {
    const start = (empPage - 1) * EMPLOYEES_PER_PAGE
    return filteredEmployees.slice(start, start + EMPLOYEES_PER_PAGE)
  }, [filteredEmployees, empPage])

  const formsCount = forms.length
  const completedCount = useMemo(() => evaluations.filter(e => e.status === 'completed').length, [evaluations])
  const pendingCount = useMemo(() => evaluations.filter(e => e.status === 'draft').length, [evaluations])
  const submittedCount = useMemo(() => evaluations.filter(e => e.status === 'submitted' || e.status === 'under_review').length, [evaluations])
  const activeEvalsCount = pendingCount + submittedCount

  return (
    <Motion.div
      className="flex w-full min-w-0 flex-col gap-6 @md:gap-8"
      initial={{ opacity: 0, y: 10 }}
      animate={{ opacity: 1, y: 0 }}
      transition={{ duration: 0.25, ease: [0.23, 1, 0.32, 1] }}
    >
      {/* Header */}
      <div className="flex w-full flex-col gap-5 pb-1 @lg:flex-row @lg:items-end @lg:justify-between">
        <div className="min-w-0 flex-1">
          <p className="text-[11px] font-bold uppercase tracking-[0.18em] text-brand">Performance</p>
          <h1 className="mt-3 text-3xl font-bold tracking-tight text-foreground @sm:text-4xl">Evaluations</h1>
          <p className="mt-2 text-[15px] leading-relaxed text-muted-foreground">
            Manage evaluation forms, evaluate employees, and review results across teams.
          </p>
        </div>
        <div className="flex w-full flex-wrap items-center gap-3 @lg:w-auto @lg:justify-end">
          <Button
            type="button"
            variant="outline"
            className={cn(evalOutlineButtonClass, 'flex-1 @lg:flex-initial')}
            onClick={() => { if (canManageTemplates) loadForms(); loadEvaluations(); loadDashboard() }}
            disabled={formsLoading || evalsLoading}
          >
            {formsLoading || evalsLoading ? <Loader2 className="size-4 animate-spin" /> : <RefreshCw className="size-4" />}
            Refresh
          </Button>
        </div>
      </div>

      {/* Dashboard Summary Cards */}
      {dashboardSummary && (
        <div className="grid w-full gap-3 @sm:grid-cols-2 @lg:grid-cols-4">
          <Card className={cn(evalCardClass, 'overflow-hidden')}>
            <CardContent className="p-5">
              <div className="flex items-start justify-between">
                <div>
                  <p className="text-xs font-medium text-muted-foreground">Employees Evaluated</p>
                  <p className="mt-1 text-4xl font-black tracking-tight text-foreground">{dashboardSummary.employees_evaluated || 0}</p>
                  <p className="mt-1 text-xs text-muted-foreground">Total evaluated</p>
                </div>
                <div className="flex size-10 items-center justify-center rounded-xl bg-blue-500/15 dark:bg-blue-500/20">
                  <Users className="size-5 text-blue-600 dark:text-blue-400" />
                </div>
              </div>
            </CardContent>
          </Card>

          <Card className={cn(evalCardClass, 'overflow-hidden', pendingCount > 0 && 'border-amber-400/60 shadow-[0_0_18px_rgba(245,158,11,0.12)] dark:border-amber-500/40')}>
            <CardContent className="p-5">
              <div className="flex items-start justify-between">
                <div>
                  <p className="text-xs font-medium text-muted-foreground">Pending</p>
                  <p className={`mt-1 text-4xl font-black tracking-tight ${pendingCount > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-foreground'}`}>
                    {dashboardSummary.pending_evaluations || 0}
                  </p>
                  <p className="mt-1 text-xs text-muted-foreground">Awaiting submission</p>
                </div>
                <div className={`flex size-10 items-center justify-center rounded-xl ${pendingCount > 0 ? 'bg-amber-500/20 animate-pulse' : 'bg-amber-500/10'}`}>
                  <Clock className={`size-5 ${pendingCount > 0 ? 'text-amber-500 dark:text-amber-400' : 'text-amber-500/50'}`} />
                </div>
              </div>
            </CardContent>
          </Card>

          <Card className={cn(evalCardClass, 'overflow-hidden')}>
            <CardContent className="p-5">
              <div className="flex items-start justify-between">
                <div>
                  <p className="text-xs font-medium text-muted-foreground">Average Score</p>
                  <p className="mt-1 text-4xl font-black tracking-tight text-emerald-600 dark:text-emerald-400">
                    {dashboardSummary.average_score != null ? `${dashboardSummary.average_score}%` : '—'}
                  </p>
                  <p className="mt-1 text-xs text-muted-foreground">Overall rating</p>
                </div>
                <div className="flex size-10 items-center justify-center rounded-xl bg-emerald-500/15 dark:bg-emerald-500/20">
                  <TrendingUp className="size-5 text-emerald-600 dark:text-emerald-400" />
                </div>
              </div>
            </CardContent>
          </Card>

          <Card className={cn(evalCardClass, 'overflow-hidden')}>
            <CardContent className="p-5">
              <div className="flex items-start justify-between">
                <div>
                  <p className="text-xs font-medium text-muted-foreground">Top Performers</p>
                  <p className="mt-1 text-4xl font-black tracking-tight text-foreground">{dashboardSummary.top_performers?.length || 0}</p>
                  <p className="mt-1 text-xs text-muted-foreground truncate">
                    {dashboardSummary.top_performers?.length > 0
                      ? dashboardSummary.top_performers.slice(0, 2).map(p => p.employee).join(', ')
                      : 'Highest rated'}
                  </p>
                </div>
                <div className="flex size-10 items-center justify-center rounded-xl bg-violet-500/15 dark:bg-violet-500/20">
                  <Star className="size-5 text-violet-600 dark:text-violet-400" />
                </div>
              </div>
            </CardContent>
          </Card>
        </div>
      )}

      {/* Tab Navigation — matches AdminLeave & AdminHoliday pill style */}
      <div className="flex flex-wrap gap-2">
        <div
          className="inline-flex min-w-0 flex-wrap gap-2 rounded-2xl border border-border/70 bg-muted/30 p-1 shadow-inner"
          role="tablist"
          aria-label="Evaluation views"
        >
          {[
            ...(canManageTemplates
              ? [{ value: 'forms', label: 'Evaluation Forms', icon: FileSpreadsheet }]
              : []),
            { value: 'evaluate', label: 'Evaluate', icon: ClipboardCheck },
            { value: 'results', label: 'Results', icon: List },
          ].map(({ value, label, icon: Icon }) => (
            <button
              key={value}
              type="button"
              role="tab"
              aria-selected={activeTab === value}
              onClick={() => setActiveTab(value)}
              className={cn(
                'flex items-center gap-2 rounded-xl px-5 py-2.5 text-sm font-semibold transition-all',
                activeTab === value
                  ? 'bg-card text-foreground shadow-sm ring-1 ring-border/70'
                  : 'text-muted-foreground hover:bg-background hover:text-foreground',
              )}
            >
              <Icon className="size-4 shrink-0" />
              {label}
            </button>
          ))}
        </div>
      </div>

      {/* ───── FORMS TAB ───── */}
      {activeTab === 'forms' && (
        <Card className={cn(evalCardClass, 'w-full min-w-0 overflow-hidden')}>
          <CardHeader className="flex flex-col gap-4 border-b border-border/40 bg-muted/10 px-4 py-4 @sm:px-6 @sm:py-5 dark:border-border/50 dark:bg-muted/20">
            <div className="flex flex-wrap items-center justify-between gap-4">
              <div className="min-w-0">
                <CardTitle className="text-lg font-semibold @md:text-xl">Evaluation Forms</CardTitle>
                <CardDescription className="text-sm @md:text-[15px]">
                  Create and manage customizable evaluation templates.
                </CardDescription>
              </div>
              <Button onClick={() => openFormDialog(null)} className={evalPrimaryButtonClass}>
                <Plus className="size-4" />
                New Form
              </Button>
            </div>
            {forms.length > 0 && (
              <p className="text-xs text-muted-foreground">{forms.length} form{forms.length !== 1 ? 's' : ''} created</p>
            )}
          </CardHeader>
          <CardContent className="p-0">
            {formsLoading ? (
              <div className="grid gap-5 p-5 @md:grid-cols-2 @xl:grid-cols-3">
                {[1, 2, 3].map(i => (
                  <div key={i} className="animate-pulse rounded-2xl border border-border/80 bg-card p-5">
                    <div className="mb-3 h-5 w-3/4 rounded bg-muted" />
                    <div className="mb-2 h-3 w-1/2 rounded bg-muted" />
                    <div className="flex gap-2"><div className="h-5 w-16 rounded bg-muted" /><div className="h-5 w-20 rounded bg-muted" /></div>
                  </div>
                ))}
              </div>
            ) : forms.length === 0 ? (
              <div className="flex min-h-[min(42vh,400px)] flex-col items-center justify-center px-6 py-16 text-center @md:py-24">
                <div className="relative mb-6 flex size-24 items-center justify-center rounded-full bg-brand/10 text-brand dark:bg-brand/15">
                  <FileSpreadsheet className="size-11" strokeWidth={1.85} aria-hidden />
                </div>
                <p className="max-w-md text-xl font-semibold tracking-tight text-foreground">No evaluation forms yet</p>
                <p className="mt-3 max-w-md text-sm leading-relaxed text-muted-foreground">
                  Create your first evaluation form to start evaluating employees.
                </p>
                <Button variant="outline" className="mt-7 gap-2" onClick={() => openFormDialog(null)}>
                  <Plus className="size-4" />
                  New Form
                </Button>
              </div>
            ) : (
              <div className="grid gap-5 p-5 @md:grid-cols-2 @xl:grid-cols-3">
                {forms.map(form => (
                  <div
                    key={form.id}
                    className="group rounded-2xl border border-border/70 bg-card p-5 shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-brand/30 hover:shadow-lg dark:border-white/10 dark:hover:border-brand/40"
                  >
                    <div className="mb-3 flex items-start justify-between gap-2">
                      <div className="min-w-0 flex-1">
                        <h3 className="truncate text-base font-bold text-foreground">{form.title}</h3>
                        {form.description && (
                          <p className="mt-1 text-sm leading-5 text-muted-foreground line-clamp-2">{form.description}</p>
                        )}
                      </div>
                      <div className="flex shrink-0 gap-1 opacity-0 transition-opacity group-hover:opacity-100">
                        <Button variant="ghost" size="icon" className="size-8" onClick={() => openFormDialog(form)}><Pencil className="size-4" /></Button>
                        <Button variant="ghost" size="icon" className="size-8 text-destructive" onClick={() => handleDeleteForm(form.id)}><Trash2 className="size-4" /></Button>
                      </div>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                      <Badge variant="outline" className="rounded-full text-xs font-normal">{form.sections?.length || 0} sections</Badge>
                      <Badge variant="outline" className="rounded-full text-xs font-normal">{form.evaluations_count || 0} evaluations</Badge>
                      {!form.is_active && <Badge className="rounded-full bg-gray-100 text-xs font-normal text-gray-600 dark:bg-gray-800 dark:text-gray-400">Inactive</Badge>}
                    </div>
                    {form.created_by && (
                      <p className="mt-3 text-xs text-muted-foreground border-t border-border/40 pt-3">Created by {form.created_by}</p>
                    )}
                  </div>
                ))}
              </div>
            )}
          </CardContent>
        </Card>
      )}

      {/* ───── EVALUATE TAB ───── */}
      {activeTab === 'evaluate' && (
        <div className="space-y-5">
          {/* Employee Cards */}
          <Card className={cn(evalCardClass, 'w-full min-w-0 overflow-hidden')}>
            <CardHeader className="flex flex-col gap-3 border-b border-border/40 bg-muted/10 px-4 py-4 @sm:px-6 @sm:py-5 dark:border-border/50 dark:bg-muted/20">
              <div className="flex flex-wrap items-end justify-between gap-3">
                <div className="min-w-0">
                  <div className="flex items-center gap-2">
                    <CardTitle className="text-lg font-semibold @md:text-xl">Employees</CardTitle>
                    {filteredEmployees.length > 0 && (
                      <Badge variant="outline" className="rounded-full text-xs font-medium">
                        {filteredEmployees.length} {filteredEmployees.length === 1 ? 'employee' : 'employees'}
                      </Badge>
                    )}
                  </div>
                  <CardDescription className="mt-1 text-sm @md:text-[15px]">
                    Click <span className="font-semibold text-foreground">Evaluate</span> on an employee, then choose the evaluation form.
                  </CardDescription>
                </div>
                <div className="flex w-full flex-wrap items-center gap-2 @sm:w-auto">
                  {!isOrgHead && (
                    <Select value={selectedCompany} onValueChange={setSelectedCompany}>
                      <SelectTrigger className="h-10 w-full rounded-xl border-border/80 bg-background @sm:w-[200px]"><SelectValue placeholder="Select company" /></SelectTrigger>
                      <SelectContent>
                        {companies.map(c => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                      </SelectContent>
                    </Select>
                  )}
                  <div className="relative w-full @sm:w-64">
                    <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
                    <Input
                      type="search"
                      value={employeeSearch}
                      onChange={(e) => setEmployeeSearch(e.target.value)}
                      placeholder="Search by name or position"
                      className="h-10 w-full rounded-xl border-border/80 bg-background pl-9 text-sm"
                    />
                  </div>
                </div>
              </div>
            </CardHeader>
            <CardContent className="p-5">
              {!isOrgHead && !selectedCompany ? (
                <div className="flex min-h-[min(34vh,320px)] flex-col items-center justify-center px-6 py-14 text-center">
                  <div className="mb-5 flex size-20 items-center justify-center rounded-full bg-brand/10 text-brand dark:bg-brand/15">
                    <Users className="size-9" strokeWidth={1.85} />
                  </div>
                  <p className="text-lg font-semibold tracking-tight text-foreground">Select a company</p>
                  <p className="mt-2 max-w-sm text-sm leading-relaxed text-muted-foreground">
                    Choose a company to load its employees for evaluation.
                  </p>
                </div>
              ) : filteredEmployees.length === 0 ? (
                <div className="flex min-h-[min(34vh,320px)] flex-col items-center justify-center px-6 py-14 text-center">
                  <div className="mb-5 flex size-20 items-center justify-center rounded-full bg-brand/10 text-brand dark:bg-brand/15">
                    <Users className="size-9" strokeWidth={1.85} />
                  </div>
                  <p className="text-lg font-semibold tracking-tight text-foreground">
                    {employeeSearch.trim() ? 'No matching employees' : 'No employees found'}
                  </p>
                  <p className="mt-2 max-w-sm text-sm leading-relaxed text-muted-foreground">
                    {employeeSearch.trim()
                      ? 'Try a different name or position.'
                      : 'There are no employees within your evaluation scope.'}
                  </p>
                </div>
              ) : (
                <div className="grid gap-4 @sm:grid-cols-2 @xl:grid-cols-3 @4xl:grid-cols-4">
                  {pagedEmployees.map(emp => {
                    const empEvals = evaluationsByEmployee[emp.id]
                    const latest = empEvals?.[0]
                    const isEvaluated = empEvals?.some(e => EVALUATED_STATUSES.includes(e.status))
                    const fullName = `${emp.first_name || ''} ${emp.last_name || ''}${emp.suffix ? ` ${emp.suffix}` : ''}`.trim()
                    const deptLabel = emp.department_name || emp.branch_name
                    const orgLabel = emp.company_name || deptLabel
                    const lastDate = latest?.evaluated_at || latest?.updated_at || latest?.created_at
                    const scored = empEvals?.find(e => e.overall_score != null)
                    const rating = scored?.overall_rating
                    const ratingStyle = rating ? RATING_STYLES[rating] : null
                    const statusDot = latest ? (STATUS_META[latest.status]?.dot || 'bg-gray-400') : 'bg-slate-300 dark:bg-slate-600'
                    const statusLabel = latest ? (STATUS_META[latest.status]?.label || 'Draft') : 'Not started'
                    return (
                      <div
                        key={emp.id}
                        className="group flex flex-col overflow-hidden rounded-2xl border border-border/80 bg-card shadow-[0_1px_3px_rgba(15,23,42,0.06),0_1px_2px_-1px_rgba(15,23,42,0.04)] transition-all duration-200 hover:-translate-y-0.5 hover:border-border hover:shadow-[0_16px_36px_-18px_rgba(15,23,42,0.32)] dark:border-white/10 dark:shadow-none dark:hover:border-white/20"
                      >
                        {/* Cover band */}
                        <div className="relative h-[62px] bg-linear-to-br from-brand/15 via-brand/5 to-brand/12 dark:from-brand/20 dark:via-brand/8 dark:to-brand/15">
                          <div className="absolute inset-0 opacity-40 [background-image:radial-gradient(circle_at_1px_1px,rgba(100,116,139,0.28)_1px,transparent_0)] [background-size:13px_13px]" />
                          <div className="absolute inset-x-3 top-2.5 flex items-center justify-between gap-2">
                            <span className="inline-flex min-w-0 items-center gap-1 rounded-full bg-card/75 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground ring-1 ring-inset ring-border/60 backdrop-blur-sm">
                              <Building2 className="size-2.5 shrink-0" />
                              <span className="max-w-[8.5rem] truncate">{orgLabel || 'Unassigned'}</span>
                            </span>
                            <span className={cn(
                              'inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold ring-1 ring-inset backdrop-blur-sm',
                              isEvaluated
                                ? 'bg-emerald-50/90 text-emerald-700 ring-emerald-600/25 dark:bg-emerald-500/15 dark:text-emerald-300'
                                : 'bg-card/75 text-muted-foreground ring-border/60',
                            )}>
                              <span className={cn('size-1.5 rounded-full', isEvaluated ? 'bg-emerald-500' : statusDot)} />
                              {isEvaluated ? 'Evaluated' : statusLabel}
                            </span>
                          </div>
                        </div>

                        {/* Body */}
                        <div className="flex flex-1 flex-col px-4 pb-4">
                          <div className="-mt-8 mb-2.5 flex items-end justify-between gap-2">
                            <Avatar className="size-16 rounded-2xl ring-4 ring-card shadow-md">
                              <AvatarImage src={profileImageUrl(emp.profile_image)} alt={fullName} className="rounded-2xl object-cover" />
                              <AvatarFallback className="rounded-2xl bg-linear-to-br from-slate-100 to-slate-200 text-base font-bold text-slate-600 dark:from-slate-700 dark:to-slate-800 dark:text-slate-200">
                                {initials(fullName)}
                              </AvatarFallback>
                            </Avatar>
                            {ratingStyle && (
                              <span className={cn('inline-flex items-center gap-1 rounded-lg px-2 py-1 text-[11px] font-bold ring-1 ring-inset', ratingStyle.chip, ratingStyle.text, ratingStyle.ring)}>
                                <Star className="size-3 fill-current" />
                                {rating}
                              </span>
                            )}
                          </div>

                          <p className="truncate text-base font-bold leading-tight text-foreground" title={fullName}>
                            {fullName || '—'}
                          </p>
                          <p className="mt-1 truncate text-[13px] leading-tight text-muted-foreground" title={emp.position || ''}>
                            {emp.position || 'No position'}
                          </p>

                          {/* Performance panel */}
                          <div className="mt-3.5 rounded-xl border border-border/60 bg-muted/25 p-3 dark:border-white/10 dark:bg-white/[0.03]">
                            {scored ? (
                              <>
                                <div className="flex items-end justify-between">
                                  <div className="flex flex-col">
                                    <span className="text-[10px] font-semibold uppercase tracking-wide text-muted-foreground/80">Overall Score</span>
                                    <span className="text-2xl font-black leading-none tabular-nums text-foreground">{scored.overall_score}</span>
                                  </div>
                                  <span className={cn('text-xs font-bold', ratingStyle?.text)}>{rating}</span>
                                </div>
                                <div className="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-border/70 dark:bg-white/10">
                                  <div className={cn('h-full rounded-full transition-all', ratingStyle?.bar || 'bg-brand')} style={{ width: `${ratingStyle?.pct ?? 60}%` }} />
                                </div>
                              </>
                            ) : (
                              <div className="flex items-center gap-2 py-1 text-xs text-muted-foreground">
                                <Clock className="size-4 shrink-0 opacity-70" />
                                {latest ? 'Evaluation in progress' : 'Awaiting first evaluation'}
                              </div>
                            )}
                          </div>

                          {/* Meta row */}
                          <div className="mt-3 grid grid-cols-2 gap-2">
                            <div className="min-w-0 rounded-lg bg-muted/40 px-2.5 py-1.5 dark:bg-white/[0.04]">
                              <p className="flex items-center gap-1 text-[10px] font-medium uppercase tracking-wide text-muted-foreground/80">
                                <IdCard className="size-2.5" /> Emp. ID
                              </p>
                              <p className="mt-0.5 truncate text-xs font-semibold text-foreground">{emp.employee_code || '—'}</p>
                            </div>
                            <div className="min-w-0 rounded-lg bg-muted/40 px-2.5 py-1.5 dark:bg-white/[0.04]">
                              <p className="flex items-center gap-1 text-[10px] font-medium uppercase tracking-wide text-muted-foreground/80">
                                <CalendarClock className="size-2.5" /> Last Eval
                              </p>
                              <p className="mt-0.5 truncate text-xs font-semibold text-foreground">{lastDate ? formatDate(lastDate) : 'Never'}</p>
                            </div>
                          </div>

                          {/* Action */}
                          <div className="mt-4">
                            {isEvaluated ? (
                              <Button
                                disabled
                                variant="outline"
                                className="h-9 w-full cursor-not-allowed justify-center gap-2 rounded-lg border-emerald-600/25 bg-emerald-50/60 text-sm font-medium text-emerald-700 disabled:opacity-100 dark:border-emerald-500/25 dark:bg-emerald-500/10 dark:text-emerald-400"
                              >
                                <CheckCircle2 className="size-4" />
                                Evaluation Completed
                              </Button>
                            ) : (
                              <Button
                                onClick={() => handleEvaluateEmployee(emp)}
                                className={cn(evalPrimaryButtonClass, 'group/btn h-9 w-full justify-center')}
                              >
                                <ClipboardCheck className="size-4" />
                                {latest ? 'Continue Evaluation' : 'Evaluate'}
                                <ChevronRight className="size-4 opacity-70 transition-transform duration-200 group-hover/btn:translate-x-0.5" />
                              </Button>
                            )}
                          </div>
                        </div>
                      </div>
                    )
                  })}
                </div>
              )}
              {filteredEmployees.length > EMPLOYEES_PER_PAGE && (
                <div className="mt-5 flex flex-col items-center justify-between gap-3 border-t border-border/50 pt-4 @sm:flex-row">
                  <p className="text-xs text-muted-foreground">
                    Showing <span className="font-semibold text-foreground">{(empPage - 1) * EMPLOYEES_PER_PAGE + 1}</span>
                    –<span className="font-semibold text-foreground">{Math.min(empPage * EMPLOYEES_PER_PAGE, filteredEmployees.length)}</span>
                    {' '}of <span className="font-semibold text-foreground">{filteredEmployees.length}</span> employees
                  </p>
                  <div className="flex items-center gap-1.5">
                    <Button
                      variant="outline"
                      size="sm"
                      className="h-9 rounded-lg"
                      onClick={() => setEmpPage(p => Math.max(1, p - 1))}
                      disabled={empPage <= 1}
                    >
                      Previous
                    </Button>
                    <span className="px-2 text-xs font-medium text-muted-foreground">
                      Page {empPage} of {totalEmpPages}
                    </span>
                    <Button
                      variant="outline"
                      size="sm"
                      className="h-9 rounded-lg"
                      onClick={() => setEmpPage(p => Math.min(totalEmpPages, p + 1))}
                      disabled={empPage >= totalEmpPages}
                    >
                      Next
                    </Button>
                  </div>
                </div>
              )}
            </CardContent>
          </Card>

          {/* Draft Evaluations */}
          {evaluations.filter(e => e.status === 'draft').length > 0 && (
            <Card className={cn(evalCardClass, 'w-full min-w-0 overflow-hidden')}>
              <CardHeader className="flex flex-col gap-3 border-b border-border/40 bg-muted/10 px-4 py-4 @sm:px-6 @sm:py-5 dark:border-border/50 dark:bg-muted/20">
                <CardTitle className="text-lg font-semibold @md:text-xl">Draft Evaluations</CardTitle>
                <CardDescription className="text-sm @md:text-[15px]">
                  {evaluations.filter(e => e.status === 'draft').length} evaluation{evaluations.filter(e => e.status === 'draft').length !== 1 ? 's' : ''} in progress
                </CardDescription>
              </CardHeader>
              <CardContent className="p-0">
                <div className="divide-y divide-border/60">
                  {evaluations.filter(e => e.status === 'draft').map(ev => (
                    <div key={ev.id} className="flex items-center justify-between px-6 py-4 transition-colors hover:bg-muted/20">
                      <div className="flex min-w-0 items-center gap-3">
                        <Avatar className="size-9 shrink-0">
                          <AvatarImage src={profileImageUrl(ev.employee?.profile_image)} />
                          <AvatarFallback className="rounded-full bg-teal-500/20 text-xs font-bold text-teal-700 dark:text-teal-300">
                            {initials((ev.employee?.first_name || '') + ' ' + (ev.employee?.last_name || ''))}
                          </AvatarFallback>
                        </Avatar>
                        <div className="min-w-0">
                          <p className="truncate text-sm font-semibold text-foreground">
                            {ev.employee?.first_name} {ev.employee?.last_name}
                          </p>
                          <p className="truncate text-xs text-muted-foreground">{ev.evaluation_form?.title}</p>
                        </div>
                      </div>
                      <div className="flex shrink-0 gap-2">
                        <Button variant="outline" size="sm" className="h-9 gap-1.5 border-emerald-500/40 text-emerald-700 hover:bg-emerald-50 dark:text-emerald-300" onClick={() => handleSubmitEvaluation(ev.id)}>
                          <ClipboardCheck className="size-3.5" />
                          Submit
                        </Button>
                        <Button variant="ghost" size="sm" className="h-9 gap-1.5 text-destructive hover:bg-destructive/10" onClick={() => handleDeleteEvaluation(ev.id)}>
                          <Trash2 className="size-3.5" />
                          Delete
                        </Button>
                      </div>
                    </div>
                  ))}
                </div>
              </CardContent>
            </Card>
          )}
        </div>
      )}

      {/* ───── RESULTS TAB ───── */}
      {activeTab === 'results' && (
        <Card className={cn(evalCardClass, 'w-full min-w-0 overflow-hidden')}>
          <CardHeader className="flex flex-col gap-4 border-b border-border/40 bg-muted/10 px-4 py-4 @sm:px-6 @sm:py-5 dark:border-border/50 dark:bg-muted/20">
            <div className="min-w-0">
              <CardTitle className="text-lg font-semibold @md:text-xl">Evaluation Results</CardTitle>
              <CardDescription className="text-sm @md:text-[15px]">
                View all evaluations and their statuses.
                {evaluations.length > 0 && ` ${completedCount} completed, ${submittedCount} in progress.`}
              </CardDescription>
            </div>
            <div className="relative w-full max-w-xs">
              <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
              <Input
                type="search"
                value={evalSearch}
                onChange={(e) => setEvalSearch(e.target.value)}
                placeholder="Search employees, forms..."
                className="h-10 w-full rounded-xl border-border/60 bg-background/70 pl-9 text-sm shadow-sm dark:bg-background/35"
              />
            </div>
          </CardHeader>
          <CardContent className="p-0">
            {evalsLoading ? (
              <div className="flex min-h-[min(42vh,400px)] items-center justify-center py-16">
                <Loader2 className="size-8 animate-spin text-muted-foreground" />
              </div>
            ) : evaluations.length === 0 ? (
              <div className="flex min-h-[min(42vh,400px)] flex-col items-center justify-center px-6 py-16 text-center @md:py-24">
                <div className="relative mb-6 flex size-24 items-center justify-center rounded-full bg-brand/10 text-brand dark:bg-brand/15">
                  <ClipboardCheck className="size-11" strokeWidth={1.85} aria-hidden />
                </div>
                <p className="max-w-md text-xl font-semibold tracking-tight text-foreground">No evaluations yet</p>
                <p className="mt-3 max-w-md text-sm leading-relaxed text-muted-foreground">
                  Start evaluating employees to see results here.
                </p>
              </div>
            ) : (
              <div className="min-w-0 flex-1 overflow-x-auto">
                <table className="w-full min-w-[min(100%,800px)] text-sm">
                  <colgroup>
                    <col className="w-[18%]" />
                    <col className="w-[16%]" />
                    <col className="w-[12%]" />
                    <col className="w-[8%]" />
                    <col className="w-[12%]" />
                    <col className="w-[10%]" />
                    <col className="w-[10%]" />
                    <col className="w-[14%]" />
                  </colgroup>
                  <thead>
                    <tr className="border-b border-border/70 bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                      <th className="px-5 py-4 font-bold">Employee</th>
                      <th className="px-5 py-4 font-bold">Form</th>
                      <th className="px-5 py-4 font-bold">Evaluator</th>
                      <th className="px-5 py-4 font-bold">Score</th>
                      <th className="px-5 py-4 font-bold">Rating</th>
                      <th className="px-5 py-4 font-bold">Status</th>
                      <th className="px-5 py-4 font-bold">Date</th>
                      <th className="px-5 py-4 text-right font-bold">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="text-[13px]">
                    {filteredEvaluations.length === 0 ? (
                      <tr>
                        <td colSpan={8} className="py-12 text-center text-sm text-muted-foreground">
                          No evaluations match your search.
                        </td>
                      </tr>
                    ) : filteredEvaluations.map((ev, idx) => {
                      const emp = ev.employee || {}
                      const evaluator = ev.evaluator || {}
                      return (
                        <tr
                          key={ev.id}
                          className={cn(
                            'group border-b border-border/60 transition-colors last:border-b-0 hover:bg-muted/20',
                            idx % 2 === 1 && 'bg-muted/10 dark:bg-muted/5',
                          )}
                        >
                          <td className="px-5 py-4 align-middle">
                            <div className="flex items-center gap-3">
                              <Avatar className="size-9 shrink-0">
                                <AvatarImage src={profileImageUrl(emp.profile_image)} />
                                <AvatarFallback className="rounded-full bg-teal-500/20 text-[10px] font-bold text-teal-700 dark:text-teal-300">
                                  {initials(emp.first_name + ' ' + emp.last_name)}
                                </AvatarFallback>
                              </Avatar>
                              <span className="font-semibold text-foreground">{emp.first_name} {emp.last_name}</span>
                            </div>
                          </td>
                          <td className="px-5 py-4 align-middle text-muted-foreground">{ev.evaluation_form?.title || '—'}</td>
                          <td className="px-5 py-4 align-middle text-muted-foreground">{evaluator.first_name} {evaluator.last_name}</td>
                          <td className="px-5 py-4 align-middle font-bold tabular-nums text-foreground">
                            {ev.overall_score != null ? `${ev.overall_score}` : '—'}
                          </td>
                          <td className="px-5 py-4 align-middle text-muted-foreground">
                            {ev.overall_rating || '—'}
                          </td>
                          <td className="px-5 py-4 align-middle">{statusBadge(ev.status)}</td>
                          <td className="px-5 py-4 align-middle text-muted-foreground tabular-nums">
                            {formatDate(ev.evaluated_at)}
                          </td>
                          <td className="px-5 py-4 text-right align-middle">
                            <div className="inline-flex items-center gap-1 opacity-0 transition-opacity group-hover:opacity-100">
                              <Button variant="ghost" size="icon" className="size-8" onClick={() => setViewDialog(ev)} title="View details">
                                <Eye className="size-4" />
                              </Button>
                              {ev.status === 'draft' && (
                                <>
                                  <Button variant="ghost" size="icon" className="size-8 text-emerald-600" onClick={() => handleSubmitEvaluation(ev.id)} title="Submit">
                                    <ClipboardCheck className="size-4" />
                                  </Button>
                                  <Button variant="ghost" size="icon" className="size-8 text-destructive" onClick={() => handleDeleteEvaluation(ev.id)} title="Delete">
                                    <Trash2 className="size-4" />
                                  </Button>
                                </>
                              )}
                              {ev.status === 'submitted' && canReview && (
                                <>
                                  <Button variant="ghost" size="icon" className="size-8 text-amber-600" onClick={() => handleReviewEvaluation(ev.id)} title="Review">
                                    <Eye className="size-4" />
                                  </Button>
                                  <Button variant="ghost" size="icon" className="size-8 text-emerald-600" onClick={() => handleCompleteEvaluation(ev.id)} title="Complete">
                                    <ClipboardCheck className="size-4" />
                                  </Button>
                                </>
                              )}
                              {ev.status === 'under_review' && (
                                <Button variant="ghost" size="icon" className="size-8 text-emerald-600" onClick={() => handleCompleteEvaluation(ev.id)} title="Complete">
                                  <ClipboardCheck className="size-4" />
                                </Button>
                              )}
                            </div>
                          </td>
                        </tr>
                      )
                    })}
                  </tbody>
                </table>
              </div>
            )}
          </CardContent>
        </Card>
      )}

      {/* ───── FORM DIALOG (Full-screen, matches overtime modal) ───── */}
      <Dialog open={!!formDialog} onOpenChange={(open) => !open && setFormDialog(null)}>
        <DialogContent
          showCloseButton
          overlayClassName="bg-black/55 backdrop-blur-sm dark:bg-black/70"
          closeButtonClassName="right-4 top-4 size-10 rounded-xl border-border/80 bg-background/90 text-foreground shadow-sm hover:bg-muted @md:right-7 @md:top-7 @md:size-14 dark:border-white/10 dark:bg-card/90"
          className="max-h-[92vh] max-w-[min(96vw,90rem)] rounded-[18px] border-border/80 bg-card shadow-[0_24px_80px_-24px_rgba(0,0,0,0.5)] dark:border-white/10 dark:bg-card"
          innerClassName="gap-0 overflow-hidden p-0 pr-0"
          aria-describedby="eval-form-desc"
        >
          <div className="min-h-0 flex-1 overflow-y-auto">
            <DialogHeader className="relative overflow-hidden border-b border-border/70 bg-linear-to-br from-card via-card to-brand/5 px-5 pb-5 pt-6 text-left dark:to-brand/10 @md:px-12 @md:pb-6 @md:pt-8">
              <AgcBrandLogo className="mb-5 h-8 @md:mb-7 @md:h-10" />
              <div className="relative z-10 max-w-[43rem] space-y-3 pr-12 @md:pr-0">
                <DialogTitle className="text-xl font-bold tracking-tight text-foreground @md:text-3xl">
                  {formDialog?.id ? 'Edit Evaluation Form' : 'New Evaluation Form'}
                </DialogTitle>
                <DialogDescription id="eval-form-desc" className="          max-w-[42rem] text-base leading-relaxed text-muted-foreground @md:text-lg">
                  Configure sections, questions, and scoring weights for this evaluation template. All fields marked with a label are required.
                </DialogDescription>
              </div>
            </DialogHeader>

            {formDialog && (
              <div className="px-5 py-6 @md:px-12 @md:py-8">
                <div className="grid gap-6 @lg:grid-cols-5 @lg:gap-8">
                  {/* ─── Left Column: Basic Information ─── */}
                  <div className="space-y-5 @lg:col-span-2">
                    <div className="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm">
                      <div className="border-b border-border/40 bg-muted/30 px-5 py-3.5">
                        <div className="flex items-center gap-2.5">
                          <div className="flex size-8 items-center justify-center rounded-lg bg-brand/10 text-brand">
                            <FileText className="size-4" />
                          </div>
                          <div>
                            <h3 className="text-sm font-bold text-foreground">Basic Information</h3>
                            <p className="text-[11px] text-muted-foreground">Evaluation template metadata</p>
                          </div>
                        </div>
                      </div>
                      <div className="space-y-5 p-5">
                        <div className="space-y-2">
                          <Label className="text-sm font-semibold">Company <span className="text-destructive">*</span></Label>
                          <Select value={String(formDialog.company_id)} onValueChange={(v) => setFormDialog(prev => ({ ...prev, company_id: Number(v) }))}>
                            <SelectTrigger className="h-11 rounded-xl border-border/80 bg-background"><SelectValue placeholder="Select company" /></SelectTrigger>
                            <SelectContent>
                              {companies.map(c => <SelectItem key={c.id} value={String(c.id)}>{c.name}</SelectItem>)}
                            </SelectContent>
                          </Select>
                        </div>
                        <div className="space-y-2">
                          <Label className="text-sm font-semibold">Title <span className="text-destructive">*</span></Label>
                          <Input
                            value={formDialog.title}
                            onChange={(e) => setFormDialog(prev => ({ ...prev, title: e.target.value }))}
                            placeholder="e.g. Q1 Performance Review"
                            className="h-11 rounded-xl border-border/80 bg-background"
                          />
                        </div>
                        <div className="space-y-2">
                          <Label className="text-sm font-semibold">Description</Label>
                          <Textarea
                            value={formDialog.description}
                            onChange={(e) => setFormDialog(prev => ({ ...prev, description: e.target.value }))}
                            rows={4}
                            placeholder="Brief description of this evaluation form..."
                            className="rounded-xl border-border/80 bg-background"
                          />
                        </div>
                      </div>
                    </div>

                    {/* Quick stats card */}
                    <div className="rounded-2xl border border-border/70 bg-muted/20 p-5">
                      <div className="flex items-center gap-2.5 text-sm font-semibold text-foreground">
                        <Star className="size-4 text-brand" />
                        Form Summary
                      </div>
                      <div className="mt-3 grid grid-cols-2 gap-3">
                        <div className="rounded-xl bg-card px-4 py-3 text-center shadow-sm">
                          <p className="text-2xl font-black tabular-nums text-foreground">{formDialog.sections.length}</p>
                          <p className="text-[11px] font-medium text-muted-foreground">Sections</p>
                        </div>
                        <div className="rounded-xl bg-card px-4 py-3 text-center shadow-sm">
                          <p className="text-2xl font-black tabular-nums text-foreground">
                            {formDialog.sections.reduce((sum, s) => sum + s.questions.length, 0)}
                          </p>
                          <p className="text-[11px] font-medium text-muted-foreground">Questions</p>
                        </div>
                      </div>
                    </div>
                  </div>

                  {/* ─── Right Column: Sections & Questions ─── */}
                  <div className="space-y-5 @lg:col-span-3">
                    <div className="flex items-center justify-between">
                      <div>
                        <h3 className="text-sm font-bold text-foreground">Sections &amp; Questions</h3>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                          Define the structure, scoring sections, and questions for this evaluation.
                        </p>
                      </div>
                      <Button variant="outline" size="sm" onClick={addSection} className="gap-1.5 rounded-xl border-brand/30 text-brand hover:bg-brand/10">
                        <Plus className="h-3.5 w-3.5" />
                        Add Section
                      </Button>
                    </div>

                    {formDialog.sections.length === 0 ? (
                      <div className="flex flex-col items-center justify-center rounded-2xl border-2 border-dashed border-border/60 bg-muted/15 px-6 py-14 text-center">
                        <div className="mb-4 flex size-14 items-center justify-center rounded-full bg-brand/10 text-brand">
                          <FileSpreadsheet className="size-7" strokeWidth={1.5} />
                        </div>
                        <p className="text-base font-semibold text-foreground">No sections yet</p>
                        <p className="mt-1.5 max-w-xs text-sm text-muted-foreground">
                          Click "Add Section" to start building your evaluation structure.
                        </p>
                        <Button variant="outline" size="sm" onClick={addSection} className="mt-5 gap-1.5 rounded-xl">
                          <Plus className="h-3.5 w-3.5" />
                          Add First Section
                        </Button>
                      </div>
                    ) : (
                      <div className="space-y-4">
                        {formDialog.sections.map((section, sIdx) => (
                          <div
                            key={sIdx}
                            className="overflow-hidden rounded-2xl border border-border/70 bg-card shadow-sm transition-all duration-200 hover:shadow-md"
                          >
                            {/* Section Header */}
                            <div className="flex items-center gap-3 border-b border-border/40 bg-muted/20 px-5 py-3.5">
                              <div className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-brand/10 text-brand text-xs font-bold">
                                {sIdx + 1}
                              </div>
                              <Input
                                value={section.title}
                                onChange={(e) => updateSection(sIdx, 'title', e.target.value)}
                                placeholder="Section title (e.g. Technical Skills)"
                                className="h-9 flex-1 rounded-lg border-border/70 bg-background text-sm font-semibold"
                              />
                              <div className="flex items-center gap-2">
                                <div className="flex items-center gap-1.5 rounded-lg border border-border/60 bg-background px-3 py-1.5">
                                  <span className="text-[11px] font-medium text-muted-foreground">Weight</span>
                                  <Input
                                    type="number"
                                    value={section.weight}
                                    onChange={(e) => updateSection(sIdx, 'weight', Number(e.target.value))}
                                    className="h-8 w-14 border-0 bg-transparent text-right text-sm font-bold tabular-nums text-foreground shadow-none focus-visible:ring-0"
                                    min={0}
                                    max={SECTION_MAX_WEIGHT}
                                  />
                                  <span className="text-xs text-muted-foreground">%</span>
                                </div>
                                <Button variant="ghost" size="icon" className="size-8 shrink-0 text-destructive/70 hover:bg-destructive/10 hover:text-destructive" onClick={() => removeSection(sIdx)} title="Remove section">
                                  <Trash2 className="size-4" />
                                </Button>
                              </div>
                            </div>

                            {/* Questions */}
                            <div className="p-4">
                              <div className="mb-3 flex items-center justify-between">
                                <span className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                                  Questions ({section.questions.length})
                                </span>
                                <Button variant="ghost" size="sm" onClick={() => addQuestion(sIdx)} className="gap-1 rounded-lg text-xs font-semibold text-brand hover:bg-brand/10">
                                  <Plus className="h-3.5 w-3.5" />
                                  Add Question
                                </Button>
                              </div>

                              {section.questions.length === 0 ? (
                                <div className="rounded-xl border border-dashed border-border/50 bg-muted/10 px-4 py-6 text-center">
                                  <p className="text-sm text-muted-foreground">No questions yet. Click "Add Question" above.</p>
                                </div>
                              ) : (
                                <div className="space-y-2.5">
                                  {section.questions.map((q, qIdx) => (
                                    <div
                                      key={qIdx}
                                      className="group flex items-center gap-2.5 rounded-xl border border-border/50 bg-muted/15 px-4 py-3 transition-all hover:border-border/80 hover:bg-muted/25"
                                    >
                                      <span className="flex size-6 shrink-0 items-center justify-center rounded-md bg-muted text-[11px] font-bold text-muted-foreground">
                                        {qIdx + 1}
                                      </span>
                                      <Input
                                        value={q.title}
                                        onChange={(e) => updateQuestion(sIdx, qIdx, 'title', e.target.value)}
                                        placeholder="Question text"
                                        className="h-9 flex-1 rounded-lg border-border/60 bg-background text-sm"
                                      />
                                      <div className="flex items-center gap-2">
                                        <Select value={q.type} onValueChange={(v) => updateQuestion(sIdx, qIdx, 'type', v)}>
                                          <SelectTrigger className="h-9 w-24 rounded-lg border-border/60 bg-background text-xs"><SelectValue /></SelectTrigger>
                                          <SelectContent>
                                            <SelectItem value="rating">⭐ Rating</SelectItem>
                                            <SelectItem value="text">📝 Text</SelectItem>
                                          </SelectContent>
                                        </Select>
                                        {q.type === 'rating' && (
                                          <div className="flex items-center gap-1.5 rounded-lg border border-border/60 bg-background px-2.5 py-1.5">
                                            <span className="text-[11px] text-muted-foreground">Max</span>
                                            <Input
                                              type="number"
                                              value={q.max}
                                              onChange={(e) => updateQuestion(sIdx, qIdx, 'max', Number(e.target.value))}
                                              className="h-8 w-10 border-0 bg-transparent text-center text-sm font-bold tabular-nums text-foreground shadow-none focus-visible:ring-0"
                                              min={1}
                                              max={100}
                                            />
                                          </div>
                                        )}
                                      </div>
                                      <Button variant="ghost" size="icon" className="size-7 shrink-0 text-destructive/60 opacity-0 transition-opacity hover:bg-destructive/10 hover:text-destructive group-hover:opacity-100" onClick={() => removeQuestion(sIdx, qIdx)} title="Remove question">
                                        <XCircle className="size-4" />
                                      </Button>
                                    </div>
                                  ))}
                                </div>
                              )}
                            </div>
                          </div>
                        ))}
                      </div>
                    )}
                  </div>
                </div>
              </div>
            )}
          </div>

          <div className="sticky bottom-0 z-10 shrink-0 border-t border-border/50 bg-card/95 px-5 py-4 pb-[max(1rem,env(safe-area-inset-bottom))] backdrop-blur supports-backdrop-filter:bg-card/90 @md:px-12">
            <div className="flex items-center justify-end gap-3">
              <Button variant="outline" size="sm" onClick={() => setFormDialog(null)} className="h-11 rounded-xl px-6">Cancel</Button>
              <Button onClick={handleSaveForm} disabled={savingForm} size="sm" className={cn(ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS, 'h-11 gap-2 rounded-xl px-6')}>
                {savingForm ? <Loader2 className="size-4 animate-spin" /> : <Plus className="size-4" />}
                {formDialog?.id ? 'Update Form' : 'Create Form'}
              </Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>

      {/* ───── FORM PICKER DIALOG ───── */}
      <Dialog open={!!formPicker} onOpenChange={(open) => !open && setFormPicker(null)}>
        <DialogContent showCloseButton className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_LG)} aria-describedby="eval-formpick-desc">
          <DialogHeader className="space-y-1.5 px-6 pt-6 text-left">
            <DialogTitle className="text-xl font-bold tracking-tight">Select Evaluation Form</DialogTitle>
            <DialogDescription id="eval-formpick-desc" className="text-sm text-muted-foreground">
              Choose the form to use{formPicker ? ` for ${[formPicker.first_name, formPicker.last_name].filter(Boolean).join(' ')}` : ''}.
            </DialogDescription>
          </DialogHeader>
          <div className={cn(ADMIN_FORM_DIALOG_BODY_CLASS, 'space-y-3')}>
            {forms.filter(f => f.is_active !== false).length === 0 ? (
              <div className="py-10 text-center text-sm text-muted-foreground">No active evaluation forms available.</div>
            ) : (
              forms.filter(f => f.is_active !== false).map(f => (
                <button
                  key={f.id}
                  type="button"
                  onClick={() => handlePickForm(f)}
                  className="group flex w-full items-center gap-4 rounded-2xl border border-border/70 bg-card p-4 text-left shadow-sm transition-all hover:-translate-y-0.5 hover:border-brand/40 hover:shadow-md dark:border-white/10"
                >
                  <div className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-brand/10 text-brand">
                    <FileSpreadsheet className="size-5" />
                  </div>
                  <div className="min-w-0 flex-1">
                    <p className="truncate text-sm font-bold text-foreground">{f.title}</p>
                    {f.description && <p className="mt-0.5 truncate text-xs text-muted-foreground">{f.description}</p>}
                    <div className="mt-1.5 flex flex-wrap gap-1.5">
                      <Badge variant="outline" className="rounded-full text-[11px] font-normal">
                        {f.sections?.length || 0} section{(f.sections?.length || 0) !== 1 ? 's' : ''}
                      </Badge>
                    </div>
                  </div>
                  <ClipboardCheck className="size-5 shrink-0 text-muted-foreground transition-colors group-hover:text-brand" />
                </button>
              ))
            )}
          </div>
          <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
            <Button variant="outline" size="sm" onClick={() => setFormPicker(null)}>Cancel</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      {/* ───── EVALUATION DIALOG (Full-screen) ───── */}
      <Dialog open={!!evalDialog} onOpenChange={(open) => !open && setEvalDialog(null)}>
        <DialogContent
          showCloseButton
          overlayClassName="bg-black/55 backdrop-blur-sm dark:bg-black/70"
          closeButtonClassName="right-7 top-7 size-14 rounded-xl border-border/80 bg-background/90 text-foreground shadow-sm hover:bg-muted dark:border-white/10 dark:bg-card/90"
          className="max-h-[92vh] max-w-[min(94vw,68rem)] rounded-[18px] border-border/80 bg-card shadow-[0_24px_80px_-24px_rgba(0,0,0,0.5)] dark:border-white/10 dark:bg-card"
          innerClassName="gap-0 overflow-hidden p-0 pr-0"
          aria-describedby="eval-dialog-desc"
        >
          <div className="min-h-0 flex-1 overflow-y-auto">
            <div className="border-b border-border/70 bg-linear-to-br from-card via-card to-brand/5 px-8 pb-6 pt-8 dark:to-brand/10 @md:px-12">
              <DialogHeader className="space-y-3 text-left">
                <DialogTitle className="text-2xl font-bold tracking-tight text-foreground @md:text-3xl">
                  Evaluate Employee
                </DialogTitle>
                <DialogDescription id="eval-dialog-desc" className="max-w-[42rem] text-base leading-relaxed text-muted-foreground @md:text-lg">
                  Complete the evaluation form and submit when ready.
                </DialogDescription>
              </DialogHeader>
              {evalDialog && (() => {
                const emp = employees.find(e => e.id === evalDialog.employee_id)
                return (
                  <div className="mt-5 flex items-center gap-4 rounded-xl bg-muted/50 p-4">
                    <Avatar className="size-12 shrink-0">
                      <AvatarImage src={emp ? profileImageUrl(emp.profile_image) : ''} />
                      <AvatarFallback className="rounded-full bg-teal-500/20 text-sm font-bold text-teal-700 dark:text-teal-300">
                        {emp ? initials(emp.first_name + ' ' + emp.last_name) : '?'}
                      </AvatarFallback>
                    </Avatar>
                    <div className="min-w-0">
                      <p className="text-base font-semibold text-foreground">{evalDialog.form?.title}</p>
                      {emp && <p className="text-sm text-muted-foreground">{emp.first_name} {emp.last_name}{emp.position ? ` — ${emp.position}` : ''}</p>}
                    </div>
                  </div>
                )
              })()}
            </div>

            <div className="px-8 py-7 @md:px-12">
              <div className="space-y-6">
                {evalDialog?.form?.sections?.map((section, sIdx) => (
                  <div key={sIdx} className="rounded-2xl border border-border/70 bg-card p-6 shadow-sm">
                    <div className="mb-1 flex items-center justify-between">
                      <h4 className="text-sm font-bold text-foreground">{section.title}</h4>
                      {section.weight > 0 && (
                        <Badge variant="outline" className="rounded-full text-xs font-normal">{section.weight}% weight</Badge>
                      )}
                    </div>
                    <div className="mt-4 space-y-4">
                      {section.questions.map((q, qIdx) => (
                        <div key={qIdx} className="flex items-center justify-between gap-4 rounded-xl bg-muted/30 px-4 py-3">
                          <span className="text-sm font-medium text-foreground">{q.title}</span>
                          {q.type === 'rating' ? (
                            <div className="flex items-center gap-1.5">
                              {Array.from({ length: q.max }, (_, i) => (
                                <button
                                  key={i}
                                  type="button"
                                  onClick={() => updateScore(section.title, q.title, i + 1)}
                                  className={cn(
                                    'size-10 rounded-xl text-sm font-bold transition-all',
                                    (evalDialog.scores?.sections?.[section.title]?.[q.title] || 0) >= i + 1
                                      ? 'bg-brand text-brand-foreground shadow-sm scale-110'
                                      : 'bg-muted text-muted-foreground hover:bg-muted/80 hover:scale-105',
                                  )}
                                >
                                  {i + 1}
                                </button>
                              ))}
                            </div>
                          ) : (
                            <Textarea
                              value={evalDialog.scores?.sections?.[section.title]?.[q.title] || ''}
                              onChange={(e) => updateScore(section.title, q.title, e.target.value)}
                              className="h-20 w-72 rounded-xl border-border/80 bg-background text-sm"
                              placeholder="Enter feedback..."
                            />
                          )}
                        </div>
                      ))}
                    </div>
                  </div>
                ))}
                <div className="space-y-3">
                  <Label className="text-sm font-semibold">Remarks</Label>
                  <Textarea
                    value={evalDialog?.remarks || ''}
                    onChange={(e) => setEvalDialog(prev => ({ ...prev, remarks: e.target.value }))}
                    rows={4}
                    placeholder="Overall assessment and comments..."
                    className="rounded-xl border-border/80 bg-background"
                  />
                </div>
              </div>
            </div>
          </div>

          <div className="sticky bottom-0 z-10 shrink-0 border-t border-border/50 bg-card/95 px-8 py-4 pb-[max(1rem,env(safe-area-inset-bottom))] backdrop-blur supports-backdrop-filter:bg-card/90 @md:px-12">
            <div className="flex items-center justify-end gap-3">
              <Button variant="outline" size="sm" onClick={() => setEvalDialog(null)} className="h-11 rounded-xl px-6">Cancel</Button>
              <Button variant="secondary" size="sm" onClick={() => handleSaveEvaluation('draft')} disabled={savingEval} className="h-11 gap-2 rounded-xl px-6">
                {savingEval ? <Loader2 className="size-4 animate-spin" /> : <FileText className="size-4" />}
                Save as Draft
              </Button>
              <Button onClick={() => handleSaveEvaluation('submitted')} disabled={savingEval} size="sm" className={cn(ADMIN_FORM_DIALOG_PRIMARY_BUTTON_CLASS, 'h-11 gap-2 rounded-xl px-6')}>
                {savingEval ? <Loader2 className="size-4 animate-spin" /> : <ClipboardCheck className="size-4" />}
                Submit Evaluation
              </Button>
            </div>
          </div>
        </DialogContent>
      </Dialog>

      {/* ───── VIEW DIALOG ───── */}
      <Dialog open={!!viewDialog} onOpenChange={(open) => !open && setViewDialog(null)}>
        <DialogContent
          showCloseButton
          className={adminFormDialogContentClass(ADMIN_FORM_DIALOG_MAX_W_LG)}
          aria-describedby="eval-view-desc"
        >
          <div className={ADMIN_FORM_DIALOG_HEADER_WRAP_CLASS}>
            <DialogHeader className={ADMIN_FORM_DIALOG_HEADER_INNER_CLASS}>
              <DialogTitle className={ADMIN_FORM_DIALOG_TITLE_CLASS}>Evaluation Details</DialogTitle>
              <p id="eval-view-desc" className={ADMIN_FORM_DIALOG_DESC_CLASS}>
                View the complete evaluation results and feedback.
              </p>
            </DialogHeader>
          </div>
          {viewDialog && (
            <div className={ADMIN_FORM_DIALOG_BODY_CLASS}>
              <div className="mb-5 flex items-center gap-4 rounded-xl bg-muted/50 p-4">
                <Avatar className="size-12 shrink-0">
                  <AvatarImage src={profileImageUrl(viewDialog.employee?.profile_image)} />
                  <AvatarFallback className="rounded-full bg-teal-500/20 text-sm font-bold text-teal-700 dark:text-teal-300">
                    {initials((viewDialog.employee?.first_name || '') + ' ' + (viewDialog.employee?.last_name || ''))}
                  </AvatarFallback>
                </Avatar>
                <div className="flex-1 min-w-0">
                  <p className="font-semibold text-foreground">{viewDialog.employee?.first_name} {viewDialog.employee?.last_name}</p>
                  <p className="text-xs text-muted-foreground">{viewDialog.evaluation_form?.title}</p>
                </div>
                <div className="text-right shrink-0">
                  {viewDialog.overall_score != null && (
                    <p className="text-2xl font-black tabular-nums text-foreground">{viewDialog.overall_score}</p>
                  )}
                  <p className="text-xs font-semibold text-muted-foreground">{viewDialog.overall_rating || '—'}</p>
                  <div className="mt-1">{statusBadge(viewDialog.status)}</div>
                </div>
              </div>

              {viewDialog.scores?.sections && Object.entries(viewDialog.scores.sections).map(([sectionTitle, questions]) => (
                <div key={sectionTitle} className="mb-4 rounded-xl border border-border/70 bg-card p-4">
                  <h4 className="mb-3 text-sm font-bold text-foreground">{sectionTitle}</h4>
                  <div className="space-y-2">
                    {Object.entries(questions).map(([qTitle, score]) => (
                      <div key={qTitle} className="flex items-center justify-between rounded-lg bg-muted/30 px-4 py-2.5">
                        <span className="text-sm text-foreground">{qTitle}</span>
                        <span className="text-sm font-semibold">
                          {typeof score === 'number' ? (
                            <span className="inline-flex items-center gap-1.5 rounded-lg bg-amber-500/15 px-3 py-1 font-bold tabular-nums text-amber-700 dark:bg-amber-500/20 dark:text-amber-300">
                              <Star className="size-3.5 fill-current" />
                              {score}
                            </span>
                          ) : (
                            <span className="text-muted-foreground">{score || '—'}</span>
                          )}
                        </span>
                      </div>
                    ))}
                  </div>
                </div>
              ))}

              {viewDialog.remarks && (
                <div className="mb-4 rounded-xl border border-border/70 bg-card p-4">
                  <h4 className="mb-2 text-sm font-bold text-foreground">Remarks</h4>
                  <p className="text-sm leading-relaxed text-muted-foreground whitespace-pre-wrap">{viewDialog.remarks}</p>
                </div>
              )}

              {viewDialog.reviewer_remarks && (
                <div className="mb-4 rounded-xl border border-amber-200/70 dark:border-amber-800/40 bg-amber-50/50 dark:bg-amber-950/20 p-4">
                  <h4 className="mb-2 text-sm font-bold text-foreground">Reviewer Remarks</h4>
                  <p className="text-sm leading-relaxed text-muted-foreground whitespace-pre-wrap">{viewDialog.reviewer_remarks}</p>
                </div>
              )}

              <div className="flex flex-wrap gap-x-6 gap-y-2 rounded-xl bg-muted/20 px-4 py-3 text-xs text-muted-foreground">
                <span>Evaluator: <span className="font-medium text-foreground">{viewDialog.evaluator?.first_name} {viewDialog.evaluator?.last_name}</span></span>
                {viewDialog.evaluated_at && <span>Evaluated: <span className="font-medium text-foreground">{formatDateFull(viewDialog.evaluated_at)}</span></span>}
                {viewDialog.overall_score != null && <span>Score: <span className="font-medium tabular-nums text-foreground">{viewDialog.overall_score}</span></span>}
              </div>
            </div>
          )}
          <DialogFooter className={ADMIN_FORM_DIALOG_FOOTER_CLASS}>
            <Button variant="outline" size="sm" onClick={() => setViewDialog(null)}>Close</Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </Motion.div>
  )
}
