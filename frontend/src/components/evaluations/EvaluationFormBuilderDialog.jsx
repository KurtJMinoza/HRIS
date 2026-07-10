/* eslint-disable react-refresh/only-export-components */
import { useMemo, useState, useCallback } from 'react'
import {
  DndContext, PointerSensor, useSensor, useSensors,
} from '@dnd-kit/core'
import {
  AlertTriangle, Archive, ArrowLeft, ArrowRight,
  Braces, Check, CheckSquare, ClipboardCheck,
  Copy, Download, FileDown, FileText,
  History, Layers, Loader2, Plus, Save, Settings2,
  Star, Trash2, Type, Upload, Users,
} from 'lucide-react'
import { AgcBrandLogo } from '@/components/AgcBrandLogo'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Checkbox } from '@/components/ui/checkbox'
import {
  Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle,
} from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import {
  Select, SelectContent, SelectItem, SelectTrigger, SelectValue,
} from '@/components/ui/select'
import { Textarea } from '@/components/ui/textarea'
import { cn } from '@/lib/utils'
import EvaluationRichTextEditor from './EvaluationRichTextEditor'
import QuestionLibraryPanel from './QuestionLibraryPanel'
import EvaluationCanvas from './EvaluationCanvas'
import PropertiesPanel from './PropertiesPanel'
import HeaderBuilder, { DEFAULT_HEADER_CONFIG } from './HeaderBuilder'
import VersionHistory, { createVersionSnapshot } from './VersionHistory'
import TemplateLibrary, { convertTemplateToBuilderState } from './TemplateLibrary'

const COMMENT_SECTION_KEY = '__comments__'

const steps = [
  { title: 'General Information', icon: FileText },
  { title: 'Introduction Page', icon: Type },
  { title: 'Employee Info & Scale', icon: Users },
  { title: 'Evaluation Builder', icon: ClipboardCheck },
  { title: 'Scoring & Rating', icon: Star },
  { title: 'Review & Publish', icon: Check },
]

const variables = [
  '{{EmployeeName}}', '{{EmployeeID}}', '{{Department}}',
  '{{Position}}', '{{Company}}', '{{Branch}}',
  '{{EvaluationDate}}', '{{Evaluator}}', '{{Period}}', '{{CurrentDate}}',
]

const employeeFields = [
  ['employee_name', 'Employee Name'],
  ['employee_number', 'Employee Number'],
  ['position', 'Position'],
  ['department', 'Department'],
  ['company', 'Company'],
  ['branch', 'Branch'],
  ['area', 'Area'],
  ['division', 'Division'],
  ['section', 'Section'],
  ['employment_status', 'Employment Status'],
  ['evaluation_period', 'Evaluation Period'],
  ['evaluator_name', 'Evaluator Name'],
  ['relationship', 'Relationship'],
  ['date', 'Date'],
]

const relationshipOptions = [
  'Immediate Supervisor', 'Peer', 'Subordinate',
  'Department Head', 'Area Head', 'Company Head',
  'HR', 'Self Assessment', 'Customer', 'Custom',
]

const defaultIntro = [
  'AMALGAMATED GROUP OF COMPANIES',
  '',
  '<h1 style="text-align: center;">360-DEGREE PERFORMANCE FEEDBACK SURVEY</h1>',
  '',
  '<p style="text-align: center; font-weight: bold; color: #dc2626;">STRICTLY CONFIDENTIAL</p>',
  '',
  '<p>Dear {{EmployeeName}},</p>',
  '',
  '<p>This survey is confidential and is intended to support fair, consistent, and growth-focused performance feedback.</p>',
].join('\n')

const defaultRatingScale = [
  { value: 5, label: 'Outstanding', description: 'Consistently exceeds expectations', color: '#16a34a', icon: 'star' },
  { value: 4, label: 'Very Good', description: 'Frequently exceeds expectations', color: '#0284c7', icon: 'star' },
  { value: 3, label: 'Good', description: 'Meets role expectations', color: '#f59e0b', icon: 'star' },
  { value: 2, label: 'Needs Improvement', description: 'Partially meets expectations', color: '#f97316', icon: 'star' },
  { value: 1, label: 'Unsatisfactory', description: 'Does not meet expectations', color: '#dc2626', icon: 'star' },
  { value: 0, label: 'N/A', description: 'Not applicable', color: '#64748b', icon: 'na' },
]

const VERSION_STORAGE_KEY = '__eval_versions__'

const defaultScoringRanges = [
  { label: 'Outstanding', min: 4.5, max: 5, color: '#16a34a', icon: 'star' },
  { label: 'Very Good', min: 3.5, max: 4.49, color: '#0284c7', icon: 'star' },
  { label: 'Good', min: 2.5, max: 3.49, color: '#f59e0b', icon: 'star' },
  { label: 'Needs Improvement', min: 1.5, max: 2.49, color: '#f97316', icon: 'star' },
  { label: 'Unsatisfactory', min: 1, max: 1.49, color: '#dc2626', icon: 'star' },
]

const defaultComments = [
  { title: 'Key Strengths', type: 'long_answer', required: false },
  { title: 'Areas for Improvement', type: 'long_answer', required: false },
  { title: 'Recommendations', type: 'long_answer', required: false },
  { title: 'Additional Comments', type: 'long_answer', required: false },
]

const defaultSections = [
  {
    title: 'Job Performance',
    weight: 70,
    description: 'Performance in assigned duties',
    archived: false,
    questions: [
      { title: 'Produces accurate work.', type: 'rating', builder_type: 'rating', max: 5, required: true, weight: 15, description: '', options: [], rows: [] },
      { title: 'Completes assignments within agreed timelines.', type: 'rating', builder_type: 'rating', max: 5, required: true, weight: 15, description: '', options: [], rows: [] },
    ],
  },
  {
    title: 'Core Values',
    weight: 30,
    description: 'Alignment with AGC values and professional conduct',
    archived: false,
    questions: [
      { title: 'Demonstrates teamwork and accountability.', type: 'rating', builder_type: 'rating', max: 5, required: true, weight: 10, description: '', options: [], rows: [] },
    ],
  },
]

