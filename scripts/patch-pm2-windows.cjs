/**
 * PM2 on Windows uses one global named pipe (rpc.sock), which breaks when another
 * Windows user already runs PM2. Patch the global install to use per-user pipes.
 * Re-run after: npm install -g pm2
 */
const fs = require('fs');
const path = require('path');
const { execSync } = require('child_process');

if (process.platform !== 'win32') {
  console.log('[pm2-patch] Skipped (not Windows).');
  process.exit(0);
}

const npmRoot = execSync('npm root -g', { encoding: 'utf8' }).trim();
const targets = [
  path.join(npmRoot, 'pm2', 'paths.js'),
  path.join(npmRoot, 'pm2', 'modules', 'pm2-io-agent', 'constants.js'),
];

const winPipeId = (process.env.USERNAME || 'pm2').toLowerCase();

const pathsReplacement = `const winPipeId = (process.env.USERNAME || 'pm2').toLowerCase();
    pm2_file_stucture.DAEMON_RPC_PORT = \`\\\\.\\pipe\\\${winPipeId}-rpc.sock\`;
    pm2_file_stucture.DAEMON_PUB_PORT = \`\\\\.\\pipe\\\${winPipeId}-pub.sock\`;
    pm2_file_stucture.INTERACTOR_RPC_PORT = \`\\\\.\\pipe\\\${winPipeId}-interactor.sock\`;`;

const constantsReplacement = `const winPipeId = (process.env.USERNAME || 'pm2').toLowerCase()
  cst.DAEMON_RPC_PORT = \`\\\\.\\pipe\\\${winPipeId}-rpc.sock\`
  cst.DAEMON_PUB_PORT = \`\\\\.\\pipe\\\${winPipeId}-pub.sock\`
  cst.INTERACTOR_RPC_PORT = \`\\\\.\\pipe\\\${winPipeId}-interactor.sock\``;

const pathsPattern =
  /\/\/@todo instead of static unique rpc\/pub file custom with PM2_HOME or UID\s+pm2_file_stucture\.DAEMON_RPC_PORT = '\\\\\.\\pipe\\rpc\.sock';\s+pm2_file_stucture\.DAEMON_PUB_PORT = '\\\\\.\\pipe\\pub\.sock';\s+pm2_file_stucture\.INTERACTOR_RPC_PORT = '\\\\\.\\pipe\\interactor\.sock';/;

const constantsPattern =
  /\/\/ @todo instead of static unique rpc\/pub file custom with PM2_HOME or UID\s+cst\.DAEMON_RPC_PORT = '\\\\\.\\pipe\\rpc\.sock'\s+cst\.DAEMON_PUB_PORT = '\\\\\.\\pipe\\pub\.sock'\s+cst\.INTERACTOR_RPC_PORT = '\\\\\.\\pipe\\interactor\.sock'/;

let patched = 0;

for (const file of targets) {
  if (!fs.existsSync(file)) {
    console.warn(`[pm2-patch] Not found: ${file}`);
    continue;
  }

  let text = fs.readFileSync(file, 'utf8');

  if (text.includes('winPipeId')) {
    console.log(`[pm2-patch] Already patched: ${file}`);
    continue;
  }

  const isPaths = file.endsWith('paths.js');
  const pattern = isPaths ? pathsPattern : constantsPattern;
  const replacement = isPaths ? pathsReplacement : constantsReplacement;

  if (!pattern.test(text)) {
    console.warn(`[pm2-patch] Unexpected PM2 file format, manual check needed: ${file}`);
    continue;
  }

  text = text.replace(pattern, replacement);
  fs.writeFileSync(file, text, 'utf8');
  patched += 1;
  console.log(`[pm2-patch] Patched: ${file}`);
}

if (patched === 0) {
  console.log('[pm2-patch] No changes needed.');
} else {
  console.log(`[pm2-patch] Done (${patched} file(s)). User pipe: ${winPipeId}-rpc.sock`);
}
