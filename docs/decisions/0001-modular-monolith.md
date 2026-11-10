# ADR-0001: Modular monolith with vertical slices

- Status: Accepted
- Date: 2026-11-11

## Context

The template is the base for several independent applications, each run by a
small team on one or a few servers. Expected loads are tens to low hundreds of
concurrent users per application.

## Problem

Choose an architectural style that keeps business areas separate without the
operational cost of distributed systems.

## Options

1. Classic Laravel layout (folders by technical type), boundaries by convention.
2. Modular monolith: one deployable application, modules as vertical slices
   (`app/Modules/<Name>/{Domain,Application,Infrastructure,Http,Database,Tests}`)
   plus a shared `app/Platform` core.
3. Microservices.
4. Clean/Onion architecture with a framework-free domain.

## Decision

Option 2. A module contains only the folders it needs. `app/Platform` holds
cross-cutting capabilities (security, access, audit, health, files, jobs,
observability) and never business rules. Boundaries are enforced by tests
(ADR-0004), not by convention.

## Consequences

- One build, one deployment, one database per application; simple operations.
- A module can later move to its own service by moving its namespace and tables,
  because cross-module access goes only through contracts and events.
- Option 3 rejected: no measured need, high operational cost for a small team.
- Option 4 rejected: isolating the domain from Laravel costs more than it saves
  here; ADR-0004 keeps only the dependency direction.

## Security implications

Authorization stays in one process and one policy layer; no inter-service
trust to secure.

## Performance implications

No network hops between modules. Scaling is vertical first, then horizontal
PHP-FPM nodes behind a load balancer (stateless app, shared DB/Redis).

## Reversal or migration strategy

Extract a module into a service if measurements show an independent scaling or
release need; its contracts become the service API.
