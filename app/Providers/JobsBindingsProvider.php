<?php

declare(strict_types=1);

namespace App\Providers;

use App\Listeners\NotifyEmployerOfApplication;
use App\Models\Employer;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use ParticleAcademy\LaravelJobs\Contracts\AuthorizesEmployers;
use ParticleAcademy\LaravelJobs\Events\ApplicationSubmitted;

/**
 * The bindings a host MUST provide, and the one it must not forget.
 *
 * `laravel-jobs` gates on `AuthorizesEmployers` and `GatesPublishing`, and both
 * REFUSE when unbound. That is the right default and it is the package's best
 * property -- but it is also its least discoverable one, because removing a
 * binding turns the feature OFF rather than opening it up, and nothing says so.
 * A host that forgets one sees a dead portal, not an error.
 *
 * So this file is deliberately the only place either is bound, and
 * `EmployerAuthorizationTest` asserts that WITHOUT the binding the package
 * denies. That test is the documentation: it encodes the invariant and
 * demonstrates the symptom.
 *
 * `GatesPublishing` is NOT bound here. This app does not sell listings or
 * moderate publication, so the package's own default applies -- which is exactly
 * the shape most hosts start with, and leaving it unbound keeps that path
 * exercised.
 */
class JobsBindingsProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(AuthorizesEmployers::class, fn () => new class implements AuthorizesEmployers
        {
            public function allows(Request $request, int|string $employerId): bool
            {
                $user = $request->user();

                if (! $user instanceof User) {
                    return false;
                }

                // The host owns "who may act for this employer". The package
                // cannot know, which is why it asks.
                return Employer::query()
                    ->whereKey($employerId)
                    ->where('user_id', $user->getKey())
                    ->exists();
            }
        });
    }

    public function boot(): void
    {
        /*
         * Registered EXPLICITLY, though Laravel would auto-discover a listener in
         * `app/Listeners` whose `handle()` type-hints the event.
         *
         * Discovery is fine in an app you wrote. It is wrong in the app people
         * copy: the whole hazard with these four events is that nothing tells you
         * when nobody is listening, and auto-discovery makes the wiring invisible
         * at exactly the point a reader is trying to find out whether it exists.
         * One grep for the event name should answer the question.
         */
        Event::listen(ApplicationSubmitted::class, NotifyEmployerOfApplication::class);
    }
}
