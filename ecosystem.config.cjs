const path = require('path');

const root = __dirname;
const isWin = process.platform === 'win32';

/** Override with env: PHP_BIN, FACE_SERVICE_PYTHON, FACE_SERVICE_PORTS, NODE_BIN, OCTANE_HOST, OCTANE_PORT */
const php = process.env.PHP_BIN || (isWin ? 'C:\\xampp\\php\\php.exe' : 'php');
const python = process.env.FACE_SERVICE_PYTHON || 'python';
const node = process.env.NODE_BIN || 'node';
const octaneHost = process.env.OCTANE_HOST || '127.0.0.1';
const octanePort = process.env.OCTANE_PORT || '8000';
const frontendVite = path.join(root, 'frontend', 'node_modules', 'vite', 'bin', 'vite.js');
const backendDir = path.join(root, 'backend');
const faceServiceDir = path.join(root, 'face_service');

const faceServicePorts = (process.env.FACE_SERVICE_PORTS || '5000,5002,5003,5004,5005')
  .split(',')
  .map((p) => p.trim())
  .filter(Boolean);

const pm2Defaults = {
  autorestart: true,
  max_restarts: 15,
  min_uptime: '5s',
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

function faceServiceApp(port) {
  return {
    name: `face-service-${port}`,
    cwd: faceServiceDir,
    script: python,
    args: `-m uvicorn main:app --host 127.0.0.1 --port ${port}`,
    interpreter: 'none',
    autorestart: true,
    max_restarts: 10,
    min_uptime: '10s',
  };
}

module.exports = {
  apps: [
    laravelApp(
      'octane',
      `artisan octane:start --server=roadrunner --host=${octaneHost} --port=${octanePort}`,
    ),
    {
      name: 'frontend-dev',
      cwd: path.join(root, 'frontend'),
      script: frontendVite,
      interpreter: node,
      ...pm2Defaults,
    },
    ...faceServicePorts.map(faceServiceApp),
    laravelApp(
      'queue-face-registration',
      'artisan queue:work redis --queue=face-registration --timeout=180 --sleep=1 --tries=2',
    ),
    laravelApp(
      'queue-payroll',
      'artisan queue:work redis --queue=payroll --timeout=300 --sleep=1 --tries=1 --max-jobs=100',
    ),
    laravelApp('scheduler', 'artisan schedule:work'),
  ],
};
