<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Http\Requests\App\StoreActualItemRequest;
use App\Http\Requests\App\UpdateActualItemRequest;
use App\Models\ActualItem;
use App\Services\BankImport\ActualEntryRecalculator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class ActualItemController extends Controller
{
    public function __construct(private readonly ActualEntryRecalculator $recalculator) {}

    public function store(StoreActualItemRequest $request): RedirectResponse
    {
        $item = DB::transaction(function () use ($request): ActualItem {
            $item = ActualItem::query()->create($request->validated());
            $this->recalculateFor($item);

            return $item;
        });

        return back()->with('success', "Voce \"{$item->description}\" aggiunta.");
    }

    public function update(UpdateActualItemRequest $request, ActualItem $actualItem): RedirectResponse
    {
        DB::transaction(function () use ($request, $actualItem): void {
            $previous = clone $actualItem;
            $actualItem->update($request->validated());

            $this->recalculateFor($previous);
            $this->recalculateFor($actualItem);
        });

        return back()->with('success', 'Voce aggiornata.');
    }

    public function destroy(ActualItem $actualItem): RedirectResponse
    {
        DB::transaction(function () use ($actualItem): void {
            $actualItem->delete();
            $this->recalculateFor($actualItem);
        });

        return back()->with('success', 'Voce eliminata.');
    }

    private function recalculateFor(ActualItem $item): void
    {
        $this->recalculator->recalculate($item->category_id, $item->date->year, $item->date->month);
    }
}
