import { Document, Image, Page, StyleSheet, Text, View } from '@react-pdf/renderer'
import { displayCompanyAddress, displayCompanyTin } from '@/lib/payslipCompanyDisplay'

const styles = StyleSheet.create({
  page: {
    paddingTop: 14,
    paddingRight: 14,
    paddingBottom: 14,
    paddingLeft: 14,
    fontFamily: 'Helvetica',
    fontSize: 8.9,
    color: '#0A0A0A',
    backgroundColor: '#F8FAFC',
  },
  sheet: {
    borderWidth: 1,
    borderColor: '#D8E0EA',
    borderRadius: 14,
    paddingTop: 14,
    paddingRight: 14,
    paddingBottom: 14,
    paddingLeft: 14,
    backgroundColor: '#FFFFFF',
  },
  header: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'flex-start',
    borderBottomWidth: 1,
    borderBottomColor: '#D8E0EA',
    paddingBottom: 12,
    marginBottom: 12,
  },
  brand: {
    flexDirection: 'row',
    alignItems: 'flex-start',
    flexGrow: 1,
    paddingRight: 8,
  },
  logo: {
    width: 50,
    height: 50,
    borderWidth: 1,
    borderColor: '#C7D2DE',
    borderRadius: 999,
    objectFit: 'contain',
    backgroundColor: '#FFFFFF',
    marginRight: 10,
  },
  logoPlaceholder: {
    width: 50,
    height: 50,
    borderWidth: 1,
    borderColor: '#C7D2DE',
    borderRadius: 999,
    backgroundColor: '#FFFFFF',
    marginRight: 10,
  },
  companyBlock: { flexGrow: 1 },
  title: { fontSize: 19, fontWeight: 800, lineHeight: 1.12 },
  subline: { marginTop: 2, fontSize: 8.2, lineHeight: 1.25, color: '#4B5563' },
  right: { width: 150, alignItems: 'flex-end' },
  badge: {
    borderWidth: 1,
    borderColor: '#C6E1FF',
    backgroundColor: '#EFF6FF',
    color: '#1D4ED8',
    borderRadius: 999,
    paddingVertical: 4,
    paddingHorizontal: 10,
    fontSize: 8.6,
    fontWeight: 700,
    textTransform: 'uppercase',
    letterSpacing: 0.8,
  },
  metaLine: { marginTop: 4, fontSize: 8.2, lineHeight: 1.2, color: '#4B5563', textAlign: 'right' },
  metaBar: {
    flexDirection: 'row',
    gap: 8,
    marginTop: 10,
    paddingVertical: 8,
    paddingHorizontal: 10,
    borderWidth: 1,
    borderColor: '#E2E8F0',
    backgroundColor: '#F8FAFC',
    borderRadius: 10,
  },
  metaItem: { flexGrow: 1 },
  metaLabel: { fontSize: 7.2, fontWeight: 700, letterSpacing: 0.8, textTransform: 'uppercase', color: '#64748B' },
  metaValue: { marginTop: 2, fontSize: 9.2, fontWeight: 700, color: '#0A0A0A', lineHeight: 1.15 },
  sectionRow: { flexDirection: 'row', marginTop: 8 },
  sectionGap: { width: 8 },
  card: {
    flexGrow: 1,
    borderWidth: 1,
    borderColor: '#D8E0EA',
    borderRadius: 9,
    paddingTop: 8,
    paddingRight: 9,
    paddingBottom: 8,
    paddingLeft: 9,
    backgroundColor: '#FFFFFF',
  },
  cardTitle: {
    fontSize: 10,
    color: '#111827',
    textTransform: 'uppercase',
    letterSpacing: 0.65,
    marginBottom: 6,
    fontWeight: 700,
  },
  line: { marginBottom: 3, fontSize: 9, lineHeight: 1.28, color: '#111827', fontWeight: 600 },
  employeeHeroName: { fontSize: 12.8, fontWeight: 800, color: '#0A0A0A', lineHeight: 1.15, marginBottom: 4 },
  employeeHeroSub: { fontSize: 8.8, color: '#475569', lineHeight: 1.2, fontWeight: 500, marginBottom: 2 },
  employeeHeroLabel: { fontSize: 7.2, color: '#64748B', fontWeight: 700, letterSpacing: 0.7, textTransform: 'uppercase' },
  tableCard: {
    flexGrow: 1,
    borderWidth: 1,
    borderColor: '#D8E0EA',
    borderRadius: 9,
    overflow: 'hidden',
  },
  sectionHeadRow: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: 7,
    paddingHorizontal: 9,
    borderBottomWidth: 1,
    borderBottomColor: '#D8E0EA',
  },
  sectionAccentEarn: { width: 3, height: 14, backgroundColor: '#16A34A', borderRadius: 2, marginRight: 7 },
  sectionAccentDed: { width: 3, height: 14, backgroundColor: '#DC2626', borderRadius: 2, marginRight: 7 },
  sectionHeadText: { fontSize: 10.5, fontWeight: 800, textTransform: 'uppercase', letterSpacing: 0.7, color: '#0A0A0A' },
  sectionHeadEarnBg: { backgroundColor: '#F0FDF4' },
  sectionHeadDedBg: { backgroundColor: '#FEF2F2' },
  thRow: {
    flexDirection: 'row',
    borderBottomWidth: 1,
    borderBottomColor: '#D8E0EA',
    paddingVertical: 5,
    paddingHorizontal: 9,
    backgroundColor: '#FAFAFA',
  },
  thDesc: { width: '52%', fontSize: 8, fontWeight: 700, color: '#64748B', textTransform: 'uppercase', letterSpacing: 0.4 },
  thUnits: { width: '18%', fontSize: 8, fontWeight: 700, color: '#64748B', textTransform: 'uppercase', textAlign: 'center', letterSpacing: 0.4 },
  thAmount: { width: '30%', fontSize: 8, fontWeight: 700, color: '#64748B', textTransform: 'uppercase', textAlign: 'right', letterSpacing: 0.4 },
  row: {
    flexDirection: 'row',
    borderBottomWidth: 1,
    borderBottomColor: '#EEF2F7',
    paddingVertical: 5,
    paddingHorizontal: 9,
    fontSize: 8.8,
    lineHeight: 1.45,
  },
  cellDesc: { width: '52%' },
  cellUnits: { width: '18%', textAlign: 'center', color: '#64748B' },
  cellAmount: { width: '30%', textAlign: 'right', fontVariantNumeric: 'tabular-nums', fontWeight: 500 },
  cellTotal: { fontSize: 9.8, fontWeight: 700 },
  rowTotal: {
    borderTopWidth: 1.5,
    borderTopColor: '#CBD5E1',
    backgroundColor: '#F1F5F9',
  },
  statsRow: { flexDirection: 'row', marginTop: 9 },
  statsCard: {
    width: '31%',
    borderWidth: 1,
    borderColor: '#D8E0EA',
    borderRadius: 9,
    paddingTop: 8,
    paddingRight: 9,
    paddingBottom: 8,
    paddingLeft: 9,
  },
  netCard: {
    width: '38%',
    borderRadius: 12,
    backgroundColor: '#0A0A0A',
    color: '#FFFFFF',
    paddingTop: 12,
    paddingRight: 12,
    paddingBottom: 12,
    paddingLeft: 12,
  },
  netLabel: {
    color: '#FFFFFF',
    fontSize: 8,
    textTransform: 'uppercase',
    letterSpacing: 1.1,
    marginBottom: 5,
    fontWeight: 500,
    opacity: 0.88,
  },
  netValue: {
    color: '#FFFFFF',
    fontSize: 32,
    lineHeight: 1.05,
    fontWeight: 700,
    fontVariantNumeric: 'tabular-nums',
  },
  netPeriod: { color: '#FFFFFF', fontSize: 8.4, lineHeight: 1.25, opacity: 0.72, marginTop: 5 },
  signRow: { flexDirection: 'row', marginTop: 11 },
  signCol: { width: '50%' },
  signLine: { height: 22, borderBottomWidth: 1, borderBottomColor: '#4B5563' },
  foot: {
    marginTop: 10,
    textAlign: 'center',
    color: '#6B7280',
    fontSize: 7.5,
    lineHeight: 1.2,
  },
  holidayNote: { fontSize: 6.5, lineHeight: 1.2, color: '#94A3B8', marginBottom: 1.2 },
})

