const http = require('http');

/**
 * @param {string} url
 * @returns {Promise<number>}
 */
function getStatus(url) {
  return new Promise((resolve, reject) => {
    const req = http.get(url, { timeout: 30_000 }, (res) => {
      res.resume();
      resolve(res.statusCode || 0);
    });
    req.on('error', reject);
    req.on('timeout', () => {
      req.destroy();
      reject(new Error('timeout'));
    });
  });
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Pre-hit Vite after listen so dependency pre-bundling finishes before users reload.
 *
 * @param {number|string} port
 */
async function warmupViteDevServer(port) {
  const base = `http://127.0.0.1:${port}`;
  const paths = [
    '/',
    '/src/main.jsx',
    '/src/App.jsx',
    '/src/pages/AdminDashboard.jsx',
    '/src/pages/EmployeeDashboard.jsx',
    '/api/public-settings',
  ];

  for (let attempt = 0; attempt < 90; attempt += 1) {
    try {
      const code = await getStatus(`${base}/`);
      if (code >= 200 && code < 500) {
        break;
      }
    } catch {
      // Vite still booting
    }
    await sleep(500);
  }

  for (const path of paths) {
    try {
      await getStatus(`${base}${path}`);
    } catch {
      // best-effort
    }
    await sleep(250);
  }

  console.log(`[frontend-dev] Vite warmup finished on port ${port}`);
}

module.exports = { warmupViteDevServer };
