# Runbook: infrastructure

## HostDown

- **Meaning:** the host's exporter stopped answering: the machine, its network or the exporter is down.
- **Check:** the provider's console; ping; `systemctl status prometheus-node-exporter` if the host answers.
- **Act:** restart the host or the exporter; if the host is lost, recover on a new one (docs/operations/recovery.md).
- **Verify:** `up{job="node"} == 1` and the application's `/health/ready` is ok.

## DiskSpaceLow

- **Meaning:** less than 15 % free on a filesystem.
- **Check:** `df -h`; `du -sh /srv/APP/shared/storage/* /var/backups/* /var/log/*`.
- **Act:** remove old releases beyond five, old logs; check backup retention; enlarge the volume if growth is genuine.
- **Verify:** free space above 20 %.

## MemoryLow

- **Meaning:** less than 10 % memory available; the kernel may start killing processes.
- **Check:** `ps aux --sort=-rss | head`; `systemctl status` of workers with `MemoryMax`.
- **Act:** restart a leaking worker (they recycle with `--max-time`); reduce `pm.max_children` to the budget (docs/operations/capacity.md).
- **Verify:** available memory above 20 %; no OOM lines in `journalctl -k`.

## CpuSaturated

- **Meaning:** CPU over 90 % busy for 15 minutes: requests queue.
- **Check:** `top`; PHP-FPM status; `pg_stat_activity` for long queries; pg_stat_statements top statements.
- **Act:** stop a runaway job or query; if it is real load, scale (more CPU, or move a queue to another host).
- **Verify:** idle CPU above 20 %; latency back to its baseline.

## PostgresDown

- **Meaning:** PostgreSQL is not reachable; the application cannot serve.
- **Check:** `systemctl status postgresql`; `journalctl -u postgresql`; disk space.
- **Act:** restart PostgreSQL; if the data directory is damaged, restore (docs/operations/recovery.md).
- **Verify:** `pg_up == 1`; `/health/ready` ok.

## PostgresConnectionsHigh

- **Meaning:** over 80 % of connections in use; new connections will soon be refused.
- **Check:** `select usename, state, count(*) from pg_stat_activity group by 1, 2`; idle-in-transaction sessions.
- **Act:** find the source (a role over its `CONNECTION LIMIT`, idle transactions); add PgBouncer if PHP-FPM workers outgrow the limit.
- **Verify:** below 60 %.

## FpmWorkersExhausted

- **Meaning:** PHP-FPM hit `pm.max_children`; requests waited for a worker.
- **Check:** FPM status page; the slow log; whether requests are slow (database, outbound calls).
- **Act:** fix slow requests first; raise `pm.max_children` only within the memory budget.
- **Verify:** `phpfpm_max_children_reached` stops increasing.

## RedisDown

- **Meaning:** Redis is unreachable; queued jobs wait and cached data is recomputed.
- **Check:** `systemctl status redis-server`; memory (`maxmemory`).
- **Act:** restart Redis; jobs dispatched meanwhile failed at dispatch, so check the application log and the outbox (outbox events are retried by the relay).
- **Verify:** `redis_up == 1`; queue sizes fall.