const NEW_QUESTION_TEMPLATES = {
  rating: { builder_type: 'rating', type: 'rating', max: 5, required: true, weight: 0, description: '', options: [], rows: [], columns: [], placeholder: '', tooltip: '' },
  rating_10: { builder_type: 'rating_10', type: 'rating', max: 10, required: true, weight: 0, description: '', options: [], rows: [], columns: [], placeholder: '', tooltip: '' },
  star_rating: { builder_type: 'star_rating', type: 'rating', max: 5, required: false, weight: 0, description: '', options: [], rows: [], columns: [], placeholder: '', tooltip: '' },
  nps: { builder_type: 'nps', type: 'rating', max: 10, required: false, weight: 0, description: '', options: [], rows: [], columns: [], placeholder: '', tooltip: '' },
  slider: { builder_type: 'slider', type: 'rating', max: 10, min: 0, step: 1, required: false, weight: 0, description: '', options: [], rows: [], columns: [], placeholder: '', tooltip: '' },
  emoji: { builder_type: 'emoji', type: 'rating', max: 5, required: false, weight: 0, description: '', options: [], rows: [], columns: [], placeholder: '', tooltip: '' },
  thumbs: { builder_type: 'thumbs', type: 'rating', max: 2, required: false, weight: 0, description: '', options: ['👍', '👎'], rows: [], columns: [], placeholder: '', tooltip: '' },
  yes_no: { builder_type: 'yes_no', type: 'rating', max: 2, required: true, weight: 0, description: '', options: ['Yes', 'No'], rows: [], columns: [], placeholder: '', tooltip: '' },
  pass_fail: { builder_type: 'pass_fail', type: 'rating', max: 2, required: true, weight: 0, description: '', options: ['Pass', 'Fail'], rows: [], columns: [], placeholder: '', tooltip: '' },
  likert: { builder_type: 'likert', type: 'rating', max: 5, required: false, weight: 0, description: '', options: ['Strongly Disagree', 'Disagree', 'Neutral', 'Agree', 'Strongly Agree'], rows: [], columns: [], placeholder: '', tooltip: '' },
  multiple_choice: { builder_type: 'multiple_choice', type: 'text', max: 1, required: false, weight: 0, description: '', options: ['Option 1', 'Option 2', 'Option 3'], rows: [], columns: [], placeholder: 'Select one...', tooltip: '' },
  checkbox: { builder_type: 'checkbox', type: 'text', max: 1, required: false, weight: 0, description: '', options: ['Option 1', 'Option 2', 'Option 3'], rows: [], columns: [], placeholder: '', tooltip: '' },
  dropdown: { builder_type: 'dropdown', type: 'text', max: 1, required: false, weight: 0, description: '', options: ['Option 1', 'Option 2', 'Option 3'], rows: [], columns: [], placeholder: 'Select...', tooltip: '' },
  matrix: { builder_type: 'matrix', type: 'rating', max: 5, required: false, weight: 0, description: '', options: [], rows: ['Quality of Work', 'Attention to Detail', 'Timeliness'], columns: [], placeholder: '', tooltip: '' },
  score_table: { builder_type: 'score_table', type: 'rating', max: 5, required: false, weight: 0, description: '', options: [], rows: ['Criterion 1', 'Criterion 2'], columns: [{ label: 'Score', weight: 50 }, { label: 'Weight', weight: 50 }], placeholder: '', tooltip: '' },
  short_answer: { builder_type: 'short_answer', type: 'text', max: 1, required: false, weight: 0, description: '', options: [], rows: [], columns: [], placeholder: 'Enter your answer...', tooltip: '' },
  long_answer: { builder_type: 'long_answer', type: 'text', max: 1, required: false, weight: 0, description: '', options: [], rows: [], columns: [], placeholder: 'Enter your detailed answer...', tooltip: '' },
  rich_text: { builder_type: 'rich_text', type: 'text', max: 1, required: false, weight: 0, description: '', options: [], rows: [], columns: [], placeholder: '', tooltip: '' },
  number: { builder_type: 'number', type: 'text', max: 1, required: false, weight: 0, description: '', options: [], rows: [], columns: [], placeholder: '0', tooltip: '' },
  percentage: { builder_type: 'percentage', type: 'text', max: 1, required: false, weight: 0, description: '', options: [], rows: [], columns: [], placeholder: '0%', tooltip: '' },
  currency: { builder_type: 'currency', type: 'text', max: 1, required: false, weight: 0, description: '', options: [], rows: [], columns: [], placeholder: '₱0.00', tooltip: '' },
  email: { builder_type: 'email', type: 'text', max: 1, required: false, weight: 0, description: '', options: [], rows: [], columns: [], placeholder: 'email@example.com', tooltip: '' },
  phone: { builder_type: 'phone', type: 'text', max: 1, required: false, weight: 0, description: '', options: [], rows: [], columns: [], placeholder: '+63 900 000 0000', tooltip: '' },
  date: { builder_type: 'date', type: 'text', max: 1, required: false, weight: 0, description: '', options: [], rows: [], columns: [], placeholder: '', tooltip: '' },
  time: { builder_type: 'time', type: 'text', max: 1, required: false, weight: 0, description: '', options: [], rows: [], columns: [], placeholder: '', tooltip: '' },
  file_upload: { builder_type: 'file_upload', type: 'text', max: 1, required: false, weight: 0, description: '', options: [], rows: [], columns: [], placeholder: '', tooltip: '', allowed_file_types: '', max_file_size: 10, required_attachment: false },
  signature: { builder_type: 'signature', type: 'text', max: 1, required: false, weight: 0, description: '', options: [], rows: [], columns: [], placeholder: '', tooltip: '', required_signature: false },
  employee_lookup: { builder_type: 'employee_lookup', type: 'text', max: 1, required: false, weight: 0, description: '', options: [], rows: [], columns: [], placeholder: '', tooltip: '' },
  manager_lookup: { builder_type: 'manager_lookup', type: 'text', max: 1, required: false, weight: 0, description: '', options: [], rows: [], columns: [], placeholder: '', tooltip: '' },
  formula: { builder_type: 'formula', type: 'text', max: 1, required: false, weight: 0, description: '', options: [], rows: [], columns: [], placeholder: '', tooltip: '', formula: '', calculated: false },
  comment_block: { builder_type: 'comment_block', type: 'text', max: 1, required: false, weight: 0, description: '', options: [], rows: [], columns: [], placeholder: '', tooltip: '', required_comment: false },
  section: { builder_type: 'section', type: 'text', max: 1, required: false, weight: 0, description: '', options: [], rows: [], columns: [], placeholder: '', tooltip: '' },
  title: { builder_type: 'title', type: 'text', max: 1, required: false, weight: 0, description: '', options: [], rows: [], columns: [], placeholder: '', tooltip: '' },
  paragraph: { builder_type: 'paragraph', type: 'text', max: 1, required: false, weight: 0, description: '', options: [], rows: [], columns: [], placeholder: '', tooltip: '' },
  instruction: { builder_type: 'instruction', type: 'text', max: 1, required: false, weight: 0, description: '', options: [], rows: [], columns: [], placeholder: '', tooltip: '' },
  divider: { builder_type: 'divider', type: 'text', max: 1, required: false, weight: 0, description: '', options: [], rows: [], columns: [], placeholder: '', tooltip: '' },
  signature_block: { builder_type: 'signature_block', type: 'text', max: 1, required: false, weight: 0, description: '', options: [], rows: [], columns: [], placeholder: '', tooltip: '' },
}

function clone(value) {
  return JSON.parse(JSON.stringify(value))
}

function normalizeQuestion(question = {}) {
  const builderType = question.builder_type || question.type || 'rating'
  const isRatingType = ['rating', 'rating_10', 'star_rating', 'nps', 'slider', 'emoji', 'thumbs', 'yes_no', 'pass_fail', 'likert', 'matrix', 'score_table'].includes(builderType)
  const apiType = isRatingType ? 'rating' : 'text'
  const template = NEW_QUESTION_TEMPLATES[builderType] || NEW_QUESTION_TEMPLATES.rating
  return {
    ...template,
    ...question,
    title: question.title || '',
    type: apiType,
    builder_type: builderType,
    max: Number(question.max ?? template.max ?? 5),
    required: question.required ?? template.required ?? true,
    weight: Number(question.weight ?? 0),
    description: question.description || '',
    options: Array.isArray(question.options) ? question.options : (template.options ? [...template.options] : []),
    rows: Array.isArray(question.rows) ? question.rows : (template.rows ? [...template.rows] : []),
    columns: Array.isArray(question.columns) ? question.columns : (template.columns ? [...template.columns] : []),
  }
}

function normalizeSection(section = {}) {
  return {
    title: section.title || '',
    weight: Number(section.weight || 0),
    description: section.description || '',
    archived: Boolean(section.archived),
    questions: Array.isArray(section.questions) && section.questions.length
      ? section.questions.map(normalizeQuestion)
      : [{ title: '', type: 'rating', builder_type: 'rating', max: 5, required: true, weight: 0, description: '', options: [], rows: [], columns: [], placeholder: '', tooltip: '' }],
  }
}

function sectionIsComments(section = {}) {
  return section.builder_kind === COMMENT_SECTION_KEY || section.meta?.kind === COMMENT_SECTION_KEY
}

function getBuilderMeta(sections) {
  return sections.find(section => section.builder_meta)?.builder_meta || {}
}

