<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Brand;
use Illuminate\Http\Request;

trait ResolvesEditableBrand
{
    protected function resolveEditableBrand(Request $request): Brand
    {
        $user = $request->user();

        if ($user->role === 'content_editor') {
            if (! $user->brand_id) {
                abort(403, 'Content editor has no assigned brand.');
            }

            return Brand::query()->findOrFail($user->brand_id);
        }

        if ($user->role === 'owner') {
            $brandId = $request->query('brand_id') ?? $request->input('brand_id');
            if ($brandId) {
                return Brand::query()->findOrFail($brandId);
            }

            return Brand::query()->where('status', 'active')->orderBy('id')->firstOrFail();
        }

        abort(403, 'Unauthorized');
    }
}
