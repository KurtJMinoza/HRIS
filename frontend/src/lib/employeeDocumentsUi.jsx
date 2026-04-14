import { Loader2, FileUp, Upload } from 'lucide-react'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'

/** Per-folder onboarding copy for empty state (employee-facing; admin can reuse). */
/** Admin viewing employee documents — third-person copy */
const DOC_FOLDER_GUIDANCE_ADMIN = {
  Contracts: {
    body:
      'Add employment contracts, addenda, or signed agreements for this employee. Files are stored securely and visible to authorized roles.',
    primaryCta: 'Upload to Contracts',
  },
  IDs: {
    body:
      'Store TIN, SSS, PhilHealth, HDMF, and other IDs. Clear scans support payroll and compliance.',
    primaryCta: 'Upload to IDs',
  },
  Certifications: {
    body: 'NBI clearance, seminars, licenses, and training certificates for this employee.',
    primaryCta: 'Upload certification',
  },
  'Disciplinary Records': {
    body: 'Official notices, warnings, or related correspondence.',
    primaryCta: 'Upload document',
  },
  'Medical Documents': {
    body: 'Medical certificates, fit-to-work notes, and similar documents.',
    primaryCta: 'Upload medical file',
  },
  'Performance Evaluations': {
    body: 'Performance reviews, KPIs, and goal-setting documents.',
    primaryCta: 'Upload evaluation',
  },
}

const DOC_FOLDER_GUIDANCE = {
  Contracts: {
    body:
      'Upload your employment contract, addendum, or any signed agreements. Files are stored securely and are visible only to you and authorized HR.',
    primaryCta: 'Upload to Contracts',
  },
  IDs: {
    body:
      'Add TIN, SSS, PhilHealth, HDMF, and other IDs. Clear PDFs or photos help payroll and compliance reviews.',
    primaryCta: 'Upload to IDs',
  },
  Certifications: {
    body: 'NBI clearance, seminars, licenses, and training certificates belong here.',
    primaryCta: 'Upload certification',
  },
  'Disciplinary Records': {
    body: 'Official notices, warnings, or related correspondence (when applicable).',
    primaryCta: 'Upload document',
  },
  'Medical Documents': {
    body: 'Medical certificates, fit-to-work notes, and similar documents.',
    primaryCta: 'Upload medical file',
  },
  'Performance Evaluations': {
    body: 'Performance reviews, KPIs, and goal-setting documents.',
    primaryCta: 'Upload evaluation',
  },
}

const FOLDER_TEASER = {
  Contracts: 'Employment contract & addenda',
  IDs: 'TIN, SSS, PhilHealth, HDMF',
  Certifications: 'NBI, trainings, licenses',
  'Disciplinary Records': 'Official HR correspondence',
  'Medical Documents': 'Med certs & fit-to-work',
  'Performance Evaluations': 'Reviews & goals',
}

export function getDocFolderGuidance(category, opts = {}) {
  const key = String(category || '').trim()
  if (opts.isAdmin) {
    return DOC_FOLDER_GUIDANCE_ADMIN[key] || {
      body:
        'Upload personnel files for this employee. PDF, DOC, DOCX, XLS, XLSX, JPG, PNG · max 10 MB per file.',
      primaryCta: 'Upload document',
    }
  }
  return DOC_FOLDER_GUIDANCE[key] || {
    body:
      'Upload PDF, DOC, DOCX, XLS, XLSX, JPG, or PNG files up to 10 MB. Submissions are reviewed by authorized personnel.',
    primaryCta: 'Upload document',
  }
}

/** Lines for the small “other folders” checklist in empty state. */
export function getFolderChecklistTeaser(documentCategories, docsByCategory, activeCategory) {
  return documentCategories
    .filter((c) => c !== activeCategory)
    .slice(0, 3)
    .map((cat) => ({
      name: cat,
      count: (docsByCategory[cat] || []).length,
      hint: FOLDER_TEASER[cat] || '',
    }))
}

export function DocumentsEmptyIllustration({ className }) {
  return (
    <svg
      className={cn('text-teal-600/90 dark:text-teal-400/90', className)}
      viewBox="0 0 120 96"
      fill="none"
      xmlns="http://www.w3.org/2000/svg"
      aria-hidden
    >
      <path
        d="M24 28h32l8 8h36v44H24V28z"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinejoin="round"
        className="fill-muted/30 dark:fill-teal-950/40"
      />
      <path d="M44 52h32M44 60h24" stroke="currentColor" strokeWidth="2" strokeLinecap="round" opacity="0.55" />
      <circle cx="88" cy="30" r="18" stroke="currentColor" strokeWidth="2" className="fill-background" />
      <path
        d="M88 22v12M82 28h12"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
      />
      <path
        d="M78 40c4 6 10 10 18 10s14-4 18-10"
        stroke="currentColor"
        strokeWidth="2"
        strokeLinecap="round"
        opacity="0.7"
      />
    </svg>
  )
}

/**
 * Compact top-right style drop zone (replaces full-width giant dashed area).
 */
