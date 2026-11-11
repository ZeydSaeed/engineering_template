<?php

namespace App\Platform\Console;

use App\Platform\Backup\BackupRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('platform:backup {kind=manual : daily or manual}')]
#[Description('Write an encrypted backup set: database, files and secrets, each with its own key')]
class BackupCommand extends Command
{
    public function handle(BackupRunner $runner): int
    {
        $kind = (string) $this->argument('kind');

        if (! in_array($kind, ['daily', 'manual'], true)) {
            $this->error('Kind must be daily or manual.');

            return self::INVALID;
        }

        try {
            $result = $runner->run($kind);
        } catch (Throwable $exception) {
            $this->error('Backup failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Backup {$result['kind']}/{$result['stamp']} written in {$result['seconds']} s: {$result['path']}");

        return self::SUCCESS;
    }
}
