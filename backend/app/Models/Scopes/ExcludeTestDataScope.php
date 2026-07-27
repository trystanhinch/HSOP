<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Hide records flagged as test/placeholder data from normal application queries.
 * Override with Model::withTestData() or withoutGlobalScope(ExcludeTestDataScope::class).
 */
class ExcludeTestDataScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $builder->where($model->getTable().'.is_test_data', false);
    }
}
