<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /** Counts users made in this process, so emails are unique without a generator. */
    private static int $sequence = 0;

    /**
     * Define the model's default state.
     *
     * No `fake()`.
     *
     * `fakerphp/faker` last saw activity 2026-02-04 and the estate's freshness bar
     * is 92 days, so the third-party allowlist fails it — and the owner's ruling
     * (2026-09-13) is that the Laravel apps replace it with first-party fake data
     * rather than carry an unmaintained dependency for two fields. The showcase has
     * already done so; this follows it.
     *
     * Deterministic values are also better here than random ones. Every assertion
     * in this repo is about authorization and identity, and a factory that invents
     * a different name each run adds variance to tests whose failures must be
     * reproducible — see `AGENTS.md` on verifying a test fails against the previous
     * version.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $n = ++self::$sequence;

        return [
            'name' => 'Test User '.$n,
            'email' => 'user'.$n.'@laravel-jobs-reference.test',
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
