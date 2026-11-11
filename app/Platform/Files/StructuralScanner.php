<?php

namespace App\Platform\Files;

use App\Platform\Files\Contracts\FileScanner;
use ZipArchive;

/**
 * Checks that a file is what its type says and carries no active content
 * (ADR-0020). It is not an antivirus; production adds ClamAV on top.
 */
class StructuralScanner implements FileScanner
{
    /** PDF names that run code, open files or carry other files. */
    private const PDF_ACTIVE = ['/JavaScript', '/JS', '/Launch', '/EmbeddedFile', '/RichMedia', '/XFA', '/SubmitForm', '/ImportData'];

    private const ZIP_MAX_UNCOMPRESSED = 200 * 1024 * 1024;

    private const ZIP_MAX_ENTRIES = 10_000;

    public function __construct(private readonly int $maxImagePixels) {}

    public function scan($stream, string $mimeType): ScanResult
    {
        $bytes = (string) stream_get_contents($stream);

        return match ($mimeType) {
            'application/pdf' => $this->pdf($bytes),
            'image/png', 'image/jpeg', 'image/webp' => $this->image($bytes, $mimeType),
            'text/csv', 'text/plain' => str_contains($bytes, "\0") ? ScanResult::rejected('binary content in a text file') : ScanResult::clean(),
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip' => $this->spreadsheet($bytes),
            default => ScanResult::rejected("no structural check for {$mimeType}"),
        };
    }

    private function pdf(string $bytes): ScanResult
    {
        if (! str_starts_with($bytes, '%PDF-')) {
            return ScanResult::rejected('not a PDF');
        }

        // PDF names may hide letters as #xx escapes (/J#61vaScript).
        $normalised = (string) preg_replace_callback('/#([0-9A-Fa-f]{2})/', fn (array $m): string => (string) hex2bin($m[1]), $bytes);

        foreach (self::PDF_ACTIVE as $name) {
            if (preg_match('#'.preg_quote($name, '#').'(?![A-Za-z])#', $normalised) === 1) {
                return ScanResult::rejected("active PDF content ({$name})");
            }
        }

        return ScanResult::clean();
    }

    private function image(string $bytes, string $mimeType): ScanResult
    {
        $info = @getimagesizefromstring($bytes);

        if ($info === false || $info['mime'] !== $mimeType) {
            return ScanResult::rejected('not a valid image of the declared type');
        }

        if ($this->maxImagePixels < $info[0] * $info[1]) {
            return ScanResult::rejected('image dimensions over the limit');
        }

        return ScanResult::clean();
    }

    private function spreadsheet(string $bytes): ScanResult
    {
        if (! str_starts_with($bytes, "PK\x03\x04")) {
            return ScanResult::rejected('not an Office Open XML file');
        }

        // tmpfile(): created with a unique name and removed when closed.
        $temporary = tmpfile();

        if ($temporary === false) {
            throw new ScannerUnavailable('no temporary file for the archive check');
        }

        fwrite($temporary, $bytes);
        fflush($temporary);
        $path = stream_get_meta_data($temporary)['uri'] ?? null;

        if (! is_string($path)) {
            fclose($temporary);

            throw new ScannerUnavailable('no path for the temporary archive file');
        }

        try {
            $zip = new ZipArchive;

            if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                return ScanResult::rejected('unreadable archive');
            }

            if ($zip->numFiles > self::ZIP_MAX_ENTRIES || $zip->locateName('[Content_Types].xml') === false) {
                return ScanResult::rejected('not a spreadsheet');
            }

            $total = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $total += is_array($stat) ? $stat['size'] : 0;

                if (is_array($stat) && stripos($stat['name'], 'vbaProject.bin') !== false) {
                    return ScanResult::rejected('spreadsheet with macros');
                }
            }

            $zip->close();

            return $total > self::ZIP_MAX_UNCOMPRESSED ? ScanResult::rejected('archive expands beyond the limit') : ScanResult::clean();
        } finally {
            fclose($temporary);
        }
    }
}
