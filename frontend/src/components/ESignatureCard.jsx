import { Button } from '@/components/ui/button'
import { CheckCircle2, FileSignature, Loader2 } from 'lucide-react'

function statusMeta(status) {
  const key = String(status || '').toLowerCase()
  if (key === 'completed' || key === 'signed') return 'completed'
  if (key === 'declined' || key === 'rejected') return 'declined'
  if (key === 'opened' || key === 'viewed' || key === 'pending') return 'pending'
  return key ? 'pending' : 'none'
}

export default function ESignatureCard({
  title = 'Electronic Signature',
  status,
  signatureImage = '',
  busy = false,
  onManage,
  onRefresh,
  manageLabel = 'Manage Signature',
  className = '',
}) {
  const state = statusMeta(status)
  const hasSignature = state === 'completed'

  return (
    <div className={className}>
      <p className="text-sm font-semibold text-foreground">{title}</p>
      <div className="mt-2 rounded-lg border border-border/60 bg-muted/15 p-4">
        {/* Vertical stack only — avoids flex row overlap when sidebar is narrow or zoomed */}
        <div className="flex min-w-0 flex-col gap-4">
          {hasSignature ? (
            <>
              <div className="inline-flex w-fit shrink-0 items-center gap-2 rounded-full border border-emerald-200/80 bg-emerald-50 px-3 py-1.5 text-sm font-medium text-emerald-800 dark:border-emerald-800/50 dark:bg-emerald-950/40 dark:text-emerald-300">
                <CheckCircle2 className="size-4 shrink-0" aria-hidden />
                On file
              </div>
              {signatureImage ? (
                <div className="min-w-0 overflow-hidden rounded-lg border border-border/70 bg-white p-3 shadow-inner dark:bg-slate-950">
                  <img
                    src={signatureImage}
                    alt="Signature preview"
                    className="mx-auto block h-auto max-h-24 w-full max-w-full object-contain object-center"
                  />
                </div>
              ) : null}
            </>
          ) : (
            <p className="text-center text-sm italic text-muted-foreground">No signature on file</p>
          )}

          {onManage ? (
            <Button
              type="button"
              className="h-11 w-full shrink-0 sm:w-auto sm:self-start"
              onClick={onManage}
              disabled={busy}
            >
              {busy ? <Loader2 className="mr-2 size-4 animate-spin" /> : <FileSignature className="mr-2 size-4" />}
              {manageLabel}
            </Button>
          ) : null}
        </div>

        <div className="mt-3 flex flex-col gap-1 text-[11px] text-muted-foreground sm:flex-row sm:items-center sm:justify-between">
          <span className="min-w-0 leading-relaxed">
            Your signature is securely stored and used for internal document verification.
          </span>
          {onRefresh ? (
            <button
              type="button"
              className="shrink-0 self-start rounded-sm px-2 py-0.5 underline underline-offset-2 hover:text-foreground sm:self-auto"
              onClick={onRefresh}
              disabled={busy}
            >
              Refresh
            </button>
          ) : null}
        </div>
      </div>
    </div>
  )
}
