<?php

namespace App\Console\Commands;

use App\Support\PayMongo;
use Illuminate\Console\Command;
use Illuminate\Http\Client\RequestException;

/**
 * Points PayMongo's webhook at this app. A free Cloudflare quick tunnel gets
 * a new https://*.trycloudflare.com address every time it starts, so run
 * this after starting the tunnel:
 *
 *   php artisan paymongo:webhook https://abc-xyz.trycloudflare.com
 *
 * First run creates the webhook and prints the id + secret to put in .env;
 * later runs (with PAYMONGO_WEBHOOK_ID set) just update its address.
 */
class PaymongoWebhook extends Command
{
    protected $signature = 'paymongo:webhook {base_url : Public https base URL, e.g. your Cloudflare tunnel address}';

    protected $description = 'Create or update the PayMongo webhook to point at {base_url}/webhooks/paymongo';

    private const EVENTS = ['checkout_session.payment.paid'];

    public function handle(): int
    {
        if (! PayMongo::enabled()) {
            $this->error('PAYMONGO_SECRET_KEY is not set in .env.');
            return self::FAILURE;
        }

        $base = rtrim($this->argument('base_url'), '/');
        if (! str_starts_with($base, 'https://')) {
            $this->error('PayMongo needs a public https:// address (e.g. your Cloudflare tunnel URL).');
            return self::FAILURE;
        }

        $url = $base . '/webhooks/paymongo';
        $id = config('services.paymongo.webhook_id');

        try {
            if ($id) {
                $hook = PayMongo::updateWebhook($id, $url, self::EVENTS);
                $this->info('Webhook updated → ' . data_get($hook, 'attributes.url'));
                return self::SUCCESS;
            }

            $hook = PayMongo::createWebhook($url, self::EVENTS);
        } catch (RequestException $e) {
            $this->error('PayMongo rejected the request: ' . $e->response?->body());
            return self::FAILURE;
        }

        $this->info('Webhook created → ' . data_get($hook, 'attributes.url'));
        $this->newLine();
        $this->line('Add these two lines to .env, then run: php artisan config:clear');
        $this->newLine();
        $this->line('PAYMONGO_WEBHOOK_ID=' . data_get($hook, 'id'));
        $this->line('PAYMONGO_WEBHOOK_SECRET=' . data_get($hook, 'attributes.secret_key'));

        return self::SUCCESS;
    }
}
