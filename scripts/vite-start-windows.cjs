/**
 * PM2 frontend-dev launcher for Windows.
 *
 * Orphan node/vite processes (or other apps) can keep port 5100 after restarts.
 * This script frees the preferred port when possible, then picks the first open
 * port in the configured range before starting Vite.
 */
const { spawn } = require('child_process');
const path = require('path');
const { resolveBinary } = require('./resolve-binaries.cjs');
const { freeListeningPort, isPortInUse, listListeningPids } = require('./free-listening-port.cjs');
const { waitForBackend } = require('./wait-for-backend.cjs');
const { warmupViteDevServer } = require('./vite-dev-warmup.cjs');

const root = path.join(__dirname, '..');
const frontendDir = path.join(root, 'frontend');
const node = resolveBinary('NODE_BIN', 'node', ['node']);
const viteScript = path.join(frontendDir, 'node_modules', 'vite', 'bin', 'vite.js');

const preferredPort = Number.parseInt(process.env.FRONTEND_DEV_PORT || '5100', 10);
const portRangeEnd = Number.parseInt(process.env.FRONTEND_DEV_PORT_END || String(preferredPort + 10), 10);

function resolvePort() {
  for (let port = preferredPort; port <= portRangeEnd; port += 1) {
    freeListeningPort(port, { label: 'frontend-dev' });
    if (!isPortInUse(port)) {
      if (port !== preferredPort) {
        console.warn(
          `[frontend-dev] Port ${preferredPort} is unavailable; using ${port} instead.`,
        );
        const blocked = listListeningPids(preferredPort);
        if (blocked.length > 0) {
          console.warn(
            `[frontend-dev] Stop the blocking process(es) on ${preferredPort}: pid ${blocked.join(', ')}`,
          );
        }
      }
      return port;
    }
  }

  return null;
}

function startVite() {
  const port = resolvePort();
  if (!port) {
    console.error(
      `[frontend-dev] No open port found in range ${preferredPort}-${portRangeEnd}.`,
    );
    process.exit(1);
  }

  const child = spawn(
    node,
    [viteScript, '--port', String(port), '--strictPort'],
    {
      cwd: frontendDir,
      stdio: 'inherit',
      windowsHide: true,
      env: {
        ...process.env,
        FRONTEND_DEV_PORT: String(port),
      },
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
    console.error(`[frontend-dev] failed to start Vite on port ${port}: ${error.message}`);
    process.exit(1);
  });

  void warmupViteDevServer(port).catch((error) => {
    console.warn(`[frontend-dev] Vite warmup skipped: ${error.message}`);
  });
}

async function main() {
  const octanePort = process.env.OCTANE_PORT || '8200';
  try {
    await waitForBackend({
      port: octanePort,
      label: 'frontend-dev',
      timeoutMs: 120_000,
    });
  } catch (error) {
    console.warn(
      `[frontend-dev] Octane not ready on port ${octanePort}: ${error.message}`,
    );
    console.warn('[frontend-dev] Starting Vite anyway; /api may fail until laravel-octane is online.');
  }
  startVite();
}

main();
