/**
 * Replace U+FFFD (replacement character) in AdminFinalizePayrollPage.jsx with ASCII-safe text.
 */
import fs from 'node:fs'
import path from 'node:path'

const FFFD = '\uFFFD'
const file = path.resolve('frontend/src/pages/AdminFinalizePayrollPage.jsx')
let text = fs.readFileSync(file, 'utf8')
const before = (text.match(/\uFFFD/g) || []).length

const pairs = [
  ['considered \uFFFDdone\uFFFD', 'considered done'],
  ["return '\uFFFD'", "return '-'"],
  ["    '\uFFFD'", "    '-'"],
  ['token) \uFFFD resets', 'token) - resets'],
  ['computation \uFFFD then', 'computation - then'],
  ['draft\uFFFD', 'draft...'],
  ["|| '\uFFFD'", "|| '-'"],
  ['employee\uFFFD"', 'employee..."'],
  ["!== '\uFFFD'", "!== '-'"],
  ['pageCount} \uFFFD {', 'pageCount} - {'],
  ['verified \uFFFD Deductions correct \uFFFD No', 'verified / Deductions correct / No'],
  ['payroll\uFFFD', 'payroll...'],
  ["\uFFFD{' '}", "-{' '}"],
  ["join(' \uFFFD ')", "join(' / ')"],
]

for (const [from, to] of pairs) {
  text = text.split(from).join(to)
}
text = text.split(FFFD).join('-')

fs.writeFileSync(file, text, 'utf8')
const after = (text.match(/\uFFFD/g) || []).length
console.log(`fixed ${before - after} replacement chars (${after} remaining)`)
