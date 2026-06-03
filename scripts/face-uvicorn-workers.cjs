/**
 * PM2 "uvicorn" app: extra face-service instances (ports 5001–5003 by default).
 * Primary instance runs as the "face-service" app on port 5000.
 */
const { spawn } = require('child_process');
const path = require('path');

const faceDir = path.join(__dirname, '..', 'face_service');
const python = process.env.FACE_SERVICE_PYTHON || 'python';
const ports = (process.env.FACE_SERVICE_EXTRA_PORTS || '5001,5002,5003')
  .split(',')
  .map((p) => p.trim())
  .filter(Boolean);

if (ports.length === 0) {
  console.error('[uvicorn] No ports configured (FACE_SERVICE_EXTRA_PORTS).');
  process.exit(1);
}

const children = [];

function startWorker(port) {
  const child = spawn(
    python,
    ['-m', 'uvicorn', 'main:app', '--host', '127.0.0.1', '--port', port],
    { cwd: faceDir, stdio: 'inherit', shell: isWinShell() },
  );

  child.on('exit', (code, signal) => {
    console.error(`[uvicorn] worker :${port} exited (code=${code}, signal=${signal})`);
    shutdown(1);
  });

  children.push(child);
  console.log(`[uvicorn] started face service on http://127.0.0.1:${port}`);
}

function isWinShell() {
  return process.platform === 'win32';
}

function shutdown(exitCode = 0) {
  for (const child of children) {
    if (!child.killed) {
      child.kill('SIGTERM');
    }
  }
  setTimeout(() => process.exit(exitCode), 500).unref();
}

for (const port of ports) {
  startWorker(port);
}

process.on('SIGINT', () => shutdown(0));
process.on('SIGTERM', () => shutdown(0));
