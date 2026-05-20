<?php

use App\Models\Tenant\Inventory;
use App\Services\HppCalculator;

it('computes moving average on inbound stock', function () {
    $inv = new Inventory(['qty' => 10, 'cost_avg' => 1000]);

    $result = (new HppCalculator())->recalculate($inv, 10, 2000);

    // ((10*1000) + (10*2000)) / 20 = 1500
    expect($result['qty'])->toBe('20.0000')
        ->and($result['cost_avg'])->toBe('1500.00');
});

it('keeps cost average unchanged on outbound stock', function () {
    $inv = new Inventory(['qty' => 20, 'cost_avg' => 1500]);

    $result = (new HppCalculator())->recalculate($inv, -5, 0);

    expect($result['qty'])->toBe('15.0000')
        ->and($result['cost_avg'])->toBe('1500.00');
});
