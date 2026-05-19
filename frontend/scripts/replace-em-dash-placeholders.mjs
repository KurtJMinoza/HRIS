/**
 * Replace Unicode em dash (U+2014) placeholders with ASCII-safe output:
 * - Standalone JS strings that were only an em dash become ''
 * - Any remaining em dash in source becomes ASCII hyphen '-'
 *
 * Run from repo root: node frontend/scripts/replace-em-dash-placeholders.mjs
 */
import fs from 'node:fs'
import path from 'node:path'

const EM = '\u2014'
const SRC = path.resolve('frontend/src')

function walk(dir) {
  const entries = fs.readdirSync(dir, { withFileTypes: true })
  for (const ent of entries) {
    const p = path.join(dir, ent.name)
    if (ent.isDirectory()) walk(p)
    else if (/\.(jsx?|tsx?)$/.test(ent.name)) {
      let s = fs.readFileSync(p, 'utf8')
      const orig = s
      s = s.split(`'${EM}'`).join(`''`)
      s = s.split(`"${EM}"`).join(`""`)
      s = s.split(EM).join('-')
      if (s !== orig) {
        fs.writeFileSync(p, s, 'utf8')
        console.log('updated', path.relative(process.cwd(), p))
      }
    }
  }
}

walk(SRC)
