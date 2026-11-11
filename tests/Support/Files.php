<?php

namespace Tests\Support;

use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * Synthetic files for upload and scanner tests.
 */
final class Files
{
    public static function pdf(string $body = 'BT /F1 12 Tf (Hello) Tj ET'): string
    {
        return "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\nstream\n{$body}\nendstream\ntrailer << /Root 1 0 R >>\n%%EOF\n";
    }

    public static function png(int $width = 20, int $height = 20): string
    {
        $image = imagecreatetruecolor(max(1, $width), max(1, $height));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /**
     * A tiny PNG whose header claims huge dimensions (decompression bomb).
     */
    public static function pngClaiming(int $width, int $height): string
    {
        $png = self::png();

        // IHDR width and height start at byte 16; the CRC is not checked here.
        return substr($png, 0, 16).pack('N', $width).pack('N', $height).substr($png, 24);
    }

    public static function xlsx(bool $withMacros = false): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types/>');
        $zip->addFromString('xl/workbook.xml', '<workbook/>');

        if ($withMacros) {
            $zip->addFromString('xl/vbaProject.bin', 'macro');
        }

        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /**
     * The EICAR antivirus test string, kept reversed in the source so local
     * antivirus software does not flag this file.
     */
    public static function eicar(): string
    {
        return strrev('*H+H$!ELIF-TSET-SURIVITNA-DRADNATS-RACIE$}7)CC7)^P(45XZP\\4[PA@%P!O5X');
    }

    public static function upload(string $name, string $bytes): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }
}
