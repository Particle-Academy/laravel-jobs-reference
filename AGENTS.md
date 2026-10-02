# AGENTS.md — laravel-jobs-reference

A **real Laravel application** that consumes `particle-academy/laravel-jobs` and
`@particle-academy/job-board` the way a customer would, and asserts the things
that only break once there is an app. `CLAUDE.md` symlinks here.

It is not a demo. Every test here exists because something was wrong, and most of
them were written to fail first.

## Why it is an app and not a testbench

`orchestra/testbench` already covers the package's own units, and the package's
suite is green. The failures that reached consumers were all in the gap testbench
cannot reach: **auth, middleware, status codes, response envelopes, and the host
bindings the package asks for.** Those only exist once something boots.

Two of the three defects this app has found so far were invisible to a green
package suite.

## What each suite is for

| Suite | Asserts |
|---|---|
| `EmployerGateTest` | a narrowed `select()` does not switch moderation off |
| `ResumeAccessTest` | `resume_path` persists AND is not readable by the wrong person |
| `CandidateIdentitySpoofTest` | you cannot *be* a candidate by saying so |
| `ApplicationEventsTest` | the events reach a listener, and a listener's failure stays the listener's |
| `tests/js/jobs-client-routes.test.ts` | `JobsClient`'s URLs are URLs the package serves |

```bash
php artisan test        # the PHP suites
npm run test:live       # boots the app on a port and drives JobsClient at it
```

## The findings, and what they cost

**1. The publish gate failed open on a narrowed query** (fixed in laravel-jobs
0.3.0). `EmployerGate` read a gate column missing from the loaded model as
*ungated*. An ordinary `select(['id','name'])` anywhere in a host's code silently
switched employer moderation off, an unapproved employer's posting went live, and
the system reported success. Nothing logged, nothing threw.

**2. An anonymous caller could BE any candidate** (fixed in 0.4.0).
`CandidateResolver` fell back to a `user_id` in the request or an `X-Candidate-Id`
header, gated on a flag that **defaulted to true and was absent from the published
config**. Unauthenticated: read any candidate's resume path, cover letter, email
and phone; withdraw their application. The ownership checks downstream compared the
record against an identity the caller supplied.

Found by reading the resolver to answer an unrelated question. The package suite
was green: `AnonymousCandidateTest` sends **no** identity and asserts 401, so the
fallback it was 401-ing past was never exercised. It tested the locked door and not
the window beside it — and `AGENTS.md` called the window a feature.

**3. A failing listener destroyed the application** (fixed in 0.5.0).
`ApplicationSubmitted` was dispatched inside `DB::transaction()`, so one throwing
listener left zero rows. A dead SMTP server silently lost applications.

## Things measured here that are not obvious

- **Laravel's default `local` disk ships `'serve' => true'`**, registering
  `GET storage/{path}` over `storage_path('app/private')`. "Private disk" means
  *signature-required*, not unreachable. Authorization belongs in a controller;
  `Storage::temporaryUrl()` is a bearer token with no notion of who holds it.
- **`ServeFile` aborts `$isProduction ? 404 : 403`.** A test pinned to 403 passes
  on every developer machine while asserting the wrong thing about production.
  Assert *not served*.
- **`php artisan serve` does not pass its environment to the server.** It forwards
  `ServeCommand::$passthroughVariables` and nothing else; `DB_*` is not on it. Pass
  configuration through `APP_ENV` plus a `.env.{env}` file, which it does forward.
  See `tests/js/serve.global.ts` — getting this wrong serves a different database
  and reads as a bug in the package.
- **`job_postings.employer_id` has no foreign key**, because the package cannot
  constrain a table the host owns. Every host has a column that can point at
  nothing.
- **SQLite ignores the `foreign_keys` pragma inside a transaction**, so
  `Schema::withoutForeignKeyConstraints` does nothing under `RefreshDatabase`.
- **`GatesPublishing` is deliberately NOT bound here.** Both host contracts refuse
  when unbound, which is the right default and the least discoverable one: forget a
  binding and the portal goes quiet rather than open. Leaving it unbound keeps that
  path exercised.
- **`employer_gate.column` is `null` here** — the ungated path, which the package's
  first consumer never ran because they moderate. It is the default that surprises.

## Rules for working in here

- **A test that passes before the fix is the defect, not the evidence.** Run it
  against the previous version and watch it fail. Every finding above was verified
  that way, and the note goes in the test's own docblock.
- **Never make the app less safe to make a test easier.** The live-serve suite
  needs authenticated requests and does not get them: it asserts "not 404" instead,
  because a 401 proves Laravel routed the request. The alternative was enabling the
  caller-supplied `user_id` that was finding #2.
- **The comments are the deliverable.** This repo is read more than it is run. A
  test whose docblock does not say what went wrong is half-written.
