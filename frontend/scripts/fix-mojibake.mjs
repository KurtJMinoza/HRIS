/**
 * Fix UTF-8 mojibake in frontend sources (run from repo root).
 * node frontend/scripts/fix-mojibake.mjs [path]
 */
import fs from 'node:fs'
import path from 'node:path'

const EM = '\u2014'
const EN = '\u2013'
const ELL = '\u2026'
const BULLET = '\u00b7'

/** â + € + wrong third char (common when em dash bytes were mis-decoded). */
const REPLACEMENTS = [
  ['\u00e2\u20ac\u201d', EM],
  ['\u00e2\u20ac\u201c', EM],
  ['\u00e2\u20ac\u2013', EN],
  ['\u00e2\u20ac\u2014', EM],
  ['\u00e2\u20ac\u00a6', ELL],
  ['\u00e2\u20ac\u00a2', BULLET],
]

function fixFile(filePath) {
  let s = fs.readFileSync(filePath, 'utf8')
  let total = 0
  for (const [from, to] of REPLACEMENTS) {
    const parts = s.split(from)
    if (parts.length > 1) {
      total += parts.length - 1
      s = parts.join(to)
    }
  }
  if (total > 0) {
    fs.writeFileSync(filePath, s, 'utf8')
    console.log(`fixed ${total} in ${path.relative(process.cwd(), filePath)}`)
  }
  return total
}

function walk(dir) {
  let total = 0
  for (const ent of fs.readdirSync(dir, { withFileTypes: true })) {
    const p = path.join(dir, ent.name)
    if (ent.isDirectory()) total += walk(p)
    else if (/\.(jsx?|tsx?|css)$/.test(ent.name)) total += fixFile(p)
  }
  return total
}

const arg = process.argv[2]
const total = arg != null ? fixFile(path.resolve(arg)) : walk(path.resolve('frontend/src'))
console.log(`total mojibake fixes: ${total}`)
