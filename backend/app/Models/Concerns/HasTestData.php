<?php

namespace App\Models\Concerns;

use App\Models\Scopes\ExcludeTestDataScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Adds is_test_data support + ExcludeTestDataScope to a model.
 * Use on every model whose table has the is_test_data column.
 */
trait HasTestData
{
    public static function bootHasTestData(): void
    {
        static::addGlobalScope(new ExcludeTestDataScope);
    }

    public function initializeHasTestData(): void
    {
        $this->mergeCasts([
            'is_test_data' => 'boolean',
        ]);

        if (! in_array('is_test_data', $this->fillable, true)
            && ! in_array('*', $this->fillable, true)) {
            $this->fillable[] = 'is_test_data';
        }
    }

    /**
     * Include test-flagged rows (owner diagnostics / flagging tools).
     */
    public function scopeWithTestData(Builder $query): Builder
    {
        return $query->withoutGlobalScope(ExcludeTestDataScope::class);
    }

    /**
     * Only production rows — explicit defense-in-depth for aggregations.
     * Redundant with the global scope when the scope is active, but safe to call always.
     */
    public function scopeProductionOnly(Builder $query): Builder
    {
        return $query->where($this->getTable().'.is_test_data', false);
    }

    public function scopeOnlyTestData(Builder $query): Builder
    {
        return $query->withoutGlobalScope(ExcludeTestDataScope::class)
            ->where($this->getTable().'.is_test_data', true);
    }

    public function isTestData(): bool
    {
        return (bool) ($this->is_test_data ?? false);
    }

    public function markAsTestData(bool $flag = true): void
    {
        $this->forceFill(['is_test_data' => $flag])->save();
    }
}
