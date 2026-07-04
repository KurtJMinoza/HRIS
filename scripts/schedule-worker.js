const { spawn } = require('child_process');
const path = require('path');

const root = path.resolve(__dirname, '..', 'backend');
const php = process.env.PHP_BIN || 'C:\\xampp\\php\\php.exe';
const artisan = path.join(root, 'artisan');

let lastExecution = null;

function runSchedule() {
  const now = new Date();
  const currentMinute = now.getFullYear() + '-' +
    String(now.getMonth() + 1).padStart(2, '0') + '-' +
    String(now.getDate()).padStart(2, '0') + ' ' +
    String(now.getHours()).padStart(2, '0') + ':' +
    String(now.getMinutes()).padStart(2, '0');

  if (lastExecution === currentMinute) return;
  lastExecution = currentMinute;

  const child = spawn(php, [artisan, 'schedule:run'], {
    cwd: root,
    windowsHide: true,
    stdio: 'inherit',
  });

  child.on('error', (err) => {
    console.error('[schedule-worker] error:', err.message);
  });
}

setInterval(runSchedule, 1000);
runSchedule();
