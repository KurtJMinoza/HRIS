const path = require('path');
const { execSync } = require('child_process');
const { resolveBinary } = require('./scripts/resolve-binaries.cjs');

const root = __dirname;
const isWin = process.platform === 'win32';

/** Override with env: PHP_BIN, FACE_SERVICE_PYTHON, FACE_SERVICE_PORTS, NODE_BIN, OCTANE_HOST, OCTANE_PORT */
const php = resolveBinary('PHP_BIN', isWin ? 'C:\\xampp\\php\\php.exe' : 'php', ['php']);
const python = resolveBinary('FACE_SERVICE_PYTHON', isWin ? 'python' : 'python3', ['python', 'python3']);
const node = resolveBinary('NODE_BIN', 'node', ['node']);
const octaneHost = process.env.OCTANE_HOST || '127.0.0.1';
const octanePort = process.env.OCTANE_PORT || '8200';
const frontendVite = path.join(root, 'frontend', 'node_modules', 'vite', 'bin', 'vite.js');
const backendDir = path.join(root, 'backend');
const faceServiceDir = path.join(root, 'face_service');

// Match backend/.env FACE_VERIFICATION_URLS (2000-2004). Override with FACE_SERVICE_PORTS.
const faceServicePorts = (process.env.FACE_SERVICE_PORTS || '2000,2001,2002,2003,2004')
  .split(',')
  .map((p) => p.trim())
  .filter(Boolean);

// ponytail: vizion runs git on every restart and flashes cmd.exe on Windows; windowsHide keeps fork spawns headless
const pm2Defaults = {
  autorestart: true,
  max_restarts: 15,
  min_uptime: '5s',
  windowsHide: true,
  vizion: false,
};

function laravelApp(name, args) {
  return {
    name,
    cwd: backendDir,
    script: php,
    args,
    interpreter: 'none',
    ...pm2Defaults,
  };
}

function octaneApp() {
  if (isWin) {
    return laravelApp('laravel-octane', [
      'octane-start-windows.php',
      `--host=${octaneHost}`,
      `--port=${octanePort}`,
    ]);
  }

  return laravelApp('laravel-octane', [
    'artisan',
    'octane:start',
    '--server=roadrunner',
    `--host=${octaneHost}`,
    `--port=${octanePort}`,
  ]);
}

function faceServiceApp(port) {
  const shared = {
    name: `face-service-${port}`,
    windowsHide: true,
    vizion: false,
    autorestart: true,
    max_restarts: 10,
    min_uptime: '10s',
  };

  if (isWin) {
    return {
      ...shared,
      cwd: root,
      script: node,
      args: [path.join(root, 'scripts', 'face-service-start-windows.cjs'), `--port=${port}`],
      interpreter: 'none',
      env: {
        FACE_SERVICE_PYTHON: python,
        FACE_SERVICE_HOST: '127.0.0.1',
      },
    };
  }

  return {
    ...shared,
    cwd: faceServiceDir,
    script: python,
    args: ['-m', 'uvicorn', 'main:app', '--host', '127.0.0.1', '--port', String(port)],
    interpreter: 'none',
  };
}

module.exports = {
  apps: [
    octaneApp(),
    {
      name: 'frontend-dev',
      cwd: path.join(root, 'frontend'),
      script: frontendVite,
      interpreter: node,
      ...pm2Defaults,
    },
    ...faceServicePorts.map(faceServiceApp),
    laravelApp('queue-face-registration', [
      'artisan',
      'queue:work',
      'redis',
      '--queue=face-registration',
      '--timeout=180',
      '--sleep=1',
      '--tries=2',
    ]),
    laravelApp('queue-payroll', [
      'artisan',
      'queue:work',
      'redis',
      '--queue=payroll',
      '--timeout=300',
      '--sleep=1',
      '--tries=1',
      '--max-jobs=100',
    ]),
    // Bulk attendance-correction follow-up (log sync + daily payroll). Without this, approved
    // corrections stay unsynced forever — AttendanceCorrectionBulkFollowUpJob uses this queue.
    laravelApp('queue-attendance-corrections', [
      'artisan',
      'queue:work',
      'redis',
      '--queue=attendance-corrections',
      '--timeout=120',
      '--sleep=1',
      '--tries=1',
    ]),
    laravelApp('queue-payslip-pdf', [
      'artisan',
      'queue:work',
      'redis',
      '--queue=payslip-pdf',
      '--timeout=600',
      '--sleep=1',
      '--tries=2',
    ]),
    laravelApp('queue-emails', [
      'artisan',
      'queue:work',
      'redis',
      '--queue=emails',
      '--timeout=60',
      '--sleep=1',
      '--tries=3',
    ]),
    laravelApp('scheduler', ['artisan', 'schedule:work']),
  ],
};
