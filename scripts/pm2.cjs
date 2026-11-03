#!/usr/bin/env node
/**
 * HRIS PM2 CLI: project-local PM2_HOME + Windows pipe patch + HR-only "all".
 * Usage: node scripts/pm2.cjs start ecosystem.config.cjs --update-env
 *        npm run pm2 -- status
 */
const { spawnSync, execSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const root = path.join(__dirname, '..');
const ecosystem = 'ecosystem.config.cjs';

if (!process.env.PM2_HOME) {
  process.env.PM2_HOME = path.join(root, '.pm2');
}

require('./patch-pm2-windows.cjs');

function resolvePm2Bin() {
  const local = path.join(root, 'node_modules', 'pm2', 'bin', 'pm2');
  if (fs.existsSync(local)) {
    return local;
  }
  const npmBin = execSync('npm prefix -g', { encoding: 'utf8', windowsHide: true }).trim();
  return path.join(npmBin, 'node_modules', 'pm2', 'bin', 'pm2');
}

/** "restart all" / "stop all" / "delete all" → HR ecosystem only (not ERP or other apps). */
function normalizeArgs(args) {
  if (args.length >= 2 && args[1] === 'all') {
    const cmd = args[0];
    if (cmd === 'restart') {
      return ['restart', ecosystem, '--update-env'];
    }
    if (cmd === 'stop' || cmd === 'delete') {
      return [cmd, ecosystem];
    }
  }
  return args;
}

const pm2Bin = resolvePm2Bin();
const args = normalizeArgs(process.argv.slice(2));

const result = spawnSync(process.execPath, [pm2Bin, ...args], {
  stdio: 'inherit',
  env: process.env,
  shell: false,
  cwd: root,
});

process.exit(result.status ?? 1);
