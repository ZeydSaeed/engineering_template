<?php

namespace App\Platform\Files;

use App\Platform\Audit\AuditLogger;
use App\Platform\Exceptions\DomainRuleViolation;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * The only way in and out for uploaded files (ADR-0020). Nothing the
 * browser says is trusted: the type comes from the bytes, the stored name
 * is random, the original name is kept for display only. A file is stored
 * as pending, scanned on the files queue, and served only when verified.
 * Callers authorise first (a Policy of the owning module).
 */
class FileStore
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, list<string>>|null  $types  extension => content types; defaults to platform.files.types
     *
     * @throws DomainRuleViolation when the file is empty, too large or of a type not allowed
     */
    public function store(UploadedFile $upload, ?array $types = null, ?Model $attachTo = null, ?Authenticatable $actor = null, string $field = 'file'): StoredFile
    {
        $types ??= self::configuredTypes();
        $size = (int) $upload->getSize();
        $max = (int) config('platform.files.max_kb') * 1024;

        if (! $upload->isValid() || $size === 0) {
            throw new DomainRuleViolation(__('The file could not be read.'), $field);
        }

        if ($size > $max) {
            throw new DomainRuleViolation(__('The file is larger than :max KB.', ['max' => intdiv($max, 1024)]), $field);
        }

        $extension = Str::lower($upload->getClientOriginalExtension());
        $mimeType = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($upload->getRealPath());

        if (! in_array($mimeType, $types[$extension] ?? [], true)) {
            throw new DomainRuleViolation(__('This type of file is not accepted.'), $field);
        }

        $disk = (string) config('platform.files.disk');
        $path = now()->format('Y/m').'/'.Str::random(40);
        $handle = fopen($upload->getRealPath(), 'rb');

        if ($handle === false) {
            throw new DomainRuleViolation(__('The file could not be read.'), $field);
        }

        try {
            Storage::disk($disk)->writeStream($path, $handle);
        } finally {
            fclose($handle);
        }

        try {
            $file = DB::transaction(function () use ($upload, $extension, $mimeType, $size, $disk, $path, $attachTo, $actor): StoredFile {
                $file = new StoredFile;
                $file->forceFill([
                    'uuid' => (string) Str::uuid7(),
                    'disk' => $disk,
                    'path' => $path,
                    'original_name' => self::displayName($upload->getClientOriginalName()),
                    'extension' => $extension,
                    'mime_type' => $mimeType,
                    'size_bytes' => $size,
                    'sha256' => (string) hash_file('sha256', $upload->getRealPath()),
                    'status' => StoredFile::PENDING,
                    'attachable_type' => $attachTo?->getMorphClass(),
                    'attachable_id' => $attachTo?->getKey(),
                    'uploaded_by' => $actor?->getAuthIdentifier(),
                ])->save();

                $this->audit->record('file.uploaded', $file, ['mime' => $mimeType, 'size' => $size], actor: $actor);

                return $file;
            });
        } catch (Throwable $exception) {
            // The record did not commit: remove the bytes it would describe.
            Storage::disk($disk)->delete($path);

            throw $exception;
        }

        // After the commit and outside the clean-up above: if queueing fails
        // the record and its bytes stay, as pending, and the hourly sweep
        // queues the scan again. Deleting the bytes here would leave a
        // committed record without its file.
        dispatch(new ScanStoredFile($file->id));

        return $file;
    }

    /**
     * A download response for a verified file; anything else is a 404, so a
     * pending or rejected file is indistinguishable from a missing one.
     */
    public function download(StoredFile $file): StreamedResponse
    {
        if (! $file->isVerified() || ! Storage::disk($file->disk)->exists($file->path)) {
            throw new NotFoundHttpException;
        }

        return Storage::disk($file->disk)->download($file->path, $file->original_name, [
            'Content-Type' => $file->mime_type,
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * platform.files.types, keeping only well-formed entries.
     *
     * @return array<string, list<string>>
     */
    private static function configuredTypes(): array
    {
        $types = [];

        foreach ((array) config('platform.files.types') as $extension => $mimeTypes) {
            if (is_string($extension) && is_array($mimeTypes)) {
                $types[$extension] = array_values(array_filter($mimeTypes, is_string(...)));
            }
        }

        return $types;
    }

    /**
     * The uploaded name without directories, control characters or
     * characters that break a Content-Disposition header.
     */
    public static function displayName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F"\\\\\/:*?<>|]+/u', '', $name);
        $name = Str::limit(trim($name), 190, '');

        return $name === '' ? 'file' : $name;
    }
}
