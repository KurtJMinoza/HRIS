import { useEffect, useMemo, useState, useCallback, useRef } from 'react'
import { Model } from 'survey-core'
import { Survey } from 'survey-react-ui'
import { SurveyCreatorComponent, SurveyCreator } from 'survey-creator-react'
import {
  Dialog, DialogContent, DialogHeader, DialogTitle, DialogDescription,
} from '@/components/ui/dialog'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Switch } from '@/components/ui/switch'
import { Badge } from '@/components/ui/badge'
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs'
import {
  Tooltip, TooltipContent, TooltipProvider, TooltipTrigger,
} from '@/components/ui/tooltip'
import {
  CheckCircle2, FileJson, FileText, Eye, Layers, Loader2, Save,
  Settings2, Sparkles, XCircle, LayoutTemplate,
} from 'lucide-react'
import { cn } from '@/lib/utils'
import { AgcBrandLogo } from '@/components/AgcBrandLogo'
import { applyCuratedToolbox, loadTemplateIntoCreator, normalizeSurveyJsonExpressions, TEMPLATE_REGISTRY } from '@/lib/surveyConfig'

// ─── Creator Tab Names ─────────────────────────────────────────────
// These are custom top-level navigation tabs. The SurveyJS Creator has its own
// internal tabs (Designer, Preview, JSON, Logic, Theme) — we use our tabs to
// switch between "Creator mode" and "standalone preview/JSON viewer" modes.

const NAV_TABS = [
  { id: 'designer', label: 'Designer', icon: Layers },
  { id: 'preview', label: 'Preview', icon: Eye },
  { id: 'json', label: 'JSON', icon: FileJson },
  { id: 'logic', label: 'Logic', icon: Settings2 },
  { id: 'theme', label: 'Theme', icon: Sparkles },
]

// Tabs that render the full Creator (vs our custom panels)
const CREATOR_VIEW_TABS = new Set(['designer', 'logic', 'theme'])

// ─── Template Selector Component ───────────────────────────────────

function TemplateSelector({ onSelect }) {
  const [open, setOpen] = useState(false)

  const handleSelect = (templateId) => {
    onSelect(templateId)
    setOpen(false)
  }

  return (
    <div className="relative">
      <TooltipProvider delayDuration={300}>
        <Tooltip>
          <TooltipTrigger asChild>
            <Button
              type="button"
              variant="outline"
              size="sm"
              className="h-8 gap-1.5 rounded-lg border-brand/25 text-xs text-brand hover:bg-brand/10"
              onClick={() => setOpen(!open)}
            >
              <LayoutTemplate className="size-3.5" />
              Templates
            </Button>
          </TooltipTrigger>
          <TooltipContent side="bottom" className="text-xs max-w-48">
            Load a pre-built evaluation template into the designer.
          </TooltipContent>
        </Tooltip>
      </TooltipProvider>

      {open && (
        <>
          <div className="fixed inset-0 z-40" onClick={() => setOpen(false)} />
          <div className="absolute right-0 top-full z-50 mt-1 w-72 overflow-hidden rounded-xl border border-border/70 bg-card shadow-xl">
            <div className="border-b border-border/50 bg-muted/20 px-4 py-2.5">
              <p className="text-xs font-bold uppercase tracking-wide text-muted-foreground">
                Choose Template
              </p>
            </div>
            <div className="max-h-64 overflow-y-auto p-2">
              {Object.values(TEMPLATE_REGISTRY).map((template) => (
                <button
                  key={template.id}
                  type="button"
                  onClick={() => handleSelect(template.id)}
                  className="flex w-full items-start gap-3 rounded-lg px-3 py-2.5 text-left text-sm transition hover:bg-brand/10"
                >
                  <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-brand/10 text-brand">
                    <FileText className="size-4" />
                  </span>
                  <div className="min-w-0 flex-1">
                    <p className="text-xs font-semibold text-foreground">{template.name}</p>
                    <p className="mt-0.5 text-[11px] leading-tight text-muted-foreground line-clamp-2">
                      {template.description}
                    </p>
                  </div>
                  <Sparkles className="mt-1 size-3.5 shrink-0 text-brand" />
                </button>
              ))}
            </div>
          </div>
        </>
      )}
    </div>
  )
}

// ─── Main Modal Component ──────────────────────────────────────────

