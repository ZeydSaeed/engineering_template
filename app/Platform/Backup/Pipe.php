<?php

namespace App\Platform\Backup;

use RuntimeException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;

/**
 * Streams one process's output into one or more processes, so data moves
 * from a dump to the encrypter (and to a verifier) without a plain copy on
 * disk (ADR-0022). Every process must exit with 0, or the pipe fails.
 */
final class Pipe
{
    /**
     * @param  list<Process>  $consumers
     */
    public static function run(Process $producer, array $consumers): void
    {
        $inputs = [];

        foreach ($consumers as $consumer) {
            $input = new InputStream;
            $consumer->setInput($input);
            $consumer->setTimeout(null);
            $consumer->start();
            $inputs[] = $input;
        }

        $errors = '';
        $producer->setTimeout(null);
        $producer->run(function (string $type, string $buffer) use ($inputs, $consumers, &$errors): void {
            if ($type !== Process::OUT) {
                $errors .= $buffer;

                return;
            }

            foreach ($inputs as $index => $input) {
                $input->write($buffer);
                // Lets the consumer read what was written so far, so the
                // stream is not held in memory until the end.
                $consumers[$index]->isRunning();
            }
        });

        foreach ($inputs as $input) {
            $input->close();
        }

        foreach ($consumers as $consumer) {
            $consumer->wait();
        }

        if (! $producer->isSuccessful()) {
            throw new RuntimeException(self::name($producer).' failed: '.trim($errors));
        }

        foreach ($consumers as $consumer) {
            if (! $consumer->isSuccessful()) {
                throw new RuntimeException(self::name($consumer).' failed: '.trim($consumer->getErrorOutput()));
            }
        }
    }

    private static function name(Process $process): string
    {
        return basename((string) strtok($process->getCommandLine(), ' '), '"\'');
    }
}
