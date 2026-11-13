# ADR-0031: One release artifact per commit, with its SHA-256 and SBOM

- Status: Accepted
- Date: 2026-11-13

## Context

Phase 8 asks for an immutable artifact with its SHA and an SBOM, so that
what is tested is what is deployed, and what is deployed can be named,
checked and inventoried.

## Decision

- `scripts/release/build.sh` builds one archive per commit,
  `engineering-template-<full sha>.tar.gz`, with a `.sha256` beside it:
    - from `git archive` of the commit, never the working tree, so nothing
      uncommitted or local (`.env`, `.tools`, keys) can reach it; it refuses
      to run with uncommitted changes;
    - production Composer packages only (`--no-dev`, optimised autoloader),
      assets built, and then the development files removed (tests, asset
      sources, CI, docs, scripts, tool configuration, the deploy templates);
    - `RELEASE.json` names the commit and its time;
    - file times, owners and order fixed and gzip without a timestamp, so
      the same commit gives the same bytes. CI builds twice and fails if
      the checksums differ.
- `scripts/release/verify.sh` is run before an artifact is used: checksum,
  one top directory named after the commit, the files a server needs, and
  none of what must never ship (`.env`, `.git`, `node_modules`, tests,
  `.tools`, SQLite files, `auth.json`, log files, our own keys or
  certificates, development packages). `RELEASE.json` must name the same
  commit as the file name.
- `scripts/release/sbom.sh` writes the CycloneDX bill of materials of the
  artifact itself with Syft (version and checksum pinned): the Composer
  packages the release carries and the npm packages its assets were built
  from (from the commit's `package-lock.json`).
- `release.yml` runs on pushes to `main` and phase branches: build, build
  again and compare, verify, SBOM, keep the three files 30 days. On `main`
  only, `actions/attest-build-provenance` signs a provenance statement in
  the public Sigstore log: a phase branch is not a release until the owner
  merges it, and a log entry cannot be withdrawn.

## Consequences

- Deployment (batch 8.3) takes an artifact and its checksum, never a
  checkout; staging and production receive the same bytes.
- The configuration cache is built on the server, with that server's
  `.env`; the artifact holds no environment.

## Security implications

No secret can be in an artifact by construction (built from a commit, and
checked); the SBOM lets an advisory be matched to the releases it
affects; the attestation lets anyone check that an artifact came from
this repository's workflow.

## Performance implications

None at run time. A local build takes about six minutes on this PC.

## Reversal or migration strategy

The scripts are independent; a deployment can still be done from a
checkout while artifacts are introduced.
