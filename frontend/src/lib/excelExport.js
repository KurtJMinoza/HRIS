import ExcelJS from 'exceljs'

function triggerBrowserDownload(blob, filename) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a)
  a.click()
  document.body.removeChild(a)
  URL.revokeObjectURL(url)
}

/**
 * Export rows to XLSX in browser using exceljs.
 * @param {string[]} headers
 * @param {Array<Array<string|number|boolean|null|undefined>>} rows
 * @param {string} filename
 * @param {string} [sheetName='Sheet1']
 */
export async function exportRowsToXlsx(headers, rows, filename, sheetName = 'Sheet1') {
  const workbook = new ExcelJS.Workbook()
  const worksheet = workbook.addWorksheet(sheetName)

  worksheet.addRow(headers)
  for (const row of rows) {
    worksheet.addRow(row)
  }

  const headerRow = worksheet.getRow(1)
  headerRow.font = { bold: true }

  const colWidths = headers.map((header, colIdx) => {
    const maxCellLen = rows.reduce((max, row) => {
      const value = row[colIdx]
      const len = value == null ? 0 : String(value).length
      return Math.max(max, len)
    }, String(header).length)
    return Math.min(40, Math.max(10, maxCellLen + 2))
  })
  worksheet.columns = colWidths.map((width) => ({ width }))

  const buffer = await workbook.xlsx.writeBuffer()
  const blob = new Blob([buffer], {
    type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
  })
  triggerBrowserDownload(blob, filename)
}

