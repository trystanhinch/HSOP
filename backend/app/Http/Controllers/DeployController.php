<?php

namespace App\Http\Controllers;

use App\Models\Contractor;
use App\Models\Job;
use App\Models\Lead;
use App\Models\User;
use App\Services\LeadCustomerResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;

class DeployController extends Controller
{
    public function seedSettings(string $secret): JsonResponse
    {
        $this->authorizeDeploy($secret);
        Artisan::call('db:seed', ['--class' => 'SettingsSeeder', '--force' => true]);

        return $this->ok('db:seed --class=SettingsSeeder --force');
    }

    public function release(string $secret): JsonResponse
    {
        $this->authorizeDeploy($secret);

        $steps = [];
        foreach ([
            'config:clear' => 'config:clear',
            'cache:clear' => 'cache:clear',
            'config:cache' => 'config:cache',
            'migrate --force' => 'migrate',
        ] as $label => $command) {
            $args = $command === 'migrate' ? ['--force' => true] : [];
            Artisan::call($command, $args);
            $steps[$label] = trim(Artisan::output());
        }

        return response()->json([
            'ok' => true,
            'message' => 'Production release completed.',
            'steps' => $steps,
        ]);
    }

    public function migrate(string $secret): JsonResponse
    {
        $this->authorizeDeploy($secret);
        Artisan::call('migrate', ['--force' => true]);

        return $this->ok('migrate --force');
    }

    public function seed(string $secret): JsonResponse
    {
        $this->authorizeDeploy($secret);
        Artisan::call('db:seed', ['--force' => true]);

        return $this->ok('db:seed --force');
    }

    public function setup(string $secret): JsonResponse
    {
        $this->authorizeDeploy($secret);

        Artisan::call('migrate', ['--force' => true]);
        $migrateOut = Artisan::output();

        Artisan::call('db:seed', ['--force' => true]);
        $seedOut = Artisan::output();

        Artisan::call('storage:link');
        $linkOut = Artisan::output();

        Artisan::call('hsop:repair-data');
        $repairOut = Artisan::output();

        return response()->json([
            'ok' => true,
            'message' => 'Migrate, seed, storage:link, and data repair completed.',
            'output' => trim($migrateOut."\n\n".$seedOut."\n\n".$linkOut."\n\n".$repairOut),
        ]);
    }

    public function repair(string $secret): JsonResponse
    {
        $this->authorizeDeploy($secret);

        $resolver = app(LeadCustomerResolver::class);
        $customers = $resolver->repairJobCustomers();
        $contractors = $resolver->repairContractorIds();

        return response()->json([
            'ok' => true,
            'repaired_customers' => $customers,
            'fixed_contractors' => $contractors,
        ]);
    }

    public function updateContractorPhone(string $secret): JsonResponse
    {
        $this->authorizeDeploy($secret);

        $contractor = User::where('email', 'contractor@hsop.com')->where('role', 'contractor')->firstOrFail();
        $profile = Contractor::where('user_id', $contractor->id)->first();

        $beforeUser = $contractor->phone;
        $beforeProfile = $profile?->phone;

        $contractor->update(['phone' => '6043187393']);
        if ($profile) {
            $profile->update(['phone' => '6043187393']);
        }

        $contractor->refresh();
        $profile?->refresh();

        return response()->json([
            'ok' => true,
            'contractor' => $contractor->name,
            'phone_before_user' => $beforeUser,
            'phone_after_user' => $contractor->phone,
            'phone_before_profile' => $beforeProfile,
            'phone_after_profile' => $profile?->phone,
            'sms_reads' => \App\Services\SmsService::phoneForUser($contractor),
            'message' => 'Mike Contractor phone updated to 6043187393 on user and contractor profile',
        ]);
    }

    public function fixCustomerAssignments(string $secret): JsonResponse
    {
        $this->authorizeDeploy($secret);

        $admin = User::where('email', 'admin@hsop.com')->first();
        $hailey = User::where('email', 'haileysmith067@gmail.com')->first();

        $fixedJobs = 0;
        $fixedLeads = 0;

        if ($admin) {
            $fixedJobs = Job::where('customer_id', $admin->id)->count();
            if ($fixedJobs > 0) {
                Job::where('customer_id', $admin->id)->update(['customer_id' => $hailey?->id]);
            }

            $fixedLeads = Lead::where('customer_id', $admin->id)->count();
            if ($fixedLeads > 0) {
                Lead::where('customer_id', $admin->id)->update(['customer_id' => $hailey?->id]);
            }
        }

        return response()->json([
            'ok' => true,
            'fixed_jobs' => $fixedJobs,
            'fixed_leads' => $fixedLeads,
            'hailey_id' => $hailey?->id,
        ]);
    }

    private function authorizeDeploy(string $secret): void
    {
        $expected = env('DEPLOY_SECRET');
        if (! $expected || ! hash_equals($expected, $secret)) {
            abort(403, 'Invalid deploy secret');
        }
    }

    private function ok(string $command): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'command' => $command,
            'output' => trim(Artisan::output()),
        ]);
    }
}
