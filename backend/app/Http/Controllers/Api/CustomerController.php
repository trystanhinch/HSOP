<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\EmailLog;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Quote;
use App\Models\SmsLog;
use App\Models\User;
use App\Services\Authorization\PmAuthorizationService;
use App\Services\Customers\CustomerMergeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerController extends Controller
{
    public function __construct(
        private CustomerMergeService $mergeService,
        private PmAuthorizationService $authz,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! in_array($request->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $view = $request->get('view', 'primary');
        $query = Customer::with('user:id,name,email,phone')->latest('id');

        if ($request->user()->role === 'pm') {
            // 4A: only customers tied to this PM's own leads/jobs
            $this->authz->scopeCustomersForPm($query, $request->user());
            // PMs do not use duplicate/review admin views
            $query->whereNull('merged_into_customer_id');
        } elseif ($view === 'needs_review') {
            $query->needsReview();
        } elseif ($view === 'duplicates') {
            $query->possibleDuplicates();
        } elseif ($view === 'all') {
            $query->whereNull('merged_into_customer_id');
        } else {
            $query->activeDirectory();
        }

        if ($search = trim((string) $request->get('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('address', 'like', "%{$search}%");
            });
        }

        $customers = $query->paginate(20);

        $customers->getCollection()->transform(function (Customer $customer) {
            $userId = $customer->user_id;
            $jobCount = $userId ? Job::where('customer_id', $userId)->count() : 0;
            $activeJobs = $userId
                ? Job::where('customer_id', $userId)->whereNotIn('status', ['completed', 'cancelled', 'paid_completed'])->count()
                : 0;

            $lastSms = $userId
                ? SmsLog::where('user_id', $userId)->latest('id')->value('created_at')
                : null;
            $lastEmail = $userId
                ? EmailLog::where('user_id', $userId)->latest('id')->value('created_at')
                : null;
            $lastContact = collect([$lastSms, $lastEmail])->filter()->max();

            return array_merge($customer->toArray(), [
                'job_count' => $jobCount,
                'active_job_count' => $activeJobs,
                'has_active_work' => $activeJobs > 0,
                'last_contact_at' => $lastContact,
            ]);
        });

        return response()->json($customers);
    }

    public function duplicateGroup(Request $request, string $groupId): JsonResponse
    {
        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $members = Customer::withTestData()
            ->where('duplicate_group_id', $groupId)
            ->whereNull('merged_into_customer_id')
            ->orderByDesc('is_duplicate_primary')
            ->get()
            ->map(fn (Customer $c) => $this->enrichCustomer($c));

        return response()->json([
            'duplicate_group_id' => $groupId,
            'members' => $members,
        ]);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        if (! in_array($request->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $customer = Customer::with('user:id,name,email,phone,role,status')->findOrFail($id);
        $this->authz->assertCustomerAccess($request->user(), $customer);

        return response()->json($this->enrichCustomer($customer, true));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $customer = Customer::findOrFail($id);
        $data = $request->validate([
            'name' => 'sometimes|string|max:255',
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string|max:500',
            'communication_preference' => 'sometimes|in:sms,email,both,none',
            'do_not_contact' => 'sometimes|boolean',
            'consent_source' => 'nullable|string|max:255',
            'consent_recorded_at' => 'nullable|date',
        ]);

        if (array_key_exists('consent_recorded_at', $data) && $data['consent_recorded_at']) {
            $data['consent_recorded_at'] = now()->parse($data['consent_recorded_at']);
        } elseif (array_key_exists('consent_source', $data) && $data['consent_source'] && ! $customer->consent_recorded_at) {
            $data['consent_recorded_at'] = now();
        }

        $customer->update($data);

        if ($customer->user_id) {
            $userUpdates = array_filter([
                'name' => $data['name'] ?? null,
                'phone' => $customer->phone,
                'email' => $data['email'] ?? null,
            ], fn ($v) => $v !== null);
            if ($userUpdates !== []) {
                User::whereKey($customer->user_id)->update($userUpdates);
            }
        }

        return response()->json($this->enrichCustomer($customer->fresh(), true));
    }

    public function merge(Request $request): JsonResponse
    {
        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'customer_ids' => 'required|array|min:2',
            'customer_ids.*' => 'integer|exists:customers,id',
            'primary_customer_id' => 'required|integer|exists:customers,id',
            'field_choices' => 'nullable|array',
        ]);

        try {
            $result = $this->mergeService->merge(
                $data['customer_ids'],
                (int) $data['primary_customer_id'],
                $request->user(),
                $data['field_choices'] ?? [],
            );
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Customers merged successfully.',
            'primary' => $this->enrichCustomer($result['primary'], true),
            'counts' => $result['counts'],
            'merge_log_id' => $result['log']->id,
        ]);
    }

    public function export(Request $request, string $id): StreamedResponse|JsonResponse
    {
        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $customer = Customer::with('user')->findOrFail($id);
        $userId = $customer->user_id;
        $format = $request->get('format', 'json');

        $payload = [
            'exported_at' => now()->toIso8601String(),
            'profile' => $customer->toArray(),
            'user' => $customer->user?->only(['id', 'name', 'email', 'phone', 'role', 'status', 'created_at']),
            'leads' => $userId ? Lead::withTestData()->where('customer_id', $userId)->get(['id', 'contact_name', 'status', 'created_at'])->toArray() : [],
            'jobs' => $userId ? Job::withTestData()->where('customer_id', $userId)->get(['id', 'job_title', 'status', 'address', 'created_at'])->toArray() : [],
            'quotes' => $userId ? Quote::withTestData()->where('customer_id', $userId)->get(['id', 'quote_number', 'status', 'customer_total', 'created_at'])->toArray() : [],
            'invoices' => $userId ? Invoice::withTestData()->where('customer_id', $userId)->get(['id', 'invoice_number', 'status', 'amount', 'balance', 'created_at'])->toArray() : [],
            'sms_log_refs' => $userId ? SmsLog::where('user_id', $userId)->latest('id')->take(50)->get(['id', 'trigger_event', 'status', 'created_at'])->toArray() : [],
            'email_log_refs' => $userId ? EmailLog::where('user_id', $userId)->latest('id')->take(50)->get(['id', 'trigger_event', 'status', 'created_at'])->toArray() : [],
        ];

        if ($format === 'csv') {
            return response()->streamDownload(function () use ($payload) {
                $out = fopen('php://output', 'w');
                fputcsv($out, ['section', 'key', 'value']);
                foreach ($payload['profile'] as $k => $v) {
                    fputcsv($out, ['profile', $k, is_scalar($v) || $v === null ? $v : json_encode($v)]);
                }
                foreach (['leads', 'jobs', 'quotes', 'invoices'] as $section) {
                    foreach ($payload[$section] as $row) {
                        fputcsv($out, [$section, $row['id'] ?? '', json_encode($row)]);
                    }
                }
                fclose($out);
            }, 'customer-'.$customer->id.'-export.csv', ['Content-Type' => 'text/csv']);
        }

        return response()->json($payload);
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $data = $request->validate([
            'confirmation' => 'required|string',
        ]);
        if (strtolower(trim($data['confirmation'])) !== 'delete') {
            return response()->json(['message' => 'Type DELETE to confirm customer data deletion.'], 422);
        }

        $customer = Customer::with('user')->findOrFail($id);
        $userId = $customer->user_id;

        if ($block = $this->deletionBlockReason($userId)) {
            return response()->json(['message' => $block], 422);
        }

        DB::transaction(function () use ($customer, $userId, $request) {
            $customerId = $customer->id;
            $customerName = $customer->name;
            $customer->delete();

            if ($userId) {
                $user = User::find($userId);
                if ($user && $user->role === 'customer') {
                    $user->delete();
                }
            }

            AuditLog::create([
                'user_id' => $request->user()->id,
                'user_role' => $request->user()->role,
                'object_type' => 'customer',
                'object_id' => $customerId,
                'action_type' => 'customer_deleted',
                'new_value' => json_encode(['name' => $customerName, 'user_id' => $userId, 'data_rights' => true]),
            ]);
        });

        return response()->json(['message' => 'Customer deleted successfully']);
    }

    private function deletionBlockReason(?int $userId): ?string
    {
        if (! $userId) {
            return null;
        }

        $activeJob = Job::withTestData()
            ->where('customer_id', $userId)
            ->whereNotIn('status', ['completed', 'cancelled', 'paid_completed'])
            ->first();
        if ($activeJob) {
            return 'Cannot delete: customer has active job #'.$activeJob->id.' ('.$activeJob->status.').';
        }

        $unpaid = Invoice::withTestData()
            ->where('customer_id', $userId)
            ->where('status', '!=', 'paid')
            ->where(function ($q) {
                $q->where('balance', '>', 0)
                    ->orWhereColumn('amount_paid', '<', 'amount');
            })
            ->first();
        if ($unpaid) {
            return 'Cannot delete: customer has unpaid invoice #'.$unpaid->id.' (balance $'.number_format((float) $unpaid->balance, 2).').';
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function enrichCustomer(Customer $customer, bool $withJobs = false): array
    {
        $userId = $customer->user_id;
        $jobs = $withJobs && $userId
            ? Job::where('customer_id', $userId)->latest()->get(['id', 'job_title', 'address', 'status', 'created_at'])
            : collect();

        return array_merge($customer->toArray(), [
            'jobs' => $jobs,
            'job_count' => $jobs->count() ?: ($userId ? Job::where('customer_id', $userId)->count() : 0),
            'quote_count' => $userId ? Quote::where('customer_id', $userId)->count() : 0,
            'invoice_count' => $userId ? Invoice::where('customer_id', $userId)->count() : 0,
            'lead_count' => $userId ? Lead::where('customer_id', $userId)->count() : 0,
        ]);
    }
}