export function createEvaluationBuilderState(form = null) {
  const rawSections = Array.isArray(form?.sections) ? form.sections : []
  const commentsSection = rawSections.find(sectionIsComments)
  const formSections = rawSections.filter(section => !sectionIsComments(section))
  const meta = form?.builder_meta || getBuilderMeta(rawSections)

  return {
    id: form?.id ?? null,
    company_id: form?.company_id ? String(form.company_id) : '',
    title: form?.title || '',
    description: form?.description || '',
    category: meta.category || 'Performance Management',
    evaluation_type: meta.evaluation_type || '360-Degree Feedback',
    applicable_department: meta.applicable_department || 'All Departments',
    applicable_employment_types: meta.applicable_employment_types || 'Regular, Probationary',
    status: meta.status || (form?.is_active === false ? 'Inactive' : 'Draft'),
    effective_date: meta.effective_date || '',
    expiration_date: meta.expiration_date || '',
    instructions: meta.instructions || '',
    introduction: meta.introduction || defaultIntro,
    employee_fields: Array.isArray(meta.employee_fields)
      ? meta.employee_fields
      : employeeFields.map(([key, label]) => ({ key, label, selected: true })),
    relationships: Array.isArray(meta.relationships)
      ? meta.relationships
      : relationshipOptions.map(label => ({ label, selected: ['Immediate Supervisor', 'Peer', 'Self Assessment', 'HR'].includes(label) })),
    rating_scale: Array.isArray(meta.rating_scale) ? meta.rating_scale : clone(defaultRatingScale),
    scoring_ranges: Array.isArray(meta.scoring_ranges) ? meta.scoring_ranges : clone(defaultScoringRanges),
    comments: commentsSection?.questions?.length
      ? commentsSection.questions.map(q => ({ title: q.title || '', type: q.builder_type || 'long_answer', required: Boolean(q.required) }))
      : clone(defaultComments),
    sections: formSections.length ? formSections.map(normalizeSection) : clone(defaultSections),
    header_config: meta.header_config ? { ...DEFAULT_HEADER_CONFIG, ...meta.header_config } : clone(DEFAULT_HEADER_CONFIG),
    versions: Array.isArray(meta.versions) ? meta.versions : [],
    is_active: form?.is_active ?? true,
  }
}

export function createEvaluationFormPayload(value) {
  const state = createEvaluationBuilderState(value)
  const builderMeta = {
    version: state.versions.length > 0 ? Math.max(...state.versions.map(v => v.version || v.number || 0)) + 1 : 1,
    category: state.category,
    evaluation_type: state.evaluation_type,
    applicable_department: state.applicable_department,
    applicable_employment_types: state.applicable_employment_types,
    status: state.status,
    effective_date: state.effective_date,
    expiration_date: state.expiration_date,
    instructions: state.instructions,
    introduction: state.introduction,
    employee_fields: state.employee_fields,
    relationships: state.relationships,
    rating_scale: state.rating_scale,
    scoring_ranges: state.scoring_ranges,
    header_config: state.header_config ? { ...state.header_config } : null,
    versions: Array.isArray(state.versions) ? state.versions.map(v => ({ ...v })) : [],
  }

  const sections = state.sections
    .filter(section => !section.archived)
    .map((section, index) => {
      // Normalize section
      const sec = { ...section }
      sec.title = section.title.trim()
      sec.weight = Number(section.weight || 0)
      if (index === 0) sec.builder_meta = builderMeta
      else delete sec.builder_meta
      sec.questions = section.questions.map(question => {
        const builderType = question.builder_type || 'rating'
        const isRatingType = ['rating', 'rating_10', 'star_rating', 'nps', 'slider', 'emoji', 'thumbs', 'yes_no', 'pass_fail', 'likert', 'matrix', 'score_table'].includes(builderType)
        const q = { ...question }
        q.title = question.title.trim()
        q.type = isRatingType ? 'rating' : 'text'
        q.max = isRatingType ? Number(question.max || 5) : 1
        // Clean up undefined fields
        Object.keys(q).forEach(key => { if (q[key] === undefined) delete q[key] })
        return q
      })
      return sec
    })

  const commentQuestions = state.comments
    .filter(comment => comment.title.trim())
    .map(comment => ({
      title: comment.title.trim(),
      type: 'text',
      builder_type: comment.type || 'long_answer',
      max: 1,
      required: Boolean(comment.required),
      weight: 0,
      description: '',
    }))

  if (commentQuestions.length) {
    sections.push({
      title: 'Comments',
      weight: 0,
      description: 'Open-ended evaluator comments',
      builder_kind: COMMENT_SECTION_KEY,
      builder_meta,
      questions: commentQuestions,
    })
  }

  return {
    company_id: state.company_id ? Number(state.company_id) : '',
    title: state.title.trim(),
    description: state.description || '',
    is_active: state.status !== 'Inactive' && state.is_active !== false,
    sections,
  }
}

function TogglePill({ checked, onClick, children }) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={cn(
        'inline-flex min-h-10 items-center gap-2 rounded-lg border px-3 py-2 text-left text-xs font-semibold transition',
        checked
          ? 'border-brand/45 bg-brand/10 text-brand'
          : 'border-border/70 bg-card text-muted-foreground hover:border-brand/35 hover:text-foreground',
      )}
    >
      <span className={cn('flex size-4 items-center justify-center rounded border', checked ? 'border-brand bg-brand text-brand-foreground' : 'border-border bg-background')}>
        {checked && <Check className="size-3" />}
      </span>
      {children}
    </button>
  )
}

function BuilderPanel({ title, description, icon, children, className }) {
  const PanelIcon = icon
  return (
    <section className={cn('rounded-lg border border-border/70 bg-card shadow-sm', className)}>
      <div className="flex items-center gap-3 border-b border-border/50 bg-muted/20 px-4 py-3">
        <span className="flex size-9 shrink-0 items-center justify-center rounded-lg bg-brand/10 text-brand">
          <PanelIcon className="size-4" />
        </span>
        <div className="min-w-0">
          <h3 className="text-sm font-bold text-foreground">{title}</h3>
          {description && <p className="mt-0.5 text-xs text-muted-foreground">{description}</p>}
        </div>
      </div>
      <div className="p-4">{children}</div>
    </section>
  )
}

