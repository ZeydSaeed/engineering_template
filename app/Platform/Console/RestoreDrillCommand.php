<?php

namespace App\Platform\Console;

use App\Platform\Backup\BackupSettings;
use App\Platform\Backup\RestoreDrill;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

#[Signature('platform:restore-drill {set? : Path of a backup set; the newest one by default}')]
#[Description('Restore a backup set into the restore database and verify it completely')]
class RestoreDrillCommand extends Command
{
    public function handle(RestoreDrill $drill): int
    {
        $set = (string) ($this->argument('set') ?? $this->newest());

        if ($set === '' || ! is_dir($set)) {
            $this->error('No backup set found.');

            return self::FAILURE;
        }

        try {
            $result = $drill->run($set);
        } catch (Throwable $exception) {
            $this->error('Restore drill FAILED: '.$exception->getMessage());

            return self::FAILURE;
        }

        foreach ($result['checks'] as $check) {
            $this->line("  ok  {$check}");
        }

        $this->info("Restore drill passed: restore {$result['restore_seconds']} s, total {$result['total_seconds']} s, backup age {$result['backup_age_hours']} h.");

        return self::SUCCESS;
    }

    private function newest(): string
    {
        $sets = [];

        foreach (['daily', 'manual'] as $kind) {
            $root = BackupSettings::directory()."/{$kind}";

            foreach (is_dir($root) ? File::directories($root) : [] as $directory) {
                $sets[basename($directory)] = $directory;
            }
        }

        ksort($sets);

        return (string) end($sets);
    }
}
