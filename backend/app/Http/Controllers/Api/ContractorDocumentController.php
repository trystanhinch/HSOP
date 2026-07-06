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

        return response()->json(
            ContractorDocument::where('contractor_id', $contractor->id)->latest()->get()
        );
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

        return response()->json(['message' => 'Document reviewed', 'document' => $doc->fresh()]);
    }
}
