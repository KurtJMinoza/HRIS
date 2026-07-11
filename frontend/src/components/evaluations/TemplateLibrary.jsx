/* eslint-disable react-refresh/only-export-components */
import { useState, useMemo } from 'react'
import { cn } from '@/lib/utils'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Input } from '@/components/ui/input'
import {
  Copy, Plus, Search, FileText, Star, Users,
  TrendingUp, Target, BookOpen, Briefcase, BarChart3,
  Settings2, CheckCircle2, Zap, Lightbulb, Layers,
  LayoutDashboard, LineChart,
} from 'lucide-react'
import { formatDistanceToNow } from 'date-fns'

// ─── Template Presets ──────────────────────────────────────────────

const PRESET_TEMPLATES = [
  {
    id: 'template-360',
    name: '360° Performance Feedback',
    description: 'Multi-rater feedback from supervisors, peers, and subordinates — the classic 360-degree assessment.',
    category: 'Performance Management',
    type: '360-Degree Feedback',
    icon: Star,
    color: 'text-amber-600',
    bg: 'bg-amber-50 dark:bg-amber-500/10',
    sections: [
      { title: 'Job Performance', weight: 70, questions: [
        { title: 'Produces accurate work.', builder_type: 'rating', max: 5, required: true, weight: 15 },
        { title: 'Completes assignments within agreed timelines.', builder_type: 'rating', max: 5, required: true, weight: 15 },
        { title: 'Demonstrates effective problem-solving skills.', builder_type: 'rating', max: 5, required: true, weight: 15 },
        { title: 'Communicates clearly and professionally.', builder_type: 'rating', max: 5, required: true, weight: 15 },
        { title: 'Collaborates effectively with team members.', builder_type: 'rating', max: 5, required: true, weight: 10 },
      ]},
      { title: 'Core Values', weight: 30, questions: [
        { title: 'Demonstrates integrity and ethical conduct.', builder_type: 'rating', max: 5, required: true, weight: 10 },
        { title: 'Shows commitment to organizational goals.', builder_type: 'rating', max: 5, required: true, weight: 10 },
        { title: 'Embraces diversity and inclusion.', builder_type: 'rating', max: 5, required: true, weight: 10 },
      ]},
    ],
  },
  {
    id: 'template-annual',
    name: 'Annual Performance Appraisal',
    description: 'Year-end evaluation covering goals, achievements, competencies, and development planning.',
    category: 'Performance Management',
    type: 'Performance Appraisal',
    icon: TrendingUp,
    color: 'text-emerald-600',
    bg: 'bg-emerald-50 dark:bg-emerald-500/10',
    sections: [
      { title: 'Goals & Objectives', weight: 40, questions: [
        { title: 'Achieved annual goals set at the beginning of the period.', builder_type: 'rating', max: 5, required: true, weight: 20 },
        { title: 'Demonstrated measurable impact on team/department results.', builder_type: 'rating', max: 5, required: true, weight: 10 },
        { title: 'Completed key projects within scope and timeline.', builder_type: 'rating', max: 5, required: true, weight: 10 },
      ]},
      { title: 'Competencies', weight: 35, questions: [
        { title: 'Technical knowledge and skill application.', builder_type: 'rating', max: 5, required: true, weight: 10 },
        { title: 'Leadership and initiative.', builder_type: 'rating', max: 5, required: true, weight: 10 },
        { title: 'Adaptability and continuous learning.', builder_type: 'rating', max: 5, required: true, weight: 8 },
        { title: 'Quality of work and attention to detail.', builder_type: 'rating', max: 5, required: true, weight: 7 },
      ]},
      { title: 'Development Plan', weight: 25, questions: [
        { title: 'Identified areas for professional growth.', builder_type: 'long_answer', required: false, weight: 0 },
        { title: 'Training and development recommendations.', builder_type: 'long_answer', required: false, weight: 0 },
      ]},
    ],
  },
  {
    id: 'template-probationary',
    name: 'Probationary Evaluation',
    description: 'Regularization assessment for probationary employees — competencies, attendance, and cultural fit.',
    category: 'Regularization',
    type: 'Probationary Evaluation',
    icon: BookOpen,
    color: 'text-blue-600',
    bg: 'bg-blue-50 dark:bg-blue-500/10',
    sections: [
      { title: 'Job Performance', weight: 60, questions: [
        { title: 'Ability to perform assigned duties.', builder_type: 'rating', max: 5, required: true, weight: 15 },
        { title: 'Quality and accuracy of work output.', builder_type: 'rating', max: 5, required: true, weight: 15 },
        { title: 'Efficiency and time management.', builder_type: 'rating', max: 5, required: true, weight: 10 },
        { title: 'Willingness to learn and improve.', builder_type: 'rating', max: 5, required: true, weight: 10 },
        { title: 'Attendance and punctuality.', builder_type: 'rating', max: 5, required: true, weight: 10 },
      ]},
      { title: 'Cultural Fit', weight: 20, questions: [
        { title: 'Aligns with company values and culture.', builder_type: 'rating', max: 5, required: true, weight: 10 },
        { title: 'Works well with team members.', builder_type: 'rating', max: 5, required: true, weight: 10 },
      ]},
      { title: 'Overall Assessment', weight: 20, questions: [
        { title: 'Recommendation for regularization.', builder_type: 'yes_no', required: true, weight: 0 },
        { title: 'Overall comments and feedback.', builder_type: 'long_answer', required: true, weight: 0 },
      ]},
    ],
  },
  {
    id: 'template-leadership',
    name: 'Leadership Assessment',
    description: 'Evaluate management and leadership competencies — strategic thinking, team development, and decision-making.',
    category: 'Performance Management',
    type: 'Leadership Assessment',
    icon: Target,
    color: 'text-violet-600',
    bg: 'bg-violet-50 dark:bg-violet-500/10',
    sections: [
      { title: 'Strategic Leadership', weight: 35, questions: [
        { title: 'Strategic thinking and vision.', builder_type: 'rating', max: 5, required: true, weight: 10 },
        { title: 'Decision-making and problem-solving.', builder_type: 'rating', max: 5, required: true, weight: 10 },
        { title: 'Change management and innovation.', builder_type: 'rating', max: 5, required: true, weight: 8 },
        { title: 'Resource management and optimization.', builder_type: 'rating', max: 5, required: true, weight: 7 },
      ]},
      { title: 'Team Development', weight: 35, questions: [
        { title: 'Team building and talent development.', builder_type: 'rating', max: 5, required: true, weight: 10 },
        { title: 'Coaching and mentoring.', builder_type: 'rating', max: 5, required: true, weight: 10 },
        { title: 'Delegation and empowerment.', builder_type: 'rating', max: 5, required: true, weight: 8 },
        { title: 'Conflict resolution.', builder_type: 'rating', max: 5, required: true, weight: 7 },
      ]},
      { title: 'Results', weight: 30, questions: [
        { title: 'Achievement of organizational goals.', builder_type: 'rating', max: 5, required: true, weight: 10 },
        { title: 'Team performance and engagement.', builder_type: 'rating', max: 5, required: true, weight: 10 },
        { title: 'Stakeholder satisfaction.', builder_type: 'rating', max: 5, required: true, weight: 10 },
      ]},
    ],
  },
  {
    id: 'template-sales',
    name: 'Sales KPI Evaluation',
    description: 'Sales performance evaluation with quantitative KPIs, revenue targets, and client satisfaction metrics.',
    category: 'Performance Management',
    type: 'KPI Review',
    icon: Briefcase,
    color: 'text-orange-600',
    bg: 'bg-orange-50 dark:bg-orange-500/10',
    sections: [
      { title: 'Revenue Targets', weight: 50, questions: [
        { title: 'Achieved monthly/quarterly sales targets.', builder_type: 'rating', max: 10, required: true, weight: 20 },
        { title: 'Revenue growth vs. previous period.', builder_type: 'rating', max: 10, required: true, weight: 15 },
        { title: 'New client acquisition.', builder_type: 'rating', max: 10, required: true, weight: 15 },
      ]},
      { title: 'Performance Metrics', weight: 30, questions: [
        { title: 'Conversion rate.', builder_type: 'rating', max: 10, required: true, weight: 10 },
        { title: 'Average deal size.', builder_type: 'rating', max: 10, required: true, weight: 10 },
        { title: 'Client retention rate.', builder_type: 'rating', max: 10, required: true, weight: 10 },
      ]},
      { title: 'Customer Feedback', weight: 20, questions: [
        { title: 'Client satisfaction score.', builder_type: 'rating', max: 10, required: true, weight: 10 },
        { title: 'Quality of client relationships.', builder_type: 'rating', max: 10, required: true, weight: 10 },
      ]},
    ],
  },
  {
    id: 'template-competency',
    name: 'Technical Competency Evaluation',
    description: 'Assess technical skills, certifications, project delivery, and domain expertise.',
    category: 'Performance Management',
    type: 'Competency Evaluation',
    icon: Settings2,
    color: 'text-cyan-600',
    bg: 'bg-cyan-50 dark:bg-cyan-500/10',
    sections: [
      { title: 'Technical Skills', weight: 50, questions: [
        { title: 'Depth of technical knowledge.', builder_type: 'rating', max: 5, required: true, weight: 15 },
        { title: 'Quality of technical deliverables.', builder_type: 'rating', max: 5, required: true, weight: 15 },
        { title: 'Problem-solving and troubleshooting.', builder_type: 'rating', max: 5, required: true, weight: 10 },
        { title: 'Adherence to best practices and standards.', builder_type: 'rating', max: 5, required: true, weight: 10 },
      ]},
      { title: 'Professional Development', weight: 25, questions: [
        { title: 'Certifications and training completed.', builder_type: 'rating', max: 5, required: true, weight: 10 },
        { title: 'Staying current with industry trends.', builder_type: 'rating', max: 5, required: true, weight: 8 },
        { title: 'Knowledge sharing and mentorship.', builder_type: 'rating', max: 5, required: true, weight: 7 },
      ]},
      { title: 'Project Delivery', weight: 25, questions: [
        { title: 'Project completion rate.', builder_type: 'rating', max: 5, required: true, weight: 10 },
        { title: 'Meeting deadlines and milestones.', builder_type: 'rating', max: 5, required: true, weight: 8 },
        { title: 'Code/Work quality review scores.', builder_type: 'rating', max: 5, required: true, weight: 7 },
      ]},
    ],
  },
  {
    id: 'template-behavioral',
    name: 'Behavioral Assessment',
    description: 'Evaluate workplace behaviors, communication, teamwork, and professional conduct.',
    category: 'Performance Management',
    type: 'Behavioral Assessment',
    icon: Users,
    color: 'text-pink-600',
    bg: 'bg-pink-50 dark:bg-pink-500/10',
    sections: [
      { title: 'Communication', weight: 30, questions: [
        { title: 'Clarity and effectiveness of communication.', builder_type: 'rating', max: 5, required: true, weight: 10 },
        { title: 'Active listening and responsiveness.', builder_type: 'rating', max: 5, required: true, weight: 10 },
        { title: 'Written communication skills.', builder_type: 'rating', max: 5, required: true, weight: 10 },
      ]},
      { title: 'Teamwork', weight: 30, questions: [
        { title: 'Collaboration and team contribution.', builder_type: 'rating', max: 5, required: true, weight: 10 },
        { title: 'Respect for diverse perspectives.', builder_type: 'rating', max: 5, required: true, weight: 10 },
        { title: 'Reliability and accountability.', builder_type: 'rating', max: 5, required: true, weight: 10 },
      ]},
      { title: 'Professional Conduct', weight: 40, questions: [
        { title: 'Attendance and punctuality.', builder_type: 'rating', max: 5, required: true, weight: 10 },
        { title: 'Adherence to company policies.', builder_type: 'rating', max: 5, required: true, weight: 10 },
        { title: 'Professional demeanor and attitude.', builder_type: 'rating', max: 5, required: true, weight: 10 },
        { title: 'Conflict management.', builder_type: 'rating', max: 5, required: true, weight: 10 },
      ]},
    ],
  },
  {
    id: 'template-customer-service',
    name: 'Customer Service Evaluation',
    description: 'Assess customer-facing skills: service quality, problem resolution, and client satisfaction.',
    category: 'Performance Management',
    type: 'Performance Appraisal',
    icon: Star,
    color: 'text-green-600',
    bg: 'bg-green-50 dark:bg-green-500/10',
    sections: [
      { title: 'Service Quality', weight: 50, questions: [
        { title: 'Quality of customer interactions.', builder_type: 'rating', max: 5, required: true, weight: 15 },
        { title: 'First-contact resolution rate.', builder_type: 'rating', max: 5, required: true, weight: 15 },
        { title: 'Product/service knowledge.', builder_type: 'rating', max: 5, required: true, weight: 10 },
        { title: 'Response time adherence.', builder_type: 'rating', max: 5, required: true, weight: 10 },
      ]},
      { title: 'Customer Satisfaction', weight: 30, questions: [
        { title: 'Customer satisfaction survey scores.', builder_type: 'rating', max: 5, required: true, weight: 15 },
        { title: 'Handling of difficult situations.', builder_type: 'rating', max: 5, required: true, weight: 15 },
      ]},
      { title: 'Process Adherence', weight: 20, questions: [
        { title: 'Follows standard operating procedures.', builder_type: 'rating', max: 5, required: true, weight: 10 },
        { title: 'Documentation and record-keeping.', builder_type: 'rating', max: 5, required: true, weight: 10 },
      ]},
    ],
  },
]

