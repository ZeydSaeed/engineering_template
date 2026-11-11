<?php

namespace App\Platform\Files;

use App\Platform\Audit\AuditLogger;
use App\Platform\Files\Contracts\FileScanner;
use App\Platform\Jobs\PlatformJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Scans one stored file and records the verdict (ADR-0020). Idempotent: a
 * file that is no longer pending is left alone. If the scanner gives no
 * verdict the job fails and is retried; the file stays pending (never
 * downloadable) and the hourly sweep sends it through again later.
 */
class ScanStoredFile extends PlatformJob
{
    public const QUEUE = 'files';

    public function __construct(public readonly int $fileId)
    {
        parent::__construct();
    }

    public function handle(FileScanner $scanner, AuditLogger $audit): void
    {
        $file = StoredFile::query()->find($this->fileId);

        if ($file === null || $file->status !== StoredFile::PENDING) {
            return;
        }

        $stream = Storage::disk($file->disk)->readStream($file->path);

        $result = $stream === null
            ? ScanResult::rejected('stored bytes are missing')
            : (function () use ($scanner, $stream, $file): ScanResult {
                try {
                    return $scanner->scan($stream, $file->mime_type);
                } finally {
                    fclose($stream);
                }
            })();

        $rejected = DB::transaction(function () use ($file, $result, $audit): bool {
            $locked = StoredFile::query()->whereKey($file->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== StoredFile::PENDING) {
                return false;
            }

            $locked->forceFill([
                'status' => $result->clean ? StoredFile::VERIFIED : StoredFile::REJECTED,
                'rejection_reason' => $result->reason,
                'scanned_at' => now(),
            ])->save();

            if (! $result->clean) {
                $audit->record('file.rejected', $locked, ['reason' => (string) $result->reason]);
            }

            return ! $result->clean;
        });

        if ($rejected) {
            // A rejected file's bytes are not kept.
            Storage::disk($file->disk)->delete($file->path);
            Log::warning('Uploaded file rejected by the scanner.', ['file' => $file->uuid, 'reason' => $result->reason]);
        }
    }
}
