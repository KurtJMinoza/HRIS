/**
 * PM2 face-service launcher for Windows.
 *
 * Orphan uvicorn/python processes often keep port 2004 (and other face ports)
 * after `pm2 restart`, causing bind errors and crash loops. This script frees
 * the target port before starting uvicorn and forwards shutdown signals.
 */
const { spawn } = require('child_process');
const path = require('path');
const { resolveBinary } = require('./resolve-binaries.cjs');
const { freeListeningPort } = require('./free-listening-port.cjs');

const root = path.join(__dirname, '..');
const faceDir = path.join(root, 'face_service');
const isWin = process.platform === 'win32';
const host = process.env.FACE_SERVICE_HOST || '127.0.0.1';
const python = resolveBinary('FACE_SERVICE_PYTHON', isWin ? 'python' : 'python3', ['python', 'python3']);

function parsePort(argv) {
  for (const arg of argv) {
    if (arg.startsWith('--port=')) {
      return String(arg.split('=')[1] || '').trim();
    }
  }

  return String(process.env.FACE_SERVICE_PORT || '2000').trim();
}

function startUvicorn(port) {
  freeListeningPort(port, { host, label: 'face-service' });

  const child = spawn(
    python,
    ['-m', 'uvicorn', 'main:app', '--host', host, '--port', String(port)],
    {
      cwd: faceDir,
      stdio: 'inherit',
      windowsHide: true,
    },
  );

  const shutdown = (signal) => {
    if (!child.killed) {
      child.kill(signal);
    }
  };

  process.on('SIGINT', () => shutdown('SIGINT'));
  process.on('SIGTERM', () => shutdown('SIGTERM'));

  child.on('exit', (code, signal) => {
    if (signal) {
      process.exit(0);
    }
    process.exit(code ?? 1);
  });

  child.on('error', (error) => {
    console.error(`[face-service] failed to start uvicorn on port ${port}: ${error.message}`);
    process.exit(1);
  });
}

const port = parsePort(process.argv.slice(2));
if (!/^\d+$/.test(port)) {
  console.error(`[face-service] Invalid port: ${port}`);
  process.exit(1);
}

startUvicorn(port);
