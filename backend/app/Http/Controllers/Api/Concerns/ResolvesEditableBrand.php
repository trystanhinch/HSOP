<?php

namespace App\Http\Controllers\Api\Concerns;

use App\Models\Brand;
use App\Services\Authorization\ContentEditorAuthorizationService;
use Illuminate\Http\Request;

trait ResolvesEditableBrand
{
    protected function resolveEditableBrand(Request $request): Brand
    {
        $user = $request->user();

        if ($user->role === 'content_editor') {
            $authz = app(ContentEditorAuthorizationService::class);
            $requested = $request->query('brand_id');

            if ($requested) {
                $authz->assertBrandAccess($user, (int) $requested, 'brand_content_resolve');

                return Brand::query()->findOrFail((int) $requested);
            }

            $brand = $authz->primaryBrand($user);
            if (! $brand) {
                abort(403, 'Content editor has no assigned brand.');
            }

            return $brand;
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
