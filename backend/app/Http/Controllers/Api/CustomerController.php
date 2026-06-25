<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        if (! in_array($request->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json(Customer::latest()->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        return response()->json([], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        if (! in_array($request->user()->role, ['owner', 'pm'])) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        return response()->json(Customer::findOrFail($id));
    }

    public function update(Request $request, string $id): JsonResponse
    {
        return response()->json([]);
    }

    public function destroy(string $id): JsonResponse
    {
        return response()->json([]);
    }
}
