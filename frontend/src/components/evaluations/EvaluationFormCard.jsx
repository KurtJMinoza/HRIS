import { FileSpreadsheet, Layers, HelpCircle, ClipboardList, Pencil, Trash2, User } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Avatar, AvatarFallback } from '@/components/ui/avatar'
import { cn } from '@/lib/utils'
import { countSurveyQuestions, surveyToSections } from '@/lib/surveyConfig'

const ACCENTS = [
  { band: 'from-amber-500/20 via-amber-500/8 to-orange-500/12', icon: 'bg-amber-500/15 text-amber-700 dark:text-amber-300', ring: 'ring-amber-500/25' },
  { band: 'from-emerald-500/20 via-emerald-500/8 to-teal-500/12', icon: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-300', ring: 'ring-emerald-500/25' },
  { band: 'from-sky-500/20 via-sky-500/8 to-blue-500/12', icon: 'bg-sky-500/15 text-sky-700 dark:text-sky-300', ring: 'ring-sky-500/25' },
  { band: 'from-violet-500/20 via-violet-500/8 to-purple-500/12', icon: 'bg-violet-500/15 text-violet-700 dark:text-violet-300', ring: 'ring-violet-500/25' },
  { band: 'from-rose-500/20 via-rose-500/8 to-pink-500/12', icon: 'bg-rose-500/15 text-rose-700 dark:text-rose-300', ring: 'ring-rose-500/25' },
  { band: 'from-brand/25 via-brand/10 to-orange-500/12', icon: 'bg-brand/15 text-brand', ring: 'ring-brand/25' },
]

function accentForTitle(title = '') {
  let hash = 0
  for (let i = 0; i < title.length; i++) hash = (hash + title.charCodeAt(i) * (i + 1)) % ACCENTS.length
  return ACCENTS[hash]
}

function creatorInitials(name = '') {
  return name.split(/[\s,]+/).filter(Boolean).map(w => w[0]).join('').toUpperCase().slice(0, 2) || '?'
}

function formatShortDate(dateStr) {
  if (!dateStr) return null
  try {
    return new Date(dateStr).toLocaleDateString('en-PH', { month: 'short', day: 'numeric', year: 'numeric' })
  } catch {
    return null
  }
}

export function getFormStats(form) {
  const survey = form?.survey_json
  if (survey && typeof survey === 'object' && Object.keys(survey).length > 0) {
    const sections = surveyToSections(survey)
    return {
      sectionCount: sections.length || survey.pages?.length || 0,
      questionCount: countSurveyQuestions(survey),
      isSurvey: true,
    }
  }
  const sections = Array.isArray(form?.sections) ? form.sections : []
  return {
    sectionCount: sections.length,
    questionCount: sections.reduce((sum, s) => sum + (s.questions?.length || 0), 0),
    isSurvey: false,
  }
}

export default function EvaluationFormCard({ form, onEdit, onDelete }) {
  const accent = accentForTitle(form.title)
  const { sectionCount, questionCount, isSurvey } = getFormStats(form)
  const evalCount = form.evaluations_count || 0
  const updatedLabel = formatShortDate(form.updated_at || form.created_at)

  return (
    <div className="group flex flex-col overflow-hidden rounded-2xl border border-border/80 bg-card shadow-[0_1px_3px_rgba(15,23,42,0.06),0_1px_2px_-1px_rgba(15,23,42,0.04)] transition-all duration-200 hover:-translate-y-0.5 hover:border-border hover:shadow-[0_16px_36px_-18px_rgba(15,23,42,0.32)] dark:border-white/10 dark:shadow-none dark:hover:border-white/20">
      {/* Cover band */}
      <div className={cn('relative h-[58px] bg-linear-to-br', accent.band)}>
        <div className="absolute inset-0 opacity-40 [background-image:radial-gradient(circle_at_1px_1px,rgba(100,116,139,0.28)_1px,transparent_0)] [background-size:13px_13px]" />
        <div className="absolute inset-x-3 top-2.5 flex items-center justify-between gap-2">
          <Badge
            variant="outline"
            className="rounded-full border-0 bg-card/80 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-muted-foreground ring-1 ring-inset ring-border/60 backdrop-blur-sm"
          >
            {isSurvey ? 'Survey Form' : 'Legacy Form'}
          </Badge>
          <Badge
            className={cn(
              'rounded-full border-0 px-2 py-0.5 text-[10px] font-semibold ring-1 ring-inset backdrop-blur-sm',
              form.is_active
                ? 'bg-emerald-50/90 text-emerald-700 ring-emerald-600/25 dark:bg-emerald-500/15 dark:text-emerald-300'
                : 'bg-muted/80 text-muted-foreground ring-border/60',
            )}
          >
            <span className={cn('mr-1 inline-block size-1.5 rounded-full', form.is_active ? 'bg-emerald-500' : 'bg-slate-400')} />
            {form.is_active ? 'Active' : 'Inactive'}
          </Badge>
        </div>
      </div>

      {/* Body */}
      <div className="flex flex-1 flex-col px-4 pb-4">
        <div className="-mt-7 mb-3 flex items-end justify-between gap-2">
          <span className={cn('flex size-14 items-center justify-center rounded-2xl ring-4 ring-card shadow-md', accent.icon, accent.ring)}>
            <FileSpreadsheet className="size-6" strokeWidth={1.75} />
          </span>
          <div className="flex shrink-0 gap-0.5 opacity-0 transition-opacity group-hover:opacity-100">
            <Button variant="ghost" size="icon" className="size-8 rounded-lg" onClick={() => onEdit?.(form)} title="Edit form">
              <Pencil className="size-3.5" />
            </Button>
            <Button variant="ghost" size="icon" className="size-8 rounded-lg text-destructive hover:text-destructive" onClick={() => onDelete?.(form.id)} title="Delete form">
              <Trash2 className="size-3.5" />
            </Button>
          </div>
        </div>

        <h3 className="line-clamp-2 text-base font-bold leading-snug text-foreground" title={form.title}>
          {form.title}
        </h3>
        <p className="mt-1.5 line-clamp-2 min-h-[2.5rem] text-[13px] leading-relaxed text-muted-foreground">
          {form.description || 'No description provided for this evaluation template.'}
        </p>

        {/* Stats */}
        <div className="mt-4 grid grid-cols-3 gap-2">
          <div className="rounded-xl border border-border/60 bg-muted/25 px-2 py-2 text-center dark:border-white/10 dark:bg-white/[0.03]">
            <Layers className="mx-auto mb-1 size-3.5 text-muted-foreground/70" />
            <p className="text-sm font-bold tabular-nums text-foreground">{sectionCount}</p>
            <p className="text-[10px] font-medium text-muted-foreground">{sectionCount === 1 ? 'Section' : 'Sections'}</p>
          </div>
          <div className="rounded-xl border border-border/60 bg-muted/25 px-2 py-2 text-center dark:border-white/10 dark:bg-white/[0.03]">
            <HelpCircle className="mx-auto mb-1 size-3.5 text-muted-foreground/70" />
            <p className="text-sm font-bold tabular-nums text-foreground">{questionCount}</p>
            <p className="text-[10px] font-medium text-muted-foreground">{questionCount === 1 ? 'Question' : 'Questions'}</p>
          </div>
          <div className="rounded-xl border border-border/60 bg-muted/25 px-2 py-2 text-center dark:border-white/10 dark:bg-white/[0.03]">
            <ClipboardList className="mx-auto mb-1 size-3.5 text-muted-foreground/70" />
            <p className="text-sm font-bold tabular-nums text-foreground">{evalCount}</p>
            <p className="text-[10px] font-medium text-muted-foreground">{evalCount === 1 ? 'Evaluation' : 'Evaluations'}</p>
          </div>
        </div>

        {/* Footer */}
        <div className="mt-4 flex items-center gap-2 border-t border-border/50 pt-3 dark:border-white/10">
          {form.created_by ? (
            <>
              <Avatar className="size-7 rounded-lg">
                <AvatarFallback className="rounded-lg bg-muted text-[10px] font-bold text-muted-foreground">
                  {creatorInitials(form.created_by)}
                </AvatarFallback>
              </Avatar>
              <div className="min-w-0 flex-1">
                <p className="truncate text-xs font-medium text-foreground">{form.created_by}</p>
                <p className="text-[10px] text-muted-foreground">
                  {updatedLabel ? `Updated ${updatedLabel}` : 'Creator'}
                </p>
              </div>
            </>
          ) : (
            <p className="flex items-center gap-1.5 text-xs text-muted-foreground">
              <User className="size-3.5" />
              {updatedLabel ? `Updated ${updatedLabel}` : 'System template'}
            </p>
          )}
        </div>

        <Button
          type="button"
          variant="outline"
          className="mt-3 h-9 w-full rounded-lg border-border/80 text-sm font-semibold hover:border-brand/40 hover:bg-brand/5 hover:text-brand"
          onClick={() => onEdit?.(form)}
        >
          Open Template
        </Button>
      </div>
    </div>
  )
}
