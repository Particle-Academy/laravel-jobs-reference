<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Host models
    |--------------------------------------------------------------------------
    |
    | This package deliberately does NOT own the employer. Every host app
    | already has its own notion of "the organisation doing the hiring" —
    | an agency, a company, a studio, a team — so you point the package at
    | yours and it relates to it polymorphically-by-configuration.
    |
    | `employer_model` must expose an `id`, and (for the default resource
    | output) a `name`. Anything else is yours.
    |
    | `user_model` is the candidate applying, and the owner side of an
    | employer. Applications relate back to it.
    |
    */
    'user_model' => env('LARAVEL_JOBS_USER_MODEL', 'App\\Models\\User'),

    'employer_model' => env('LARAVEL_JOBS_EMPLOYER_MODEL', 'App\\Models\\Employer'),

    /*
    |--------------------------------------------------------------------------
    | Employer gating
    |--------------------------------------------------------------------------
    |
    | Hosts usually moderate who may advertise. Name the column on the
    | employer model that holds its state and the value that means "cleared
    | to publish". A posting can always be drafted; publishing is what the
    | gate applies to.
    |
    | Set `column` to null to disable gating entirely.
    |
    */
    'employer_gate' => [
        // GATING OFF. This is the whole reason this app exists.
        //
        // The package's default is `status`/`approved`, and every host that does
        // NOT moderate its employers has to set this to null explicitly. The
        // package's first real consumer moderates theirs, so they only ever run
        // the gate ON -- which means this path had never run anywhere outside the
        // package's own tests. It is the DEFAULT that surprises, not the option.
        //
        // Tests here set the column back to 'status' where they need to assert
        // the gate's own behaviour; the app's own configuration is the ungated
        // one, so every request this app serves exercises it.
        'column'   => null,
        'approved' => env('LARAVEL_JOBS_EMPLOYER_APPROVED_VALUE', 'approved'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Route mounting
    |--------------------------------------------------------------------------
    */
    'routes' => [
        'enabled'    => true,
        'prefix'     => 'api/jobs',
        'middleware' => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Defaults
    |--------------------------------------------------------------------------
    */
    'defaults' => [
        // Listings per page for the public board.
        'per_page' => 20,

        // Currency used when a posting does not name one.
        'currency' => env('LARAVEL_JOBS_CURRENCY', 'USD'),

        // Let a candidate apply to the same posting more than once.
        'allow_duplicate_applications' => false,

        // Automatically stop showing a posting once expires_at has passed.
        'auto_expire' => true,
    ],
];
