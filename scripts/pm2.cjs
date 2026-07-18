#!/usr/bin/env node
/**
 * Wrapper: apply Windows PM2 pipe patch, then run the global pm2 CLI.
 * Usage: node scripts/pm2.cjs start ecosystem.config.cjs
 *        npm run pm2 -- list
 */
const { spawnSync } = require('child_process');
const path = require('path');

require('./patch-pm2-windows.cjs');

const npmBin = require('child_process').execSync('npm prefix -g', { encoding: 'utf8' }).trim();
const pm2Bin = path.join(npmBin, 'node_modules', 'pm2', 'bin', 'pm2');
const args = process.argv.slice(2);

const result = spawnSync(process.execPath, [pm2Bin, ...args], {
  stdio: 'inherit',
  env: process.env,
  shell: false,
});

process.exit(result.status ?? 1);
