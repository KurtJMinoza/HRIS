const { execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

/**
 * Resolve PHP/Python/Node paths for PM2 on Windows (avoids bare "python" ENOENT).
 */
function resolveBinary(envKey, fallback, names) {
  if (process.env[envKey]) {
    return process.env[envKey];
  }

  const isWin = process.platform === 'win32';
  if (!isWin) {
    return fallback;
  }

  for (const name of names) {
    try {
      const output = execSync(`cmd /c where ${name}`, {
        encoding: 'utf8',
        windowsHide: true,
      });
      const lines = output
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter((line) => line && !line.startsWith('INFO:'));
      const preferred = lines.find(
        (line) => !line.includes('WindowsApps') && fs.existsSync(line),
      );
      if (preferred) {
        return preferred;
      }
      if (lines[0] && fs.existsSync(lines[0])) {
        return lines[0];
      }
    } catch (_) {
      // try next name
    }
  }

  try {
    const output = execSync('py -0p', { encoding: 'utf8', windowsHide: true });
    const lines = output
      .split(/\r?\n/)
      .map((line) => line.trim())
      .filter(Boolean);
    for (const line of lines) {
      const match = line.match(/([A-Za-z]:\\[^\\]+(?:\\[^\\]+)*\\python\.exe)\s*$/i);
      if (match && fs.existsSync(match[1])) {
        return match[1];
      }
    }
  } catch (_) {
    // py launcher unavailable
  }

  const localAppData = process.env.LOCALAPPDATA || '';
  if (localAppData) {
    const pythonRoot = path.join(localAppData, 'Programs', 'Python');
    try {
      const versions = fs.readdirSync(pythonRoot).filter((entry) => entry.startsWith('Python'));
      versions.sort().reverse();
      for (const version of versions) {
        const candidate = path.join(pythonRoot, version, 'python.exe');
        if (fs.existsSync(candidate)) {
          return candidate;
        }
      }
    } catch (_) {
      // ignore missing Programs/Python
    }
  }

  return fallback;
}

module.exports = { resolveBinary };
