<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Job;
use App\Models\Lead;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! in_array($request->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $customers = Customer::with('user:id,name,email,phone')
            ->latest()
            ->paginate(20);

        $customers->getCollection()->transform(function (Customer $customer) {
            $jobCount = $customer->user_id
                ? Job::where('customer_id', $customer->user_id)->count()
                : 0;

            return array_merge($customer->toArray(), ['job_count' => $jobCount]);
        });

        return response()->json($customers);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        if (! in_array($request->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $customer = Customer::with('user:id,name,email,phone,role,status')->findOrFail($id);
        $jobs = $customer->user_id
            ? Job::where('customer_id', $customer->user_id)
                ->latest()
                ->get(['id', 'job_title', 'address', 'status', 'created_at'])
            : collect();

        return response()->json(array_merge($customer->toArray(), [
            'jobs' => $jobs,
            'job_count' => $jobs->count(),
        ]));
    }

    public function destroy(Request $request, string $id): JsonResponse
    {
        if ($request->user()->role !== 'owner') {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $customer = Customer::with('user')->findOrFail($id);
        $userId = $customer->user_id;

        if ($userId && Job::where('customer_id', $userId)->exists()) {
            return response()->json([
                'message' => 'This customer has job history and cannot be deleted. Contact support if this record needs to be removed.',
            ], 422);
        }

        if ($userId && Lead::where('customer_id', $userId)->where('status', '!=', 'lost')->exists()) {
            return response()->json([
                'message' => 'This customer is linked to active leads and cannot be deleted.',
            ], 422);
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
                'new_value' => json_encode(['name' => $customerName, 'user_id' => $userId]),
            ]);
        });

        return response()->json(['message' => 'Customer deleted successfully']);
    }
}
