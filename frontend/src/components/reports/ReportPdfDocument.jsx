import {
  Document,
  Page,
  View,
  Text,
  StyleSheet,
} from '@react-pdf/renderer'

// Standard bond/paper sizes in pt (portrait: width x height). 72pt = 1 inch.
const PAGE_DIMENSIONS = {
  A4: [595.28, 841.89],
  LETTER: [612, 792],
  LEGAL: [612, 1008],
}

const ROW_HEIGHT_PT = 20
const HEADER_BLOCK_PT = 108
const FOOTER_PT = 28

function getPageDimensions(pageSize, orientation) {
  const size = typeof pageSize === 'string' ? PAGE_DIMENSIONS[pageSize] || PAGE_DIMENSIONS.A4 : pageSize
  const [w, h] = Array.isArray(size) && size.length >= 2 ? size : PAGE_DIMENSIONS.A4
  return orientation === 'landscape' ? { width: h, height: w } : { width: w, height: h }
}

function getRowsPerPage(pageHeight, paddingPt) {
  const contentHeight = pageHeight - paddingPt * 2 - HEADER_BLOCK_PT - FOOTER_PT
  return Math.max(1, Math.floor(contentHeight / ROW_HEIGHT_PT))
}

function getPaddingForSize(width, height) {
  const minSide = Math.min(width, height)
  return Math.min(28, Math.max(18, Math.round(minSide * 0.035)))
}

const styles = StyleSheet.create({
  page: {
    fontSize: 9,
    fontFamily: 'Helvetica',
  },
  brandRow: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    marginBottom: 10,
  },
  logo: {
    backgroundColor: '#0f766e',
    color: '#ecfeff',
    paddingVertical: 4,
    paddingHorizontal: 10,
    borderRadius: 999,
    fontSize: 10,
    fontWeight: 'bold',
    letterSpacing: 0.5,
  },
  brandText: {
    fontSize: 10,
    color: '#6b7280',
  },
  header: {
    textAlign: 'center',
    marginBottom: 18,
  },
  title: {
    fontSize: 18,
    fontWeight: 'bold',
    marginBottom: 6,
  },
  subtitle: {
    fontSize: 10,
    color: '#6b7280',
    marginBottom: 4,
  },
  meta: {
    fontSize: 8,
    color: '#9ca3af',
    marginTop: 1,
  },
  table: {
    marginTop: 10,
    borderWidth: 1,
    borderColor: '#d1d5db',
    borderRadius: 4,
  },
  tableRow: {
    flexDirection: 'row',
    borderBottomWidth: 1,
    borderBottomColor: '#e5e7eb',
    minHeight: 20,
    alignItems: 'center',
  },
  tableRowLast: {
    borderBottomWidth: 0,
  },
  tableHeaderRow: {
    flexDirection: 'row',
    backgroundColor: '#f3f4f6',
    borderBottomWidth: 1,
    borderBottomColor: '#d1d5db',
    minHeight: 22,
    alignItems: 'center',
    fontWeight: 'bold',
  },
  tableCell: {
    paddingVertical: 4,
    paddingHorizontal: 6,
    fontSize: 8,
    borderRightWidth: 1,
    borderRightColor: '#e5e7eb',
  },
  tableCellLast: {
    borderRightWidth: 0,
  },
  tableHeaderCell: {
    paddingVertical: 4,
    paddingHorizontal: 6,
    fontSize: 8,
    fontWeight: 'bold',
    borderRightWidth: 1,
    borderRightColor: '#d1d5db',
  },
  tableHeaderCellLast: {
    borderRightWidth: 0,
  },
  noData: {
    padding: 16,
    textAlign: 'center',
    color: '#6b7280',
    fontSize: 9,
  },
  summaryBottomWrap: {
    marginTop: 16,
    flexDirection: 'row',
    justifyContent: 'flex-end',
  },
  summaryBottom: {
    borderWidth: 1,
    borderColor: '#d1d5db',
    borderRadius: 4,
    paddingVertical: 10,
    paddingHorizontal: 14,
    minWidth: 260,
    backgroundColor: '#f9fafb',
  },
  summaryBottomTitle: {
    fontSize: 11,
    fontWeight: 'bold',
    color: '#111827',
    marginBottom: 8,
    paddingBottom: 4,
    borderBottomWidth: 1,
    borderBottomColor: '#e5e7eb',
  },
  summaryBottomGrid: {
    marginTop: 6,
    flexDirection: 'row',
    flexWrap: 'wrap',
    justifyContent: 'space-between',
    gap: 6,
  },
  summaryCard: {
    flexGrow: 1,
    flexBasis: '45%',
    borderRadius: 6,
    backgroundColor: '#ffffff',
    paddingVertical: 6,
    paddingHorizontal: 8,
    borderWidth: 0.5,
    borderColor: '#e5e7eb',
  },
  summaryCardLabel: {
    fontSize: 8,
    color: '#6b7280',
    marginBottom: 2,
  },
  summaryCardValue: {
    fontSize: 13,
    fontWeight: 700,
    color: '#111827',
  },
  footer: {
    position: 'absolute',
    bottom: 20,
    textAlign: 'right',
    fontSize: 8,
    color: '#9ca3af',
  },
})

