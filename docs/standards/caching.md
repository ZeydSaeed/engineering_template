# Caching standards

Status: policy in force (ADR-0018). No application cache exists yet.

## Layers

| Layer                                 | What may be cached                                       | Rule                                                              |
| ------------------------------------- | -------------------------------------------------------- | ----------------------------------------------------------------- |
| Browser                               | Built assets with content hashes                         | `Cache-Control: public, max-age=31536000, immutable` (web server) |
| CDN                                   | Public assets and public pages without personal data     | Never a response with a session; purge on release                 |
| HTTP                                  | Nothing for signed-in users                              | `no-store, private` (ADR-0016)                                    |
| Application (Redis or database store) | Data with a measured cost and a named invalidation owner | This page                                                         |
| PostgreSQL buffers, OS file cache     | Automatic                                                | Sized in the capacity model                                       |
| OPcache                               | Compiled PHP                                             | `validate_timestamps=0` in production with symlinked releases     |
| Laravel config, routes, views, events | Framework                                                | `php artisan optimize` on every release                           |

## Before adding an application cache

Write next to the code:

1. **What** is cached and the **measured cost** it saves (statement time from
   pg_stat_statements or Server-Timing).
2. **Time to live** and the **maximum staleness** the business accepts.
3. **Who invalidates** it, and on which write.
4. **Data class**: public, internal, personal. Personal data only under a key
   that cannot be guessed and never in a shared layer.
5. **Store down**: the code reads the source instead (fail open to the
   database, never to a default value).

## Rules

- Keys: `<module>:<entity>:v<n>:<id>`; bump `v<n>` when the shape changes.
- Writes, authorisation and money or grade decisions never read from a cache.
- Hot keys use `Cache::flexible()` (stale-while-revalidate) or a lock to avoid
  stampedes.
- Each application has its own cache prefix and, on a shared Redis, its own
  ACL user or instance.
- Tests cover invalidation: a write followed by a read returns the new value.
