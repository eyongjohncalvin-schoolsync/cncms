<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Support\UpperCaseLabel;

/**
 * Stores the model's label columns normalised (App\Support\UpperCaseLabel).
 *
 * Done at the model, in setAttribute(), rather than in each Form Request,
 * import and sync service: every Eloquent write — create(), update(), fill(),
 * forceFill(), plain assignment, factories — passes through it, so no write
 * path (web, API, mobile sync, field registration, imports) can miss it. The
 * one thing it cannot see is a query-builder/DB::table() write; none exists
 * for these columns outside seeders, which normalise explicitly.
 *
 * The using model lists its label columns in upperCaseLabels().
 */
trait HasUpperCaseLabels
{
    /**
     * @return list<string>
     */
    abstract protected function upperCaseLabels(): array;

    /**
     * @param  string  $key
     * @param  mixed  $value
     */
    public function setAttribute($key, $value): mixed
    {
        if ((is_string($value) || $value === null) && in_array($key, $this->upperCaseLabels(), true)) {
            $value = UpperCaseLabel::normalise($value);
        }

        return parent::setAttribute($key, $value);
    }
}
