import { useCallback, useEffect, useMemo, useState } from 'react'
import {
  Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Textarea } from '@/components/ui/textarea'
import { Switch } from '@/components/ui/switch'
import { Badge } from '@/components/ui/badge'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import {
  Eye, Layers, Loader2, Plus, Save, Trash2, ClipboardCheck, FileSpreadsheet, Sparkles, GripVertical,
} from 'lucide-react'
import { cn } from '@/lib/utils'
import { AgcBrandLogo } from '@/components/AgcBrandLogo'
import EvaluationNativeForm from '@/components/EvaluationNativeForm'
import {
  APP_MODAL_FOOTER,
  APP_MODAL_FOOTER_ACTIONS,
  APP_MODAL_INNER_FLUSH,
  APP_MODAL_OUTLINE_BUTTON_CLASS,
  APP_MODAL_PRIMARY_BUTTON_CLASS,
} from '@/lib/appModalStyles'
import {
  PRESET_360,
  createEmptyDefinition,
  definitionForApiSave,
  nextQuestionId,
  resolveFormDefinition,
  sectionsWeightTotal,
  validateDefinitionWeights,
} from '@/lib/evaluationFormDefinition'

const BUILDER_SHELL =
  'flex h-[min(94vh,920px)] max-h-[min(94vh,920px)] w-[min(98vw,100rem)] min-w-[min(98vw,100rem)] max-w-none flex-col gap-0 overflow-hidden rounded-[18px] border-border/80 bg-card p-0 shadow-[0_24px_80px_-24px_rgba(0,0,0,0.5)] dark:border-white/10 dark:bg-card sm:max-w-none lg:max-w-none xl:max-w-none'

const fieldClass =
  'h-11 rounded-xl border-border/70 bg-background px-4 text-sm shadow-sm transition-colors focus-visible:ring-[3px] focus-visible:ring-brand/25 dark:border-border/60 dark:bg-input/25'

const labelClass = 'text-sm font-semibold tracking-tight text-foreground'

function WeightMeter({ total, ok }) {
  const pct = Math.min(100, Math.max(0, total))
  return (
    <div className="flex min-w-[200px] flex-1 flex-col gap-1.5 @md:max-w-xs">
      <div className="flex items-center justify-between gap-2 text-xs">
        <span className="font-semibold text-muted-foreground">Section weights</span>
        <span className={cn('tabular-nums font-bold', ok ? 'text-emerald-600 dark:text-emerald-400' : 'text-destructive')}>
          {total}% / 100%
        </span>
      </div>
      <div className="h-2 overflow-hidden rounded-full bg-muted/80">
        <div
          className={cn('h-full rounded-full transition-all duration-300', ok ? 'bg-emerald-500' : total > 100 ? 'bg-destructive' : 'bg-brand')}
          style={{ width: `${pct}%` }}
        />
      </div>
    </div>
  )
}

