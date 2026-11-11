# ADR-0020: Uploaded files are untrusted until scanned

- Status: Accepted
- Date: 2026-11-12

## Context

Uploads are the most direct way to put attacker-controlled bytes on a
server: executables named `.pdf`, PDFs with scripts, image decompression
bombs, spreadsheets with macros, paths in file names.

## Problem

Accept files without trusting anything the client says, and never serve a
file that has not been checked.

## Options

1. Store and serve uploads as received.
2. One file service: allow-listed types checked against the bytes, random
   storage names, a scan before release, and authorised downloads only.

## Decision

Option 2 (`App\Platform\Files`).

- `FileStore::store()`: size limit; extension must be in the allow-list and
  the content type detected from the bytes (`finfo`) must be one allowed for
  that extension; stored under `YYYY/MM/<40 random chars>` with no extension;
  original name kept for display only, stripped of paths and header-breaking
  characters; SHA-256 recorded; status `pending`; audited. Bytes are deleted
  only if the record does not commit; the scan is queued after the commit.
- `ScanStoredFile` (queue `files`): `FileScanner` port.
  `StructuralScanner`: PDF active content (also hex-escaped names), image
  type and pixel limit, spreadsheet container, macros and expansion size,
  binary content in text. `ClamAvScanner`: structural first, then clamd
  INSTREAM. No verdict = `ScannerUnavailable`: the file stays pending and is
  retried (fail closed). Rejected files: status `rejected`, bytes deleted,
  audited.
- `FileStore::download()`: verified files only (anything else is a 404);
  `Content-Disposition: attachment`, the detected type, `nosniff`,
  `Content-Security-Policy: default-src 'none'; sandbox`, `no-store`. The
  owning module authorises first.
- `platform:files-sweep` hourly: rescans stale pending files, reports records
  without bytes, deletes bytes without records older than a day.
- The local disk is no longer served over HTTP (`storage/{path}` removed).
- Production: `FILES_SCANNER=clamav`. Locally: structural (no antivirus
  installed); CI runs the ClamAV daemon and checks the EICAR signature.

## Consequences

A file is available a moment after upload, once scanned. Modules show
"being checked" for pending files.

## Security implications

Defence against disguised types, active content, bombs, path tricks,
content sniffing and inline rendering of untrusted files.

## Performance implications

Scanning is off the request path. ClamAV needs about 1 to 1.5 GB of memory
(capacity model).

## Reversal or migration strategy

Object storage (Cloudflare R2, S3) is a disk change (`FILES_DISK=r2`); the
service and its tests stay the same.
