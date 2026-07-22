<?php

namespace App\Console\Commands;

use App\Contracts\PaymentProviderInterface;
use App\Models\AiActionLog;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Console\Command;
use Throwable;

class ProcessScheduledPayoutsCommand extends Command
{
    protected $signature = 'payouts:process-scheduled {--dry-run : List payouts without transferring}';

    protected $description = 'Execute Stripe transfers for payouts whose scheduled_for date has arrived';

    public function handle(PaymentProviderInterface $payments): int
    {
        $query = Payout::query()
            ->whereIn('status', ['scheduled', 'queued', 'eligible', 'approved', 'ready_for_payout'])
            ->where(function ($q) {
                $q->whereNull('scheduled_for')
                    ->orWhereDate('scheduled_for', '<=', now()->toDateString());
            })
            ->whereNotNull('payout_amount')
            ->where('payout_amount', '>', 0);

        $payouts = $query->orderBy('id')->get();
        $this->info('Found '.$payouts->count().' payout(s) due for transfer');

        if ($this->option('dry-run')) {
            foreach ($payouts as $p) {
                $this->line("#{$p->id} {$p->split_type}/{$p->payout_type} \${$p->payout_amount} status={$p->status}");
            }

            return self::SUCCESS;
        }

        $ok = 0;
        $queued = 0;
        $failed = 0;

        foreach ($payouts as $payout) {
            try {
                $result = $payments->createTransfer($payout->fresh());
                $status = $result['status'] ?? '';
                if ($status === 'queued') {
                    $queued++;
                    $this->warn("Payout #{$payout->id} queued");
                } else {
                    $ok++;
                    $this->info("Payout #{$payout->id} → {$status} ({$result['transfer_id']})");
                }

                AiActionLog::create([
                    'trigger_event' => 'payout_transfer_executed',
                    'actor_id' => User::where('role', 'owner')->value('id'),
                    'data_viewed' => [
                        'payout_id' => $payout->id,
                        'result' => $result,
                    ],
                    'decision' => $status,
                    'action_taken' => 'stripe_transfer',
                    'rule_applied' => 'scheduled_for <= today → transfer',
                    'required_human_approval' => false,
                ]);
            } catch (Throwable $e) {
                $failed++;
                $this->error("Payout #{$payout->id} failed: ".$e->getMessage());

                // Soft-fail: leave retryable, never abort the whole sweep loudly
                try {
                    $payout->fresh()?->update([
                        'status' => 'queued',
                        'eligibility_status' => 'Transfer error — queued: '.mb_substr($e->getMessage(), 0, 180),
                    ]);
                } catch (Throwable) {
                    //
                }

                AiActionLog::create([
                    'trigger_event' => 'payout_transfer_deferred',
                    'actor_id' => User::where('role', 'owner')->value('id'),
                    'data_viewed' => ['payout_id' => $payout->id],
                    'decision' => 'queued',
                    'action_taken' => 'catch_transfer_exception',
                    'rule_applied' => 'unhandled transfer exception → queued',
                    'required_human_approval' => false,
                    'error' => mb_substr($e->getMessage(), 0, 500),
                ]);
            }
        }

        $this->info("Done. transferred/paid={$ok} queued={$queued} failed={$failed}");

        // Queued/deferred is success for ops — only hard process crashes should fail the command.
        return self::SUCCESS;
    }
}