const numericColumnLabels = new Set([
  'Total Hours',
  'Total Hrs',
  'Late (min)',
  'Undertime (min)',
  'OT (min)',
  'Overtime (min)',
  'ND hrs',
  'ND pay (₱)',
  'OT pay (₱)',
  'Total premium (₱)',
  'Payroll Impact',
  'Payroll Impact (hrs)',
  'Total Late Count',
  'Total Late Minutes',
  'Total Undertime Count',
  'Half Day Count',
  'Absences Count',
  'Overtime Count',
  'Overtime Hours',
  'Attendance Rate %',
  'Total Hours Rendered',
  'Present Days',
  'Late Days',
  'Absences',
  'Half Days',
  'Undertime Days',
  'Leave Duration',
])

// Center-aligned: time columns, status columns, multiplier
const centerColumnLabels = new Set([
  'Time In',
  'Time Out',
  'Early Out',
  'Early Out Time',
  'Multiplier',
  'Status',
  'Leave Status',
  'Overtime Status',
  'OT Status',
])

// Flex proportions aligned with min-widths (narrow cols = smaller flex, wide = larger)
const columnFlexByLabel = {
  Employee: 1.4,
  Department: 1.3,
  Company: 1.4,
  Date: 1,
  Schedule: 1.1,
  'Time In': 0.9,
  'Time Out': 0.9,
  'Early Out': 0.9,
  'Early Out Time': 1,
  'Total Hours': 0.9,
  'Total Hrs': 0.9,
  'Late (min)': 0.8,
  'Undertime (min)': 1,
  'OT (min)': 0.8,
  'Overtime (min)': 1,
  'ND hrs': 0.7,
  'ND pay (₱)': 1,
  'OT pay (₱)': 1,
  'Total premium (₱)': 1.2,
  'Work Condition': 1.4,
  'Pay Rule': 1.1,
  Multiplier: 1.1,
  Status: 1,
  'Leave Type': 1.1,
  'Leave Status': 1,
  'Leave Duration': 0.9,
  'Payroll Impact': 1,
  'Payroll Impact (hrs)': 1.1,
  'Overtime Status': 1.1,
  'OT Status': 1.1,
}

function getColumnGroup(label) {
  if (
    label === 'Time In' ||
    label === 'Time Out' ||
    label === 'Early Out' ||
    label === 'Early Out Time' ||
    label === 'Total Hours' ||
    label === 'Total Hrs' ||
    label === 'Schedule'
  ) {
    return 'attendance'
  }
  if (
    label === 'Late (min)' ||
    label === 'Undertime (min)' ||
    label === 'OT (min)' ||
    label === 'Overtime (min)' ||
    label === 'ND hrs' ||
    label === 'ND pay (₱)' ||
    label === 'OT pay (₱)' ||
    label === 'Total premium (₱)' ||
    label === 'Payroll Impact' ||
    label === 'Payroll Impact (hrs)'
  ) {
    return 'deductions'
  }
  if (
    label === 'Overtime (min)' ||
    label === 'Overtime Status' ||
    label === 'Leave Type' ||
    label === 'Leave Status' ||
    label === 'Leave Duration' ||
    label === 'Work Condition' ||
    label === 'Pay Rule' ||
    label === 'Multiplier' ||
    label === 'Status'
  ) {
    return 'extras'
  }
  return null
}

function getHeaderBackgroundForLabel(label) {
  const group = getColumnGroup(label)
  if (group === 'attendance') return '#eff6ff' // blue-tinted
  if (group === 'deductions') return '#fef9c3' // soft yellow
  if (group === 'extras') return '#ecfdf5' // soft green
  return '#f3f4f6'
}

function getColumnFlex(label, col) {
  if (col && typeof col.minW === 'number') return col.minW / 100
  return columnFlexByLabel[label] || 1
}

