# Engineering-Template — rules for every session

A reusable, independent engineering platform (Laravel modular monolith) for
future applications. It is NOT an implementation of EXAMIS business features.

## Absolute boundaries

- Never read, modify, copy from, link to, or run anything inside
  `D:\Projects\External_Link` or `D:\Projects\sis`, nor touch their servers,
  databases, backups, keys, DNS, Cloudflare or GitHub repositories. A review
  authorization is not an implementation authorization; each access needs a
  separate, explicit, narrowly scoped approval from the owner.
- The template is built clean-room on a fresh Laravel skeleton. Patterns
  learned in earlier assessments are re-written, never copied.
- No production deployment, no pushes, no new remote repository, no purchases
  without explicit approval. Local work only.
- Never fabricate measurements. Mark every claim Implemented / Tested /
  Verified / Planned / Proposed / Blocked.

## Working rules

- Reports to the owner are in Arabic; code, comments and docs stay in English.
- End every phase with the Arabic report format in
  `docs/PHASE-0-DECISIONS.md` §6 and wait for approval before the next phase.
- A phase is complete only when its acceptance criteria are verified.

See `docs/PHASE-0-DECISIONS.md` for the approved decisions and backlog.
