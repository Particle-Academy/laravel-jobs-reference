# laravel-jobs-reference

A **real Laravel application** that consumes
[`particle-academy/laravel-jobs`](https://github.com/Particle-Academy/laravel-jobs)
and [`@particle-academy/job-board`](https://github.com/Particle-Academy/job-board)
the way a customer would — installed from Packagist and npm, no source aliases —
and asserts the things that only break once there is an app.

It is the shape to copy. It is also where three defects in those packages were
found, two of them invisible to a green package suite.

## Run it

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate

php artisan test        # the PHP suites
npm install
npm run test:live       # boots the app on a port and drives JobsClient at it
```

## What is worth copying

**`app/Http/Controllers/ResumeDownloadController.php`** — serving a candidate's
CV. `laravel-jobs` stores a `resume_path` and leaves serving to the host, which is
the right split and means the obvious implementation is `Storage::url()` on a
public disk. This one uses a private disk, authorises every request, allows exactly
the candidate and the employer the application was sent to — **not admins** — and
returns **404 rather than 403**, because a 403 confirms an application exists at
that id.

**`app/Providers/JobsBindingsProvider.php`** — the two host contracts. Both refuse
when unbound, which is the package's best property and its least discoverable one:
forget a binding and the portal goes quiet rather than wide open. `GatesPublishing`
is deliberately left unbound here.

**`app/Listeners/NotifyEmployerOfApplication.php`** — the far end of a wire the
package cannot connect. It catches its own failures, and the comment explains why
`ShouldQueue` would not have been enough (the default queue driver is `sync`).

**`tests/js/serve.global.ts`** — booting the real app for the route-agreement
suite, including the `php artisan serve` environment trap that makes the server read
a different database than the one you seeded.

## What it has found

| | Fixed in |
|---|---|
| The publish gate failed **open** when a `select()` omitted the gate column | laravel-jobs 0.3.0 |
| An anonymous caller could **be any candidate** by passing `user_id` | laravel-jobs 0.4.0 |
| A failing listener **destroyed** the candidate's application | laravel-jobs 0.5.0 |

Details, and the measurements behind each, in [`AGENTS.md`](./AGENTS.md).
