const { execSync } = require('child_process')
const fs = require('fs')
const path = require('path')

const root = path.join(__dirname, '..')
const viteCache = path.join(root, 'frontend', 'node_modules', '.vite')

try {
  fs.rmSync(viteCache, { recursive: true, force: true })
} catch {
  // best-effort
}

execSync('node ./node_modules/pm2/bin/pm2 restart ecosystem.config.cjs --update-env', {
  cwd: root,
  stdio: 'inherit',
})
