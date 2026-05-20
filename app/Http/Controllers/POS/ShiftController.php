<?php

namespace App\Http\Controllers\POS;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Shift;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

class ShiftController extends Controller
{
    public function open(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'warehouse_id' => ['required', 'integer'],
            'opening_cash' => ['required', 'numeric', 'min:0'],
        ]);

        Shift::create([
            'cashier_id' => $request->user()->id,
            'warehouse_id' => $data['warehouse_id'],
            'opened_at' => now(),
            'opening_cash' => $data['opening_cash'],
            'status' => 'open',
        ]);

        return back()->with('success', 'Shift dibuka.');
    }

    public function close(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'shift_id' => ['required', 'integer'],
            'closing_cash' => ['required', 'numeric', 'min:0'],
            'expected_cash' => ['required', 'numeric', 'min:0'],
        ]);

        $shift = Shift::where('id', $data['shift_id'])
            ->where('cashier_id', $request->user()->id)
            ->firstOrFail();

        $shift->update([
            'closed_at' => now(),
            'closing_cash' => $data['closing_cash'],
            'expected_cash' => $data['expected_cash'],
            'cash_variance' => $data['closing_cash'] - $data['expected_cash'],
            'status' => 'closed',
        ]);

        return back()->with('success', 'Shift ditutup.');
    }
}
