const path = require('path');

const root = __dirname;
const isWin = process.platform === 'win32';

/** Override with env: PHP_BIN, FACE_SERVICE_PYTHON, FACE_SERVICE_EXTRA_PORTS */
const php = process.env.PHP_BIN || (isWin ? 'C:\\xampp\\php\\php.exe' : 'php');
const python = process.env.FACE_SERVICE_PYTHON || 'python';
const node = process.env.NODE_BIN || 'node';
const frontendVite = path.join(root, 'frontend', 'node_modules', 'vite', 'bin', 'vite.js');

module.exports = {
  apps: [
    {
      name: 'backend',
      cwd: path.join(root, 'backend'),
      script: php,
      args: 'artisan serve --host=127.0.0.1 --port=8000',
      interpreter: 'none',
      autorestart: true,
      max_restarts: 15,
      min_uptime: '5s',
    },
    {
      name: 'frontend',
      cwd: path.join(root, 'frontend'),
      script: frontendVite,
      interpreter: node,
      autorestart: true,
      max_restarts: 15,
      min_uptime: '5s',
    },
    {
      name: 'face-service',
      cwd: path.join(root, 'face_service'),
      script: python,
      args: '-m uvicorn main:app --host 127.0.0.1 --port 5000',
      interpreter: 'none',
      autorestart: true,
      max_restarts: 10,
      min_uptime: '10s',
    },
    {
      name: 'uvicorn',
      cwd: root,
      script: path.join(root, 'scripts', 'face-uvicorn-workers.cjs'),
      interpreter: 'node',
      autorestart: true,
      max_restarts: 10,
      min_uptime: '10s',
    },
  ],
};
