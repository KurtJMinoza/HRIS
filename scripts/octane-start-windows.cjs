/**
 * PM2 laravel-octane launcher for Windows.
 *
 * Orphan rr.exe/php workers can keep port 8200 after restarts, causing bind failures
 * and brief windows where Vite proxies /api to ECONNREFUSED.
 */
const { spawn } = require('child_process');
const path = require('path');
const { resolveBinary } = require('./resolve-binaries.cjs');
const { freeListeningPort } = require('./free-listening-port.cjs');

const root = path.join(__dirname, '..');
const backendDir = path.join(root, 'backend');
const isWin = process.platform === 'win32';
const php = resolveBinary('PHP_BIN', isWin ? 'C:\\xampp\\php\\php.exe' : 'php', ['php']);
const host = process.env.OCTANE_HOST || '127.0.0.1';

function parsePort(argv) {
  for (const arg of argv) {
    if (arg.startsWith('--port=')) {
      return String(arg.split('=')[1] || '').trim();
    }
  }
  return String(process.env.OCTANE_PORT || '8200').trim();
}

function startOctane() {
  const forwardArgs = process.argv.slice(2);
  const port = parsePort(forwardArgs);

  freeListeningPort(port, { host, label: 'laravel-octane' });

  const child = spawn(php, ['octane-start-windows.php', ...forwardArgs], {
    cwd: backendDir,
    stdio: 'inherit',
    windowsHide: true,
  });

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
    console.error(`[laravel-octane] failed to start on port ${port}: ${error.message}`);
    process.exit(1);
  });
}

startOctane();
