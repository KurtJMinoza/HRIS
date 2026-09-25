import { useEffect, useMemo, useState } from 'react'
import { FileDown, Loader2 } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { getDocFileKind } from '@/lib/documentFileKind'

/**
 * In-modal preview for supporting documents (PDF, images, DOCX; download fallback otherwise).
 */
export default function SupportingDocumentFilePreview({ url, filename, className }) {
  const kind = useMemo(() => getDocFileKind({ url, filename }), [url, filename])
  const [docxHtml, setDocxHtml] = useState('')
  const [docxLoading, setDocxLoading] = useState(false)
  const [docxError, setDocxError] = useState('')
  const [blobUrl, setBlobUrl] = useState(null)
  const [blobLoading, setBlobLoading] = useState(false)
  const [blobError, setBlobError] = useState('')

  useEffect(() => {
    let alive = true
    setDocxHtml('')
    setDocxError('')
    setDocxLoading(false)
    setBlobUrl(null)
    setBlobError('')
    setBlobLoading(false)

    if (!url) return undefined

    if (kind === 'docx') {
      setDocxLoading(true)
      ;(async () => {
        try {
          const res = await fetch(url, { credentials: 'include' })
          if (!res.ok) throw new Error('Could not load this document for preview.')
          const arrayBuffer = await res.arrayBuffer()
          if (!alive) return
          const mammoth = await import('mammoth')
          const result = await mammoth.convertToHtml({ arrayBuffer }, { includeDefaultStyleMap: true })
          if (!alive) return
          setDocxHtml(result?.value || '')
        } catch (e) {
          if (!alive) return
          setDocxError(e?.message || 'DOCX preview failed.')
        } finally {
          if (alive) setDocxLoading(false)
        }
      })()
      return () => {
        alive = false
      }
    }

    if (kind === 'doc' || kind === 'xlsx' || kind === 'file') {
      setBlobLoading(true)
      ;(async () => {
        try {
          const res = await fetch(url, { credentials: 'include' })
          if (!res.ok) throw new Error('Could not load this file.')
          const blob = await res.blob()
          if (!alive) return
          setBlobUrl(URL.createObjectURL(blob))
        } catch (e) {
          if (!alive) return
          setBlobError(e?.message || 'Preview failed.')
        } finally {
          if (alive) setBlobLoading(false)
        }
      })()
      return () => {
        alive = false
        setBlobUrl((prev) => {
          if (prev) URL.revokeObjectURL(prev)
          return null
        })
      }
    }

    return undefined
  }, [url, kind])

  if (!url) {
    return <p className="text-sm text-muted-foreground">No file to preview.</p>
  }

  const download = () => window.open(url, '_blank', 'noopener,noreferrer')

  return (
    <div className={className}>
      <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
        <p className="min-w-0 truncate text-sm font-medium text-foreground" title={filename}>
          {filename || 'Document'}
        </p>
        <Button
          type="button"
          variant="outline"
          size="sm"
          className="shrink-0 gap-1.5 border-brand/40 text-foreground hover:border-brand hover:bg-brand/10 hover:text-brand"
          onClick={download}
        >
          <FileDown className="size-4" aria-hidden />
          Download
        </Button>
      </div>

      {kind === 'pdf' ? (
        <iframe
          title={filename || 'Document preview'}
          src={url}
          className="h-[min(62vh,520px)] min-h-[240px] w-full rounded-lg border border-brand/20 bg-muted/10"
        />
      ) : null}

      {kind === 'image' ? (
        <div className="flex max-h-[min(62vh,520px)] min-h-[200px] items-center justify-center overflow-auto rounded-lg border border-brand/20 bg-muted/20 p-3">
          <img
            src={url}
            alt={filename || 'Supporting document'}
            className="max-h-[min(58vh,480px)] max-w-full rounded-md object-contain"
          />
        </div>
      ) : null}

      {kind === 'docx' ? (
        docxLoading ? (
          <div className="flex h-[min(62vh,520px)] min-h-[200px] items-center justify-center rounded-lg border border-brand/20">
            <Loader2 className="mr-2 size-5 animate-spin text-muted-foreground" aria-hidden />
            <span className="text-sm text-muted-foreground">Generating preview…</span>
          </div>
        ) : docxError ? (
          <PreviewFallback message={docxError} onDownload={download} />
        ) : (
          <div className="h-[min(62vh,520px)] min-h-[200px] overflow-auto rounded-lg border border-brand/20 bg-background p-4">
            <div
              className="prose prose-sm max-w-none dark:prose-invert"
              dangerouslySetInnerHTML={{ __html: docxHtml || '<p>No preview content.</p>' }}
            />
          </div>
        )
      ) : null}

      {kind === 'doc' || kind === 'xlsx' || kind === 'file' ? (
        blobLoading ? (
          <div className="flex h-[min(62vh,520px)] min-h-[200px] items-center justify-center rounded-lg border border-brand/20">
            <Loader2 className="mr-2 size-5 animate-spin text-muted-foreground" aria-hidden />
            <span className="text-sm text-muted-foreground">Loading file…</span>
          </div>
        ) : blobError ? (
          <PreviewFallback message={blobError} onDownload={download} />
        ) : blobUrl ? (
          <iframe
            title={filename || 'Document preview'}
            src={blobUrl}
            className="h-[min(62vh,520px)] min-h-[240px] w-full rounded-lg border border-brand/20 bg-muted/10"
          />
        ) : (
          <PreviewFallback
            message={
              kind === 'doc'
                ? 'In-browser preview may be limited for .DOC files.'
                : kind === 'xlsx'
                  ? 'Spreadsheet preview may not render in the browser.'
                  : 'Preview is not available for this file type.'
            }
            onDownload={download}
          />
        )
      ) : null}
    </div>
  )
}

function PreviewFallback({ message, onDownload }) {
  return (
    <div className="flex min-h-[200px] flex-col items-center justify-center gap-3 rounded-lg border border-brand/20 bg-muted/20 p-6 text-center">
      <p className="text-sm text-muted-foreground">{message}</p>
      <Button
        type="button"
        size="sm"
        className="bg-brand text-brand-foreground hover:bg-brand-strong"
        onClick={onDownload}
      >
        Open / download file
      </Button>
    </div>
  )
}
