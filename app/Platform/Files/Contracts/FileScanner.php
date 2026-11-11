<?php

namespace App\Platform\Files\Contracts;

use App\Platform\Files\ScannerUnavailable;
use App\Platform\Files\ScanResult;

/**
 * Port for content scanners (ADR-0007, ADR-0020).
 */
interface FileScanner
{
    /**
     * @param  resource  $stream  positioned at the start of the file
     *
     * @throws ScannerUnavailable when the verdict cannot be reached; the file
     *                            then stays unverified (fail closed)
     */
    public function scan($stream, string $mimeType): ScanResult;
}
