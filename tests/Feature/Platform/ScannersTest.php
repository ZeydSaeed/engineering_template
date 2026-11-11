<?php

use App\Platform\Files\ClamAvScanner;
use App\Platform\Files\Contracts\FileScanner;
use App\Platform\Files\ScannerUnavailable;
use App\Platform\Files\ScanResult;
use App\Platform\Files\StructuralScanner;
use Tests\Support\Files;

function scanBytes(FileScanner $scanner, string $bytes, string $mime): ScanResult
{
    $stream = fopen('php://memory', 'r+b');
    fwrite($stream, $bytes);
    rewind($stream);

    return $scanner->scan($stream, $mime);
}

it('passes ordinary files', function (string $bytes, string $mime) {
    expect(scanBytes(new StructuralScanner(40_000_000), $bytes, $mime)->clean)->toBeTrue();
})->with([
    'pdf' => [fn () => Files::pdf(), 'application/pdf'],
    'png' => [fn () => Files::png(), 'image/png'],
    'csv' => ["name,score\nA,10\n", 'text/csv'],
    'xlsx' => [fn () => Files::xlsx(), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
]);

it('rejects active or disguised content', function (string $bytes, string $mime, string $reason) {
    $result = scanBytes(new StructuralScanner(40_000_000), $bytes, $mime);

    expect($result->clean)->toBeFalse()->and($result->reason)->toContain($reason);
})->with([
    'pdf with JavaScript' => [fn () => Files::pdf('<< /S /JavaScript /JS (app.alert(1)) >>'), 'application/pdf', '/JavaScript'],
    'pdf with hex-escaped JavaScript' => [fn () => Files::pdf('<< /S /J#61vaScript >>'), 'application/pdf', '/JavaScript'],
    'pdf launching a program' => [fn () => Files::pdf('<< /S /Launch /F (cmd.exe) >>'), 'application/pdf', '/Launch'],
    'pdf carrying a file' => [fn () => Files::pdf('<< /Type /EmbeddedFile >>'), 'application/pdf', '/EmbeddedFile'],
    'executable named pdf' => ["MZ\x90\x00 program", 'application/pdf', 'not a PDF'],
    'text claiming to be png' => ['not an image', 'image/png', 'not a valid image'],
    'png bomb' => [fn () => Files::pngClaiming(50_000, 50_000), 'image/png', 'dimensions'],
    'csv with binary' => ["a,b\n\0\0", 'text/csv', 'binary'],
    'spreadsheet with macros' => [fn () => Files::xlsx(withMacros: true), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'macros'],
    'unknown type' => ['x', 'application/x-msdownload', 'no structural check'],
]);

it('fails closed when ClamAV cannot be reached', function () {
    $scanner = new ClamAvScanner(new StructuralScanner(40_000_000), '127.0.0.1', 1, 2);

    scanBytes($scanner, "a,b\n1,2\n", 'text/csv');
})->throws(ScannerUnavailable::class, 'ClamAV unreachable');

it('does not ask ClamAV about a file the structural checks already rejected', function () {
    $scanner = new ClamAvScanner(new StructuralScanner(40_000_000), '127.0.0.1', 1, 2);

    expect(scanBytes($scanner, 'not an image', 'image/png')->clean)->toBeFalse();
});

/*
| Against a real ClamAV daemon (CI starts one; CLAMAV_TEST=1).
*/
describe('ClamAV daemon', function () {
    beforeEach(function () {
        if (getenv('CLAMAV_TEST') !== '1') {
            $this->markTestSkipped('Set CLAMAV_TEST=1 with a reachable clamd (CLAMAV_HOST, CLAMAV_PORT).');
        }
    });

    it('finds the EICAR test signature and passes a clean file', function () {
        $scanner = new ClamAvScanner(new StructuralScanner(40_000_000), (string) getenv('CLAMAV_HOST'), (int) (getenv('CLAMAV_PORT') ?: 3310), 30);

        $infected = scanBytes($scanner, Files::eicar(), 'text/plain');

        expect($infected->clean)->toBeFalse()
            ->and($infected->reason)->toContain('malware')->toContain('Eicar')
            ->and(scanBytes($scanner, "name,score\nA,10\n", 'text/csv')->clean)->toBeTrue();
    });
});
