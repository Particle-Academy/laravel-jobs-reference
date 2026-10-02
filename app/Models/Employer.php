<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The host's employer. `laravel-jobs` owns no such table on purpose.
 *
 * It carries a `status` column EVEN THOUGH this app disables gating
 * (`employer_gate.column => null`). That is deliberate and it is the point of
 * this repo: the column has to exist for the gate's own behaviour to be
 * assertable, while the app's own configuration exercises the ungated path that
 * the package's first real consumer structurally cannot reach, because their
 * agencies are moderated and they only ever run the gate ON.
 */
class Employer extends Model
{
    use HasFactory;

    protected $guarded = [];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
