<?php

namespace App\Platform\Files;

use App\Platform\Files\Contracts\FileScanner;

/**
 * Structural checks, then the ClamAV daemon over its INSTREAM protocol
 * (ADR-0020). Any failure to reach a verdict is ScannerUnavailable, so the
 * file stays unverified and the scan is retried.
 */
class ClamAvScanner implements FileScanner
{
    private const CHUNK = 8192;

    public function __construct(
        private readonly FileScanner $structural,
        private readonly string $host,
        private readonly int $port,
        private readonly int $timeout,
    ) {}

    public function scan($stream, string $mimeType): ScanResult
    {
        $first = $this->structural->scan($stream, $mimeType);

        if (! $first->clean) {
            return $first;
        }

        rewind($stream);

        return $this->clamd($stream);
    }

    /**
     * @param  resource  $stream
     */
    private function clamd($stream): ScanResult
    {
        $socket = @stream_socket_client("tcp://{$this->host}:{$this->port}", $errno, $error, $this->timeout);

        if ($socket === false) {
            throw new ScannerUnavailable("ClamAV unreachable at {$this->host}:{$this->port} ({$error})");
        }

        try {
            stream_set_timeout($socket, $this->timeout);
            $this->write($socket, "zINSTREAM\0");

            while (! feof($stream)) {
                $chunk = (string) fread($stream, self::CHUNK);

                if ($chunk !== '') {
                    $this->write($socket, pack('N', strlen($chunk)).$chunk);
                }
            }

            $this->write($socket, pack('N', 0));
            $reply = trim((string) stream_get_contents($socket), "\0\r\n ");
        } finally {
            fclose($socket);
        }

        return match (true) {
            str_ends_with($reply, 'OK') => ScanResult::clean(),
            str_ends_with($reply, 'FOUND') => ScanResult::rejected('malware: '.trim(substr($reply, (int) strpos($reply, ':') + 1, -5))),
            default => throw new ScannerUnavailable('ClamAV gave no verdict: '.$reply),
        };
    }

    /**
     * @param  resource  $socket
     */
    private function write($socket, string $data): void
    {
        if (@fwrite($socket, $data) !== strlen($data)) {
            throw new ScannerUnavailable('ClamAV connection lost while sending the file');
        }
    }
}
