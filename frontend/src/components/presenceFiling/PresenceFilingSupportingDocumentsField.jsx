import { useEffect, useId, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { UploadCloud, X } from 'lucide-react'
import { Label } from '@/components/ui/label'
import { Button } from '@/components/ui/button'
import { cn } from '@/lib/utils'
import { resetRadixModalLock, scheduleRadixModalLockReset, scheduleUnlockAfterNativeFilePicker } from '@/lib/radixModalLock'

export const MAX_PRESENCE_FILING_SUPPORTING_FILES = 15
export const MAX_PRESENCE_FILING_FILE_BYTES = 10 * 1024 * 1024

export const CORR_FILE_MODAL_SHELL_CLASS =
  'flex min-h-[min(78dvh,calc(100dvh-4.5rem))] max-h-[min(92dvh,calc(100dvh-2rem))] w-[calc(100vw-1.5rem)] max-w-[min(100vw-1.5rem,40rem)] flex-col overflow-hidden rounded-2xl border border-border/80 bg-card p-0 text-card-foreground shadow-[0_24px_80px_-28px_rgba(0,0,0,0.55)] scheme-light sm:min-h-[min(82vh,calc(100dvh-4rem))] sm:max-h-[min(92vh,calc(100dvh-2rem))] sm:w-[calc(100vw-2rem)] dark:border-white/10 dark:bg-card dark:scheme-dark'

export const CORR_FILE_MODAL_SCROLL_CLASS =
  'min-h-0 flex-1 overflow-y-auto overscroll-contain bg-card'

const labelClass = 'text-sm font-semibold tracking-tight text-foreground sm:text-base'
const hintClass = 'text-[13px] leading-relaxed text-muted-foreground'

const SUPPORTING_DOC_ACCEPT =
  '.pdf,.jpg,.jpeg,.png,.doc,.docx,application/pdf,image/jpeg,image/png,application/msword,application/vnd.openxmlformats-officedocument.wordprocessingml.document'

const SUPPORTING_DOC_PICKER_TYPES = [
  {
    description: 'Supporting documents',
    accept: {
      'application/pdf': ['.pdf'],
      'image/jpeg': ['.jpg', '.jpeg'],
      'image/png': ['.png'],
      'application/msword': ['.doc'],
      'application/vnd.openxmlformats-officedocument.wordprocessingml.document': ['.docx'],
    },
  },
]

function afterFilePickerClosed() {
  window.requestAnimationFrame(() => {
    resetRadixModalLock()
    scheduleRadixModalLockReset()
  })
}

export default function PresenceFilingSupportingDocumentsField({
  attachments,
  onAttachmentsChange,
  required = false,
}) {
  const inputRef = useRef(null)
  const inputId = useId()
  const pickSessionRef = useRef(false)
  const [portalReady, setPortalReady] = useState(false)

  useEffect(() => {
    setPortalReady(true)
  }, [])

  useEffect(() => {
    const input = inputRef.current
    if (!input) return undefined

    function onInputClick() {
      scheduleUnlockAfterNativeFilePicker()
    }

    function onInputCancel() {
      pickSessionRef.current = false
      scheduleRadixModalLockReset()
    }

    input.addEventListener('click', onInputClick)
    input.addEventListener('cancel', onInputCancel)
    return () => {
      input.removeEventListener('click', onInputClick)
      input.removeEventListener('cancel', onInputCancel)
    }
  }, [portalReady])

  function endPickSession() {
    pickSessionRef.current = false
    afterFilePickerClosed()
  }

  function openNativeFileInputAgain() {
    scheduleUnlockAfterNativeFilePicker()
    window.requestAnimationFrame(() => {
      inputRef.current?.click()
    })
  }

  function mergeFiles(picked, { fromPicker = false } = {}) {
    if (!picked.length) return
    let continuePickSession = false
    onAttachmentsChange((prev) => {
      const current = Array.isArray(prev) ? prev : []
      const next = [...current, ...picked].slice(0, MAX_PRESENCE_FILING_SUPPORTING_FILES)
      // ponytail: Windows legacy dialog needs Ctrl/Shift for multi — reopen after each single pick until Cancel
      continuePickSession =
        fromPicker &&
        pickSessionRef.current &&
        picked.length === 1 &&
        next.length < MAX_PRESENCE_FILING_SUPPORTING_FILES
      return next
    })
    if (continuePickSession) openNativeFileInputAgain()
    else if (fromPicker) endPickSession()
  }

  function addFromInput(e) {
    const picked = Array.from(e.target.files || [])
    if (e.target) e.target.value = ''
    const fromPicker = pickSessionRef.current
    if (!picked.length) {
      if (fromPicker) endPickSession()
      return
    }
    mergeFiles(picked, { fromPicker })
    if (!fromPicker) afterFilePickerClosed()
  }

  async function pickSupportingFiles() {
    pickSessionRef.current = true
    scheduleUnlockAfterNativeFilePicker()

    if (typeof window.showOpenFilePicker === 'function') {
      try {
        const handles = await window.showOpenFilePicker({
          multiple: true,
          types: SUPPORTING_DOC_PICKER_TYPES,
        })
        const files = await Promise.all(handles.map((handle) => handle.getFile()))
        mergeFiles(files, { fromPicker: true })
        return
      } catch (err) {
        if (err?.name === 'AbortError') {
          pickSessionRef.current = false
          scheduleRadixModalLockReset()
          return
        }
      }
    }

    inputRef.current?.click()
  }

  function onDrop(e) {
    e.preventDefault()
    e.stopPropagation()
    mergeFiles(Array.from(e.dataTransfer?.files || []))
  }

  function onDragOver(e) {
    e.preventDefault()
    e.stopPropagation()
  }

  function removeAt(index) {
    onAttachmentsChange((prev) => {
      const current = Array.isArray(prev) ? prev : []
      return current.filter((_, i) => i !== index)
    })
  }

  const fileInput =
    portalReady &&
    createPortal(
      <input
        id={inputId}
        ref={inputRef}
        type="file"
        tabIndex={-1}
        className="sr-only"
        multiple
        accept={SUPPORTING_DOC_ACCEPT}
        onChange={addFromInput}
      />,
      document.body,
    )

  return (
    <div className="space-y-3">
      {fileInput}
      <Label className={labelClass}>
        Supporting documents
        {required ? <span className="text-destructive"> *</span> : null}
        {!required ? <span className="font-normal text-muted-foreground"> (optional)</span> : null}
      </Label>
      <div
        data-native-file-upload-trigger
        className={cn(
          'rounded-xl border border-dashed border-border bg-muted/15 px-5 py-6 dark:border-white/15 dark:bg-white/[0.03]',
        )}
        onDrop={onDrop}
        onDragOver={onDragOver}
      >
        <div className="flex flex-col items-center justify-center gap-3 text-center">
          <button
            type="button"
            data-native-file-upload-trigger
            className="flex w-full cursor-pointer flex-col items-center justify-center gap-2 rounded-lg border-0 bg-transparent px-4 py-2 text-muted-foreground transition hover:text-foreground"
            onClick={() => void pickSupportingFiles()}
          >
            <UploadCloud className="size-9 text-foreground" strokeWidth={1.7} aria-hidden />
            <span className="text-base font-medium text-muted-foreground">
              Drag and drop files here or click to upload
            </span>
            <span className={hintClass}>
              Choose files one at a time — the picker reopens after each file until you cancel (up to{' '}
              {MAX_PRESENCE_FILING_SUPPORTING_FILES}). Or drag several at once. PDF, PNG, JPG, DOC, DOCX up to 10MB
              each
              {required ? '. At least one file is required.' : ''}
            </span>
          </button>
        </div>
        {attachments?.length > 0 ? (
          <ul className="relative z-[1] mt-3 max-h-[min(36dvh,16rem)] space-y-2 overflow-y-auto overscroll-contain pr-0.5">
            {attachments.map((f, i) => (
              <li
                key={`${f.name}-${i}-${f.size}`}
                className="flex items-center justify-between gap-2 rounded-lg border border-border/60 bg-background px-3 py-2 text-sm"
              >
                <span className="min-w-0 flex-1 truncate font-medium text-foreground">{f.name}</span>
                <Button
                  type="button"
                  variant="ghost"
                  size="icon"
                  className="relative z-[2] size-7 shrink-0 text-muted-foreground hover:text-destructive"
                  onClick={(e) => {
                    e.preventDefault()
                    e.stopPropagation()
                    removeAt(i)
                  }}
                  aria-label={`Remove ${f.name}`}
                >
                  <X className="size-3.5" />
                </Button>
              </li>
            ))}
          </ul>
        ) : null}
      </div>
    </div>
  )
}