function getCellAlign(label, col) {
  if (col && col.align) return col.align
  if (centerColumnLabels.has(label)) return 'center'
  return numericColumnLabels.has(label) ? 'right' : 'left'
}

function getStatusBadgeStyles(raw) {
  const base = {
    paddingVertical: 2,
    paddingHorizontal: 6,
    borderRadius: 999,
    fontSize: 7,
    fontWeight: 500,
    alignSelf: 'flex-start',
  }

  if (raw === 'Undertime (Unfiled)') {
    return {
      ...base,
      backgroundColor: '#fef3c7',
      color: '#92400e',
      borderWidth: 0.5,
      borderColor: '#fbbf24',
    }
  }

  if (raw === 'Undertime (Approved)') {
    return {
      ...base,
      backgroundColor: '#dcfce7',
      color: '#166534',
      borderWidth: 0.5,
      borderColor: '#4ade80',
    }
  }

  return base
}

function getCellValue(row, col) {
  const raw =
    typeof col.accessor === 'function' ? col.accessor(row) : row[col.accessor]
  const str = raw != null ? String(raw) : ''
  if (col.label === 'Department' && !str.trim()) return 'No Department Assigned'
  return str || '—'
}

function TableHeader({ columns, colCount, styles: s }) {
  return (
    <View style={s.tableHeaderRow}>
      {columns.map((col, i) => (
        <Text
          key={col.label}
          style={[
            s.tableHeaderCell,
            {
              flex: getColumnFlex(col.label, col),
              textAlign: getCellAlign(col.label, col),
              backgroundColor: getHeaderBackgroundForLabel(col.label),
            },
            i === colCount - 1 && s.tableHeaderCellLast,
          ]}
        >
          {col.label}
        </Text>
      ))}
    </View>
  )
}

function TableBodyRows({ rows, columns, colCount, styles: s, isLastChunk, chunkStartIndex }) {
  return rows.map((row, idx) => {
    const rowIndex = chunkStartIndex + idx
    const isLastRow = isLastChunk && rowIndex === chunkStartIndex + rows.length - 1
    return (
      <View
        key={row.employee_id ?? row.department ?? row.date ?? rowIndex}
        wrap={false}
        style={[
          s.tableRow,
          isLastRow && s.tableRowLast,
        ]}
      >
        {columns.map((col, i) => {
          const value = getCellValue(row, col)
          const group = getColumnGroup(col.label)
          const backgroundColor =
            group === 'attendance'
              ? '#f9fafb'
              : group === 'deductions'
                ? '#fffbeb'
                : group === 'extras'
                  ? '#ecfdf5'
                  : undefined

          const align = getCellAlign(col.label, col)
          const cellStyle = [
            s.tableCell,
            {
              flex: getColumnFlex(col.label, col),
              textAlign: align,
              backgroundColor,
              ...(align === 'right' && { fontVariant: ['tabular-nums'] }),
            },
            i === colCount - 1 && s.tableCellLast,
          ]

          if (col.label === 'Status' && (value || '') !== '—') {
            const badgeStyles = getStatusBadgeStyles(value)
            return (
              <Text key={col.label} style={cellStyle} numberOfLines={1}>
                <Text style={badgeStyles}>{value}</Text>
              </Text>
            )
          }

          return (
            <Text
              key={col.label}
              style={cellStyle}
              numberOfLines={1}
            >
              {value}
            </Text>
          )
        })}
      </View>
    )
  })
}

/**
 * PDF document for report export. Layout adapts to page size (A4, Letter, Legal, or custom).
 * For Detailed tab, pass orientation="landscape" so all columns fit; header repeats on every page.
 * @param {{
 *   title: string,
 *   period: string,
 *   rows: object[],
 *   columns: { label: string, accessor: string|function }[],
 *   generatedAt?: string,
 *   filtersSummary?: string,
 *   subtitle?: string,
 *   orientation?: 'portrait' | 'landscape',
 *   pageSize?: 'A4' | 'LETTER' | 'LEGAL' | [number, number],
 *   totalHoursRendered?: number | string,
 *   totalAbsences?: number | string,
 *   totalLates?: number | string,
 *   totalOvertime?: number | string
 * }} props
 */
