const { execSync } = require('child_process');

/**
 * @param {string|number} port
 * @param {string} [host='127.0.0.1']
 * @returns {number[]}
 */
function listListeningPids(port, host = '127.0.0.1') {
  const pids = new Set();

  const portPattern = new RegExp(`:${port}(\\s|$)`);

  try {
    const output = execSync('netstat -ano', {
      encoding: 'utf8',
      windowsHide: true,
    });

    for (const line of output.split(/\r?\n/)) {
      if (!line.includes('LISTENING') || !portPattern.test(line)) {
        continue;
      }

      const trimmed = line.trim();
      const parts = trimmed.split(/\s+/);
      const pid = Number.parseInt(parts[parts.length - 1], 10);
      if (Number.isFinite(pid) && pid > 0 && pid !== process.pid) {
        pids.add(pid);
      }
    }
  } catch (_) {
    // findstr exits 1 when there are no matches
  }

  return [...pids];
}

/**
 * @param {string|number} port
 * @param {string} [host='127.0.0.1']
 * @returns {boolean}
 */
function isPortInUse(port, host = '127.0.0.1') {
  return listListeningPids(port, host).length > 0;
}

/**
 * @param {string|number} port
 * @param {{ host?: string, label?: string }} [options]
 * @returns {{ freed: number[], blocked: number[] }}
 */
function freeListeningPort(port, options = {}) {
  const host = options.host || '127.0.0.1';
  const label = options.label || 'port-free';
  const pids = listListeningPids(port, host);
  const freed = [];
  const blocked = [];

  for (const pid of pids) {
    try {
      execSync(`taskkill /PID ${pid} /F`, { stdio: 'ignore', windowsHide: true });
      freed.push(pid);
      console.log(`[${label}] freed port ${port} (stopped pid ${pid})`);
    } catch (_) {
      blocked.push(pid);
      console.warn(`[${label}] port ${port} still held by pid ${pid} (could not stop)`);
    }
  }

  if (freed.length > 0) {
    try {
      execSync('timeout /t 1 /nobreak >nul', { shell: true, stdio: 'ignore', windowsHide: true });
    } catch (_) {
      // Non-zero exit is fine.
    }
  }

  return { freed, blocked };
}

module.exports = {
  freeListeningPort,
  isPortInUse,
  listListeningPids,
};
