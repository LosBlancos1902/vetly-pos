<?php

namespace App\Services;

use App\Models\Tenant\Customer;
use App\Models\Tenant\Sale;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Two-way sync with the Vetly platform.
 */
class VetlySyncService
{
    private function client()
    {
        return Http::baseUrl((string) config('services.vetly.base_url'))
            ->withToken((string) config('services.vetly.token'))
            ->acceptJson()
            ->retry(3, 1000);
    }

    /**
     * Consume a Vetly customer webhook payload.
     *
     * @param  array{vetly_customer_id: string, name: string, phone?: string, email?: string}  $payload
     */
    public function syncCustomerFromVetly(array $payload): Customer
    {
        return Customer::updateOrCreate(
            ['vetly_customer_id' => $payload['vetly_customer_id']],
            [
                'code' => $payload['code'] ?? ('VET-'.$payload['vetly_customer_id']),
                'name' => $payload['name'],
                'phone' => $payload['phone'] ?? null,
                'email' => $payload['email'] ?? null,
            ],
        );
    }

    /**
     * Push a completed sale to Vetly when the customer is linked.
     */
    public function pushSaleToVetly(Sale $sale): void
    {
        $sale->loadMissing('customer');

        if (! $sale->customer || ! $sale->customer->vetly_customer_id) {
            return;
        }

        try {
            $this->client()->post('/v1/sales', [
                'vetly_customer_id' => $sale->customer->vetly_customer_id,
                'invoice_no' => $sale->invoice_no,
                'total' => (float) $sale->total,
                'date' => optional($sale->date)->toIso8601String(),
            ])->throw();
        } catch (\Throwable $e) {
            Log::warning('Vetly push failed', ['sale' => $sale->id, 'error' => $e->getMessage()]);
        }
    }
}
