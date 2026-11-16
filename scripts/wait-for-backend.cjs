const http = require('http');

/**
 * Poll until the Laravel/Octane HTTP listener responds.
 *
 * @param {{
 *   host?: string,
 *   port?: number|string,
 *   path?: string,
 *   timeoutMs?: number,
 *   intervalMs?: number,
 *   label?: string,
 * }} [options]
 * @returns {Promise<void>}
 */
function waitForBackend(options = {}) {
  const host = options.host || process.env.OCTANE_HOST || '127.0.0.1';
  const port = Number(options.port || process.env.OCTANE_PORT || 8200);
  const path = options.path || '/api/public-settings';
  const timeoutMs = options.timeoutMs ?? 90_000;
  const intervalMs = options.intervalMs ?? 500;
  const label = options.label || 'backend';
  const started = Date.now();

  return new Promise((resolve, reject) => {
    const attempt = () => {
      const req = http.request(
        {
          host,
          port,
          path,
          method: 'GET',
          timeout: 4000,
        },
        (res) => {
          res.resume();
          if (res.statusCode >= 200 && res.statusCode < 500) {
            const elapsed = Date.now() - started;
            console.log(`[${label}] ready http://${host}:${port}${path} (${elapsed}ms)`);
            resolve();
            return;
          }
          schedule();
        },
      );

      req.on('error', schedule);
      req.on('timeout', () => {
        req.destroy();
        schedule();
      });
      req.end();
    };

    function schedule() {
      if (Date.now() - started >= timeoutMs) {
        reject(
          new Error(
            `[${label}] not ready after ${timeoutMs}ms (http://${host}:${port}${path})`,
          ),
        );
        return;
      }
      setTimeout(attempt, intervalMs);
    }

    attempt();
  });
}

module.exports = { waitForBackend };

if (require.main === module) {
  waitForBackend({ label: 'wait-for-backend' })
    .then(() => process.exit(0))
    .catch((err) => {
      console.error(err.message);
      process.exit(1);
    });
}
