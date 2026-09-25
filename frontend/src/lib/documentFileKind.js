/** Classify uploaded file for in-app preview (path / name / mime). */
export function getDocFileKind({ url, filename, mime, path } = {}) {
  const mimeStr = String(mime || '').toLowerCase()
  const pathStr = String(path || filename || url || '').toLowerCase()
  if (mimeStr.includes('pdf') || pathStr.endsWith('.pdf')) return 'pdf'
  if (mimeStr.includes('officedocument.wordprocessingml.document') || pathStr.endsWith('.docx')) return 'docx'
  if (mimeStr.includes('msword') || pathStr.endsWith('.doc')) return 'doc'
  if (
    mimeStr.includes('spreadsheetml')
    || mimeStr.includes('ms-excel')
    || pathStr.endsWith('.xlsx')
    || pathStr.endsWith('.xls')
  ) {
    return 'xlsx'
  }
  if (mimeStr.includes('image/') || /\.(jpe?g|png|gif|webp|bmp)$/i.test(pathStr)) return 'image'
  return 'file'
}
