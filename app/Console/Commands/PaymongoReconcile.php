<?php

namespace App\Console\Commands;

use App\Models\PaymongoCheckout;
use App\Support\PayMongo;
use Illuminate\Console\Command;

/**
 * Scheduled every five minutes (routes/console.php). Asks PayMongo about
 * every checkout that's still unconfirmed and records the ones that were
 * paid — covers a parent who paid and closed the tab, and a webhook that
 * never arrived. Safe to run any number of times: recording is idempotent.
 */
class PaymongoReconcile extends Command
{
    protected $signature = 'paymongo:reconcile {--hours=48 : Only check checkouts opened within this many hours}';

    protected $description = 'Record paid PayMongo checkouts that were never confirmed (closed tab / missed webhook)';

    public function handle(): int
    {
        if (! PayMongo::enabled()) {
            return self::SUCCESS;
        }

        $checkouts = PaymongoCheckout::where('status', 'pending')
            ->whereNotNull('checkout_session_id')
            ->where('created_at', '>=', now()->subHours((int) $this->option('hours')))
            ->get();

        $recorded = 0;
        foreach ($checkouts as $checkout) {
            try {
                PayMongo::syncCheckout($checkout);
                if ($checkout->refresh()->status === 'paid') {
                    $recorded++;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $this->info("Checked {$checkouts->count()} open checkout(s), recorded {$recorded} payment(s).");

        return self::SUCCESS;
    }
}
