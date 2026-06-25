<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\Contractor;
use App\Models\File;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\Lead;
use App\Models\Payment;
use App\Models\Payout;
use App\Models\Quote;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminController extends Controller
{
    public function databaseOverview(): JsonResponse
    {
        return response()->json([
            'tables' => [
                [
                    'name' => 'companies',
                    'purpose' => 'Stores each service company (Drywall, Insulation, Flooring etc.) — multi-company ready',
                    'count' => Company::count(),
                    'columns' => ['id', 'name', 'slug', 'service_type', 'email', 'phone', 'gst_number', 'is_active', 'created_at'],
                    'sample' => Company::first(),
                ],
                [
                    'name' => 'users',
                    'purpose' => 'All system users with role-based access control',
                    'count' => User::count(),
                    'columns' => ['id', 'name', 'email', 'role (owner|pm|contractor|customer)', 'status (active|inactive)', 'created_at'],
                    'roles' => User::select('role', DB::raw('count(*) as total'))->groupBy('role')->get(),
                ],
                [
                    'name' => 'leads',
                    'purpose' => 'Customer inquiries tracked from first contact through conversion',
                    'count' => Lead::count(),
                    'columns' => ['id', 'company_id', 'customer_id', 'contact_name', 'phone', 'email', 'address', 'service_category', 'source', 'assigned_pm_id', 'status', 'site_visit_date'],
                    'statuses' => Lead::select('status', DB::raw('count(*) as total'))->groupBy('status')->get(),
                ],
                [
                    'name' => 'jobs',
                    'purpose' => 'Active work orders linked to leads, customers, contractors and PMs',
                    'count' => Job::count(),
                    'columns' => ['id', 'company_id', 'lead_id', 'customer_id', 'contractor_id', 'pm_id', 'service_category', 'address', 'status', 'scope_of_work', 'contractor_submitted_price', 'contractor_price_status', 'scheduled_start_date', 'scheduled_end_date'],
                    'statuses' => Job::select('status', DB::raw('count(*) as total'))->groupBy('status')->get(),
                ],
                [
                    'name' => 'quotes',
                    'purpose' => 'Pricing with automatic 20% markup and 5% GST — markup hidden from contractor and customer',
                    'count' => Quote::count(),
                    'columns' => ['id', 'job_id', 'customer_id', 'contractor_base_price', 'customer_price_before_gst', 'hsop_markup (hidden)', 'gst (5%)', 'customer_total', 'status', 'customer_token', 'sent_at', 'accepted_at'],
                    'statuses' => Quote::select('status', DB::raw('count(*) as total'))->groupBy('status')->get(),
                ],
                [
                    'name' => 'invoices',
                    'purpose' => 'Invoices generated for completed jobs, tracked against payments',
                    'count' => Invoice::count(),
                    'columns' => ['id', 'job_id', 'customer_id', 'amount', 'gst', 'balance', 'status (unpaid|partial|paid)', 'due_date'],
                ],
                [
                    'name' => 'payments',
                    'purpose' => 'Manual e-transfer payment tracking — admin marks paid/cleared',
                    'count' => Payment::count(),
                    'columns' => ['id', 'invoice_id', 'amount', 'method (e_transfer)', 'paid_status', 'cleared_status', 'marked_by', 'paid_date'],
                ],
                [
                    'name' => 'payouts',
                    'purpose' => 'Contractor payout queue — approved and released by admin after job completion',
                    'count' => Payout::count(),
                    'columns' => ['id', 'job_id', 'contractor_id', 'payout_amount', 'status (pending|approved|paid)', 'eligibility_status', 'paid_date', 'authorized_by'],
                ],
                [
                    'name' => 'contractors',
                    'purpose' => 'Contractor company profiles with compliance document tracking',
                    'count' => Contractor::count(),
                    'columns' => ['id', 'user_id', 'legal_name', 'operating_name', 'services (JSON)', 'cities (JSON)', 'wcb_status', 'liability_insurance_status', 'approval_status (pending|approved|suspended)', 'payment_info'],
                ],
                [
                    'name' => 'files (contractor documents)',
                    'purpose' => 'All uploaded files including contractor WCB and insurance documents',
                    'count' => File::count() + \App\Models\ContractorDocument::count(),
                    'columns' => ['id', 'uploader_id', 'related_type (contractor|lead|job)', 'related_id', 'file_type (wcb|liability_insurance|photo|other)', 'visibility (internal|customer)', 'file_url'],
                ],
            ],
        ]);
    }
}
