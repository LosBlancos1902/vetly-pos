<?php

namespace App\Http\Controllers\Api\Vetly;

use App\Http\Controllers\Controller;
use App\Services\VetlySyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VetlyWebhookController extends Controller
{
    public function customer(Request $request, VetlySyncService $vetly): JsonResponse
    {
        // Shared-secret guard for the inbound webhook.
        $expected = (string) config('services.vetly.token');
        if ($expected === '' || ! hash_equals($expected, (string) $request->bearerToken())) {
            abort(401, 'Invalid Vetly webhook token.');
        }

        $payload = $request->validate([
            'vetly_customer_id' => ['required', 'string'],
            'name' => ['required', 'string'],
            'phone' => ['nullable', 'string'],
            'email' => ['nullable', 'email'],
            'code' => ['nullable', 'string'],
        ]);

        $customer = $vetly->syncCustomerFromVetly($payload);

        return response()->json(['ok' => true, 'customer_id' => $customer->id]);
    }
}