export default function EvaluationFormBuilderDialog({
  open,
  value,
  onChange,
  companies = [],
  saving = false,
  onSave,
  onCancel,
}) {
  const [activeStep, setActiveStep] = useState(0)
  const [selectedQuestion, setSelectedQuestion] = useState(null)
  const [showVersionHistoryStep1, setShowVersionHistoryStep1] = useState(false)
  const [showVersionHistoryStep6, setShowVersionHistoryStep6] = useState(false)
  const [showHeaderBuilder, setShowHeaderBuilder] = useState(false)

  // Reset step-specific panels when navigating between steps
  const goToStep = useCallback((step) => {
    setActiveStep(step)
    setShowVersionHistoryStep1(false)
    setShowVersionHistoryStep6(false)
    setShowHeaderBuilder(false)
  }, [])

  const form = useMemo(() => createEvaluationBuilderState(value), [value])
  const totalWeight = useMemo(
    () => form.sections.filter(section => !section.archived).reduce((sum, section) => sum + Number(section.weight || 0), 0),
    [form.sections],
  )
  const activeSections = form.sections.filter(section => !section.archived)
  const totalQuestions = activeSections.reduce((sum, section) => sum + section.questions.length, 0)

  const patch = useCallback((partial) => {
    onChange(prev => {
      const next = { ...createEvaluationBuilderState(prev), ...partial }
      return next
    })
  }, [onChange])

  const patchList = useCallback((field, updater) => {
    onChange(prev => {
      const prevState = createEvaluationBuilderState(prev)
      const currentList = prevState[field] || []
      const updatedList = updater(currentList)
      return { ...prevState, [field]: updatedList }
    })
  }, [onChange])

  const updateSection = useCallback((index, updates) => {
    patchList('sections', sections => sections.map((section, sectionIndex) => (
      sectionIndex === index ? { ...section, ...updates } : section
    )))
  }, [patchList])

  const updateQuestion = useCallback((sectionIndex, questionIndex, updates) => {
    patchList('sections', sections => sections.map((section, currentSectionIndex) => {
      if (currentSectionIndex !== sectionIndex) return section
      return {
        ...section,
        questions: section.questions.map((question, currentQuestionIndex) => (
          currentQuestionIndex === questionIndex ? { ...question, ...updates } : question
        )),
      }
    }))
  }, [patchList])

  const addSection = useCallback(() => {
    patchList('sections', sections => [
      ...sections,
      { title: 'New Section', weight: 0, description: '', archived: false, questions: clone([NEW_QUESTION_TEMPLATES.rating]) },
    ])
  }, [patchList])

  const insertVariable = useCallback((token) => {
    patch({ introduction: `${form.introduction}${form.introduction.endsWith(' ') || form.introduction.endsWith('\n') ? '' : ' '}${token}` })
  }, [patch, form.introduction])

  // ─── Drag & Drop: Add new question from palette ─────────────────

  const dndSensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 5 } })
  )

  const handleDragEndFromPalette = useCallback((event) => {
    const { active, over } = event
    if (!active || !over) return

    const activeData = active.data.current
    if (!activeData || activeData.type !== 'new_question') return

    const questionType = activeData.questionType
    const template = NEW_QUESTION_TEMPLATES[questionType]
    if (!template) return

    // Determine which section to drop into
    let targetSectionIndex = -1
    let targetQuestionIndex = -1

    if (over.id === 'canvas-drop-zone') {
      // Dropped on empty canvas
      targetSectionIndex = 0
      if (form.sections.length === 0) {
        patchList('sections', sections => [
          ...sections,
          { title: 'New Section', weight: 0, description: '', archived: false, questions: [clone(template)] },
        ])
        return
      }
      targetQuestionIndex = form.sections[0].questions.length
    } else {
      // Dropped on an existing section or question
      const overData = over.data.current
      if (overData?.type === 'section') {
        targetSectionIndex = overData.sectionIndex
        // Add to the end of this section
        patchList('sections', sections => sections.map((section, idx) =>
          idx === targetSectionIndex
            ? { ...section, questions: [...section.questions, clone(template)] }
            : section
        ))
        return
      } else if (overData?.type === 'question') {
        targetSectionIndex = overData.sectionIndex
        targetQuestionIndex = overData.questionIndex
        // Insert after the targeted question
        patchList('sections', sections => sections.map((section, idx) => {
          if (idx !== targetSectionIndex) return section
          const newQuestions = [...section.questions]
          newQuestions.splice(targetQuestionIndex + 1, 0, clone(template))
          return { ...section, questions: newQuestions }
        }))
        return
      }
    }

    // Default: add to first section
    if (form.sections.length > 0) {
      patchList('sections', sections => sections.map((section, idx) =>
        idx === 0
          ? { ...section, questions: [...section.questions, clone(template)] }
          : section
      ))
    } else {
      patchList('sections', sections => [
        ...sections,
        { title: 'New Section', weight: 0, description: '', archived: false, questions: [clone(template)] },
      ])
    }
  }, [patchList, form.sections])

  const handleSelectQuestion = useCallback((sectionIndex, questionIndex) => {
    setSelectedQuestion({ sectionIndex, questionIndex })
  }, [])

  const currentStep = steps[activeStep]
  const CurrentIcon = currentStep.icon

  // Derive the currently selected question/section for the properties panel
  const currentQuestion = selectedQuestion
    ? form.sections[selectedQuestion.sectionIndex]?.questions?.[selectedQuestion.questionIndex]
    : null
  const currentSection = selectedQuestion
    ? form.sections[selectedQuestion.sectionIndex]
    : null

  return (
    <Dialog open={open} onOpenChange={(nextOpen) => !nextOpen && onCancel()}>
      <DialogContent
        showCloseButton
        overlayClassName="bg-black/55 backdrop-blur-sm dark:bg-black/70"
        closeButtonClassName="right-4 top-4 size-10 rounded-lg border-border/80 bg-background/90 text-foreground shadow-sm hover:bg-muted @md:right-7 @md:top-7 @md:size-11 dark:border-white/10 dark:bg-card/90"
        className="h-[95vh] max-h-[95vh] w-[95vw] max-w-[95vw] rounded-lg border-border/80 bg-card shadow-[0_24px_80px_-24px_rgba(0,0,0,0.5)] dark:border-white/10"
        innerClassName="gap-0 overflow-hidden p-0 pr-0"
        aria-describedby="eval-builder-desc"
      >
        <div className="flex min-h-0 flex-1 flex-col">
          <div className="shrink-0 border-b border-border/70 bg-card">
            <DialogHeader className="px-5 pb-4 pt-5 text-left @md:px-8">
              <div className="flex flex-wrap items-center justify-between gap-4 pr-12">
                <div className="min-w-0">
                  <AgcBrandLogo className="mb-3 h-8" />
                  <DialogTitle className="text-xl font-bold tracking-tight text-foreground @md:text-2xl">
                    {form.id ? 'Edit Performance Evaluation Form' : 'Create Evaluation Form'}
                  </DialogTitle>
                  <DialogDescription id="eval-builder-desc" className="mt-1 max-w-3xl text-sm text-muted-foreground">
                    Enterprise-grade performance evaluation builder with drag-and-drop, rich text editing, custom scoring, and dynamic HRIS variables.
                  </DialogDescription>
                </div>
                <div className="flex items-center gap-2">
                  <Badge variant="outline" className="rounded-full px-3 py-1 text-xs">{activeSections.length} sections</Badge>
                  <Badge variant="outline" className="rounded-full px-3 py-1 text-xs">{totalQuestions} questions</Badge>
                  <Badge className={cn('rounded-full border-0 px-3 py-1 text-xs', totalWeight === 100 ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/15 dark:text-emerald-300' : 'bg-amber-100 text-amber-700 dark:bg-amber-500/15 dark:text-amber-300')}>
                    {totalWeight}% total
                  </Badge>
                </div>
              </div>
            </DialogHeader>

            <div className="overflow-x-auto border-t border-border/50 px-4 py-3 @md:px-8">
              <div className="grid min-w-[820px] grid-cols-6 gap-2">                        {steps.map((step, index) => {
                  const Icon = step.icon
                  const isActive = activeStep === index
                  const isDone = activeStep > index
                  return (
                    <button
                      key={step.title}
                      type="button"
                      onClick={() => goToStep(index)}
                      className={cn(
                        'flex min-h-12 items-center gap-2 rounded-lg border px-3 py-2 text-left text-xs font-bold transition',
                        isActive
                          ? 'border-brand/50 bg-brand text-brand-foreground shadow-sm'
                          : isDone
                            ? 'border-brand/25 bg-brand/10 text-brand'
                            : 'border-border/70 bg-muted/20 text-muted-foreground hover:bg-muted/35 hover:text-foreground',
                      )}
                    >
                      <span className={cn('flex size-6 shrink-0 items-center justify-center rounded-full', isActive ? 'bg-white/18' : isDone ? 'bg-brand text-brand-foreground' : 'bg-background')}>
                        {isDone ? <Check className="size-3.5" /> : <Icon className="size-3.5" />}
                      </span>
                      <span className="truncate">{step.title}</span>
                    </button>
                  )
                })}
              </div>
            </div>
          </div>

          <div className="min-h-0 flex-1 overflow-y-auto bg-muted/10 px-4 py-5 @md:px-8 @md:py-6">
            <div className="mx-auto w-full max-w-[88rem] space-y-5">
              <div className="flex items-center gap-3">
                <span className="flex size-10 items-center justify-center rounded-lg bg-brand text-brand-foreground shadow-sm">
                  <CurrentIcon className="size-5" />
                </span>
                <div>
                  <p className="text-xs font-bold uppercase tracking-[0.16em] text-brand">Step {activeStep + 1} of {steps.length}</p>
                  <h2 className="text-xl font-bold tracking-tight text-foreground">{currentStep.title}</h2>
                </div>
              </div>

              {/* ─── STEP 1: General Information ─── */}
              {activeStep === 0 && (
                <div className="grid gap-5 @xl:grid-cols-3">
                  <BuilderPanel title="Basic Information" description="Core template details and availability." icon={FileText} className="@xl:col-span-2">
                    <div className="grid gap-4 @md:grid-cols-2">
                      <div className="space-y-2 @md:col-span-2">
                        <Label>Evaluation Name *</Label>
                        <Input value={form.title} onChange={(e) => patch({ title: e.target.value })} className="h-10 rounded-lg" placeholder="360-Degree Performance Feedback Survey" />
                      </div>
                      <div className="space-y-2">
                        <Label>Category *</Label>
                        <Input value={form.category} onChange={(e) => patch({ category: e.target.value })} className="h-10 rounded-lg" />
                      </div>
                      <div className="space-y-2">
                        <Label>Evaluation Type *</Label>
                        <Select value={form.evaluation_type} onValueChange={(evType) => patch({ evaluation_type: evType })}>
                          <SelectTrigger className="h-10 rounded-lg"><SelectValue /></SelectTrigger>
                          <SelectContent>
                            {['360-Degree Feedback', 'Regularization', 'Leadership Assessment', 'Probationary Evaluation', 'Performance Appraisal', 'Competency Evaluation', 'KPI Review', 'Behavioral Assessment'].map(type => (
                              <SelectItem key={type} value={type}>{type}</SelectItem>
                            ))}
                          </SelectContent>
                        </Select>
                      </div>
                      <div className="space-y-2">
                        <Label>Applicable Company</Label>
                        <Select value={form.company_id} onValueChange={(companyId) => patch({ company_id: companyId })}>
                          <SelectTrigger className="h-10 rounded-lg"><SelectValue placeholder="Select company" /></SelectTrigger>
                          <SelectContent>
                            {companies.map(company => <SelectItem key={company.id} value={String(company.id)}>{company.name}</SelectItem>)}
                          </SelectContent>
                        </Select>
                      </div>
                      <div className="space-y-2">
                        <Label>Applicable Department</Label>
                        <Input value={form.applicable_department} onChange={(e) => patch({ applicable_department: e.target.value })} className="h-10 rounded-lg" />
                      </div>
                      <div className="space-y-2">
                        <Label>Employment Types</Label>
                        <Input value={form.applicable_employment_types} onChange={(e) => patch({ applicable_employment_types: e.target.value })} className="h-10 rounded-lg" placeholder="Regular, Probationary" />
                      </div>
                      <div className="space-y-2">
                        <Label>Status</Label>
                        <Select value={form.status} onValueChange={(status) => patch({ status })}>
                          <SelectTrigger className="h-10 rounded-lg"><SelectValue /></SelectTrigger>
                          <SelectContent>
                            {['Draft', 'Active', 'Inactive'].map(s => <SelectItem key={s} value={s}>{s}</SelectItem>)}
                          </SelectContent>
                        </Select>
                      </div>
                      <div className="space-y-2">
                        <Label>Effective Date</Label>
                        <Input type="date" value={form.effective_date} onChange={(e) => patch({ effective_date: e.target.value })} className="h-10 rounded-lg" />
                      </div>
                      <div className="space-y-2">
                        <Label>Expiration Date</Label>
                        <Input type="date" value={form.expiration_date} onChange={(e) => patch({ expiration_date: e.target.value })} className="h-10 rounded-lg" />
                      </div>
                      <div className="space-y-2 @md:col-span-2">
                        <Label>Instructions</Label>
                        <InlineRichTextEditor
                          content={form.instructions}
                          onChange={(html) => patch({ instructions: html })}
                          placeholder="Internal HR notes and administration instructions (supports rich text)..."
                          minHeight="6rem"
                          compact
                        />
                      </div>
                    </div>
                  </BuilderPanel>

                  <div className="space-y-4">
                    <BuilderPanel title="Template Library" description="Start from a professionally designed template." icon={Layers}>
                      <TemplateLibrary
                        savedForms={[]}
                        onUseTemplate={(template) => {
                          const builderState = convertTemplateToBuilderState(template, companies)
                          patch(builderState)
                          setShowTemplateLibrary(false)
                        }}
                        onDuplicateForm={(form) => {
                          patch({ id: null, title: `${form.title || 'Untitled'} Copy`, status: 'Draft' })
                        }}
                      />
                    </BuilderPanel>

                    <BuilderPanel title="Header Builder" description="Design logo, watermark, and document header." icon={Layers}>
                      <div className="space-y-3">
                        {form.header_config && (
                          <div className="flex items-center gap-3 rounded-lg border border-border/60 bg-muted/15 p-3">
                            <div className="flex size-8 items-center justify-center rounded-lg bg-brand/10 text-brand">
                              <Layers className="size-4" />
                            </div>
                            <div className="min-w-0 flex-1 text-xs">
                              <p className="font-semibold text-foreground">
                                {form.header_config.title_text || 'Performance Evaluation Form'}
                              </p>
                              <p className="text-muted-foreground mt-0.5">
                                {form.header_config.logo_url ? 'Logo configured' : 'No logo'} · {form.header_config.watermark_enabled ? 'Watermark on' : 'No watermark'}
                              </p>
                            </div>
                            <Button type="button" variant="outline" size="sm" className="h-8 rounded-lg text-xs" onClick={() => setShowHeaderBuilder(!showHeaderBuilder)}>
                              {showHeaderBuilder ? 'Done' : 'Edit'}
                            </Button>
                          </div>
                        )}
                        {showHeaderBuilder && (
                          <div className="max-h-[28rem] overflow-y-auto">
                            <HeaderBuilder
                              config={form.header_config || clone(DEFAULT_HEADER_CONFIG)}
                              onChange={(headerConfig) => patch({ header_config: headerConfig })}
                            />
                          </div>
                        )}
                      </div>
                    </BuilderPanel>

                    <BuilderPanel title="Template Controls" description="Draft and template lifecycle actions." icon={Settings2}>
                      <div className="grid gap-2">
                        {[
                          [Save, 'Save as Template'],
                          [Copy, 'Duplicate Template'],
                          [History, 'Version History'],
                          [Layers, 'Header Builder'],
                        ].map(([ActionIcon, label]) => (
                          <Button key={label} type="button" variant="outline" className="h-10 justify-start rounded-lg"
                            onClick={() => {
                              if (label === 'Duplicate Template') {
                                patch({ id: null, title: `${form.title || 'Untitled'} Copy`, status: 'Draft' })
                              } else if (label === 'Version History') {
                                setShowVersionHistoryStep1(!showVersionHistoryStep1)
                              } else if (label === 'Header Builder') {
                                setShowHeaderBuilder(!showHeaderBuilder)
                              }
                            }}
                          >
                            <ActionIcon className="size-4" />
                            {label}
                          </Button>
                        ))}
                      </div>

                      {/* Version History inline */}
                      {showVersionHistoryStep1 && (
                        <div className="mt-4 max-h-[24rem] overflow-y-auto rounded-lg border border-border/60 p-3">
                          <VersionHistory
                            versions={form.versions || []}
                            currentVersionId={form.versions.length > 0 ? Math.max(...form.versions.map(v => v.version || v.number || 0)) : 1}
                            onRestore={(version) => {
                              // Direct state restore that bypasses createEvaluationBuilderState defaults
                              if (version.snapshot) {
                                onChange(prev => ({
                                  // Spread the raw form first
                                  ...(typeof prev === 'object' && prev !== null ? prev : {}),
                                  // Then apply snapshot fields directly
                                  title: version.snapshot.title || '',
                                  description: version.snapshot.description || '',
                                  category: version.snapshot.category || 'Performance Management',
                                  evaluation_type: version.snapshot.evaluation_type || '360-Degree Feedback',
                                  sections: version.snapshot.sections || [],
                                  introduction: version.snapshot.introduction || '',
                                  employee_fields: version.snapshot.employee_fields || [],
                                  relationships: version.snapshot.relationships || [],
                                  rating_scale: version.snapshot.rating_scale || [],
                                  scoring_ranges: version.snapshot.scoring_ranges || [],
                                  comments: version.snapshot.comments || [],
                                  header_config: version.snapshot.header_config || null,
                                }))
                              }
                            }}
                            onDuplicate={(version) => {
                              if (version.snapshot) {
                                patch({
                                  id: null,
                                  title: `${version.snapshot.title || 'Untitled'} (v${version.version || version.number}) Copy`,
                                  status: 'Draft',
                                  sections: version.snapshot.sections,
                                })
                              }
                            }}
                            onDelete={(version) => {
                              const filtered = (form.versions || []).filter(v => (v.version || v.number) !== (version.version || version.number))
                              patch({ versions: filtered })
                            }}
                            onCreateVersion={() => {
                              const newVersion = createVersionSnapshot(form, form.versions || [])
                              patch({ versions: [...(form.versions || []), newVersion] })
                            }}
                          />
                        </div>
                      )}
                    </BuilderPanel>
                  </div>
                </div>
              )}

              {/* ─── STEP 2: Introduction Page (Rich Text) ─── */}
              {activeStep === 1 && (
                <div className="grid gap-5 @xl:grid-cols-[1fr_18rem]">
                  <BuilderPanel title="Introduction Page" description="Word-style editor with dynamic HRIS variables." icon={Type}>
                    <EvaluationRichTextEditor
                      content={form.introduction}
                      onChange={(html) => patch({ introduction: html })}
                      placeholder="Compose your evaluation introduction..."
                      minHeight="28rem"
                      variables={variables}
                    />
                  </BuilderPanel>

                  <div className="space-y-4">
                    <BuilderPanel title="Variables" description="Click to insert into the document." icon={Braces}>
                      <div className="space-y-1.5">
                        {variables.map(variable => (
                          <button
                            key={variable}
                            type="button"
                            onClick={() => insertVariable(variable)}
                            className="w-full rounded-lg border border-border/70 bg-card px-3 py-2 text-left font-mono text-xs font-semibold text-brand transition hover:border-brand/40 hover:bg-brand/10"
                          >
                            {variable}
                          </button>
                        ))}
                      </div>
                    </BuilderPanel>

                    <BuilderPanel title="Quick Actions" icon={Settings2}>
                      <div className="space-y-2">
                        <Button type="button" variant="outline" className="h-9 w-full justify-start rounded-lg text-xs" onClick={() => patch({ introduction: '' })}>
                          <Trash2 className="size-3.5 mr-2" />
                          Clear Content
                        </Button>
                        <Button type="button" variant="outline" className="h-9 w-full justify-start rounded-lg text-xs" onClick={() => patch({ introduction: defaultIntro })}>
                          <Copy className="size-3.5 mr-2" />
                          Reset to Default
                        </Button>
                      </div>
                    </BuilderPanel>
                  </div>
                </div>
              )}

              {/* ─── STEP 3: Employee Info & Rating Scale ─── */}
              {activeStep === 2 && (
                <div className="grid gap-5 @xl:grid-cols-2">
                  <BuilderPanel title="Employee Information Fields" description="Choose what HR wants shown on the evaluation document." icon={Users}>
                    <div className="grid gap-2 @sm:grid-cols-2">
                      {form.employee_fields.map((field, index) => (
                        <TogglePill
                          key={field.key}
                          checked={field.selected}
                          onClick={() => patchList('employee_fields', fields => fields.map((item, itemIndex) => itemIndex === index ? { ...item, selected: !item.selected } : item))}
                        >
                          {field.label}
                        </TogglePill>
                      ))}
                    </div>
                  </BuilderPanel>

                  <BuilderPanel title="Evaluator Relationship" description="Select allowed evaluator perspectives." icon={Users}>
                    <div className="grid gap-2 @sm:grid-cols-2">
                      {form.relationships.map((relationship, index) => (
                        <TogglePill
                          key={relationship.label}
                          checked={relationship.selected}
                          onClick={() => patchList('relationships', list => list.map((item, itemIndex) => itemIndex === index ? { ...item, selected: !item.selected } : item))}
                        >
                          {relationship.label}
                        </TogglePill>
                      ))}
                    </div>
                  </BuilderPanel>

                  <BuilderPanel title="Rating Scale Builder" description="Define score values, labels, descriptions, colors, and icons." icon={Star} className="@xl:col-span-2">
                    <div className="mb-4 flex justify-end">
                      <Button type="button" variant="outline" className="h-9 rounded-lg" onClick={() => patchList('rating_scale', rows => [...rows, { value: rows.length + 1, label: 'New Rating', description: '', color: '#f97316', icon: 'star' }])}>
                        <Plus className="size-4" />
                        Add Rating
                      </Button>
                    </div>
                    <div className="space-y-3">
                      {form.rating_scale.map((rating, index) => (
                        <div key={index} className="grid gap-3 rounded-lg border border-border/70 bg-muted/15 p-3 @lg:grid-cols-[5rem_1fr_2fr_5rem_7rem_auto] @lg:items-center">
                          <Input type="number" value={rating.value} onChange={(e) => patchList('rating_scale', rows => rows.map((row, rowIndex) => rowIndex === index ? { ...row, value: Number(e.target.value) } : row))} className="h-9 rounded-lg" />
                          <Input value={rating.label} onChange={(e) => patchList('rating_scale', rows => rows.map((row, rowIndex) => rowIndex === index ? { ...row, label: e.target.value } : row))} className="h-9 rounded-lg" placeholder="Label" />
                          <Input value={rating.description} onChange={(e) => patchList('rating_scale', rows => rows.map((row, rowIndex) => rowIndex === index ? { ...row, description: e.target.value } : row))} className="h-9 rounded-lg" placeholder="Description" />
                          <Input type="color" value={rating.color} onChange={(e) => patchList('rating_scale', rows => rows.map((row, rowIndex) => rowIndex === index ? { ...row, color: e.target.value } : row))} className="h-9 rounded-lg p-1" />
                          <Select value={rating.icon} onValueChange={(icon) => patchList('rating_scale', rows => rows.map((row, rowIndex) => rowIndex === index ? { ...row, icon } : row))}>
                            <SelectTrigger className="h-9 rounded-lg"><SelectValue /></SelectTrigger>
                            <SelectContent>
                              <SelectItem value="star">Star</SelectItem>
                              <SelectItem value="check">Check</SelectItem>
                              <SelectItem value="na">N/A</SelectItem>
                            </SelectContent>
                          </Select>
                          <Button type="button" variant="ghost" size="icon-sm" className="text-destructive" onClick={() => patchList('rating_scale', rows => rows.filter((_, rowIndex) => rowIndex !== index))}>
                            <Trash2 className="size-4" />
                          </Button>
                        </div>
                      ))}
                    </div>
                  </BuilderPanel>
                </div>
              )}

              {/* ─── STEP 4: Evaluation Builder (Three-Panel Layout) ─── */}
              {activeStep === 3 && (
                <div className="flex h-[calc(100vh-22rem)] gap-0 overflow-hidden rounded-xl border border-border/70 bg-card shadow-sm">
                  {/* Left: Question Library */}
                  <div className="w-64 shrink-0 border-r border-border/50">
                    <QuestionLibraryPanel className="h-full" />
                  </div>

                  {/* Center: Canvas */}
                  <div className="flex min-w-0 flex-1 flex-col overflow-hidden">
                    <DndContext
                      sensors={dndSensors}
                      collisionDetection={undefined}
                      onDragEnd={handleDragEndFromPalette}
                    >
                      <EvaluationCanvas
                        sections={form.sections}
                        onUpdateSection={updateSection}
                        onAddSection={addSection}
                        onDuplicateSection={(sIdx) => {
                          const newSection = clone(form.sections[sIdx])
                          newSection.title = `${newSection.title || 'Section'} (Copy)`
                          const result = [...form.sections]
                          result.splice(sIdx + 1, 0, newSection)
                          result.forEach((sec, idx) => updateSection(idx, sec))
                        }}
                        onDeleteSection={(sIdx) => {
                          const result = form.sections.filter((_, i) => i !== sIdx)
                          result.forEach((sec, idx) => updateSection(idx, sec))
                        }}
                        onSelectQuestion={handleSelectQuestion}
                        selectedQuestion={selectedQuestion}
                        totalWeight={totalWeight}
                        className="h-full"
                      />
                    </DndContext>
                  </div>

                  {/* Right: Properties */}
                  <div className="w-72 shrink-0 border-l border-border/50 bg-muted/5">
                    <PropertiesPanel
                      question={currentQuestion}
                      section={currentSection}
                      sectionIndex={selectedQuestion?.sectionIndex}
                      questionIndex={selectedQuestion?.questionIndex}
                      onUpdateQuestion={updateQuestion}
                      onUpdateSection={updateSection}
                      onDuplicate={() => {
                        if (!selectedQuestion) return
                        const { sectionIndex, questionIndex } = selectedQuestion
                        const section = form.sections[sectionIndex]
                        const newQuestions = [...section.questions]
                        newQuestions.splice(questionIndex + 1, 0, clone(section.questions[questionIndex]))
                        updateSection(sectionIndex, { questions: newQuestions })
                        setSelectedQuestion({ sectionIndex, questionIndex: questionIndex + 1 })
                      }}
                      onDelete={() => {
                        if (!selectedQuestion) return
                        const { sectionIndex, questionIndex } = selectedQuestion
                        const section = form.sections[sectionIndex]
                        const filtered = section.questions.filter((_, i) => i !== questionIndex)
                        updateSection(sectionIndex, { questions: filtered })
                        setSelectedQuestion(null)
                      }}
                      className="h-full"
                    />
                  </div>
                </div>
              )}

              {/* ─── STEP 5: Scoring & Rating ─── */}
              {activeStep === 4 && (
                <div className="grid gap-5 @xl:grid-cols-2">
                  <BuilderPanel title="Weighted Scoring" description="Section weights must total exactly 100%." icon={Star}>
                    <div className="space-y-3">
                      {form.sections.filter(section => !section.archived).map((section, index) => (
                        <div key={index} className="flex items-center gap-3 rounded-lg border border-border/60 bg-muted/15 p-3">
                          <span className="min-w-0 flex-1 truncate text-sm font-semibold text-foreground">{section.title || `Section ${index + 1}`}</span>
                          <Input
                            type="number" min="0" max="100"
                            value={section.weight}
                            onChange={(e) => updateSection(form.sections.indexOf(section), { weight: Number(e.target.value) })}
                            className="h-9 w-24 rounded-lg text-right font-bold"
                          />
                          <span className="text-sm text-muted-foreground">%</span>
                        </div>
                      ))}
                    </div>
                    <div className={cn('mt-4 flex items-center gap-2 rounded-lg border p-3 text-sm font-semibold', totalWeight === 100 ? 'border-emerald-500/30 bg-emerald-500/10 text-emerald-700 dark:text-emerald-300' : 'border-amber-500/30 bg-amber-500/10 text-amber-700 dark:text-amber-300')}>
                      {totalWeight === 100 ? <Check className="size-4" /> : <AlertTriangle className="size-4" />}
                      Current Total: {totalWeight}%. {totalWeight === 100 ? 'Ready to publish.' : 'Must equal exactly 100%.'}
                    </div>

                    {/* Comments Section */}
                    <div className="mt-6">
                      <h4 className="mb-3 text-sm font-bold text-foreground">Comment Fields</h4>
                      <div className="space-y-3">
                        {form.comments.map((comment, index) => (
                          <div key={index} className="flex items-center gap-2 rounded-lg border border-border/60 bg-muted/15 p-2">
                            <Input value={comment.title} onChange={(e) => patchList('comments', rows => rows.map((row, rowIndex) => rowIndex === index ? { ...row, title: e.target.value } : row))} className="h-9 flex-1 rounded-lg" />
                            <Checkbox checked={Boolean(comment.required)} onCheckedChange={(required) => patchList('comments', rows => rows.map((row, rowIndex) => rowIndex === index ? { ...row, required: Boolean(required) } : row))} />
                            <Button type="button" variant="ghost" size="icon-sm" className="text-destructive" onClick={() => patchList('comments', rows => rows.filter((_, rowIndex) => rowIndex !== index))}><Trash2 className="size-4" /></Button>
                          </div>
                        ))}
                        <Button type="button" variant="outline" className="h-9 w-full rounded-lg" onClick={() => patchList('comments', rows => [...rows, { title: 'New Comment Field', type: 'long_answer', required: false }])}>
                          <Plus className="size-4" />
                          Add Comment Field
                        </Button>
                      </div>
                    </div>
                  </BuilderPanel>

                  <BuilderPanel title="Performance Rating" description="Unlimited rating bands for final score interpretation." icon={Star}>
                    <div className="space-y-3">
                      {form.scoring_ranges.map((range, index) => (
                        <div key={index} className="grid gap-2 rounded-lg border border-border/60 bg-muted/15 p-3 @md:grid-cols-[1fr_6rem_6rem_5rem_auto]">
                          <Input value={range.label} onChange={(e) => patchList('scoring_ranges', rows => rows.map((row, rowIndex) => rowIndex === index ? { ...row, label: e.target.value } : row))} className="h-9 rounded-lg" placeholder="Label" />
                          <Input type="number" step="0.01" value={range.min} onChange={(e) => patchList('scoring_ranges', rows => rows.map((row, rowIndex) => rowIndex === index ? { ...row, min: Number(e.target.value) } : row))} className="h-9 rounded-lg" placeholder="Min" />
                          <Input type="number" step="0.01" value={range.max} onChange={(e) => patchList('scoring_ranges', rows => rows.map((row, rowIndex) => rowIndex === index ? { ...row, max: Number(e.target.value) } : row))} className="h-9 rounded-lg" placeholder="Max" />
                          <Input type="color" value={range.color || '#16a34a'} onChange={(e) => patchList('scoring_ranges', rows => rows.map((row, rowIndex) => rowIndex === index ? { ...row, color: e.target.value } : row))} className="h-9 rounded-lg p-1" />
                          <Button type="button" variant="ghost" size="icon-sm" className="text-destructive" onClick={() => patchList('scoring_ranges', rows => rows.filter((_, rowIndex) => rowIndex !== index))}><Trash2 className="size-4" /></Button>
                        </div>
                      ))}
                      <Button type="button" variant="outline" className="h-9 w-full rounded-lg" onClick={() => patchList('scoring_ranges', rows => [...rows, { label: 'New Rating', min: 0, max: 0, color: '#16a34a', icon: 'star' }])}>
                        <Plus className="size-4" />
                        Add Rating Band
                      </Button>
                    </div>

                    {/* Preview */}
                    <div className="mt-4 space-y-2">
                      <p className="text-xs font-bold uppercase tracking-wide text-muted-foreground">Preview</p>
                      <div className="flex flex-wrap gap-2">
                        {form.scoring_ranges.map((range, index) => (
                          <div key={index} className="flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-semibold" style={{ backgroundColor: range.color + '18', color: range.color, borderColor: range.color + '40', border: '1px solid' }}>
                            <span className="flex size-4 items-center justify-center rounded-full text-[9px] font-bold" style={{ backgroundColor: range.color, color: '#fff' }}>{range.icon === 'star' ? '★' : range.icon === 'check' ? '✓' : '—'}</span>
                            {range.label} ({range.min}–{range.max})
                          </div>
                        ))}
                      </div>
                    </div>
                  </BuilderPanel>
                </div>
              )}

              {/* ─── STEP 6: Review & Publish ─── */}
              {activeStep === 5 && (
                <div className="grid gap-5 @xl:grid-cols-[1fr_20rem]">
                  <div className="rounded-lg border border-border/70 bg-white p-8 text-slate-900 shadow-sm dark:bg-slate-950 dark:text-slate-100">
                    <div className="border-b border-slate-200 pb-5 text-center dark:border-slate-800">
                      <p className="text-xs font-bold uppercase tracking-[0.22em] text-slate-500">AMALGAMATED GROUP OF COMPANIES</p>
                      <h2 className="mt-2 text-2xl font-black tracking-tight">{form.title || 'Performance Evaluation Form'}</h2>
                      <p className="mt-1 text-sm text-slate-500">{form.evaluation_type}</p>
                    </div>
                    <div className="mt-6" dangerouslySetInnerHTML={{ __html: form.introduction }} />
                    <div className="mt-6">
                      <h3 className="text-sm font-bold uppercase tracking-wide">Employee Information</h3>
                      <div className="mt-3 grid gap-2 @md:grid-cols-2">
                        {form.employee_fields.filter(field => field.selected).map(field => (
                          <div key={field.key} className="rounded border border-slate-200 px-3 py-2 text-sm dark:border-slate-800">
                            <span className="font-semibold">{field.label}:</span> <span className="text-slate-500">________________</span>
                          </div>
                        ))}
                      </div>
                    </div>
                    <div className="mt-6">
                      <h3 className="text-sm font-bold uppercase tracking-wide">Rating Scale</h3>
                      <div className="mt-3 grid gap-2">
                        {form.rating_scale.map((rating, index) => (
                          <div key={index} className="flex items-center gap-3 rounded border border-slate-200 px-3 py-2 text-sm dark:border-slate-800">
                            <span className="flex size-7 items-center justify-center rounded text-white" style={{ backgroundColor: rating.color }}>{rating.value}</span>
                            <span className="font-semibold">{rating.label}</span>
                            <span className="text-slate-500">{rating.description}</span>
                          </div>
                        ))}
                      </div>
                    </div>
                    {activeSections.map((section, index) => (
                      <div key={index} className="mt-6">
                        <div className="flex items-center justify-between border-b border-slate-200 pb-2 dark:border-slate-800">
                          <h3 className="text-sm font-bold uppercase tracking-wide">Part {index + 1}: {section.title}</h3>
                          <span className="text-xs font-bold text-slate-500">{section.weight}%</span>
                        </div>
                        <p className="mt-2 text-sm text-slate-500">{section.description}</p>
                        <div className="mt-3 space-y-2">
                          {section.questions.map((question, questionIndex) => (
                            <div key={questionIndex} className="rounded border border-slate-200 px-3 py-3 text-sm dark:border-slate-800">
                              <p className="font-semibold">{questionIndex + 1}. {question.title || 'Untitled question'}</p>
                              <p className="mt-1 text-xs uppercase tracking-wide text-slate-500">{question.builder_type?.replace(/_/g, ' ') || question.type}</p>
                            </div>
                          ))}
                        </div>
                      </div>
                    ))}
                    <div className="mt-6">
                      <h3 className="text-sm font-bold uppercase tracking-wide">Comments</h3>
                      <div className="mt-3 space-y-2">
                        {form.comments.map((comment, index) => (
                          <div key={index} className="rounded border border-slate-200 px-3 py-3 text-sm dark:border-slate-800">
                            <p className="font-semibold">{comment.title}</p>
                            <div className="mt-2 h-12 rounded border border-dashed border-slate-300 dark:border-slate-700" />
                          </div>
                        ))}
                      </div>
                    </div>
                  </div>

                  <div className="space-y-4">
                    <BuilderPanel title="Publish Checklist" description="Final checks before saving." icon={CheckSquare}>
                      <div className="space-y-3 text-sm">
                        {[
                          [Boolean(form.title.trim()), 'Evaluation name is set'],
                          [Boolean(form.company_id), 'Applicable company is selected'],
                          [activeSections.length > 0, 'At least one active section exists'],
                          [totalQuestions > 0, 'Questions are configured'],
                          [totalWeight === 100, 'Scoring total equals 100%'],
                        ].map(([done, label]) => (
                          <div key={label} className="flex items-center gap-2">
                            <span className={cn('flex size-5 items-center justify-center rounded-full', done ? 'bg-emerald-500 text-white' : 'bg-amber-500/15 text-amber-600')}>
                              {done ? <Check className="size-3" /> : <AlertTriangle className="size-3" />}
                            </span>
                            <span className={done ? 'text-foreground' : 'text-muted-foreground'}>{label}</span>
                          </div>
                        ))}
                      </div>
                      <div className="mt-5 grid gap-2">
                        <Button type="button" variant="outline" className="h-10 rounded-lg justify-start">
                          <Download className="size-4" />
                          Export PDF Preview
                        </Button>
                        <Button type="button" variant="outline" className="h-10 rounded-lg justify-start" onClick={() => setShowVersionHistoryStep6(!showVersionHistoryStep6)}>
                          <History className="size-4" />
                          {showVersionHistoryStep6 ? 'Hide' : 'View'} Version History
                          {(form.versions || []).length > 0 && (
                            <Badge className="ml-1 rounded-full bg-brand/15 text-brand text-[9px] px-1.5 py-0 border-0">
                              {(form.versions || []).length}
                            </Badge>
                          )}
                        </Button>
                      </div>
                    </BuilderPanel>

                    {showVersionHistoryStep6 && (
                      <BuilderPanel title="Version History" description="Every save creates a new version." icon={History}>
                        <VersionHistory
                          versions={form.versions || []}
                          currentVersionId={form.versions.length > 0 ? Math.max(...form.versions.map(v => v.version || v.number || 0)) : 1}
                          onRestore={(version) => {
                            if (version.snapshot) {
                              onChange(prev => ({
                                ...(typeof prev === 'object' && prev !== null ? prev : {}),
                                title: version.snapshot.title || '',
                                description: version.snapshot.description || '',
                                category: version.snapshot.category || 'Performance Management',
                                evaluation_type: version.snapshot.evaluation_type || '360-Degree Feedback',
                                sections: version.snapshot.sections || [],
                                introduction: version.snapshot.introduction || '',
                                employee_fields: version.snapshot.employee_fields || [],
                                relationships: version.snapshot.relationships || [],
                                rating_scale: version.snapshot.rating_scale || [],
                                scoring_ranges: version.snapshot.scoring_ranges || [],
                                comments: version.snapshot.comments || [],
                                header_config: version.snapshot.header_config || null,
                              }))
                            }
                          }}
                          onDuplicate={(version) => {
                            if (version.snapshot) {
                              patch({
                                id: null,
                                title: `${version.snapshot.title || 'Untitled'} (v${version.version || version.number}) Copy`,
                                status: 'Draft',
                                sections: version.snapshot.sections,
                              })
                            }
                          }}
                          onDelete={(version) => {
                            const filtered = (form.versions || []).filter(v => (v.version || v.number) !== (version.version || version.number))
                            patch({ versions: filtered })
                          }}
                          onCreateVersion={() => {
                            const newVersion = createVersionSnapshot(form, form.versions || [])
                            patch({ versions: [...(form.versions || []), newVersion] })
                          }}
                        />
                      </BuilderPanel>
                    )}
                  </div>
                </div>
              )}
            </div>
          </div>

          <div className="shrink-0 border-t border-border/70 bg-card/95 px-4 py-3 backdrop-blur @md:px-8">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <Button type="button" variant="outline" className="h-10 rounded-lg" onClick={onCancel}>Cancel</Button>
              <div className="flex flex-wrap items-center gap-2">
                <Button type="button" variant="secondary" className="h-10 rounded-lg" onClick={onSave} disabled={saving}>
                  {saving ? <Loader2 className="size-4 animate-spin" /> : <Save className="size-4" />}
                  Save Draft
                </Button>
                <Button type="button" variant="outline" className="h-10 rounded-lg" onClick={() => goToStep(Math.max(0, activeStep - 1))} disabled={activeStep === 0}>
                  <ArrowLeft className="size-4" />
                  Back
                </Button>
                {activeStep < steps.length - 1 ? (
                  <Button type="button" className="h-10 rounded-lg bg-brand text-brand-foreground hover:bg-brand-strong" onClick={() => goToStep(Math.min(steps.length - 1, activeStep + 1))}>
                    Next
                    <ArrowRight className="size-4" />
                  </Button>
                ) : (
                  <Button type="button" className="h-10 rounded-lg bg-brand text-brand-foreground hover:bg-brand-strong" onClick={onSave} disabled={saving}>
                    {saving ? <Loader2 className="size-4 animate-spin" /> : <ClipboardCheck className="size-4" />}
                    Publish
                  </Button>
                )}
              </div>
            </div>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  )
}
