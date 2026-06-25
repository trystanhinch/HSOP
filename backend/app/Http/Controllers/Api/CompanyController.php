<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\JsonResponse;

class CompanyController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(
            Company::where('is_active', true)->get(['id', 'name', 'service_type'])
        );
    }
}