export default function ReportPdfDocument({
  title,
  period,
  rows,
  columns,
  generatedAt,
  filtersSummary,
  subtitle,
  orientation = 'portrait',
  pageSize = 'A4',
  totalHoursRendered,
  totalAbsences,
  totalLates,
  totalOvertime,
}) {
  const colCount = columns.length
  const hasRows = rows && rows.length > 0
  const generatedLabel = generatedAt || new Date().toLocaleString()
  const effectiveSubtitle = subtitle || 'HR Attendance Summary'
  const { width: pageWidth, height: pageHeight } = getPageDimensions(pageSize, orientation)
  const paddingPt = getPaddingForSize(pageWidth, pageHeight)
  const rowsPerPage = getRowsPerPage(pageHeight, paddingPt)

  const pageStyle = [styles.page, { padding: paddingPt }]
  const footerStyle = [styles.footer, { left: paddingPt, right: paddingPt }]

  const sizeProp = typeof pageSize === 'string' && PAGE_DIMENSIONS[pageSize]
    ? pageSize
    : (Array.isArray(pageSize) && pageSize.length >= 2
        ? (orientation === 'landscape' ? [pageSize[1], pageSize[0]] : pageSize)
        : 'A4')

  // Paginate rows so we can repeat the table header on every page
  const pageChunks = hasRows
    ? Array.from(
        { length: Math.ceil(rows.length / rowsPerPage) },
        (_, p) => rows.slice(p * rowsPerPage, (p + 1) * rowsPerPage)
      )
    : [[]]

  return (
    <Document>
      {pageChunks.map((chunk, pageIndex) => (
        <Page
          key={pageIndex}
          size={sizeProp}
          orientation={orientation}
          style={pageStyle}
        >
          <View style={styles.brandRow}>
            <Text style={styles.logo}>SmartDTR</Text>
            <Text style={styles.brandText}>Smart Attendance & HR Insights</Text>
          </View>

          <View style={styles.header}>
            <Text style={styles.title}>{title}</Text>
            <Text style={styles.subtitle}>{effectiveSubtitle}</Text>
            <Text style={styles.meta}>Period: {period || '—'}</Text>
            {filtersSummary ? <Text style={styles.meta}>{filtersSummary}</Text> : null}
            <Text style={styles.meta}>
              Records: {rows?.length ?? 0}
              {pageChunks.length > 1 ? ` · Page ${pageIndex + 1} of ${pageChunks.length}` : ''}
            </Text>
            <Text style={styles.meta}>Generated at: {generatedLabel}</Text>
          </View>

          <View style={styles.table}>
            <TableHeader columns={columns} colCount={colCount} styles={styles} />
            {!hasRows ? (
              <View style={styles.tableRow} wrap={false}>
                <Text style={[styles.tableCell, { flex: colCount, textAlign: 'center' }]}>
                  No data for this period and filters.
                </Text>
              </View>
            ) : (
              <TableBodyRows
                rows={chunk}
                columns={columns}
                colCount={colCount}
                styles={styles}
                isLastChunk={pageIndex === pageChunks.length - 1}
                chunkStartIndex={pageIndex * rowsPerPage}
              />
            )}
          </View>

          {(pageIndex === pageChunks.length - 1) && (
            <View style={styles.summaryBottomWrap}>
              <View style={styles.summaryBottom}>
                <Text style={styles.summaryBottomTitle}>Summary</Text>
                <View style={styles.summaryBottomGrid}>
                  {totalHoursRendered != null && (
                    <View style={styles.summaryCard}>
                      <Text style={styles.summaryCardLabel}>Total Hours</Text>
                      <Text style={styles.summaryCardValue}>{String(totalHoursRendered)}</Text>
                    </View>
                  )}
                  {totalLates != null && (
                    <View style={styles.summaryCard}>
                      <Text style={styles.summaryCardLabel}>Total Lates</Text>
                      <Text style={styles.summaryCardValue}>{String(totalLates)}</Text>
                    </View>
                  )}
                  {totalAbsences != null && (
                    <View style={styles.summaryCard}>
                      <Text style={styles.summaryCardLabel}>Total Absences</Text>
                      <Text style={styles.summaryCardValue}>{String(totalAbsences)}</Text>
                    </View>
                  )}
                  {totalOvertime != null && (
                    <View style={styles.summaryCard}>
                      <Text style={styles.summaryCardLabel}>Total Overtime</Text>
                      <Text style={styles.summaryCardValue}>{String(totalOvertime)}</Text>
                    </View>
                  )}
                </View>
              </View>
            </View>
          )}

          <Text style={footerStyle}>
            Generated at {generatedLabel}
            {pageChunks.length > 1 ? ` · Page ${pageIndex + 1} of ${pageChunks.length}` : ''}
          </Text>
        </Page>
      ))}
    </Document>
  )
}
