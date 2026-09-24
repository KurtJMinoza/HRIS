<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

class RunBackupCommand extends Command
{
    protected $signature = 'backup:run';

    protected $description = 'Export the hris MySQL database to a plain .sql file';

    public function handle(): int
    {
        $projectRoot = dirname(base_path());
        $script = $projectRoot.DIRECTORY_SEPARATOR.'scripts'.DIRECTORY_SEPARATOR.'backup-hris.cjs';

        if (! is_file($script)) {
            $this->error("Backup script not found: {$script}");

            return self::FAILURE;
        }

        $node = (string) (env('NODE_BIN') ?: 'node');
        $backupDir = (string) (env('BACKUP_DIR') ?: 'C:\\Users\\hr\\Documents\\DATABASE BACKUPS');

        $this->info("Starting backup to {$backupDir}...");

        $process = new Process(
            [$node, $script],
            $projectRoot,
            [
                'BACKUP_DIR' => $backupDir,
                'BACKUP_TIMEZONE' => config('attendance.timezone', 'Asia/Manila'),
                'MYSQLDUMP_BIN' => env('MYSQLDUMP_BIN'),
                'BACKUP_DAILY_RETENTION' => (string) (env('BACKUP_DAILY_RETENTION', 7)),
                'BACKUP_WEEKLY_RETENTION' => (string) (env('BACKUP_WEEKLY_RETENTION', 4)),
            ],
            null,
            7200,
        );

        $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });

        if (! $process->isSuccessful()) {
            $this->error('Backup failed.');

            return self::FAILURE;
        }

        $this->info('Backup finished.');

        return self::SUCCESS;
    }
}
