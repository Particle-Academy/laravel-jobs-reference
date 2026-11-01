<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| Every test in tests/Feature is bound to Tests\TestCase (a booted Laravel
| application) with RefreshDatabase. tests/Unit stays on the bare PHPUnit
| TestCase: nothing there needs the app.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');
