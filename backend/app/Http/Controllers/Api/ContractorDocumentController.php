<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contractor;
use App\Models\ContractorDocument;
use App\Services\UploadStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContractorDocumentController extends Controller
{
    public function __construct(protected UploadStorage $uploads) {}

    public function index(string $id): JsonResponse
    {
        $contractor = Contractor::findOrFail($id);
        $user = auth()->user();

        if ($user->role === 'contractor' && $contractor->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (! in_array($user->role, ['owner', 'pm', 'contractor'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $docs = ContractorDocument::where('contractor_id', $contractor->id)->latest()->get()
            ->map(fn ($d) => array_merge($d->toArray(), ['computed_status' => self::computeStatus($d)]));

        return response()->json($docs);
    }

    /**
     * CT-03: Owner review queue — all pending documents across contractors.
     */
    public function pendingReview(): JsonResponse
    {
        $docs = ContractorDocument::where('status', 'pending_review')
            ->with(['contractor:id,legal_name,operating_name,contact_name,user_id', 'contractor.user:id,name,email', 'uploader:id,name'])
            ->latest()
            ->get()
            ->map(fn ($d) => array_merge($d->toArray(), ['computed_status' => self::computeStatus($d)]));

        return response()->json($docs);
    }

    public static function computeStatus(ContractorDocument $doc): string
    {
        if ($doc->status === 'rejected') {
            return 'rejected';
        }
        if ($doc->status === 'pending_review') {
            return 'pending_review';
        }
        if ($doc->status === 'approved' && $doc->expiry_date) {
            $expiry = \Carbon\Carbon::parse($doc->expiry_date);
            if ($expiry->isPast()) {
                return 'expired';
            }
            if ($expiry->diffInDays(now(), absolute: true) <= 30 && $expiry->isFuture()) {
                return 'expiring_soon';
            }
        }

        return $doc->status ?: 'not_uploaded';
    }

    public function upload(Request $request, string $id): JsonResponse
    {
        $contractor = Contractor::findOrFail($id);
        $user = auth()->user();

        if ($user->role === 'contractor' && $contractor->user_id !== $user->id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        if (! in_array($user->role, ['owner', 'pm', 'contractor'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'document_type' => 'required|in:wcb,liability_insurance,business_license,other',
            'document_label' => 'nullable|string|max:100',
            'document' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240',
            'expiry_date' => 'nullable|date',
        ]);

        $file = $request->file('document');
        $filename = time().'_'.preg_replace('/[^A-Za-z0-9._-]/', '_', $file->getClientOriginalName());
        $path = $this->uploads->storeAs($file, 'contractor-documents/'.$contractor->id, $filename);
        $url = $this->uploads->publicUrl($path);

        $doc = ContractorDocument::create([
            'contractor_id' => $contractor->id,
            'uploaded_by' => $user->id,
            'document_type' => $request->document_type,
            'document_label' => $request->document_label,
            'file_name' => $file->getClientOriginalName(),
            'file_url' => $url,
            'file_size' => round($file->getSize() / 1024, 1).' KB',
            'expiry_date' => $request->expiry_date,
            'status' => 'pending_review',
        ]);

        if ($request->document_type === 'wcb') {
            $contractor->update(['wcb_status' => 'pending_review', 'wcb_file_url' => $url, 'wcb_expiry_date' => $request->expiry_date]);
        }

        if ($request->document_type === 'liability_insurance') {
            $contractor->update(['liability_insurance_status' => 'pending_review', 'insurance_file_url' => $url, 'insurance_expiry_date' => $request->expiry_date]);
        }

        return response()->json(['message' => 'Document uploaded successfully', 'document' => $doc], 201);
    }

    public function review(Request $request, string $id, ContractorDocument $doc): JsonResponse
    {
        $user = auth()->user();

        if ($user->role === 'contractor') {
            return response()->json(['message' => 'Contractors are not permitted to approve documents'], 403);
        }

        if (! in_array($user->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $contractor = Contractor::findOrFail($id);

        if ($doc->contractor_id !== $contractor->id) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $request->validate([
            'status' => 'required|in:approved,rejected',
            'rejection_reason' => 'nullable|string',
        ]);

        $doc->update([
            'status' => $request->status,
            'rejection_reason' => $request->rejection_reason,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        if ($doc->document_type === 'wcb') {
            $contractor->update(['wcb_status' => $request->status]);
        }

        if ($doc->document_type === 'liability_insurance') {
            $contractor->update(['liability_insurance_status' => $request->status]);
        }

        app(\App\Services\Contractors\ContractorProfileCompleteness::class)->refresh($contractor->fresh());

        return response()->json(['message' => 'Document reviewed', 'document' => $doc->fresh()]);
    }
}
