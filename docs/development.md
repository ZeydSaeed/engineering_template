# Development

## Toolchain

| Tool       | Version | Where                                                                                |
| ---------- | ------- | ------------------------------------------------------------------------------------ |
| PHP        | 8.5     | Laravel Herd (`%USERPROFILE%\.config\herd\bin\php85`); the machine default stays 8.4 |
| Node       | 24 LTS  | portable, `.tools/node` (not committed)                                              |
| Composer   | 2.x     | on PATH                                                                              |
| PostgreSQL | 18      | local service (used from Phase 2)                                                    |

Open a PowerShell in the repository and load the toolchain for that shell
only:

```powershell
. .\scripts\env.ps1
```

### Installing Node into `.tools/node`

Download `node-v24.x.y-win-x64.zip` from https://nodejs.org/dist/, check its
SHA-256 against `SHASUMS256.txt` in the same folder, and extract it so that
`.tools/node/node.exe` exists.

### Composer arguments with `^`

`composer` on PATH is a `.bat` file, and `cmd` drops `^`. Quote constraints
and call the phar through PHP when a constraint uses `^`:

```powershell
php C:\composer\composer.phar require "vendor/package:^1.2"
```

## Everyday commands

| Command                              | What it does                                               |
| ------------------------------------ | ---------------------------------------------------------- |
| `composer dev`                       | app server, queue listener, logs and Vite                  |
| `composer test`                      | Pint check, PHPStan (level 8), deptrac, Pest               |
| `composer ci:check`                  | frontend lint and format, TypeScript, then `composer test` |
| `composer arch`                      | deptrac only                                               |
| `vendor/bin/pest tests/Architecture` | architecture rules only                                    |
| `npm run build`                      | production assets                                          |

Tests run on in-memory SQLite in Phase 1; PostgreSQL tests start in Phase 2.