export default function EvaluationFormBuilderModal({
  open,
  value,
  saving,
  onCancel,
  onSave,
}) {
  const [title, setTitle] = useState('')
  const [description, setDescription] = useState('')
  const [isActive, setIsActive] = useState(true)
  const [definition, setDefinition] = useState(() => createEmptyDefinition())
  const [selectedSectionId, setSelectedSectionId] = useState(null)
  const [tab, setTab] = useState('builder')

  useEffect(() => {
    if (!open) return
    const resolved = resolveFormDefinition(value || {})
    setTitle(value?.title || '')
    setDescription(value?.description || '')
    setIsActive(value?.is_active !== false)
    setDefinition(resolved.definition)
    setSelectedSectionId(resolved.definition.sections?.[0]?.id || null)
    setTab('builder')
  }, [open, value])

  const weightTotal = useMemo(() => sectionsWeightTotal(definition), [definition])
  const weightCheck = useMemo(() => validateDefinitionWeights(definition), [definition])
  const selectedSection = (definition?.sections || []).find((s) => s.id === selectedSectionId) || (definition?.sections || [])[0]
  const questionCount = useMemo(
    () => (definition?.sections || []).reduce((n, s) => n + (s.questions?.length || 0), 0),
    [definition],
  )

  const updateDefinition = useCallback((patch) => {
    setDefinition((prev) => ({ ...prev, ...patch }))
  }, [])

  const updateSection = useCallback((sectionId, patch) => {
    setDefinition((prev) => ({
      ...prev,
      sections: prev.sections.map((s) => (s.id === sectionId ? { ...s, ...patch } : s)),
    }))
  }, [])

  const addSection = () => {
    const id = nextQuestionId('section')
    setDefinition((prev) => ({
      ...prev,
      sections: [
        ...prev.sections,
        {
          id,
          title: `Section ${prev.sections.length + 1}`,
          weight: 0,
          questions: [{ id: nextQuestionId('rating'), title: 'New question', type: 'rating', max: 5, required: true }],
        },
      ],
    }))
    setSelectedSectionId(id)
  }

  const removeSection = (sectionId) => {
    setDefinition((prev) => {
      const sections = prev.sections.filter((s) => s.id !== sectionId)
      return { ...prev, sections: sections.length ? sections : createEmptyDefinition().sections }
    })
  }

  const addQuestion = (sectionId) => {
    updateSection(sectionId, {
      questions: [
        ...(definition.sections.find((s) => s.id === sectionId)?.questions || []),
        { id: nextQuestionId('rating'), title: 'New question', type: 'rating', max: 5, required: true },
      ],
    })
  }

  const updateQuestion = (sectionId, questionId, patch) => {
    setDefinition((prev) => ({
      ...prev,
      sections: prev.sections.map((s) => {
        if (s.id !== sectionId) return s
        return {
          ...s,
          questions: (s.questions || []).map((q) => (q.id === questionId ? { ...q, ...patch } : q)),
        }
      }),
    }))
  }

  const removeQuestion = (sectionId, questionId) => {
    setDefinition((prev) => ({
      ...prev,
      sections: prev.sections.map((s) => {
        if (s.id !== sectionId) return s
        const questions = (s.questions || []).filter((q) => q.id !== questionId)
        return {
          ...s,
          questions: questions.length ? questions : [{ id: nextQuestionId('rating'), title: 'Question', type: 'rating', max: 5, required: true }],
        }
      }),
    }))
  }

  const handleSave = () => {
    if (!title.trim() || !weightCheck.ok) return
    onSave?.({
      id: value?.id,
      company_id: value?.company_id,
      title: title.trim(),
      description: description.trim(),
      is_active: isActive,
      sections: definitionForApiSave(definition),
      survey_json: null,
    })
  }

  return (
    <Dialog open={open} onOpenChange={(o) => !o && onCancel?.()}>
      <DialogContent
        showCloseButton
        overlayClassName="bg-black/55 backdrop-blur-sm dark:bg-black/70"
        closeButtonClassName="right-6 top-6 size-10 rounded-xl border-border/80 bg-background/90 shadow-sm hover:bg-muted dark:border-white/10"
        className={BUILDER_SHELL}
        innerClassName={APP_MODAL_INNER_FLUSH}
      >
        {/* Header */}
        <div className="shrink-0 border-b border-border/60 bg-linear-to-r from-brand/10 via-card to-teal-500/5 px-8 py-6 dark:from-brand/15 dark:via-card dark:to-teal-950/20">
          <DialogHeader className="space-y-3 text-left">
            <div className="flex flex-wrap items-start justify-between gap-4">
              <div className="flex min-w-0 items-start gap-4">
                <div className="flex size-12 shrink-0 items-center justify-center rounded-2xl border border-brand/20 bg-brand/10 shadow-sm">
                  <FileSpreadsheet className="size-6 text-brand" strokeWidth={1.75} />
                </div>
                <div className="min-w-0">
                  <DialogTitle className="text-2xl font-bold tracking-tight text-foreground">
                    {value?.id ? 'Edit Evaluation Template' : 'New Evaluation Template'}
                  </DialogTitle>
                  <DialogDescription className="mt-1.5 max-w-3xl text-sm leading-relaxed text-muted-foreground">
                    Design competency sections, assign percentage weights that total 100%, and preview the evaluator experience before publishing.
                  </DialogDescription>
                </div>
              </div>
              <AgcBrandLogo className="hidden h-8 opacity-80 @lg:block" />
            </div>
          </DialogHeader>
        </div>

        {/* Toolbar */}
        <div className="flex shrink-0 flex-wrap items-center gap-4 border-b border-border/60 bg-muted/15 px-8 py-4 dark:bg-muted/10">
          <Tabs value={tab} onValueChange={setTab}>
            <TabsList className="h-10 rounded-xl bg-background/80 p-1 shadow-sm">
              <TabsTrigger value="builder" className="gap-2 rounded-lg px-4 text-sm font-semibold">
                <Layers className="size-4" />
                Builder
              </TabsTrigger>
              <TabsTrigger value="preview" className="gap-2 rounded-lg px-4 text-sm font-semibold">
                <Eye className="size-4" />
                Preview
              </TabsTrigger>
            </TabsList>
          </Tabs>

          <WeightMeter total={weightTotal} ok={weightCheck.ok} />

          <div className="flex flex-wrap items-center gap-2 @md:ml-auto">
            <Badge variant="outline" className="rounded-full px-3 py-1 text-xs font-medium">
              {definition.sections?.length || 0} sections
            </Badge>
            <Badge variant="outline" className="rounded-full px-3 py-1 text-xs font-medium">
              {questionCount} questions
            </Badge>
            <Button
              type="button"
              size="sm"
              variant="outline"
              className="h-9 gap-2 rounded-lg border-brand/30 bg-background font-semibold text-brand hover:bg-brand/10"
              onClick={() => {
                setDefinition(JSON.parse(JSON.stringify(PRESET_360)))
                setTitle((t) => t || '360° Performance Feedback')
              }}
            >
              <Sparkles className="size-4" />
              Load 360° preset
            </Button>
          </div>
        </div>

        {/* Body */}
        <div className="flex min-h-0 flex-1 flex-col overflow-hidden">
          {tab === 'builder' ? (
            <div className="grid min-h-0 flex-1 grid-cols-1 overflow-hidden lg:grid-cols-[minmax(240px,280px)_minmax(0,1fr)_minmax(280px,320px)]">
              {/* Sections rail */}
              <aside className="flex min-h-0 flex-col border-b border-border/60 bg-muted/10 lg:border-b-0 lg:border-r dark:bg-muted/5">
                <div className="flex items-center justify-between border-b border-border/50 px-5 py-4">
                  <div>
                    <p className="text-xs font-bold uppercase tracking-wider text-muted-foreground">Sections</p>
                    <p className="mt-0.5 text-xs text-muted-foreground">Click to edit</p>
                  </div>
                  <Button type="button" size="icon" variant="outline" className="size-8 rounded-lg" onClick={addSection} aria-label="Add section">
                    <Plus className="size-4" />
                  </Button>
                </div>
                <div className="min-h-0 flex-1 space-y-2 overflow-y-auto p-4">
                  {(definition.sections || []).map((section, idx) => (
                    <button
                      key={section.id}
                      type="button"
                      onClick={() => setSelectedSectionId(section.id)}
                      className={cn(
                        'flex w-full items-start gap-3 rounded-xl border px-4 py-3 text-left transition-all',
                        selectedSection?.id === section.id
                          ? 'border-brand/40 bg-brand/8 shadow-sm ring-1 ring-brand/20'
                          : 'border-border/60 bg-card hover:border-border hover:bg-muted/30',
                      )}
                    >
                      <span className="mt-0.5 flex size-7 shrink-0 items-center justify-center rounded-lg bg-muted text-xs font-bold text-muted-foreground">
                        {idx + 1}
                      </span>
                      <span className="min-w-0 flex-1">
                        <span className="block truncate text-sm font-semibold text-foreground">{section.title}</span>
                        <span className="mt-1 block text-xs text-muted-foreground">
                          {(section.questions || []).length} question{(section.questions || []).length !== 1 ? 's' : ''}
                        </span>
                      </span>
                      <Badge variant="secondary" className="shrink-0 rounded-full tabular-nums">
                        {section.weight}%
                      </Badge>
                    </button>
                  ))}
                </div>
              </aside>

              {/* Canvas */}
              <main className="min-h-0 overflow-y-auto bg-background px-6 py-6 lg:px-8">
                <div className="mx-auto max-w-3xl space-y-6">
                  <section className="rounded-2xl border border-border/70 bg-card p-6 shadow-sm">
                    <h3 className="mb-4 text-sm font-bold uppercase tracking-wide text-muted-foreground">Template details</h3>
                    <div className="grid gap-4 @md:grid-cols-2">
                      <div className="space-y-2 @md:col-span-2">
                        <Label className={labelClass}>Template title</Label>
                        <Input value={title} onChange={(e) => setTitle(e.target.value)} placeholder="360° Performance Feedback" className={fieldClass} />
                      </div>
                      <div className="space-y-2 @md:col-span-2">
                        <Label className={labelClass}>Description</Label>
                        <Textarea value={description} onChange={(e) => setDescription(e.target.value)} rows={2} className="rounded-xl border-border/70 bg-background" placeholder="Brief summary for HR when assigning this template..." />
                      </div>
                      <div className="space-y-2 @md:col-span-2">
                        <Label className={labelClass}>Intro / confidentiality (HTML allowed)</Label>
                        <Textarea value={definition.intro_html || ''} onChange={(e) => updateDefinition({ intro_html: e.target.value })} rows={3} className="rounded-xl border-border/70 bg-background font-mono text-xs" placeholder="<p>STRICTLY CONFIDENTIAL</p>" />
                      </div>
                      <div className="flex items-center gap-3 rounded-xl border border-border/60 bg-muted/20 px-4 py-3 @md:col-span-2">
                        <Switch checked={isActive} onCheckedChange={setIsActive} id="form-active" />
                        <Label htmlFor="form-active" className="cursor-pointer text-sm font-medium">Active template (available for assignment)</Label>
                      </div>
                    </div>
                  </section>

                  {selectedSection && (
                    <section className="rounded-2xl border border-border/70 bg-card shadow-sm">
                      <div className="flex items-center justify-between gap-3 border-b border-border/60 px-6 py-4">
                        <div className="flex min-w-0 items-center gap-2">
                          <ClipboardCheck className="size-5 shrink-0 text-brand" />
                          <h3 className="truncate text-base font-bold text-foreground">{selectedSection.title}</h3>
                        </div>
                        <Button type="button" size="sm" variant="ghost" className="shrink-0 text-destructive hover:bg-destructive/10 hover:text-destructive" onClick={() => removeSection(selectedSection.id)}>
                          <Trash2 className="mr-1 size-4" />
                          Remove
                        </Button>
                      </div>
                      <div className="space-y-3 p-6">
                        {(selectedSection.questions || []).map((q, qi) => (
                          <div key={q.id} className="group flex items-start gap-3 rounded-xl border border-border/60 bg-muted/15 p-4 transition-colors hover:border-border hover:bg-muted/25">
                            <GripVertical className="mt-2.5 size-4 shrink-0 text-muted-foreground/40" />
                            <div className="flex min-w-0 flex-1 flex-col gap-2">
                              <div className="flex items-center gap-2">
                                <span className="text-xs font-bold uppercase tracking-wide text-muted-foreground">Q{qi + 1}</span>
                                <Badge variant="outline" className="rounded-full text-[10px]">Rating 1–{q.max || 5}</Badge>
                              </div>
                              <Input
                                value={q.title}
                                onChange={(e) => updateQuestion(selectedSection.id, q.id, { title: e.target.value })}
                                className={fieldClass}
                                placeholder="Question text..."
                              />
                            </div>
                            <Button type="button" size="icon" variant="ghost" className="shrink-0 text-destructive opacity-60 hover:opacity-100" onClick={() => removeQuestion(selectedSection.id, q.id)}>
                              <Trash2 className="size-4" />
                            </Button>
                          </div>
                        ))}
                        <Button type="button" variant="outline" className="h-11 w-full rounded-xl border-dashed font-semibold" onClick={() => addQuestion(selectedSection.id)}>
                          <Plus className="mr-2 size-4" />
                          Add rating question
                        </Button>
                      </div>
                    </section>
                  )}
                </div>
              </main>

              {/* Properties */}
              <aside className="min-h-0 overflow-y-auto border-t border-border/60 bg-muted/10 p-6 lg:border-l lg:border-t-0 dark:bg-muted/5">
                {selectedSection ? (
                  <div className="space-y-5">
                    <div>
                      <p className="text-xs font-bold uppercase tracking-wider text-muted-foreground">Section properties</p>
                      <p className="mt-1 text-xs leading-relaxed text-muted-foreground">Weights on scored sections must total 100%.</p>
                    </div>
                    <div className="space-y-2">
                      <Label className={labelClass}>Section title</Label>
                      <Input value={selectedSection.title} onChange={(e) => updateSection(selectedSection.id, { title: e.target.value })} className={fieldClass} />
                    </div>
                    <div className="space-y-2">
                      <Label className={labelClass}>Weight (%)</Label>
                      <Input
                        type="number"
                        min={0}
                        max={100}
                        value={selectedSection.weight}
                        onChange={(e) => updateSection(selectedSection.id, { weight: Number(e.target.value) || 0 })}
                        className={fieldClass}
                      />
                      <p className="text-xs text-muted-foreground">Contribution to overall score (0 = not scored).</p>
                    </div>
                    {!weightCheck.ok && (
                      <div className="rounded-xl border border-destructive/30 bg-destructive/5 px-4 py-3 text-xs leading-relaxed text-destructive">
                        {weightCheck.message}
                      </div>
                    )}
                    {weightCheck.ok && (
                      <div className="rounded-xl border border-emerald-500/30 bg-emerald-500/5 px-4 py-3 text-xs leading-relaxed text-emerald-700 dark:text-emerald-300">
                        Weights are balanced. Evaluators will see live percentage scoring on submit.
                      </div>
                    )}
                  </div>
                ) : (
                  <p className="text-sm text-muted-foreground">Select a section to edit its properties.</p>
                )}
              </aside>
            </div>
          ) : (
            <div className="min-h-0 flex-1 overflow-y-auto bg-muted/10 px-8 py-8">
              <div className="mx-auto max-w-4xl rounded-2xl border border-border/70 bg-card p-8 shadow-sm">
                <div className="mb-6 flex items-center justify-between gap-3 border-b border-border/60 pb-4">
                  <div>
                    <p className="text-xs font-bold uppercase tracking-wide text-muted-foreground">Live preview</p>
                    <p className="text-lg font-bold text-foreground">{title || 'Untitled template'}</p>
                  </div>
                  <Badge variant="outline" className="rounded-full">Read-only</Badge>
                </div>
                <EvaluationNativeForm definition={definition} initialScores={{}} readOnly />
              </div>
            </div>
          )}
        </div>

        {/* Footer */}
        <div className={APP_MODAL_FOOTER}>
          <div className="text-sm text-muted-foreground">
            {!title.trim() && <span className="text-destructive">Template title is required.</span>}
            {title.trim() && !weightCheck.ok && <span className="text-destructive">{weightCheck.message}</span>}
            {title.trim() && weightCheck.ok && (
              <span className="font-medium text-foreground">Ready to save — {definition.sections?.length || 0} sections, {weightTotal}% weighted.</span>
            )}
          </div>
          <div className={APP_MODAL_FOOTER_ACTIONS}>
            <Button type="button" variant="outline" className={APP_MODAL_OUTLINE_BUTTON_CLASS} onClick={onCancel}>
              Cancel
            </Button>
            <Button type="button" className={APP_MODAL_PRIMARY_BUTTON_CLASS} onClick={handleSave} disabled={saving || !title.trim() || !weightCheck.ok}>
              {saving ? <Loader2 className="mr-2 size-4 animate-spin" /> : <Save className="mr-2 size-4" />}
              Save template
            </Button>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  )
}
