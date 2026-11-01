<?php

declare(strict_types=1);

use App\Models\Employer;
use App\Models\User;
use ParticleAcademy\LaravelJobs\Support\EmployerGate;

/**
 * THE LEAD TEST OF THIS REPO, and it is first on purpose.
 *
 * `laravel-jobs` has sharp edges, and all but one of them are LOUD: forget a host
 * binding and the portal is dead, and you know in seconds. This one was the
 * opposite, and it is the reason this repo exists in the shape it does.
 *
 * `EmployerGate::allowsPublishing()` treated a gate column missing from the
 * employer INSTANCE as *ungated* and returned true. `getAttributes()` is what was
 * LOADED, not what the table has — so an ordinary `select()` narrowing a query
 * for performance, anywhere in a host's code, silently switched moderation off.
 * An unapproved employer's posting went live and the system reported success.
 * Nothing logged. Nothing threw. No `PublishDecision` denial to inspect.
 *
 * Fixed in laravel-jobs 0.3.0 (issue #6), found by the package's first real
 * consumer while answering a question about something else — because a failure
 * this quiet is not found by looking for it.
 *
 * Their own protection was an accident: their publish gate re-checks approval
 * first, because money must never buy past moderation. That is a coincidence they
 * benefited from, not a safety property. **This test is the safety property**, and
 * it is here rather than only in the package because the symptom is a host-shaped
 * one: a query narrowed somewhere else entirely.
 */
function gateEmployer(string $status): Employer
{
    return Employer::query()->create([
        'user_id' => User::factory()->create()->getKey(),
        'name' => 'Acme Hiring',
        'status' => $status,
    ]);
}

/** Turn gating ON. This app ships it OFF; these tests assert the gate itself. */
function gatedEmployerGate(): EmployerGate
{
    config()->set('laravel-jobs.employer_model', Employer::class);
    config()->set('laravel-jobs.employer_gate.column', 'status');
    config()->set('laravel-jobs.employer_gate.approved', 'approved');

    return new EmployerGate;
}

test('a narrowed select does not bypass the gate', function () {
    // THE ONE THAT MATTERS. A host narrowing a query for performance --
    // elsewhere, long after the gate was configured and tested -- must not
    // move from "moderation enforced" to "moderation off".
    gateEmployer('pending');

    $narrowed = Employer::query()->select(['id', 'name'])->firstOrFail();

    expect($narrowed->getAttributes())->not->toHaveKey(
        'status',
        message: 'the fixture must not load the gate column, or this test asserts nothing',
    );

    expect(gatedEmployerGate()->allowsPublishing($narrowed))->toBeFalse(
        'a select() that omits the gate column must not disable moderation',
    );
});

test('a narrowed APPROVED employer is still allowed', function () {
    // The other half, and the reason the fix is not simply "deny when absent".
    // A fail-open traded for a silent lockout is the same defect in a
    // different coat: every publish refused, for a reason nobody can see.
    gateEmployer('approved');

    $narrowed = Employer::query()->select(['id', 'name'])->firstOrFail();

    expect(gatedEmployerGate()->allowsPublishing($narrowed))->toBeTrue();
});

test('a fully loaded employer is gated as expected', function () {
    expect(gatedEmployerGate()->allowsPublishing(gateEmployer('pending')))->toBeFalse();
    expect(gatedEmployerGate()->allowsPublishing(gateEmployer('approved')))->toBeTrue();
});

test('this APP runs ungated which is the path nobody had exercised', function () {
    // This app's own configuration, untouched: `employer_gate.column => null`.
    //
    // Every host that does not moderate its employers must set this
    // explicitly, and the package's first consumer moderates theirs — so
    // outside the package's own tests this path had never run anywhere. It is
    // the DEFAULT that surprises, not the option.
    config()->set('laravel-jobs.employer_model', Employer::class);

    expect(config('laravel-jobs.employer_gate.column'))->toBeNull();

    $gate = new EmployerGate;

    // A `pending` employer publishes freely here, and that is CORRECT: this
    // host does not moderate. The gate is off because it was turned off.
    expect($gate->allowsPublishing(gateEmployer('pending')))->toBeTrue();
});

test('a column the table does not have stays forgiving', function () {
    // The branch the fix had to preserve. A host naming a column its employer
    // table lacks is misconfigured, and refusing every publish would be
    // baffling rather than informative -- so it allows, and warns.
    config()->set('laravel-jobs.employer_model', Employer::class);
    config()->set('laravel-jobs.employer_gate.column', 'no_such_column');

    expect((new EmployerGate)->allowsPublishing(gateEmployer('pending')))->toBeTrue();
});
