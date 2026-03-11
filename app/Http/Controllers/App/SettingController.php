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
                'saldo_iniziale' => Setting::getValue('saldo_iniziale', '0'),
                'limite_fatturato_annuale' => Setting::getValue('limite_fatturato_annuale', '0'),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'saldo_iniziale' => ['required', 'numeric', 'min:0'],
            'limite_fatturato_annuale' => ['required', 'numeric', 'min:0'],
        ]);

        foreach ($validated as $key => $value) {
            Setting::setValue($key, (string) $value);
        }

        return redirect()->route('settings.index')->with('success', 'Impostazioni salvate.');
    }
}
