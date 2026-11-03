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

execSync('node ./scripts/ensure-redis-windows.cjs', { cwd: root, stdio: 'inherit' })
execSync('node ./scripts/pm2.cjs restart ecosystem.config.cjs --update-env', {
  cwd: root,
  stdio: 'inherit',
})
