<?php

namespace App\Platform\Backup;

use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * Streams one process's output into one or more processes, so data moves
 * from a dump to the encrypter (and to a verifier) without a plain copy on
 * disk (ADR-0022). Every process must exit with 0, or the pipe fails.
 *
 * Unencrypted sets (BACKUP_ENCRYPT=false) write the stream to a file as
 * well ($file), and are read back with fromFile().
 */
final class Pipe
{
    /**
     * @param  list<Process>  $consumers
     */
    public static function run(Process $producer, array $consumers, ?string $file = null): void
    {
        $handle = $file === null ? null : self::open($file, 'xb');
        $inputs = self::start($consumers);

        $errors = '';
        $producer->setTimeout(null);

        try {
            $producer->run(function (string $type, string $buffer) use ($inputs, $consumers, $handle, &$errors): void {
                if ($type !== Process::OUT) {
                    $errors .= $buffer;

                    return;
                }

                if ($handle !== null && fwrite($handle, $buffer) !== strlen($buffer)) {
                    throw new RuntimeException('Could not write the backup artifact.');
                }

                self::feed($inputs, $consumers, $buffer);
            });
        } finally {
            if ($handle !== null) {
                fclose($handle);
            }
        }

        self::finish($inputs, $consumers);

        if (! $producer->isSuccessful()) {
            throw new RuntimeException(self::name($producer).' failed: '.trim($errors));
        }

        self::assertSuccessful($consumers);
    }

    /**
     * @param  list<Process>  $consumers
     */
    public static function fromFile(string $file, array $consumers): void
    {
        $handle = self::open($file, 'rb');
        $inputs = self::start($consumers);

        try {
            while (! feof($handle)) {
                $buffer = (string) fread($handle, 1 << 16);

                if ($buffer !== '') {
                    self::feed($inputs, $consumers, $buffer);
                }
            }
        } finally {
            fclose($handle);
        }

        self::finish($inputs, $consumers);
        self::assertSuccessful($consumers);
    }

    /**
     * @param  list<Process>  $consumers
     * @return list<InputStream>
     */
    private static function start(array $consumers): array
    {
        $inputs = [];

        foreach ($consumers as $consumer) {
            $input = new InputStream;
            $consumer->setInput($input);
            $consumer->setTimeout(null);
            $consumer->start();
            $inputs[] = $input;
        }

        return $inputs;
    }

    /**
     * @param  list<InputStream>  $inputs
     * @param  list<Process>  $consumers
     */
    private static function feed(array $inputs, array $consumers, string $buffer): void
    {
        foreach ($inputs as $index => $input) {
            $input->write($buffer);
            // Lets the consumer read what was written so far, so the
            // stream is not held in memory until the end.
            $consumers[$index]->isRunning();
        }
    }

    /**
     * @param  list<InputStream>  $inputs
     * @param  list<Process>  $consumers
     */
    private static function finish(array $inputs, array $consumers): void
    {
        foreach ($inputs as $input) {
            $input->close();
        }

        foreach ($consumers as $consumer) {
            $consumer->wait();
        }
    }

    /**
     * @param  list<Process>  $consumers
     */
    private static function assertSuccessful(array $consumers): void
    {
        foreach ($consumers as $consumer) {
            if (! $consumer->isSuccessful()) {
                throw new RuntimeException(self::name($consumer).' failed: '.trim($consumer->getErrorOutput()));
            }
        }
    }

    /**
     * @return resource
     */
    private static function open(string $file, string $mode)
    {
        $handle = @fopen($file, $mode);

        if ($handle === false) {
            throw new RuntimeException("Could not open {$file}.");
        }

        return $handle;
    }

    private static function name(Process $process): string
    {
        return basename((string) strtok($process->getCommandLine(), ' '), '"\'');
    }
}