export function DocumentCompactDropZone({
  categoryLabel,
  docDragOver,
  setDocDragOver,
  docsUploading,
  docsUploadProgress,
  dropZoneRef,
  onDropFiles,
  onBrowse,
  disabled,
}) {
  return (
    <div
      ref={dropZoneRef}
      className={cn(
        'flex w-full max-w-full flex-col gap-1 rounded-xl border px-3 py-2.5 transition-colors @md:ml-auto @md:max-w-[300px] @md:shrink-0',
        docDragOver
          ? 'border-teal-400/60 bg-teal-500/10 shadow-[0_0_0_3px_rgba(20,184,166,0.12)]'
          : 'border-border/60 bg-muted/25 dark:bg-white/4',
        docsUploading && 'opacity-80',
      )}
      onDragEnter={(e) => {
        e.preventDefault()
        e.stopPropagation()
        if (e.dataTransfer?.types?.includes('Files')) {
          e.dataTransfer.dropEffect = 'copy'
          setDocDragOver(true)
        }
      }}
      onDragOver={(e) => {
        e.preventDefault()
        e.stopPropagation()
        if (e.dataTransfer?.types?.includes('Files')) {
          e.dataTransfer.dropEffect = 'copy'
          setDocDragOver(true)
        }
      }}
      onDragLeave={(e) => {
        e.preventDefault()
        e.stopPropagation()
        const ref = dropZoneRef?.current
        if (!ref || (e.relatedTarget && !ref.contains(e.relatedTarget))) setDocDragOver(false)
      }}
      onDrop={(e) => {
        e.preventDefault()
        e.stopPropagation()
        setDocDragOver(false)
        try {
          const files = e.dataTransfer?.files
          if (files?.length) onDropFiles(files)
        } catch {
          setDocDragOver(false)
        }
      }}
    >
      <div className="flex items-center gap-2.5">
        <div
          className={cn(
            'flex size-9 shrink-0 items-center justify-center rounded-lg border',
            docDragOver ? 'border-teal-400/50 bg-teal-500/15' : 'border-border/60 bg-background dark:bg-[#1e293b]',
          )}
        >
          {docsUploading ? (
            <Loader2 className="size-4 animate-spin text-teal-600 dark:text-teal-400" />
          ) : (
            <FileUp className={cn('size-4', docDragOver ? 'text-teal-600 dark:text-teal-400' : 'text-muted-foreground')} />
          )}
        </div>
        <div className="min-w-0 flex-1 text-left">
          <p className="text-[11px] font-semibold leading-snug text-foreground">
            {docDragOver ? `Drop to upload to ${categoryLabel}` : 'Drop files here'}
          </p>
          <p className="text-[10px] text-muted-foreground">or browse · PDF, Office, images · max 10 MB</p>
        </div>
        <Button
          type="button"
          size="sm"
          variant="secondary"
          className="h-8 shrink-0 px-2.5 text-xs"
          onClick={onBrowse}
          disabled={disabled}
        >
          <Upload className="mr-1 size-3" />
          Browse
        </Button>
      </div>
      {docsUploading && docsUploadProgress?.total > 0 ? (
        <p className="text-[10px] font-medium text-teal-600 dark:text-teal-400">
          Uploading {docsUploadProgress.done}/{docsUploadProgress.total}…
        </p>
      ) : null}
    </div>
  )
}

export function DocumentsFolderEmptyState({
  headline,
  body,
  primaryCta,
  onPrimaryUpload,
  onBrowse,
  checklist,
  uploading,
}) {
  return (
    <div className="rounded-xl border border-border/60 border-dashed bg-linear-to-b from-muted/15 to-transparent px-4 py-10 text-center dark:border-white/10 dark:from-white/3 sm:px-8">
      <div className="mx-auto flex max-w-md flex-col items-center gap-4">
        <DocumentsEmptyIllustration className="mx-auto h-20 w-auto" />
        <div className="space-y-2">
          <h4 className="text-lg font-semibold tracking-tight text-foreground">{headline}</h4>
          <p className="text-sm leading-relaxed text-muted-foreground">{body}</p>
        </div>
        <p className="text-[11px] text-muted-foreground">
          Supported: PDF, DOC, DOCX, XLS, XLSX, JPG, PNG, GIF, WebP · Max 10 MB per file
        </p>
        <div className="flex w-full flex-col items-stretch justify-center gap-2 sm:flex-row sm:flex-wrap">
          <Button type="button" className="min-h-10" onClick={onPrimaryUpload} disabled={uploading}>
            <Upload className="mr-2 size-4" />
            {primaryCta}
          </Button>
          <Button type="button" variant="outline" className="min-h-10" onClick={onBrowse} disabled={uploading}>
            Browse files
          </Button>
        </div>
        {checklist?.length > 0 ? (
          <div className="w-full max-w-md rounded-lg border border-border/50 bg-muted/20 px-3 py-2.5 text-left text-[11px] dark:border-white/8 dark:bg-black/20">
            <p className="mb-1.5 font-semibold uppercase tracking-wider text-muted-foreground">Other folders</p>
            <ul className="space-y-1 text-muted-foreground">
              {checklist.map((row) => (
                <li key={row.name} className="flex items-baseline justify-between gap-2">
                  <span>
                    <span className="font-medium text-foreground">{row.name}</span>
                    {row.hint ? <span className="text-muted-foreground"> — {row.hint}</span> : null}
                  </span>
                  <span className="tabular-nums text-muted-foreground">({row.count})</span>
                </li>
              ))}
            </ul>
          </div>
        ) : null}
      </div>
    </div>
  )
}
