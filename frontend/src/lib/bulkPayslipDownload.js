/**
 * Trigger browser download for a bulk payslip ZIP blob.
 * @param {Blob} blob
 * @param {string} [filename]
 */
export function saveBulkPayslipZipBlob(blob, filename = 'Payslips.zip') {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  a.click()
  URL.revokeObjectURL(url)
}

/**
 * Human-readable label for bulk download polling UI.
 * @param {Record<string, unknown>|null|undefined} bulk
 */
export function bulkPayslipDownloadStatusLabel(bulk) {
  const status = String(bulk?.status || '').toLowerCase()
  if (status === 'completed' && bulk?.ready) return 'Ready to download'
  if (status === 'processing') return 'Processing'
  if (status === 'pending') return 'Preparing'
  if (status === 'failed') return 'Failed'
  return 'Preparing'
}