// ─── Template Card ─────────────────────────────────────────────────

function plural(count, singular, pluralForm) {
  return `${count} ${count === 1 ? singular : (pluralForm ?? `${singular}s`)}`
}

function TemplateCard({ template, onUse }) {
  const Icon = template.icon
  const questionCount = template.sections.reduce((sum, s) => sum + s.questions.length, 0)

  return (
    <div className="group flex flex-col overflow-hidden rounded-2xl border border-border/80 bg-card shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-brand/30 hover:shadow-lg dark:border-white/10">
      <div className={cn('relative h-12 bg-linear-to-br', template.band || 'from-muted/40 to-muted/10')}>
        <div className="absolute inset-0 opacity-30 [background-image:radial-gradient(circle_at_1px_1px,rgba(100,116,139,0.25)_1px,transparent_0)] [background-size:12px_12px]" />
        <div className="absolute inset-x-3 top-2.5">
          <Badge variant="outline" className="rounded-full border-0 bg-card/80 px-2 py-0 text-[10px] font-semibold text-muted-foreground ring-1 ring-inset ring-border/60 backdrop-blur-sm">
            {template.type}
          </Badge>
        </div>
      </div>

      <div className="flex flex-1 flex-col p-4 pt-3">
        <div className="-mt-8 mb-3 flex items-end gap-3">
          <span className={cn('flex size-11 shrink-0 items-center justify-center rounded-xl ring-4 ring-card shadow-sm', template.bg, template.color)}>
            <Icon className="size-5" />
          </span>
          <div className="min-w-0 flex-1 pb-0.5">
            <h4 className="line-clamp-2 text-sm font-bold leading-snug text-foreground">{template.name}</h4>
            <p className="mt-0.5 text-[11px] font-medium text-muted-foreground">{template.category}</p>
          </div>
        </div>

        <p className="line-clamp-2 min-h-[2.25rem] text-xs leading-relaxed text-muted-foreground">
          {template.description}
        </p>

        <div className="mt-3 grid grid-cols-2 gap-2">
          <div className="rounded-lg bg-muted/30 px-2.5 py-1.5 text-center dark:bg-white/[0.04]">
            <p className="text-sm font-bold tabular-nums text-foreground">{template.sections.length}</p>
            <p className="text-[10px] text-muted-foreground">{plural(template.sections.length, 'Section')}</p>
          </div>
          <div className="rounded-lg bg-muted/30 px-2.5 py-1.5 text-center dark:bg-white/[0.04]">
            <p className="text-sm font-bold tabular-nums text-foreground">{questionCount}</p>
            <p className="text-[10px] text-muted-foreground">{plural(questionCount, 'Question')}</p>
          </div>
        </div>

        <Button
          type="button"
          variant="outline"
          size="sm"
          className="mt-4 h-9 w-full gap-1.5 rounded-lg border-brand/35 text-xs font-semibold text-brand hover:bg-brand/10"
          onClick={() => onUse?.(template)}
        >
          <Copy className="size-3.5" />
          Use This Template
        </Button>
      </div>
    </div>
  )
}