export default function EvaluationSurveyCreatorModal({
  open,
  value,
  saving = false,
  onSave,
  onCancel,
}) {
  const [activeNavTab, setActiveNavTab] = useState('designer')
  const creatorRef = useRef(null)
  const [formMeta, setFormMeta] = useState(() => ({
    title: '',
    description: '',
    is_active: true,
  }))
  // Track preview updates: increment every time we switch to preview
  const [previewKey, setPreviewKey] = useState(0)

  // Initialize form meta from the incoming value
  const prevValueRef = useRef(value)
  if (value !== prevValueRef.current && open) {
    prevValueRef.current = value
    setFormMeta({
      title: value?.title || '',
      description: value?.description || '',
      is_active: value?.is_active ?? true,
    })
  }

  // Create a stable SurveyCreator instance (only once)
  const creator = useMemo(() => {
    const c = new SurveyCreator({
      showEmbeddedSurveyTab: false,
      showTranslationTab: true,
      // Disable the Creator's own tab bar — we control navigation via our custom NAV_TABS.
      // For Logic and Theme, the Creator still renders the correct editor panel when
      // its component is visible, even without the tab button being shown.
      showJSONEditorTab: false,
      showLogicTab: true,
      showThemeTab: true,
      showPreviewTab: false,
      allowEditSurveyTitle: false,
      allowEditSurveyDescription: false,
      maxUndoRedoCount: 50,
      haveCommercialLicense: false,
      showPagesToolbar: true,
      showToolbox: true,
      showPropertyGrid: true,
      // Hide the Creator's internal tab bar so only our custom nav tabs are visible
      showTabs: false,
    })

    c.JSON = value?.survey_json || {}

    creatorRef.current = c
    return c
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  // Apply curated toolbox after the Creator mounts in DOM
  const toolboxAppliedRef = useRef(false)
  useEffect(() => {
    if (!creatorRef.current || !CREATOR_VIEW_TABS.has(activeNavTab)) return
    const id = setTimeout(() => {
      if (creatorRef.current) {
        try {
          applyCuratedToolbox(creatorRef.current)
          toolboxAppliedRef.current = true
        } catch {
          // toolbox may not be ready yet
        }
      }
    }, 200)
    return () => clearTimeout(id)
  }, [activeNavTab])

  // Reset toolbox flag when navigating back to a creator tab
  useEffect(() => {
    if (CREATOR_VIEW_TABS.has(activeNavTab)) {
      toolboxAppliedRef.current = false
    }
  }, [activeNavTab])

  // Update JSON when the parent value changes (e.g. editing an existing form)
  const prevJsonRef = useRef(null)
  const currentJson = value?.survey_json
  if (currentJson && currentJson !== prevJsonRef.current && open && creatorRef.current) {
    prevJsonRef.current = currentJson
    try {
      creatorRef.current.JSON = JSON.parse(JSON.stringify(normalizeSurveyJsonExpressions(currentJson)))
    } catch {
      // ignore parse errors
    }
  }

  // Refresh preview model when switching to preview tab
  const previewModel = useMemo(() => {
    if (activeNavTab !== 'preview') return null
    const json = creatorRef.current?.JSON
    if (!json || !json.pages?.length) return null
    const m = new Model(JSON.parse(JSON.stringify(json)))
    m.showTitle = false
    m.showCompletedPage = false
    m.showPreviewButton = false
    return m
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activeNavTab, previewKey])

  // Handle tab switch – refresh preview if needed
  const handleTabChange = useCallback((tab) => {
    setActiveNavTab(tab)
    if (tab === 'preview' && creatorRef.current) {
      setPreviewKey(k => k + 1)
    }
  }, [])

  // Collect form payload on save
  const handleSave = useCallback(() => {
    const creatorJson = creatorRef.current?.JSON || {}
    const hasContent = creatorJson.pages?.length > 0

    // Build a clean payload — only carry over essential fields from value,
    // DON'T spread the whole value object (which may contain empty {} or stale arrays).
    const payload = {
      id: value?.id ?? null,
      company_id: value?.company_id || null,
      title: formMeta.title || creatorJson.title || 'Untitled Evaluation Form',
      description: formMeta.description || creatorJson.description || '',
      is_active: formMeta.is_active,
      survey_json: hasContent ? normalizeSurveyJsonExpressions(creatorJson) : null,
    }

    onSave?.(payload)
  }, [formMeta, onSave, value])

  const surveyHasQuestions = creatorRef.current?.JSON?.pages?.length > 0

  return (
    <Dialog open={open} onOpenChange={(nextOpen) => !nextOpen && onCancel?.()}>
      <DialogContent
        showCloseButton
        surfaceStyle={{ width: '95vw', maxWidth: '95vw', minWidth: '95vw' }}
        overlayClassName="bg-black/55 backdrop-blur-sm dark:bg-black/70"
        closeButtonClassName="right-4 top-4 size-10 rounded-lg border-border/80 bg-background/90 text-foreground shadow-sm hover:bg-muted @md:right-6 @md:top-6 @md:size-11 dark:border-white/10 dark:bg-card/90"
        className="!h-[95vh] !max-h-[95vh] !min-h-[95vh] !w-[95vw] !min-w-[95vw] !max-w-none !sm:max-w-none !lg:max-w-none !xl:max-w-none rounded-lg border border-border/80 bg-card shadow-[0_24px_80px_-24px_rgba(0,0,0,0.5)] dark:border-white/10"
        innerClassName="!gap-0 !overflow-hidden !p-0 !pr-0"
        aria-describedby="survey-creator-desc"
      >
        <div className="flex min-h-0 flex-1 flex-col">
          {/* ─── Header ─── */}
          <div className="shrink-0 border-b border-border/70 bg-card">
            <DialogHeader className="px-5 pb-3 pt-4 text-left @md:px-6">
              <div className="flex flex-wrap items-center justify-between gap-3 pr-10">
                <div className="flex min-w-0 items-center gap-4">
                  <AgcBrandLogo className="h-7 shrink-0" />
                  <div>
                    <DialogTitle className="text-base font-bold tracking-tight text-foreground @md:text-lg">
                      {formMeta.title || 'New Evaluation Form'}
                    </DialogTitle>
                    <DialogDescription id="survey-creator-desc" className="text-xs text-muted-foreground">
                      Design evaluation forms with the full SurveyJS form builder.
                    </DialogDescription>
                  </div>
                </div>
                <div className="flex items-center gap-2">
                  {surveyHasQuestions && (
                    <>
                      <Badge variant="outline" className="rounded-full text-[10px] font-normal">
                        {creatorRef.current?.JSON?.pages?.length || 0} pages
                      </Badge>
                      <Badge variant="outline" className="rounded-full text-[10px] font-normal">
                        {countQuestions(creatorRef.current?.JSON)} questions
                      </Badge>
                    </>
                  )}
                  <TemplateSelector onSelect={(templateId) => {
                    loadTemplateIntoCreator(creatorRef.current, templateId)
                    // Trigger a re-render so badges/stats update
                    setPreviewKey(k => k + 1)
                    // Switch to designer tab so the template is visible
                    if (activeNavTab !== 'designer') setActiveNavTab('designer')
                  }} />
                </div>
              </div>
            </DialogHeader>

            {/* ─── Form Meta Bar ─── */}
            <div className="flex flex-wrap items-center gap-3 border-t border-border/50 px-5 py-2.5 @md:px-6">
              <div className="flex items-center gap-2 min-w-0 flex-1">
                <div className="min-w-0 flex-1 @md:max-w-xs">
                  <Input
                    value={formMeta.title}
                    onChange={(e) => setFormMeta(p => ({ ...p, title: e.target.value }))}
                    placeholder="Evaluation Form Name"
                    className="h-8 rounded-lg text-xs font-semibold"
                  />
                </div>
                <div className="hidden min-w-0 flex-1 @md:block @md:max-w-xs">
                  <Input
                    value={formMeta.description}
                    onChange={(e) => setFormMeta(p => ({ ...p, description: e.target.value }))}
                    placeholder="Brief description..."
                    className="h-8 rounded-lg text-xs"
                  />
                </div>
              </div>
              <label className="flex items-center gap-1.5 text-xs text-muted-foreground shrink-0">
                <Switch
                  checked={formMeta.is_active}
                  onCheckedChange={(v) => setFormMeta(p => ({ ...p, is_active: v }))}
                  className="scale-75"
                />
                Active
              </label>
            </div>

            {/* ─── Navigation Tabs ─── */}
            <div className="border-t border-border/50 bg-muted/15 px-5 py-1.5 @md:px-6">
              <Tabs value={activeNavTab} onValueChange={handleTabChange} className="w-full">
                <TabsList className="h-8 gap-0.5 rounded-lg bg-muted/30 p-0.5">
                  {NAV_TABS.map(tab => {
                    const Icon = tab.icon
                    return (
                      <TabsTrigger
                        key={tab.id}
                        value={tab.id}
                        className={cn(
                          'gap-1.5 rounded-md px-3 py-1 text-[11px] font-semibold data-[state=active]:bg-background data-[state=active]:text-foreground data-[state=active]:shadow-sm',
                        )}
                      >
                        <Icon className="size-3" />
                        <span className="hidden @sm:inline">{tab.label}</span>
                      </TabsTrigger>
                    )
                  })}
                </TabsList>
              </Tabs>
            </div>
          </div>

          {/* ─── Content Area ─── */}
          <div className="min-h-0 flex-1 overflow-hidden bg-muted/5">
            {/* Hide the SurveyJS license/watermark banner (free community edition) */}
            <style>{`
              .sv-license-banner,
              .sd-license-banner,
              .sv_license_banner,
              [class*="license-banner"],
              [class*="license_notification"],
              [class*="watermark"],
              .sv-watermark,
              .sv-footer__watermark,
              .sv-footer__brand-info,
              .sv-do-license,
              .sv_do_license {
                display: none !important;
              }
            `}</style>

            {/* Creator Component — conditionally mounted only for designer/logic/theme tabs.
                Using conditional mount (not hidden CSS) to prevent DOM conflicts between
                SurveyJS internal DOM management and React reconciliation when the dialog closes. */}
            {CREATOR_VIEW_TABS.has(activeNavTab) && (
              <div className="survey-creator-host h-full w-full overflow-hidden">
                <SurveyCreatorComponent key={activeNavTab} creator={creator} />
              </div>
            )}

            {/* Preview Tab — standalone Survey rendering */}
            {activeNavTab === 'preview' && (
              <div className="h-full overflow-y-auto bg-muted/10 px-6 py-6">
                {previewModel && previewModel.getAllQuestions().length > 0 ? (
                  <div className="mx-auto max-w-3xl rounded-2xl border border-border/70 bg-card p-6 shadow-sm">
                    <Survey model={previewModel} />
                  </div>
                ) : (
                  <div className="flex h-full flex-col items-center justify-center text-center">
                    <div className="mb-4 flex size-16 items-center justify-center rounded-full bg-muted/30">
                      <Eye className="size-7 text-muted-foreground/60" strokeWidth={1.5} />
                    </div>
                    <p className="text-sm font-semibold text-foreground">Nothing to preview</p>
                    <p className="mt-1 max-w-xs text-xs text-muted-foreground">
                      Switch to the Designer tab and build your evaluation form first.
                    </p>
                  </div>
                )}
              </div>
            )}

            {/* JSON Tab — raw JSON viewer */}
            {activeNavTab === 'json' && (
              <div className="h-full overflow-y-auto bg-slate-950 p-6">
                <pre className="text-xs leading-relaxed text-slate-300 font-mono whitespace-pre-wrap">
                  {JSON.stringify(creatorRef.current?.JSON || {}, null, 2)}
                </pre>
              </div>
            )}
          </div>

          {/* ─── Footer ─── */}
          <div className="shrink-0 border-t border-border/70 bg-card/95 px-5 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] backdrop-blur @md:px-6">
            <div className="flex items-center justify-between gap-3">
              <div className="flex items-center gap-2 text-xs text-muted-foreground">
                {surveyHasQuestions ? (
                  <span className="flex items-center gap-1 text-emerald-600">
                    <CheckCircle2 className="size-3.5" />
                    Form has {countQuestions(creatorRef.current?.JSON)} question{countQuestions(creatorRef.current?.JSON) !== 1 ? 's' : ''}
                  </span>
                ) : (
                  <span className="flex items-center gap-1">
                    <XCircle className="size-3.5" />
                    Empty form — add questions in the Designer tab
                  </span>
                )}
              </div>
              <div className="flex items-center gap-2">
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  className="h-9 rounded-lg text-xs"
                  onClick={onCancel}
                >
                  Cancel
                </Button>
                <Button
                  type="button"
                  size="sm"
                  className="h-9 gap-1.5 rounded-lg bg-brand text-xs text-brand-foreground hover:bg-brand-strong"
                  onClick={handleSave}
                  disabled={saving}
                >
                  {saving ? <Loader2 className="size-3.5 animate-spin" /> : <Save className="size-3.5" />}
                  {value?.id ? 'Update Form' : 'Save Template'}
                </Button>
              </div>
            </div>
          </div>
        </div>
      </DialogContent>
    </Dialog>
  )
}

// ─── Helper: Count questions in a survey JSON ──────────────────────

function countQuestions(surveyJson) {
  if (!surveyJson || !Array.isArray(surveyJson.pages)) return 0
  let count = 0
  const walk = (elements) => {
    for (const el of elements || []) {
      if (!el) continue
      if (el.type === 'panel' || el.type === 'paneldynamic') {
        walk(el.elements)
      } else if (el.type !== 'html') {
        count += 1
      }
    }
  }
  for (const page of surveyJson.pages) walk(page.elements)
  return count
}
