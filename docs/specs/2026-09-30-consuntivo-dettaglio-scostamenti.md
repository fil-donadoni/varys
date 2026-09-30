# Consuntivo: dettaglio voci e analisi scostamenti

Data: 2026-09-30

## Problema

- Il consuntivo mostra per categoria/mese un solo input (parte manuale) più una label "+ X da banca = Y".
  Non si vede quali movimenti compongono il totale, né si possono avere più voci manuali distinte.
- I movimenti bancari di un import confermato non sono più modificabili ("elimina e reimporta").
- Non esiste una vista che confronti budget e consuntivo per categoria nel tempo
  (es. "previsti 300 €/mese per pranzi e cene, media reale 420 €, picco 580 € ad agosto").

## Obiettivi

1. Ogni categoria del mese è espandibile e mostra tutte le voci che compongono l'effettivo:
   movimenti bancari confermati e voci manuali.
2. Voci manuali: lista di righe (data, descrizione, importo), ognuna modificabile/eliminabile.
3. Movimenti bancari confermati: dal consuntivo si possono ricategorizzare o escludere.
   Importo e data restano quelli della banca.
4. Scostamento budget/consuntivo per categoria leggibile a colpo d'occhio, sia per mese sia per anno.

## Modello dati

### Nuova tabella `actual_items`

| colonna       | tipo                      | note                                                    |
|---------------|---------------------------|---------------------------------------------------------|
| id            | bigint                    |                                                         |
| category_id   | FK categories, cascade    |                                                         |
| date          | date                      | il mese contabile deriva dalla data                     |
| description   | string                    | obbligatoria                                            |
| amount        | decimal(12,2)             | positivo = aumenta l'effettivo della categoria (come oggi `manual_amount`); negativo ammesso (rimborsi) |
| timestamps    |                           |                                                         |

Indice su `(category_id, date)`. Model `ActualItem` (`$guarded = ['id']`, `casts()`, relazione `category()`),
`Category::actualItems()`. Factory e seeder.

### `actual_entries` resta come aggregato

Dashboard e riconciliazioni continuano a leggere `actual_entries`. Diventa una cache derivata:

- `manual_amount` = somma `actual_items` della categoria nel mese
- `imported_amount` = somma movimenti `Confirmed` (logica attuale, segno per tipo categoria)
- `amount` = somma delle due

`ActualEntryRecalculator::recalculate()` calcola anche `manual_amount` dalle voci invece di leggerlo dalla riga.
`description`/`notes` di `actual_entries` non sono più usati dalla UI (colonne lasciate, nessuna rimozione).

### Migrazione dati

Per ogni `actual_entry` con `manual_amount <> 0` crea una `actual_item` con
`date` = 1° del mese, `description` = `description` esistente oppure "Voce manuale", `amount` = `manual_amount`.

### Backup (DataExportController)

- Export: nuovo file `actual_items.csv` (`id, category_id, date, description, amount`).
- Import: se `actual_items.csv` c'è, importa le voci; se manca (backup vecchi) genera le voci da `manual_amount`
  con la stessa regola della migrazione. Reset sequence di `actual_items`.

## Backend

### Voci manuali

Rotte (`ActualItemController`):

- `POST actual-items` → store (`StoreActualItemRequest`)
- `PUT actual-items/{actual_item}` → update (`UpdateActualItemRequest`)
- `DELETE actual-items/{actual_item}` → destroy

Regole: `category_id` required exists, `date` required date, `description` required string max 255,
`amount` required numeric diverso da 0.
Dopo ogni operazione ricalcola il mese coinvolto; su update, se cambia categoria o mese, ricalcola anche quello vecchio.
Redirect back con flash.

### Movimenti bancari dal consuntivo

`PATCH bank-transactions/{bank_transaction}/reassign` (`ReassignBankTransactionRequest`: `category_id` nullable exists,
`exclude` boolean; uno dei due obbligatorio).