// ─── Saved Template Component ──────────────────────────────────────

function SavedTemplateCard({ form, onDuplicate }) {
  const sections = Array.isArray(form.sections) ? form.sections : []
  const totalQuestions = sections.reduce((sum, s) => sum + (s.questions?.length || 0), 0)

  return (
    <div className="group flex items-center gap-3 rounded-xl border border-border/70 bg-card p-3.5 transition-all hover:border-brand/30 hover:shadow-sm dark:border-white/10">
      <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand/10 text-brand ring-1 ring-inset ring-brand/20">
        <FileText className="size-4" />
      </span>
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-semibold text-foreground">{form.title}</p>
        <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-muted-foreground">
          <span>{plural(sections.length, 'section')}</span>
          <span className="text-border">·</span>
          <span>{plural(totalQuestions, 'question')}</span>
          {form.updated_at && (
            <>
              <span className="text-border">·</span>
              <span>{
                (() => {
                  try { return formatDistanceToNow(new Date(form.updated_at), { addSuffix: true }) }
                  catch { return '' }
                })()
              }</span>
            </>
          )}
        </div>
      </div>
      <Button type="button" variant="outline" size="sm" className="h-8 shrink-0 gap-1.5 rounded-lg text-xs opacity-0 transition-opacity group-hover:opacity-100" onClick={() => onDuplicate?.(form)} title="Duplicate this template">
        <Copy className="size-3.5" />
        Duplicate
      </Button>
    </div>
  )
}

