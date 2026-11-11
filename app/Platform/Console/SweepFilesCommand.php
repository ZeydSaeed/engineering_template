<?php

namespace App\Platform\Console;

use App\Platform\Files\ScanStoredFile;
use App\Platform\Files\StoredFile;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

#[Signature('platform:files-sweep {--dry-run : Report only, change nothing}')]
#[Description('Rescan stale pending files, report records without bytes, delete bytes without records')]
class SweepFilesCommand extends Command
{
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $disk = (string) config('platform.files.disk');
        $staleBefore = now()->subMinutes((int) config('platform.files.rescan_after_minutes'));

        // 1. Pending too long: the scan job was lost or the scanner was down.
        $rescanned = 0;
        StoredFile::query()->where('status', StoredFile::PENDING)->where('created_at', '<', $staleBefore)
            ->select('id')->lazyById(500)
            ->each(function (StoredFile $file) use ($dryRun, &$rescanned): void {
                if (! $dryRun) {
                    dispatch(new ScanStoredFile($file->id));
                }

                $rescanned++;
            });

        // 2. Records whose bytes are gone (verified or pending).
        $missing = 0;
        StoredFile::query()->where('disk', $disk)->whereIn('status', [StoredFile::PENDING, StoredFile::VERIFIED])
            ->select(['id', 'uuid', 'path'])->lazyById(500)
            ->each(function (StoredFile $file) use ($disk, &$missing): void {
                if (! Storage::disk($disk)->exists($file->path)) {
                    $missing++;
                    Log::error('Stored file record without bytes.', ['file' => $file->uuid]);
                }
            });

        // 3. Bytes without a record, older than a day (a crash between the
        //    write and the commit).
        $known = array_flip(StoredFile::query()->where('disk', $disk)->pluck('path')->all());
        $orphans = 0;
        $dayAgo = now()->subDay()->getTimestamp();

        foreach (Storage::disk($disk)->allFiles() as $path) {
            if (! isset($known[$path]) && Storage::disk($disk)->lastModified($path) < $dayAgo) {
                if (! $dryRun) {
                    Storage::disk($disk)->delete($path);
                }

                $orphans++;
            }
        }

        $this->info(($dryRun ? '[dry run] ' : '')."Rescanned {$rescanned}, records without bytes {$missing}, orphaned files ".($dryRun ? 'found' : 'deleted')." {$orphans}.");

        return $missing > 0 ? self::FAILURE : self::SUCCESS;
    }
}
