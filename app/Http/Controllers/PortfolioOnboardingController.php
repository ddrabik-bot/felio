<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

final class PortfolioOnboardingController extends Controller
{
    public function create(Request $request): Response|RedirectResponse
    {
        if ($this->hasActivePortfolio($request)) {
            return redirect('/portfolio/imports/xtb');
        }

        return Inertia::render('Portfolio/Onboarding');
    }

    public function store(Request $request): RedirectResponse
    {
        if ($this->hasActivePortfolio($request)) {
            return redirect('/portfolio/imports/xtb');
        }

        $validated = $request->validate([
            'accountReference' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('portfolio_accounts', 'account_reference')->where('broker', 'xtb')->ignore(null, 'account_reference'),
            ],
        ]);

        DB::transaction(function () use ($request, $validated): void {
            $userId = $request->user()->id;

            DB::table('users')->where('id', $userId)->lockForUpdate()->firstOrFail();

            if (DB::table('portfolio_accounts')->where('user_id', $userId)->where('is_active', true)->exists()) {
                return;
            }

            $accountReference = ($validated['accountReference'] ?? null) !== null && ($validated['accountReference'] ?? '') !== ''
                ? $validated['accountReference']
                : null;

            DB::table('portfolio_accounts')->insert([
                'user_id' => $userId,
                'broker' => 'xtb',
                'account_reference' => $accountReference,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        return redirect('/portfolio/imports/xtb');
    }

    private function hasActivePortfolio(Request $request): bool
    {
        return DB::table('portfolio_accounts')
            ->where('user_id', $request->user()->id)
            ->where('is_active', true)
            ->exists();
    }
}