function peso(value) {
  const n = Number(value)
  if (!Number.isFinite(n)) return 'PHP 0.00'
  return `PHP ${n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`
}

function dateValue(value) {
  if (!value) return '—'
  const d = new Date(value)
  if (Number.isNaN(d.getTime())) return String(value)
  return d.toLocaleDateString(undefined, { month: 'short', day: '2-digit', year: 'numeric' })
}

function periodLabel(start, end) {
  return `${dateValue(start)} - ${dateValue(end)}`
}

export default function PayslipPdfDocument({ data }) {
  const earnLines = Array.isArray(data?.summary?.payslip_earning_lines) ? data.summary.payslip_earning_lines : []
  const dailyEarnLines = Array.isArray(data?.summary?.daily_computation_earning_lines) ? data.summary.daily_computation_earning_lines : []
  const holidayBreakdownRows = (Array.isArray(data?.summary?.holiday_premium_breakdown) ? data.summary.holiday_premium_breakdown : []).filter(
    (row) => Number(row?.amount || 0) > 0,
  )
  const govDeductionLines = Array.isArray(data?.summary?.payslip_deduction_lines) ? data.summary.payslip_deduction_lines : []
  const customDeductionLines = Array.isArray(data?.summary?.payslip_custom_deduction_lines) ? data.summary.payslip_custom_deduction_lines : []
  const hasWithholdingLine = [...govDeductionLines, ...customDeductionLines].some((line) =>
    String(line?.key || line?.label || '').toLowerCase().includes('withholding')
  )
  const logoCandidate = data?.company?.logo_url || data?.company?.logo || null

  const displayDailyEarnLines = dailyEarnLines.length > 0
    ? dailyEarnLines
    : [
        ...(Number(data?.summary?.basic_pay || 0) > 0
          ? [{ key: 'fallback:regular_pay', label: 'Regular pay', amount: Number(data?.summary?.basic_pay || 0) }]
          : []),
        ...(Number(data?.summary?.attendance_premium_pay_this_period || 0) > 0
          ? [{
              key: 'fallback:attendance_premium',
              label: 'Attendance premiums (OT/ND/Holiday)',
              amount: Number(data?.summary?.attendance_premium_pay_this_period || 0),
            }]
          : []),
      ]

  return (
    <Document title={`Payslip - ${data?.employee?.name || 'Employee'}`}>
      <Page size="A4" style={styles.page}>
        <View style={styles.sheet}>
          <View style={styles.header}>
            <View style={styles.brand}>
              {logoCandidate ? <Image src={logoCandidate} style={styles.logo} /> : <View style={styles.logoPlaceholder} />}
              <View style={styles.companyBlock}>
                <Text style={styles.title}>{data?.company?.name || 'Company'}</Text>
                <Text style={styles.subline}>Company address: {displayCompanyAddress(data?.company?.address)}</Text>
                <Text style={styles.subline}>Company TIN: {displayCompanyTin(data?.company?.tin)}</Text>
              </View>
            </View>
            <View style={styles.right}>
              <Text style={styles.badge}>Official Payslip</Text>
              <Text style={styles.metaLine}>Pay period: {periodLabel(data?.payroll?.pay_period_start, data?.payroll?.pay_period_end)}</Text>
              <Text style={styles.metaLine}>Pay date: {dateValue(data?.payroll?.pay_date)}</Text>
              <Text style={styles.metaLine}>Cycle: {data?.payroll?.cycle_label || '—'}</Text>
              <Text style={styles.metaLine}>Daily rate: {peso(data?.payroll?.daily_rate || data?.summary?.daily_rate || 0)}</Text>
            </View>
          </View>

          <View style={styles.metaBar}>
            <View style={styles.metaItem}>
              <Text style={styles.metaLabel}>Pay period</Text>
              <Text style={styles.metaValue}>{periodLabel(data?.payroll?.pay_period_start, data?.payroll?.pay_period_end)}</Text>
            </View>
            <View style={styles.metaItem}>
              <Text style={styles.metaLabel}>Pay date</Text>
              <Text style={styles.metaValue}>{dateValue(data?.payroll?.pay_date)}</Text>
            </View>
            <View style={styles.metaItem}>
              <Text style={styles.metaLabel}>Daily rate</Text>
              <Text style={styles.metaValue}>{peso(data?.payroll?.daily_rate || data?.summary?.daily_rate || 0)}</Text>
            </View>
          </View>

          <View style={styles.sectionRow}>
            <View style={styles.card}>
              <Text style={styles.employeeHeroLabel}>Employee</Text>
              <Text style={styles.employeeHeroName}>{data?.employee?.name || '—'}</Text>
              <Text style={styles.employeeHeroSub}>{(data?.employee?.position?.trim() || '—')}</Text>
              <Text style={styles.employeeHeroSub}>{data?.employee?.department || '—'}</Text>
              <Text style={[styles.employeeHeroSub, { marginTop: 4 }]}>Employee ID: {data?.employee?.employee_code || '—'}</Text>
              <Text style={styles.employeeHeroSub}>Employment status: {data?.employee?.employment_status_label?.trim() || '—'}</Text>
            </View>
            <View style={styles.sectionGap} />
            <View style={styles.card}>
              <Text style={styles.cardTitle}>Government IDs</Text>
              <Text style={styles.line}>TIN: {data?.employee?.tin_number || 'Not set'}</Text>
              <Text style={styles.line}>SSS: {data?.employee?.sss_number || 'Not set'}</Text>
              <Text style={styles.line}>PhilHealth: {data?.employee?.philhealth_number || 'Not set'}</Text>
              <Text style={styles.line}>Pag-IBIG: {data?.employee?.pagibig_number || 'Not set'}</Text>
            </View>
          </View>

          <View style={styles.sectionRow}>
            <View style={styles.tableCard}>
              <View style={[styles.sectionHeadRow, styles.sectionHeadEarnBg]}>
                <View style={styles.sectionAccentEarn} />
                <Text style={styles.sectionHeadText}>Earnings</Text>
              </View>
              <View style={styles.thRow}>
                <Text style={styles.thDesc}>Description</Text>
                <Text style={styles.thUnits}>Units</Text>
                <Text style={styles.thAmount}>Amount (PHP)</Text>
              </View>
              {displayDailyEarnLines.map((line, idx) => {
                const isHolidayPremium = String(line?.label || '').trim().toLowerCase() === 'holiday premium'
                return (
                  <View key={`dearn-${idx}`}>
                    <View style={styles.row}>
                      <Text style={styles.cellDesc}>{line?.label || 'Daily computation earning'}</Text>
                      <Text style={styles.cellUnits}>{line?.units || '—'}</Text>
                      <Text style={styles.cellAmount}>{peso(line?.amount || 0)}</Text>
                    </View>
                    {isHolidayPremium && holidayBreakdownRows.length > 0 ? (
                      <View style={{ paddingHorizontal: 9, paddingBottom: 4 }}>
                        {holidayBreakdownRows.map((row, ri) => {
                          const name = String(row?.holiday_name || 'Holiday').trim()
                          const amount = Number(row?.amount || 0)
                          const d = row?.date ? new Date(row.date) : null
                          const dt = d && !Number.isNaN(d.getTime())
                            ? d.toLocaleDateString(undefined, { month: 'short', day: '2-digit' })
                            : String(row?.date || '')
                          return (
                            <Text key={`hpd-${ri}`} style={styles.holidayNote}>
                              {`${name} (${dt}): ${peso(amount)}`}
                            </Text>
                          )
                        })}
                      </View>
                    ) : null}
                  </View>
                )
              })}
              {earnLines.map((line, idx) => (
                <View key={`earn-${idx}`} style={styles.row}>
                  <Text style={styles.cellDesc}>{line?.label || 'Earning'}</Text>
                  <Text style={styles.cellUnits}>—</Text>
                  <Text style={styles.cellAmount}>{peso(line?.amount || 0)}</Text>
                </View>
              ))}
              {displayDailyEarnLines.length === 0 && earnLines.length === 0 ? (
                <View style={styles.row}>
                  <Text style={styles.cellDesc}>No earnings computed.</Text>
                  <Text style={styles.cellUnits}>—</Text>
                  <Text style={styles.cellAmount}>—</Text>
                </View>
              ) : null}
              <View style={[styles.row, styles.rowTotal]}>
                <Text style={[styles.cellDesc, styles.cellTotal]}>Gross pay</Text>
                <Text style={styles.cellUnits}> </Text>
                <Text style={[styles.cellAmount, styles.cellTotal]}>{peso(data?.amounts?.gross_pay)}</Text>
              </View>
            </View>

            <View style={styles.sectionGap} />

            <View style={styles.tableCard}>
              <View style={[styles.sectionHeadRow, styles.sectionHeadDedBg]}>
                <View style={styles.sectionAccentDed} />
                <Text style={styles.sectionHeadText}>Deductions</Text>
              </View>
              <View style={styles.thRow}>
                <Text style={[styles.thDesc, { width: '66%' }]}>Description</Text>
                <Text style={[styles.thAmount, { width: '34%' }]}>Amount (PHP)</Text>
              </View>
              {govDeductionLines.map((line, idx) => (
                <View key={`gd-${idx}`} style={styles.row}>
                  <Text style={[styles.cellDesc, { width: '66%' }]}>{line?.label || 'Deduction'}</Text>
                  <Text style={[styles.cellAmount, { width: '34%' }]}>{peso(line?.amount || 0)}</Text>
                </View>
              ))}
              {customDeductionLines.map((line, idx) => (
                <View key={`cd-${idx}`} style={styles.row}>
                  <Text style={[styles.cellDesc, { width: '66%' }]}>{line?.label || 'Custom deduction'}</Text>
                  <Text style={[styles.cellAmount, { width: '34%' }]}>{peso(line?.amount || 0)}</Text>
                </View>
              ))}
              {!hasWithholdingLine ? (
                <View style={styles.row}>
                  <Text style={[styles.cellDesc, { width: '66%' }]}>Withholding tax</Text>
                  <Text style={[styles.cellAmount, { width: '34%' }]}>{peso(data?.summary?.withholding_tax_this_period_estimate)}</Text>
                </View>
              ) : null}
              {govDeductionLines.length === 0 && customDeductionLines.length === 0 ? (
                <View style={styles.row}>
                  <Text style={[styles.cellDesc, { width: '66%' }]}>No additional deductions computed.</Text>
                  <Text style={[styles.cellAmount, { width: '34%' }]}>—</Text>
                </View>
              ) : null}
              <View style={[styles.row, styles.rowTotal]}>
                <Text style={[styles.cellDesc, { width: '66%' }, styles.cellTotal]}>Total deductions</Text>
                <Text style={[styles.cellAmount, { width: '34%' }, styles.cellTotal]}>{peso(data?.amounts?.total_deductions)}</Text>
              </View>
            </View>
          </View>

          <View style={styles.statsRow}>
            <View style={styles.statsCard}>
              <Text style={styles.cardTitle}>Tax summary</Text>
              <Text style={styles.line}>Taxable: {peso(data?.amounts?.taxable_total_this_period || 0)}</Text>
              <Text style={styles.line}>Non-taxable: {peso(data?.amounts?.non_taxable_total_this_period || 0)}</Text>
            </View>
            <View style={{ width: 8 }} />
            <View style={styles.statsCard}>
              <Text style={styles.cardTitle}>YTD balances</Text>
              <Text style={styles.line}>Gross: {peso(data?.amounts?.ytd_gross || 0)}</Text>
              <Text style={styles.line}>Deductions: {peso(data?.amounts?.ytd_deductions || 0)}</Text>
              <Text style={styles.line}>Tax withheld: {peso(data?.amounts?.ytd_tax || 0)}</Text>
            </View>
            <View style={{ width: 8 }} />
            <View style={styles.netCard}>
              <Text style={styles.netLabel}>Net Take-Home Pay</Text>
              <Text style={styles.netValue}>{peso(data?.amounts?.net_pay)}</Text>
              <Text style={styles.netPeriod}>For the period {periodLabel(data?.payroll?.pay_period_start, data?.payroll?.pay_period_end)}</Text>
            </View>
          </View>

          <View style={styles.signRow}>
            <View style={styles.signCol}>
              <View style={styles.signLine} />
              <Text style={[styles.line, { marginTop: 3, color: '#4B5563' }]}>Company Authorized</Text>
            </View>
            <View style={styles.signCol}>
              <View style={styles.signLine} />
              <Text style={[styles.line, { marginTop: 3, color: '#4B5563' }]}>Employee Acknowledged</Text>
            </View>
          </View>

          <Text style={styles.foot}>
            THIS IS A SYSTEM-GENERATED DOCUMENT. GENERATED {new Date().toLocaleString()}. NO SIGNATURE IS REQUIRED UNLESS MANUALLY REQUESTED.
          </Text>
        </View>
      </Page>
    </Document>
  )
}
