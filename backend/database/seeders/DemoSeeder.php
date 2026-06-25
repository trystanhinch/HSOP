<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Contractor;
use App\Models\ContractorDocument;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Job;
use App\Models\JobUpdate;
use App\Models\JobUpdatePhoto;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Payout;
use App\Models\Quote;
use App\Models\QuoteItem;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $company = Company::firstOrCreate(['slug' => 'hsop-drywall'], [
            'name' => 'HSOP Drywall & Paint',
            'service_type' => 'drywall_paint',
            'email' => 'info@hsop.ca',
            'phone' => '604-555-0100',
            'address' => '100 Main St, Vancouver, BC',
            'gst_number' => '123456789RT0001',
            'is_active' => true,
        ]);

        $admin = User::firstOrCreate(['email' => 'admin@hsop.com'], [
            'name' => 'Trystan Owner', 'password' => Hash::make('password'), 'role' => 'owner', 'status' => 'active',
        ]);
        $pm = User::firstOrCreate(['email' => 'pm@hsop.com'], [
            'name' => 'Jordan PM', 'password' => Hash::make('password'), 'role' => 'pm', 'status' => 'active',
        ]);
        $contractorUser = User::firstOrCreate(['email' => 'contractor@hsop.com'], [
            'name' => 'Mike Contractor', 'password' => Hash::make('password'), 'role' => 'contractor', 'status' => 'active',
        ]);
        $sarah = User::firstOrCreate(['email' => 'sarah@example.com'], [
            'name' => 'Sarah Johnson', 'password' => Hash::make('password'), 'role' => 'customer', 'status' => 'active',
        ]);
        $david = User::firstOrCreate(['email' => 'david@example.com'], [
            'name' => 'David Chen', 'password' => Hash::make('password'), 'role' => 'customer', 'status' => 'active',
        ]);

        Contractor::updateOrCreate(['user_id' => $contractorUser->id], [
            'legal_name' => 'Mike Pro Drywall Ltd', 'operating_name' => 'Mike Pro Drywall',
            'contact_name' => 'Mike Contractor', 'phone' => '604-555-0200', 'email' => 'contractor@hsop.com',
            'services' => ['Drywall', 'Paint'], 'cities' => ['Vancouver', 'Burnaby'],
            'wcb_status' => 'approved', 'liability_insurance_status' => 'pending_review',
            'approval_status' => 'approved',
            'wcb_expiry_date' => now()->addYear()->toDateString(),
            'insurance_expiry_date' => now()->addMonths(6)->toDateString(),
            'wcb_file_url' => '/storage/contractor-documents/sample-wcb.pdf',
        ]);

        $contractor = Contractor::where('user_id', $contractorUser->id)->first();

        ContractorDocument::updateOrCreate(
            ['contractor_id' => $contractor->id, 'document_type' => 'wcb'],
            ['uploaded_by' => $contractorUser->id, 'file_name' => 'wcb-certificate.pdf',
                'file_url' => '/storage/contractor-documents/sample-wcb.pdf', 'file_size' => '245 KB',
                'expiry_date' => now()->addYear(), 'status' => 'approved',
                'reviewed_by' => $admin->id, 'reviewed_at' => now()->subDays(30)]
        );
        ContractorDocument::updateOrCreate(
            ['contractor_id' => $contractor->id, 'document_type' => 'liability_insurance'],
            ['uploaded_by' => $contractorUser->id, 'file_name' => 'liability-insurance.pdf',
                'file_url' => '/storage/contractor-documents/sample-insurance.pdf', 'file_size' => '312 KB',
                'expiry_date' => now()->addMonths(6), 'status' => 'pending_review']
        );

        foreach ([$sarah, $david] as $u) {
            Customer::firstOrCreate(['user_id' => $u->id], [
                'name' => $u->name, 'email' => $u->email, 'phone' => '604-555-0300', 'address' => 'Vancouver, BC', 'portal_link_status' => true,
            ]);
        }

        // 1. New lead — no job
        Lead::updateOrCreate(['email' => 'emily@example.com'], [
            'company_id' => $company->id, 'contact_name' => 'Emily Park', 'phone' => '604-555-0303',
            'address' => '321 Cedar Rd, Surrey, BC', 'service_category' => 'insulation', 'source' => 'google',
            'project_description' => 'Attic insulation upgrade.', 'assigned_pm_id' => $pm->id, 'status' => 'new',
        ]);

        // 2. Site visit scheduled lead
        Lead::updateOrCreate(['email' => 'newlead@example.com'], [
            'company_id' => $company->id, 'contact_name' => 'James Wilson', 'phone' => '604-555-0304',
            'address' => '654 Maple Dr, Coquitlam, BC', 'service_category' => 'drywall_paint', 'source' => 'manual',
            'project_description' => 'Garage conversion.', 'assigned_pm_id' => $pm->id,
            'status' => 'site_visit_scheduled', 'site_visit_date' => now()->addDays(2)->toDateString(),
        ]);

        // 3. Converted → in_progress job (Sarah)
        $leadSarah = Lead::updateOrCreate(['email' => 'sarah@example.com'], [
            'company_id' => $company->id, 'customer_id' => $sarah->id, 'contact_name' => 'Sarah Johnson',
            'phone' => '604-555-0301', 'address' => '456 Oak Street, Vancouver, BC',
            'service_category' => 'drywall_paint', 'source' => 'website',
            'project_description' => 'Full basement drywall and paint.', 'assigned_pm_id' => $pm->id, 'status' => 'converted',
        ]);

        $jobSarah = Job::updateOrCreate(['lead_id' => $leadSarah->id], [
            'company_id' => $company->id, 'customer_id' => $sarah->id, 'contractor_id' => $contractorUser->id, 'pm_id' => $pm->id,
            'job_title' => 'Sarah Johnson — Drywall Paint', 'service_category' => 'drywall_paint',
            'address' => '456 Oak Street, Vancouver, BC', 'status' => 'in_progress',
            'scope_of_work' => 'Full basement drywall installation and paint.',
            'contractor_submitted_price' => 4000, 'contractor_price_status' => 'approved',
            'scheduled_start_date' => now()->subDays(3)->toDateString(),
            'estimated_completion_date' => now()->addDays(4)->toDateString(),
            'scheduled_end_date' => now()->addDays(4)->toDateString(),
        ]);

        $quoteSarah = Quote::updateOrCreate(['job_id' => $jobSarah->id], [
            'company_id' => $company->id, 'customer_id' => $sarah->id, 'quote_number' => 'QT-0001',
            'scope_of_work' => $jobSarah->scope_of_work, 'subtotal' => 5000, 'customer_price_before_gst' => 5000,
            'contractor_base_price' => 4000, 'hsop_markup' => 1000, 'gst_enabled' => true, 'gst_rate' => 5,
            'gst' => 250, 'customer_total' => 5250, 'status' => 'approved',
            'customer_token' => 'sarah-approved-token', 'sent_at' => now()->subDays(7), 'accepted_at' => now()->subDays(6),
        ]);

        Invoice::updateOrCreate(['job_id' => $jobSarah->id], [
            'quote_id' => $quoteSarah->id, 'company_id' => $company->id, 'customer_id' => $sarah->id,
            'invoice_number' => 'INV-0001', 'scope_of_work' => $quoteSarah->scope_of_work,
            'subtotal' => 5000, 'gst' => 250, 'gst_rate' => 5, 'balance' => 5250, 'amount' => 5250,
            'status' => 'draft', 'due_date' => now()->addDays(14)->toDateString(),
        ]);

        $updateVisible = JobUpdate::firstOrCreate(
            ['job_id' => $jobSarah->id, 'update_text' => 'Day 1 complete — drywall hung in main area.'],
            ['posted_by' => $contractorUser->id, 'poster_role' => 'contractor', 'visibility' => 'customer_visible']
        );
        JobUpdatePhoto::firstOrCreate(
            ['job_update_id' => $updateVisible->id, 'file_name' => 'progress-day1.jpg'],
            ['file_url' => '/storage/job-updates/placeholder-progress.jpg', 'file_size' => '120 KB']
        );

        JobUpdate::firstOrCreate(
            ['job_id' => $jobSarah->id, 'update_text' => 'Internal: may need extra mud for corner beads.'],
            ['posted_by' => $pm->id, 'poster_role' => 'pm', 'visibility' => 'internal']
        );

        Message::firstOrCreate(
            ['job_id' => $jobSarah->id, 'content' => 'Hi Sarah, we started work today.'],
            ['sender_id' => $pm->id, 'sender_role' => 'pm', 'visibility' => 'customer_visible', 'channel' => 'pm_to_customer']
        );
        Message::firstOrCreate(
            ['job_id' => $jobSarah->id, 'content' => 'Contractor confirmed start time 8am.'],
            ['sender_id' => $pm->id, 'sender_role' => 'pm', 'visibility' => 'internal', 'channel' => 'pm_internal']
        );

        Payout::firstOrCreate(['job_id' => $jobSarah->id], [
            'contractor_id' => $contractorUser->id, 'payout_amount' => 4000, 'status' => 'pending', 'eligibility_status' => 'pending_completion',
        ]);

        // 4. Quoted → quote_sent job (David) — pending approval
        $leadDavid = Lead::updateOrCreate(['email' => 'david@example.com'], [
            'company_id' => $company->id, 'customer_id' => $david->id, 'contact_name' => 'David Chen',
            'phone' => '604-555-0302', 'address' => '789 Pine Ave, Burnaby, BC',
            'service_category' => 'drywall_paint', 'source' => 'referral',
            'project_description' => 'Main floor renovation.', 'assigned_pm_id' => $pm->id, 'status' => 'quote_needed',
        ]);

        $jobDavid = Job::updateOrCreate(['lead_id' => $leadDavid->id], [
            'company_id' => $company->id, 'customer_id' => $david->id, 'contractor_id' => $contractorUser->id, 'pm_id' => $pm->id,
            'job_title' => 'David Chen — Drywall Paint', 'service_category' => 'drywall_paint',
            'address' => '789 Pine Ave, Burnaby, BC', 'status' => 'quote_sent',
            'scope_of_work' => 'Two bedroom renovation and hallway.',
            'contractor_submitted_price' => 2800, 'contractor_price_status' => 'approved',
            'scheduled_start_date' => now()->addDays(7)->toDateString(),
            'estimated_completion_date' => now()->addDays(10)->toDateString(),
        ]);

        $quoteDavid = Quote::updateOrCreate(['job_id' => $jobDavid->id], [
            'company_id' => $company->id, 'customer_id' => $david->id, 'quote_number' => 'QT-0002',
            'scope_of_work' => $jobDavid->scope_of_work, 'subtotal' => 3500, 'customer_price_before_gst' => 3500,
            'contractor_base_price' => 2800, 'hsop_markup' => 700, 'gst_enabled' => true, 'gst_rate' => 5,
            'gst' => 175, 'customer_total' => 3675, 'status' => 'sent',
            'customer_token' => 'demo_token_123', 'sent_at' => now()->subDays(2),
            'customer_notes' => 'Quote includes all materials and labour.',
        ]);

        QuoteItem::firstOrCreate(['quote_id' => $quoteDavid->id, 'description' => 'Drywall patching'], [
            'quantity' => 1, 'unit' => 'job', 'unit_price' => 2000, 'total' => 2000, 'sort_order' => 0,
        ]);
        QuoteItem::firstOrCreate(['quote_id' => $quoteDavid->id, 'description' => 'Paint — 2 coats'], [
            'quantity' => 1, 'unit' => 'job', 'unit_price' => 1500, 'total' => 1500, 'sort_order' => 1,
        ]);

        $this->command?->info('Demo data seeded.');
        $this->command?->info('David quote: /quote/view/demo_token_123');
    }
}
