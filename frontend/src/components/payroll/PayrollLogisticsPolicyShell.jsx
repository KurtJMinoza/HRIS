import { useState } from 'react'
import { BookOpen, Loader2, Layers } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { DailyComputationSubNav } from '@/components/DailyComputationSubNav'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog'

/**
 * Payroll Logistics framing for Daily Computation → Policy Settings.
 * Top bar + sub-nav + draft/publish actions (confirm via modal).
 */
export function PayrollLogisticsPolicyShell({
  children,
  dirty = false,
  saving = false,
  /** Persist policy (same API as in-page save; draft vs publish is UX-only in this app). */
  onSaveDraft,
  onPublish,
  disableActions = false,
}) {
  const [draftModalOpen, setDraftModalOpen] = useState(false)
  const [publishModalOpen, setPublishModalOpen] = useState(false)

  const runDraft = () => {
    setDraftModalOpen(false)
    if (!dirty) return
    onSaveDraft?.()
  }

  const runPublish = () => {
    setPublishModalOpen(false)
    if (!dirty) return
    onPublish?.()
  }

  return (
    <div className="flex min-h-0 min-w-0 flex-col bg-transparent">
      {/* Top bar: same surface tokens as DashboardLayout sticky header + Daily computation context */}
      <header className="sticky top-0 z-40 border-b border-border/40 bg-card/95 backdrop-blur dark:border-border/40 dashboard-header-glass dark:shadow-none">
        <div className="flex flex-col gap-3 px-4 py-3.5 sm:flex-row sm:items-center sm:justify-between sm:px-6">
          <div className="flex min-w-0 flex-wrap items-center gap-3">
            <div className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-gradient-to-br from-slate-800 to-slate-950 text-white shadow-md ring-1 ring-white/10 dark:from-slate-700 dark:to-slate-900">
              <Layers className="size-5 opacity-95" aria-hidden />
            </div>
            <div className="min-w-0">
              <p className="truncate text-[15px] font-semibold tracking-tight text-foreground">
                Payroll Logistics
              </p>
              <p className="truncate text-xs text-muted-foreground">Policy &amp; premium configuration</p>
            </div>
          </div>

          <div className="flex flex-wrap items-center justify-end gap-2">
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={disableActions || saving || !dirty}
              onClick={() => setDraftModalOpen(true)}
              className="border-border/80 bg-background/50"
              title={!dirty ? 'No unsaved changes' : 'Save current edits'}
            >
              {saving ? <Loader2 className="size-4 animate-spin" /> : 'Save draft'}
            </Button>
            <Button
              type="button"
              size="sm"
              className="bg-primary text-primary-foreground shadow-sm hover:bg-primary/90"
              disabled={disableActions || saving || !dirty}
              onClick={() => setPublishModalOpen(true)}
              title={!dirty ? 'No unsaved changes' : 'Apply policy to payroll'}
            >
              {saving ? <Loader2 className="size-4 animate-spin" /> : 'Publish'}
            </Button>
          </div>
        </div>

        <div className="flex flex-wrap items-center justify-between gap-3 border-t border-border/30 bg-muted/30 px-4 py-2.5 backdrop-blur-sm dark:border-border/30 dark:bg-background/35 dark:backdrop-blur-md sm:px-6">
          <DailyComputationSubNav />
          <a
            href="https://www.dole.gov.ph/"
            target="_blank"
            rel="noopener noreferrer"
            className="inline-flex items-center gap-1.5 rounded-full border border-transparent px-2 py-1 text-xs text-muted-foreground transition-colors hover:border-border hover:bg-background hover:text-foreground"
          >
            <BookOpen className="size-3.5" />
            DOLE reference
          </a>
        </div>
      </header>

      {/* Match header/sub-nav horizontal inset so page title & cards align with shell chrome */}
      <div className="min-w-0 flex-1 bg-transparent px-4 sm:px-6">{children}</div>

      <Dialog open={draftModalOpen} onOpenChange={setDraftModalOpen}>
        <DialogContent className="max-w-md gap-4" showCloseButton>
          <DialogHeader>
            <DialogTitle>Save draft</DialogTitle>
            <DialogDescription>
              This will write your current edits to the server. For employees assigned to this policy, saved
              multipliers and night-differential settings are what payroll uses on the next run.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setDraftModalOpen(false)} disabled={saving}>
              Cancel
            </Button>
            <Button type="button" onClick={runDraft} disabled={saving || !dirty} className="gap-1.5">
              {saving ? <Loader2 className="size-4 animate-spin" aria-hidden /> : null}
              Save draft
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={publishModalOpen} onOpenChange={setPublishModalOpen}>
        <DialogContent className="max-w-md gap-4" showCloseButton>
          <DialogHeader>
            <DialogTitle>Publish changes</DialogTitle>
            <DialogDescription>
              Publishing applies this policy version to payroll for every employee currently assigned to it.
              Confirm only when you intend to put these rates into effect.
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setPublishModalOpen(false)} disabled={saving}>
              Cancel
            </Button>
            <Button type="button" onClick={runPublish} disabled={saving || !dirty} className="gap-1.5">
              {saving ? <Loader2 className="size-4 animate-spin" aria-hidden /> : null}
              Publish
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  )
}
