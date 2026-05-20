<?php

namespace App\Http\Controllers\Accounting;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Journal;
use Inertia\Inertia;
use Inertia\Response;

class JournalController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Accounting/Journal', [
            'journals' => Journal::with('entries.coa:id,code,name')
                ->latest('date')
                ->paginate(20),
        ]);
    }
}
