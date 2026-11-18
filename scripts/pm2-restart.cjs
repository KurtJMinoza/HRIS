const { execSync } = require('child_process')
const fs = require('fs')
const path = require('path')

const root = path.join(__dirname, '..')
const viteCache = path.join(root, 'frontend', 'node_modules', '.vite')
const { freeListeningPort } = require('./free-listening-port.cjs')
const frontendDevPort = process.env.FRONTEND_DEV_PORT || '5173'
const octanePort = process.env.OCTANE_PORT || '8000'

try {
  fs.rmSync(viteCache, { recursive: true, force: true })
} catch {
  // best-effort
}

execSync('node ./scripts/ensure-redis-windows.cjs', { cwd: root, stdio: 'inherit' })

if (process.platform === 'win32') {
  freeListeningPort(frontendDevPort, { label: 'pm2-restart' })
  freeListeningPort(octanePort, { label: 'pm2-restart' })
}

const pm2 = 'node ./scripts/pm2.cjs'
const waitScript = path.join(__dirname, 'wait-for-backend.cjs')

const otherApps = [
  'face-service-2000',
  'face-service-2001',
  'face-service-2002',
  'face-service-2003',
  'face-service-2004',
  'queue-face-registration',
  'queue-payroll',
  'queue-attendance-corrections',
  'queue-payslip-pdf',
  'queue-emails',
  'scheduler',
]

function run(cmd) {
  execSync(cmd, { cwd: root, stdio: 'inherit' })
}

function orderedRestart() {
  // Octane first so Vite never proxies to a dead listener during restart.
  run(`${pm2} restart laravel-octane --update-env`)
  run(`node "${waitScript}"`)

  run(`${pm2} restart frontend-dev --update-env`)

  const batch = otherApps.join(' ')
  if (batch) {
    run(`${pm2} restart ${batch} --update-env`)
  }
}

try {
  orderedRestart()
} catch {
  // Recover when frontend-dev is stuck errored after a config change.
  try {
    execSync(`${pm2} delete frontend-dev`, { cwd: root, stdio: 'ignore' })
  } catch {
    // already removed
  }
  execSync(`${pm2} start ecosystem.config.cjs --update-env`, { cwd: root, stdio: 'inherit' })
  try {
    run(`node "${waitScript}"`)
  } catch {
    // Octane may still be booting; frontend launcher waits as well.
  }
}
