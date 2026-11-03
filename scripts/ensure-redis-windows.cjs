/**
 * Start Redis on Windows when nothing listens on REDIS_HOST:REDIS_PORT (default 127.0.0.1:6379).
 * Set REDIS_SERVER_BIN to redis-server.exe if not on PATH / Laragon default.
 */
const net = require('net');
const { spawn } = require('child_process');
const fs = require('fs');
const path = require('path');
const { resolveBinary } = require('./resolve-binaries.cjs');

const host = process.env.REDIS_HOST || '127.0.0.1';
const port = Number(process.env.REDIS_PORT || 6379);

function portOpen() {
  return new Promise((resolve) => {
    const socket = net.createConnection({ host, port }, () => {
      socket.end();
      resolve(true);
    });
    socket.on('error', () => resolve(false));
    socket.setTimeout(1500, () => {
      socket.destroy();
      resolve(false);
    });
  });
}

function waitForPort(maxMs = 15000) {
  const start = Date.now();
  return (async function poll() {
    if (await portOpen()) {
      return true;
    }
    if (Date.now() - start > maxMs) {
      return false;
    }
    await new Promise((r) => setTimeout(r, 300));
    return poll();
  })();
}

async function main() {
  if (process.platform !== 'win32') {
    return;
  }

  if (await portOpen()) {
    return;
  }

  const laragonDefault =
    'C:\\laragon\\bin\\redis\\redis-x64-5.0.14.1\\redis-server.exe';
  const redisServer = resolveBinary('REDIS_SERVER_BIN', laragonDefault, ['redis-server']);

  if (!fs.existsSync(redisServer)) {
    console.error(
      `[ensure-redis] Nothing on ${host}:${port} and redis-server not found. ` +
        'Start Redis (e.g. Laragon) or set REDIS_SERVER_BIN.',
    );
    process.exit(1);
  }

  const redisDir = path.dirname(redisServer);
  const conf = path.join(redisDir, 'redis.windows.conf');
  const args = fs.existsSync(conf) ? [conf] : [];

  console.log(`[ensure-redis] Starting ${redisServer} …`);
  spawn(redisServer, args, {
    cwd: redisDir,
    detached: true,
    stdio: 'ignore',
    windowsHide: true,
  }).unref();

  if (!(await waitForPort())) {
    console.error(`[ensure-redis] Redis did not accept ${host}:${port} in time.`);
    process.exit(1);
  }
  console.log(`[ensure-redis] Redis ready on ${host}:${port}`);
}

main().catch((err) => {
  console.error('[ensure-redis]', err);
  process.exit(1);
});
