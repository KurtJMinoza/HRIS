#!/usr/bin/env node
/**
 * Daily HRIS database export via mysqldump → plain .sql file (phpMyAdmin-compatible).
 * Default output: C:\Users\hr\Documents\DATABASE BACKUPS\hris - YYYY-MM-DD.sql
 *
 * Env overrides: BACKUP_DIR, MYSQLDUMP_BIN, BACKUP_TIMEZONE,
 * BACKUP_DAILY_RETENTION (default 7), BACKUP_WEEKLY_RETENTION (default 4)
 */
const fs = require('fs');
const path = require('path');
const { spawnSync } = require('child_process');

const root = path.join(__dirname, '..');
const backendDir = path.join(root, 'backend');
const envPath = path.join(backendDir, '.env');

const DEFAULT_BACKUP_DIR = 'C:\\Users\\hr\\Documents\\DATABASE BACKUPS';
const DEFAULT_TIMEZONE = 'Asia/Manila';
const DAILY_RETENTION = Number(process.env.BACKUP_DAILY_RETENTION || 7);
const WEEKLY_RETENTION = Number(process.env.BACKUP_WEEKLY_RETENTION || 4);
const EXPORT_PREFIX = 'hris - ';

function log(message) {
  process.stdout.write(`${message}\n`);
}

function fail(message) {
  process.stderr.write(`backup-hris: ${message}\n`);
  process.exit(1);
}

function parseEnv(filePath) {
  const env = {};
  if (!fs.existsSync(filePath)) {
    return env;
  }

  for (const line of fs.readFileSync(filePath, 'utf8').split(/\r?\n/)) {
    const trimmed = line.trim();
    if (!trimmed || trimmed.startsWith('#')) {
      continue;
    }
    const eq = trimmed.indexOf('=');
    if (eq === -1) {
      continue;
    }
    const key = trimmed.slice(0, eq).trim();
    let value = trimmed.slice(eq + 1).trim();
    if (
      (value.startsWith('"') && value.endsWith('"'))
      || (value.startsWith("'") && value.endsWith("'"))
    ) {
      value = value.slice(1, -1);
    }
    env[key] = value;
  }

  return env;
}

function resolveMysqldump() {
  if (process.env.MYSQLDUMP_BIN && fs.existsSync(process.env.MYSQLDUMP_BIN)) {
    return process.env.MYSQLDUMP_BIN;
  }

  const candidates = [
    'C:\\xampp\\mysql\\bin\\mysqldump.exe',
    'C:\\laragon\\bin\\mysql\\mysql-8.0.30-winx64\\bin\\mysqldump.exe',
  ];

  for (const candidate of candidates) {
    if (fs.existsSync(candidate)) {
      return candidate;
    }
  }

  return process.platform === 'win32' ? 'mysqldump.exe' : 'mysqldump';
}

function todayKey(timezone) {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: timezone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(new Date());
}

function fileSizeBytes(filePath) {
  if (!fs.existsSync(filePath)) {
    return 0;
  }
  return fs.statSync(filePath).size;
}

function exportFileName(dateKey) {
  return `${EXPORT_PREFIX}${dateKey}.sql`;
}

function parseExportDate(fileName) {
  const match = fileName.match(/^hris - (\d{4}-\d{2}-\d{2})\.sql$/);
  return match ? match[1] : null;
}

function runMysqldump(mysqldump, dbConfig, sqlPath) {
  const args = [
    `-h${dbConfig.host}`,
    `-P${dbConfig.port}`,
    `-u${dbConfig.username}`,
    '--single-transaction',
    '--routines',
    '--triggers',
    '--add-drop-table',
    '--result-file',
    sqlPath,
    dbConfig.database,
  ];

  if (dbConfig.password !== '') {
    args.splice(3, 0, `--password=${dbConfig.password}`);
  }

  const result = spawnSync(mysqldump, args, {
    stdio: 'inherit',
    windowsHide: true,
  });

  if (result.status !== 0) {
    fail(`mysqldump exited with code ${result.status ?? 'unknown'}`);
  }

  if (!fs.existsSync(sqlPath) || fileSizeBytes(sqlPath) === 0) {
    fail('mysqldump produced an empty SQL file');
  }
}

function isoWeekKey(dateKey) {
  const date = new Date(`${dateKey}T12:00:00`);
  const target = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
  const day = target.getUTCDay() || 7;
  target.setUTCDate(target.getUTCDate() + 4 - day);
  const yearStart = new Date(Date.UTC(target.getUTCFullYear(), 0, 1));
  const week = Math.ceil((((target - yearStart) / 86400000) + 1) / 7);
  return `${target.getUTCFullYear()}-W${String(week).padStart(2, '0')}`;
}

function applyRetention(backupRoot) {
  if (!fs.existsSync(backupRoot)) {
    return;
  }

  const exports = fs.readdirSync(backupRoot, { withFileTypes: true })
    .filter((entry) => entry.isFile())
    .map((entry) => entry.name)
    .map((name) => ({ name, dateKey: parseExportDate(name) }))
    .filter((entry) => entry.dateKey !== null)
    .sort((a, b) => b.dateKey.localeCompare(a.dateKey));

  const keep = new Set(exports.slice(0, DAILY_RETENTION).map((entry) => entry.name));

  const weeklyCandidates = new Map();
  for (const entry of exports.slice(DAILY_RETENTION)) {
    const weekKey = isoWeekKey(entry.dateKey);
    if (!weeklyCandidates.has(weekKey)) {
      weeklyCandidates.set(weekKey, entry.name);
    }
  }

  [...weeklyCandidates.values()]
    .sort()
    .reverse()
    .slice(0, WEEKLY_RETENTION)
    .forEach((name) => keep.add(name));

  for (const entry of exports) {
    if (keep.has(entry.name)) {
      continue;
    }
    fs.unlinkSync(path.join(backupRoot, entry.name));
    log(`Removed old export: ${entry.name}`);
  }
}

function main() {
  const timezone = process.env.BACKUP_TIMEZONE || DEFAULT_TIMEZONE;
  const backupRoot = process.env.BACKUP_DIR || DEFAULT_BACKUP_DIR;
  const dateKey = todayKey(timezone);
  const fileName = exportFileName(dateKey);
  const sqlPath = path.join(backupRoot, fileName);

  fs.mkdirSync(backupRoot, { recursive: true });

  const env = parseEnv(envPath);
  const connection = env.DB_CONNECTION || 'mysql';
  if (connection !== 'mysql') {
    fail(`DB_CONNECTION=${connection}; only mysql exports are supported`);
  }

  const dbConfig = {
    host: env.DB_HOST || '127.0.0.1',
    port: env.DB_PORT || '3306',
    database: env.DB_DATABASE || 'hris',
    username: env.DB_USERNAME || 'root',
    password: env.DB_PASSWORD || '',
  };

  log(`Export destination: ${sqlPath}`);
  log(`Database: ${dbConfig.database}@${dbConfig.host}:${dbConfig.port}`);

  const mysqldump = resolveMysqldump();
  log('Running mysqldump...');
  runMysqldump(mysqldump, dbConfig, sqlPath);

  log('Applying retention policy...');
  applyRetention(backupRoot);

  log('Database export completed successfully.');
  log(`File: ${fileName} (${(fileSizeBytes(sqlPath) / (1024 * 1024)).toFixed(1)} MB)`);
}

main();