// ─── Main Template Library ─────────────────────────────────────────

export default function TemplateLibrary({
  savedForms = [],
  onUseTemplate,
  onDuplicateForm,
  className,
}) {
  const [search, setSearch] = useState('')
  const [activeTab, setActiveTab] = useState('presets')

  const filteredPresets = useMemo(() => {
    if (!search.trim()) return PRESET_TEMPLATES
    const q = search.toLowerCase()
    return PRESET_TEMPLATES.filter(t =>
      t.name.toLowerCase().includes(q) ||
      t.description.toLowerCase().includes(q) ||
      t.category.toLowerCase().includes(q) ||
      t.type.toLowerCase().includes(q)
    )
  }, [search])

  const filteredSaved = useMemo(() => {
    if (!search.trim()) return savedForms
    const q = search.toLowerCase()
    return (savedForms || []).filter(f =>
      (f.title || '').toLowerCase().includes(q)
    )
  }, [search, savedForms])

  return (
    <div className={cn('space-y-4', className)}>
      <div className="flex items-center gap-3">
        <span className="flex size-9 items-center justify-center rounded-lg bg-brand/10 text-brand">
          <Layers className="size-4" />
        </span>
        <div>
          <h3 className="text-sm font-bold text-foreground">Template Library</h3>
          <p className="text-xs text-muted-foreground">Start from a professionally designed template or duplicate an existing form.</p>
        </div>
      </div>

      {/* Search */}
      <div className="relative">
        <Search className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground" />
        <Input
          type="search"
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder="Search templates..."
          className="h-10 rounded-lg pl-9"
        />
      </div>

      {/* Tabs */}
      <div className="flex gap-2">
        <button
          type="button"
          onClick={() => setActiveTab('presets')}
          className={cn(
            'rounded-lg px-4 py-2 text-xs font-semibold transition',
            activeTab === 'presets'
              ? 'bg-brand text-brand-foreground'
              : 'bg-muted text-muted-foreground hover:bg-muted/70 hover:text-foreground',
          )}
        >
          <Copy className="mr-1.5 inline size-3.5" />
          Template Presets ({filteredPresets.length})
        </button>
        <button
          type="button"
          onClick={() => setActiveTab('saved')}
          className={cn(
            'rounded-lg px-4 py-2 text-xs font-semibold transition',
            activeTab === 'saved'
              ? 'bg-brand text-brand-foreground'
              : 'bg-muted text-muted-foreground hover:bg-muted/70 hover:text-foreground',
          )}
        >
          <FileText className="mr-1.5 inline size-3.5" />
          Saved Forms ({(savedForms || []).length})
        </button>
      </div>

      {/* Content */}
      {activeTab === 'presets' ? (
        filteredPresets.length === 0 ? (
          <div className="flex flex-col items-center justify-center rounded-lg border border-dashed border-border/60 bg-muted/10 px-4 py-10 text-center">
            <Search className="mb-2 size-8 text-muted-foreground/40" strokeWidth={1.5} />
            <p className="text-sm font-semibold text-muted-foreground">No templates match your search</p>
            <p className="mt-1 text-xs text-muted-foreground/60">Try a different search term.</p>
          </div>
        ) : (
          <div className="grid gap-3 @sm:grid-cols-2 @xl:grid-cols-3 @3xl:grid-cols-4">
            {filteredPresets.map(template => (
              <TemplateCard
                key={template.id}
                template={template}
                onUse={onUseTemplate}
              />
            ))}
          </div>
        )
      ) : (
        (savedForms || []).length === 0 ? (
          <div className="flex flex-col items-center justify-center rounded-lg border border-dashed border-border/60 bg-muted/10 px-4 py-10 text-center">
            <FileText className="mb-2 size-8 text-muted-foreground/40" strokeWidth={1.5} />
            <p className="text-sm font-semibold text-muted-foreground">No saved forms yet</p>
            <p className="mt-1 text-xs text-muted-foreground/60">Create an evaluation form first, then you can duplicate it here.</p>
          </div>
        ) : (
          <div className="space-y-2">
            {filteredSaved.length === 0 ? (
              <p className="py-4 text-center text-xs text-muted-foreground">No saved forms match your search.</p>
            ) : (
              filteredSaved.map((form, idx) => (
                <SavedTemplateCard key={form.id || idx} form={form} onDuplicate={onDuplicateForm} />
              ))
            )}
          </div>
        )
      )}
    </div>
  )
}