- Ammesso solo su movimenti `Confirmed` (anche con import completato).
- Categoria: stesso vincolo della review (un'uscita non va in una categoria di entrata).
- Exclude: status → `Excluded`.
- Ricalcola categoria vecchia e nuova per il mese di `accounting_date`.
- Nessuna regola esercente creata (resta compito della review import).

La rotta `actual/bulk` e `BulkUpsertActualRequest` vengono rimossi.

### Dati per le viste

`ActualEntryController@index` con query `tab=month|year` (default `month`), `year`, `month`, `category` (da espandere).

**Tab mese**: per categoria `budget`, `actual`, lista voci unificate ordinate per data:
`{ kind: 'bank'|'manual', id, date, description, amount, category_id }`
(per i movimenti: `merchant_label` come descrizione, importo con segno già normalizzato per il tipo categoria).
Solo movimenti `Confirmed` con `accounting_date` nel mese.

**Tab anno**: service `App\Services\ActualVarianceReport`, `build(int $year, CarbonImmutable $today)`:

- per categoria: 12 celle `{ month, budget, actual, status }` con `status` ∈ `closed | current | future`
- mesi chiusi: anno < corrente → 1..12; anno corrente → 1..(mese corrente − 1); anno futuro → nessuno
- `avg_budget`, `avg_actual`: medie sui mesi chiusi (null se zero mesi chiusi)
- `variance` = `avg_actual − avg_budget`, `variance_pct` = variance / avg_budget (null se avg_budget = 0)
- `peak`: mese chiuso con effettivo massimo (`month`, `actual`), null se tutti zero
- totali per tipo (entrate, uscite) e saldo, stesse metriche

## UI (`resources/js/pages/actual/index.tsx`, spezzata in componenti)

### Header comune

Tab `Mese | Anno`, selettore anno, selettore mese (solo tab Mese), `BankImportDialog`.
Tab/anno/mese/categoria in query string.

### Tab Mese

- Card riepilogo come oggi.
- Tabella raggruppata Entrate/Uscite: `Categoria | Budget | Effettivo | Scostamento € | %` +
  barra sottile effettivo/budget. Nessun input in riga; rimossa la label "+ X da banca = Y".
- Click riga → espande le voci: `data | descrizione | importo | origine (Banca/Manuale)`.
  - Banca: `CategoryCombobox` per ricategorizzare + bottone "Escludi".
  - Manuale: modifica inline (data, descrizione, importo), elimina con conferma in-app (niente `confirm()` del browser).
  - Riga "+ Aggiungi voce": data di default = oggi se mese corrente, altrimenti 1° del mese.
- Categorie senza budget né effettivo raggruppate in fondo, collassate ("N categorie vuote").
- Totali per gruppo e saldo netto come oggi.

### Tab Anno

- Matrice: `Categoria | Budget/mese | Media eff. | Scost. € | Scost. % | Picco | Gen … Dic`, raggruppata Entrate/Uscite,
  con righe totale e saldo.
- Cella mese = effettivo. Colore per scostamento rispetto al budget di quel mese
  (uscite; per le entrate la logica si inverte):
  - verde: sotto budget oltre il 10%
  - neutro: entro ±10%
  - ambra: +10% … +30%
  - rosso: oltre +30%, oppure effettivo > 0 senza budget (marcatore "senza budget")
- Mesi futuri: budget in grigio, nessun colore. Mese corrente: bordo tratteggiato, escluso dalle medie.
- Tooltip cella: budget, effettivo, differenza.
- Ordinamento per colonna, default scostamento % decrescente (a parità/null: scostamento €).
- Click cella → tab Mese su quel mese con la categoria espansa.

La logica di classificazione colore sta in una funzione pura in `resources/js/lib/` testata con Vitest.

## Test

- Pest: `ActualVarianceReport` (mesi chiusi per anno passato/corrente/futuro, medie, picco, budget zero),
  CRUD voci manuali + ricalcolo (incluso cambio mese/categoria), reassign/exclude su import completato
  + ricalcolo di entrambe le categorie, vincolo uscita→categoria entrata, migrazione dati, export/import backup
  (con e senza `actual_items.csv`), index tab mese/anno.
- Vitest: classificazione colore scostamento (uscite/entrate, senza budget, soglie).
- Verifica manuale in Chrome di entrambe le tab.

## Fuori scopo

- Modifica di importo/data dei movimenti bancari.
- Creazione regole esercente dal consuntivo.
- Rimozione colonne `description`/`notes` da `actual_entries`.
