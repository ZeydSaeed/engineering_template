<?php

namespace App\Platform\Backup;

use InvalidArgumentException;

/**
 * Backup settings from config/platform.php, checked before anything runs.
 */
final class BackupSettings
{
    public const CLASSES = ['database', 'files', 'secrets'];

    /**
     * Artifact file names per class, by whether the set is encrypted.
     */
    public const ARTIFACTS = ['database' => 'database.dump', 'files' => 'files.tar', 'secrets' => 'secrets.tar'];

    /**
     * Whether sets are encrypted. BACKUP_ENCRYPT=false is honoured on the
     * local machine only (ADR-0022, amendment); anywhere else it is refused.
     */
    public static function encrypted(): bool
    {
        if (config('platform.backup.encrypt', true) !== false) {
            return true;
        }

        if (! app()->environment('local')) {
            throw new InvalidArgumentException('BACKUP_ENCRYPT=false is allowed in the local environment only.');
        }

        return false;
    }

    public static function artifact(string $class, bool $encrypted): string
    {
        return self::ARTIFACTS[$class].($encrypted ? '.age' : '');
    }

    public static function directory(): string
    {
        return rtrim((string) config('platform.backup.directory'), '/\\');
    }

    public static function tool(string $name): string
    {
        return (string) config("platform.backup.tools.{$name}");
    }

    /**
     * The age public key for one class: given directly, or read from a file.
     */
    public static function recipient(string $class): string
    {
        $value = trim((string) config("platform.backup.recipients.{$class}"));

        if ($value !== '' && ! str_starts_with($value, 'age1') && is_file($value)) {
            $value = trim((string) file_get_contents($value));
        }

        if (preg_match('/^age1[0-9a-z]{58}$/', $value) !== 1) {
            throw new InvalidArgumentException("No valid age recipient for backup class [{$class}] (platform.backup.recipients.{$class}).");
        }

        return $value;
    }

    /**
     * The private identity file for one class; needed only for restores.
     */
    public static function identity(string $class): string
    {
        $path = (string) config("platform.backup.restore.identities.{$class}");

        if ($path === '' || ! is_file($path)) {
            throw new InvalidArgumentException("No identity file for backup class [{$class}] (platform.backup.restore.identities.{$class}).");
        }

        return $path;
    }

    /**
     * Secret files to back up, relative to the application root and inside it.
     *
     * @return list<string>
     */
    public static function secretFiles(): array
    {
        $files = [];

        foreach ((array) config('platform.backup.secret_files') as $file) {
            if (! is_string($file) || $file === '' || preg_match('#^([\\\\/]|[A-Za-z]:)|(^|[\\\\/])\.\.([\\\\/]|$)#', $file) === 1) {
                throw new InvalidArgumentException('Each entry of platform.backup.secret_files must be a path inside the application root.');
            }

            $files[] = $file;
        }

        if ($files === []) {
            throw new InvalidArgumentException('platform.backup.secret_files is empty.');
        }

        return $files;
    }

    /**
     * The three recipients must differ: one key per class (ADR-0022).
     */
    public static function assertSeparateKeys(): void
    {
        $recipients = array_map(self::recipient(...), self::CLASSES);

        if (count(array_unique($recipients)) !== count($recipients)) {
            throw new InvalidArgumentException('Each backup class needs its own age key (platform.backup.recipients).');
        }
    }
}