// ─── Convert template data to form builder state ───────────────────

export function convertTemplateToBuilderState(template, companies = []) {
  return {
    id: null,
    company_id: companies.length > 0 ? String(companies[0].id) : '',
    title: template.name,
    description: template.description,
    category: template.category,
    evaluation_type: template.type,
    status: 'Draft',
    sections: template.sections.map(section => ({
      ...section,
      archived: false,
      description: '',
      questions: section.questions.map(q => ({
        title: q.title,
        type: 'rating',
        builder_type: q.builder_type || 'rating',
        max: q.max || 5,
        required: q.required ?? true,
        weight: q.weight || 0,
        description: '',
        options: [],
        rows: [],
        columns: [],
        placeholder: '',
        tooltip: '',
      })),
    })),
    // Default values for other fields
    applicable_department: 'All Departments',
    applicable_employment_types: 'Regular, Probationary',
    effective_date: '',
    expiration_date: '',
    instructions: '',
    introduction: [
      'AMALGAMATED GROUP OF COMPANIES',
      '',
      `<h1 style="text-align: center;">${template.name}</h1>`,
      '',
      '<p style="text-align: center; font-weight: bold; color: #dc2626;">STRICTLY CONFIDENTIAL</p>',
    ].join('\n'),
    employee_fields: [],
    relationships: [],
    rating_scale: [],
    scoring_ranges: [],
    comments: [],
    is_active: true,
  }
}
