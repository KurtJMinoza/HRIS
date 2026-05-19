/**
 * Strip UTF-8 em dash from PHP sources (API JSON + placeholders).
 * Run from repo root: node frontend/scripts/replace-em-dash-php.mjs
 */
import fs from 'node:fs'
import path from 'node:path'

const EM = '\u2014'
const ROOT = path.resolve('backend')

function walk(dir) {
  const entries = fs.readdirSync(dir, { withFileTypes: true })
  for (const ent of entries) {
    if (
      ent.name === 'vendor' ||
      ent.name === 'node_modules' ||
      ent.name === 'storage' ||
      ent.name === '.git'
    )
      continue
    const p = path.join(dir, ent.name)
    if (ent.isDirectory()) walk(p)
    else if (ent.name.endsWith('.php')) {
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

walk(ROOT)
