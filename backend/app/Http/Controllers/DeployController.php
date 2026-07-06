<?php

namespace App\Http\Controllers;

use App\Services\LeadCustomerResolver;
use App\Services\UploadStorage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

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
            'storage:link' => 'storage:link',
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

    public function storageLink(string $secret): JsonResponse
    {
        $this->authorizeDeploy($secret);
        Artisan::call('storage:link');

        return $this->ok('storage:link');
    }

    public function fixPhotoUrls(string $secret): JsonResponse
    {
        $this->authorizeDeploy($secret);

        $updated = 0;

        foreach (\App\Models\JobUpdatePhoto::all() as $photo) {
            $new = UploadStorage::normalizeStoredUrl($photo->file_url);
            if ($new !== $photo->file_url) {
                $photo->update(['file_url' => $new]);
                $updated++;
            }
        }

        foreach (\App\Models\RevisionRequestPhoto::all() as $photo) {
            $new = UploadStorage::normalizeStoredUrl($photo->file_url);
            if ($new !== $photo->file_url) {
                $photo->update(['file_url' => $new]);
                $updated++;
            }
        }

        foreach (\App\Models\ContractorDocument::all() as $doc) {
            $new = UploadStorage::normalizeStoredUrl($doc->file_url);
            if ($new !== $doc->file_url) {
                $doc->update(['file_url' => $new]);
                $updated++;
            }
        }

        foreach (\App\Models\LeadPhoto::all() as $photo) {
            $new = UploadStorage::normalizeStoredUrl($photo->file_url);
            if ($new !== $photo->file_url) {
                $photo->update(['file_url' => $new]);
                $updated++;
            }
        }

        foreach (\App\Models\Contractor::all() as $contractor) {
            $changes = [];
            foreach (['wcb_file_url', 'insurance_file_url'] as $col) {
                $new = UploadStorage::normalizeStoredUrl($contractor->{$col});
                if ($new !== $contractor->{$col}) {
                    $changes[$col] = $new;
                }
            }
            if ($changes) {
                $contractor->update($changes);
                $updated += count($changes);
            }
        }

        return response()->json([
            'ok' => true,
            'message' => 'Photo URLs normalized to /api/files route.',
            'updated' => $updated,
        ]);
    }

    public function cleanTestData(string $secret): JsonResponse
    {
        $this->authorizeDeploy($secret);

        DB::transaction(function () {
            \App\Models\RevisionRequestPhoto::query()->delete();
            \App\Models\RevisionRequest::query()->delete();
            \App\Models\JobUpdatePhoto::query()->delete();
            \App\Models\JobUpdate::query()->delete();
            \App\Models\Payment::query()->delete();
            \App\Models\Payout::query()->delete();
            \App\Models\Invoice::query()->delete();
            \App\Models\Message::query()->delete();
            \App\Models\Quote::query()->delete();
            \App\Models\Job::query()->delete();
            \App\Models\SiteVisit::query()->delete();
            \App\Models\Lead::query()->delete();
            \App\Models\Customer::query()->delete();
            \App\Models\SmsLog::query()->delete();
            \App\Models\EmailLog::query()->delete();
            \App\Models\AuditLog::query()->delete();

            \App\Models\Contractor::query()->update([
                'wcb_status' => 'not_uploaded',
                'liability_insurance_status' => 'not_uploaded',
                'wcb_file_url' => null,
                'insurance_file_url' => null,
            ]);
        });

        return response()->json([
            'ok' => true,
            'message' => 'Test data cleaned successfully',
            'kept' => 'users, companies, settings, contractor profiles',
            'deleted' => 'leads, jobs, quotes, invoices, payments, payouts, messages, updates, site visits, logs',
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
