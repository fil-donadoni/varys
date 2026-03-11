<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SettingController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('settings/index', [
            'settings' => [
                'opening_balance' => Setting::getValue('opening_balance', '0'),
                'annual_invoice_limit' => Setting::getValue('annual_invoice_limit', '0'),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'opening_balance' => ['required', 'numeric', 'min:0'],
            'annual_invoice_limit' => ['required', 'numeric', 'min:0'],
        ]);

        foreach ($validated as $key => $value) {
            Setting::setValue($key, (string) $value);
        }

        return redirect()->route('settings.index')->with('success', 'Impostazioni salvate.');
    }
}
