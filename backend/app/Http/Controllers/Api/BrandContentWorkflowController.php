<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesEditableBrand;
use App\Http\Controllers\Controller;
use App\Models\BrandPage;
use App\Models\BrandRedirect;
use App\Models\ContentRevision;
use App\Models\LocationPage;
use App\Services\Content\ContentWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class BrandContentWorkflowController extends Controller
{
    use ResolvesEditableBrand;

    public function __construct(private ContentWorkflowService $workflow) {}

    public function transitionLocation(Request $request, LocationPage $locationPage): JsonResponse
    {
        $brand = $this->resolveEditableBrand($request);
        $this->assertOwns($brand->id, $locationPage->brand_id);

        $data = $request->validate([
            'action' => ['required', Rule::in([
                'save_draft', 'submit_review', 'request_changes', 'approve', 'schedule', 'publish', 'unpublish',
            ])],
            'scheduled_at' => ['nullable', 'date'],
            'acknowledge_duplicate' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = $this->workflow->transition(
            $locationPage,
            $data['action'],
            $request->user(),
            [
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'acknowledge_duplicate' => (bool) ($data['acknowledge_duplicate'] ?? false),
                'notes' => $data['notes'] ?? null,
            ]
        );

        return response()->json([
            'location' => $result['page']->publicPayload(),
            'from' => $result['from'],
            'to' => $result['to'],
            'revision' => $result['revision'],
            'preview' => $this->workflow->previewPayload($result['page']),
        ]);
    }

    public function transitionPage(Request $request, BrandPage $brandPage): JsonResponse
    {
        $brand = $this->resolveEditableBrand($request);
        $this->assertOwns($brand->id, $brandPage->brand_id);

        $data = $request->validate([
            'action' => ['required', Rule::in([
                'save_draft', 'submit_review', 'request_changes', 'approve', 'schedule', 'publish', 'unpublish',
            ])],
            'scheduled_at' => ['nullable', 'date'],
            'acknowledge_duplicate' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $result = $this->workflow->transition(
            $brandPage,
            $data['action'],
            $request->user(),
            [
                'scheduled_at' => $data['scheduled_at'] ?? null,
                'acknowledge_duplicate' => (bool) ($data['acknowledge_duplicate'] ?? false),
                'notes' => $data['notes'] ?? null,
            ]
        );

        return response()->json([
            'page' => $result['page']->publicPayload(),
            'from' => $result['from'],
            'to' => $result['to'],
            'revision' => $result['revision'],
            'preview' => $this->workflow->previewPayload($result['page']),
        ]);
    }

    public function previewLocation(Request $request, LocationPage $locationPage): JsonResponse
    {
        $brand = $this->resolveEditableBrand($request);
        $this->assertOwns($brand->id, $locationPage->brand_id);

        return response()->json([
            'preview' => $this->workflow->previewPayload($locationPage),
            'matches_public_shape' => true,
            'brand' => $brand->publicConfig(),
        ]);
    }

    public function previewPage(Request $request, BrandPage $brandPage): JsonResponse
    {
        $brand = $this->resolveEditableBrand($request);
        $this->assertOwns($brand->id, $brandPage->brand_id);

        return response()->json([
            'preview' => $this->workflow->previewPayload($brandPage),
            'matches_public_shape' => true,
            'brand' => $brand->publicConfig(),
        ]);
    }

    public function revisionsLocation(Request $request, LocationPage $locationPage): JsonResponse
    {
        $brand = $this->resolveEditableBrand($request);
        $this->assertOwns($brand->id, $locationPage->brand_id);

        return response()->json([
            'revisions' => ContentRevision::query()
                ->where('subject_type', $locationPage->getMorphClass())
                ->where('subject_id', $locationPage->id)
                ->with(['author:id,name', 'reviewer:id,name'])
                ->orderByDesc('id')
                ->limit(50)
                ->get(),
        ]);
    }

    public function revisionsPage(Request $request, BrandPage $brandPage): JsonResponse
    {
        $brand = $this->resolveEditableBrand($request);
        $this->assertOwns($brand->id, $brandPage->brand_id);

        return response()->json([
            'revisions' => ContentRevision::query()
                ->where('subject_type', $brandPage->getMorphClass())
                ->where('subject_id', $brandPage->id)
                ->with(['author:id,name', 'reviewer:id,name'])
                ->orderByDesc('id')
                ->limit(50)
                ->get(),
        ]);
    }

    public function rollbackLocation(Request $request, LocationPage $locationPage, ContentRevision $revision): JsonResponse
    {
        $brand = $this->resolveEditableBrand($request);
        $this->assertOwns($brand->id, $locationPage->brand_id);

        $rev = $this->workflow->rollback($locationPage, $revision, $request->user());

        return response()->json([
            'location' => $locationPage->fresh()->publicPayload(),
            'revision' => $rev,
        ]);
    }

    public function rollbackPage(Request $request, BrandPage $brandPage, ContentRevision $revision): JsonResponse
    {
        $brand = $this->resolveEditableBrand($request);
        $this->assertOwns($brand->id, $brandPage->brand_id);

        $rev = $this->workflow->rollback($brandPage, $revision, $request->user());

        return response()->json([
            'page' => $brandPage->fresh()->publicPayload(),
            'revision' => $rev,
        ]);
    }

    public function redirects(Request $request): JsonResponse
    {
        $brand = $this->resolveEditableBrand($request);

        return response()->json([
            'redirects' => BrandRedirect::query()->where('brand_id', $brand->id)->orderBy('from_path')->get(),
        ]);
    }

    public function storeRedirect(Request $request): JsonResponse
    {
        $brand = $this->resolveEditableBrand($request);
        $data = $request->validate([
            'from_path' => ['required', 'string', 'max:255'],
            'to_path' => ['required', 'string', 'max:255'],
            'status_code' => ['nullable', 'integer', Rule::in([301, 302])],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $row = BrandRedirect::updateOrCreate(
            ['brand_id' => $brand->id, 'from_path' => $data['from_path']],
            [
                'to_path' => $data['to_path'],
                'status_code' => $data['status_code'] ?? 301,
                'is_active' => $data['is_active'] ?? true,
            ]
        );

        return response()->json(['redirect' => $row], 201);
    }

    public function sectionTypes(): JsonResponse
    {
        $labels = [
            'faq' => 'FAQ',
            'gallery' => 'Photo gallery',
            'before_after' => 'Before & after',
            'testimonials' => 'Testimonials',
            'trust_blocks' => 'Trust blocks',
            'service_areas' => 'Service areas',
            'internal_links' => 'Internal links',
        ];

        return response()->json([
            'types' => collect(ContentWorkflowService::SECTION_TYPES)->map(fn ($key) => [
                'key' => $key,
                'label' => $labels[$key] ?? $key,
            ])->values(),
        ]);
    }

    public function pageKeyLabels(): JsonResponse
    {
        return response()->json([
            'labels' => [
                'home' => 'Home page',
                'quote' => 'Quote page',
                'locations' => 'Locations index',
                'service' => 'Service page',
            ],
        ]);
    }

    private function assertOwns(int $brandId, int $pageBrandId): void
    {
        if ($brandId !== $pageBrandId) {
            abort(403, 'Page does not belong to your assigned brand.');
        }
    }
}
