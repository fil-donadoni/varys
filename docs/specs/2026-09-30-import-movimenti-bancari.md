# Import movimenti bancari → consuntivi

**Data:** 2026-09-30
**Stato:** approvata

## Obiettivo

Caricare gli export dei movimenti di Intesa Sanpaolo e ING e ritrovare i consuntivi mensili (`actual_entries`) già compilati, senza seguire a mano ogni spesa. L'app chiede conferma solo per le spese di cui non è sicura.

## Perimetro

> Aggiornato il 2026-09-30: il perimetro è stato esteso da "solo spese con carta" a **tutti i movimenti**.

- **Incluso:** tutti i movimenti contabilizzati dei due conti, classificati per tipo (`TransactionKind`):
    - `card`: pagamenti con carta di debito, prepagata o credito, compreso l'addebito mensile della carta di credito ING (esercente `CARTA DI CREDITO ING`, regola iniziale → "Telefono, TV e Internet")
    - `direct_debit`: addebiti diretti SDD
    - `transfer_in` / `transfer_out`: bonifici ricevuti e inviati
    - `other`: tutto il resto (F24, polizze, canoni, bolli, interessi, erogazioni)
- **Importi** con il segno della banca: negativo = uscita, positivo = entrata. Le categorie di spesa sommano le uscite in positivo, quelle di entrata sommano le entrate.
- **Mese del consuntivo:** `accounting_date` = data contabile (ING), oppure l'unica data disponibile (Intesa). Così i consuntivi tornano con i saldi reali dei conti.
- **Giroconti tra conti propri** ed erogazioni di finanziamenti: si escludono con una regola "escludi sempre" sulla controparte (`merchant_rules.exclude`).
- **Movimenti Intesa "NON CONTABILIZZATO":** saltati. Verranno importati quando saranno contabilizzati.
- **Banche:** Intesa (xlsx "Lista Operazione") e ING (xlsx "MovimentiContoCorrenteArancio"). Solo xlsx.
- **Ambiente:** l'app gira solo in locale.
- **Saldo iniziale:** somma dei saldi dei conti al 1/1/2026, inserita a mano dall'utente nelle Impostazioni.

## Principi di privacy

1. Il file caricato non viene salvato: viene letto in memoria e se ne salvano solo le spese con carta.
2. I movimenti esclusi da regola vengono salvati con stato `excluded`, così un nuovo import li riconosce come duplicati.
3. All'LLM vanno **solo nomi di esercenti distinti e normalizzati**, più la lista delle categorie ed eventualmente la categoria proposta dalla banca. Non vanno importi, date, città, indirizzi, numeri di carta o conto, né quante volte compare un esercente.
4. Id casuali usa e getta, ordine mescolato.
5. Gli esercenti che "sembrano una persona" (PayPal verso un utente, `Sum*Nome Cognome`, Satispay…) e **tutte le controparti di bonifici senza forma societaria** (Srl, SpA, associazione…) non vengono mai inviati all'LLM: vanno direttamente a conferma manuale.
6. Prima di ogni invio c'è un'anteprima della lista che partirà, e l'utente può escludere delle voci.

## Modello dati

### Nuove tabelle

**`bank_imports`**: una riga per ogni file caricato.

| Colonna                      | Tipo                                                    | Note                                          |
| ---------------------------- | ------------------------------------------------------- | --------------------------------------------- |
| `bank`                       | string (enum `Bank`: `intesa`, `ing`)                   |                                               |
| `original_filename`          | string                                                  | solo per riferimento                          |
| `period_start`, `period_end` | date, nullable                                          | min/max data operazione delle spese importate |
| `rows_total`                 | int                                                     | righe movimento nel file                      |
| `rows_card_expenses`         | int                                                     | spese con carta trovate                       |
| `rows_duplicates`            | int                                                     | già presenti da import precedenti             |
| `status`                     | string (enum `BankImportStatus`: `review`, `completed`) |                                               |
| `completed_at`               | timestamp, nullable                                     |                                               |

**`bank_transactions`**: le singole spese con carta.

| Colonna                 | Tipo                                                                                        | Note                                                                                               |
| ----------------------- | ------------------------------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| `bank_import_id`        | FK, cascade delete                                                                          |                                                                                                    |
| `fingerprint`           | string, **unique**                                                                          | hash(bank, data operazione, importo, descrizione grezza normalizzata, n-esima occorrenza nel file) |
| `operation_date`        | date                                                                                        | determina **anno e mese** del consuntivo                                                           |
| `booking_date`          | date, nullable                                                                              | data contabile                                                                                     |
| `amount`                | decimal(12,2)                                                                               | positivo = spesa; negativo = storno/rimborso                                                       |
| `raw_description`       | text                                                                                        | causale originale (resta solo nel DB locale)                                                       |
| `merchant_key`          | string, index                                                                               | esercente normalizzato (chiave per la memoria)                                                     |
| `merchant_label`        | string                                                                                      | nome leggibile                                                                                     |
| `payment_instrument`    | string, nullable                                                                            | es. "Carta di debito", "SUPERFLASH"                                                                |
| `bank_category`         | string, nullable                                                                            | categoria proposta da Intesa                                                                       |
| `category_id`           | FK nullable → categories                                                                    |                                                                                                    |
| `categorization_source` | string nullable (enum `CategorizationSource`: `memory`, `keyword`, `bank`, `llm`, `manual`) |                                                                                                    |
| `confidence`            | decimal(3,2), nullable                                                                      | solo per `llm` e `bank`                                                                            |
| `status`                | string (enum `TransactionStatus`: `to_review`, `auto`, `confirmed`, `excluded`)             |                                                                                                    |

**`merchant_rules`**: la memoria dell'app (esercente → categoria).

| Colonna           | Tipo                                                   | Note                                                                      |
| ----------------- | ------------------------------------------------------ | ------------------------------------------------------------------------- |
| `match_type`      | string (enum `MerchantMatchType`: `exact`, `contains`) | `exact` su `merchant_key`; `contains` = regola a parola chiave            |
| `pattern`         | string                                                 | unique insieme a `match_type`                                             |
| `category_id`     | FK → categories, cascade delete                        |                                                                           |
| `always_ask`      | bool, default false                                    | per esercenti ambigui (Amazon, PayPal generico): propone ma chiede sempre |
| `times_confirmed` | int, default 0                                         |                                                                           |

**`bank_category_mappings`**: categoria della banca → categoria dell'app.

| Colonna         | Tipo                 | Note                             |
| --------------- | -------------------- | -------------------------------- |
| `bank`          | string (enum `Bank`) | unique insieme a `bank_category` |
| `bank_category` | string               | es. "Ristoranti e bar"           |
| `category_id`   | FK nullable          | null = nessuna mappatura         |

### Modifiche a `actual_entries` (opzione C)

- Nuove colonne `manual_amount` decimal(12,2) default 0 e `imported_amount` decimal(12,2) default 0.
- `amount` resta il **totale** (`manual_amount + imported_amount`). Dashboard, riconciliazioni ed export continuano a leggere `amount` senza modifiche.
- Migrazione dei dati esistenti: `manual_amount = amount`, `imported_amount = 0`.
- Servizio `ActualEntryRecalculator::recalculate(categoryId, year, month)`:
    - `imported_amount` = somma delle `bank_transactions` con stato `confirmed` per quella categoria, anno e mese (dalla data operazione)
    - `amount` = `manual_amount + imported_amount`
    - riga eliminata se entrambi sono 0
- Pagina Consuntivo: l'input modifica `manual_amount`. La parte importata compare in sola lettura accanto ("di cui importato: € X", con link all'elenco movimenti). `bulkUpsert` scrive `manual_amount` e poi ricalcola.
- Backup/export (`DataExportController`): includere le nuove colonne e le nuove tabelle, e aumentare la versione del formato del backup.

## Parsing

### Lettura file

`SpreadsheetReader` condiviso (estratto dal comando `bank:anonymize`): da csv/xls/xlsx produce righe di celle. Le celle data di Excel vengono convertite in `CarbonImmutable`. Trova la riga di intestazione.

### Riconoscimento della banca

Dalla riga di intestazione, con possibilità di scegliere la banca a mano nel form:

- **ING:** `DATA CONTABILE | DATA VALUTA | CAUSALE | DESCRIZIONE OPERAZIONE | IMPORTO IN EURO`
- **Intesa:** `Data | Operazione | Dettagli | Conto o carta | Contabilizzazione | Categoria | Valuta | Importo`

Interfaccia `BankStatementParser` con `supports(header): bool` e `parse(rows): list<ParsedCardExpense>`.

### ING (`IngParser`)

- **Spesa con carta:** `CAUSALE = "Pagamento Carta"`, oppure `CAUSALE = "Addebito Carta Di Credito"` (esercente `CARTA DI CREDITO ING`, data operazione = data contabile).
- **Data operazione:** dalla descrizione "Operazione Mastercard del dd/mm/yyyy"; se manca, `DATA VALUTA`.
- **Esercente:** il testo dopo `presso `, senza il suffisso ` - Transazione C-less`.
- **Importo:** `-IMPORTO IN EURO` (spesa positiva).

### Intesa (`IntesaParser`)

- **Spesa con carta** se una di queste è vera:
    - `Dettagli` contiene `Mediante La Carta` / `Carta N.` / `Pagamento Su POS`
    - `Conto o carta` non inizia con "Conto" (carta prepagata, es. SUPERFLASH)

    e in più `Operazione` non inizia con "Canone Carta" e non è un bonifico o una ricarica.

- **Data operazione:** colonna `Data`.
- **Esercente:** colonna `Operazione`.
- **Categoria della banca:** colonna `Categoria`.
- **Righe con importo positivo su carta** (storni/rimborsi): importate come importo negativo nella categoria dell'esercente.

### Normalizzazione dell'esercente (`MerchantNormalizer`)

`merchant_key` si ottiene così:

1. maiuscolo
2. via prefissi e suffissi noti (`- TRANSAZIONE C-LESS`, `NCR`)
3. via i codici transazione (token misti lettere/cifre come `ZG3658CU4`, `#219733428`) e i numeri
4. via la coda indirizzo (`VIA …`, `CORSO …`, `VIALE …`, `PIAZZA …`) e la data/ora incollata (`24/030820`)
5. famiglie note unificate: `AMZN MKTP`, `AMAZON.IT`, `WWW.AMAZON` → `AMAZON`
6. spazi compattati

Esempi: `IPER STATION MAGENTA Corso 24/030820 …` → `IPER STATION MAGENTA`; `AMZN Mktp IT*ZC8XJ0HG4` → `AMAZON`.

### Esercente-persona (`PersonLikeMerchantDetector`)

Euristica:

- `PAYPAL *` seguito da un nome utente in minuscolo o con `.`
- `SUM*` seguito da due parole con iniziale maiuscola
- `SATISPAY`

Può sbagliare in entrambi i sensi. L'anteprima dell'invio permette comunque di escludere voci a mano.

## Categorizzazione

Si lavora per `merchant_key` distinto, con questo ordine. Il primo livello che risponde vince.

| #   | Livello                                                       | Esito                                                               |
| --- | ------------------------------------------------------------- | ------------------------------------------------------------------- |
| 1   | `merchant_rules` exact                                        | `auto` (o `to_review` se `always_ask`)                              |
| 2   | `merchant_rules` contains                                     | `auto`                                                              |
| 3   | esercente-persona                                             | `to_review`, non inviato all'LLM                                    |
| 4   | LLM (su richiesta dell'utente, dopo l'anteprima)              | `auto` se confidenza ≥ soglia (default 0.8), altrimenti `to_review` |
| 5   | `bank_category_mappings` (senza LLM, o se l'LLM non risponde) | `to_review` con categoria proposta                                  |
| 6   | nessuna proposta                                              | `to_review` senza categoria                                         |

La categoria di Intesa **non viene applicata in automatico**: nei campioni è spesso imprecisa (Google Cloud → "Tempo libero varie", Nespresso → "Generi alimentari"). Viene passata all'LLM come suggerimento ed è la proposta di riserva.

### LLM

- Interfaccia `MerchantCategorizer::categorize(list<MerchantInput>, list<CategoryOption>): list<MerchantSuggestion>`.
    - `MerchantInput { id, label, bankCategory? }`
    - `MerchantSuggestion { id, categoryId|null, confidence, ambiguous }`
- Implementazioni, scelte con `BANK_IMPORT_LLM=claude|ollama|none`:
    - `ClaudeMerchantCategorizer`: SDK ufficiale `anthropic-ai/sdk`, output strutturato (`outputConfig.format` json_schema; `category_id` vincolato all'elenco degli id). Modello da `.env` (`ANTHROPIC_MODEL`, default `claude-opus-5-5` con effort `low`; si può impostare un modello più economico, es. `claude-haiku-4-5`). Chiave `ANTHROPIC_API_KEY`. Gestione di `stop_reason = refusal`.
    - `OllamaMerchantCategorizer`: `Http` → `OLLAMA_URL/api/chat` (default `http://localhost:11434`) con `format` = schema JSON, modello da `OLLAMA_MODEL`.
    - `NullMerchantCategorizer`: nessun invio.
- Invio a blocchi (default 50 esercenti per richiesta).
- Prompt in italiano. Istruzioni:
    - scegli solo tra le categorie date
    - `ambiguous = true` per esercenti che vendono di tutto (marketplace, PayPal generico, grandi magazzini)
    - `category_id = null` se l'esercente non è riconoscibile
- `LlmPayloadBuilder` produce il payload ed è testato: non contiene cifre di importi né date, gli id sono casuali e l'ordine è mescolato.
- Errori (rete, 4xx/5xx, refusal): messaggio flash; gli esercenti restano da confermare a mano.

## Flusso utente (UI in italiano)

1. **`/bank-imports`**: elenco degli import (data, banca, periodo, spese, stato) e form di upload (file + banca "Riconosci automaticamente / Intesa / ING").
2. **Upload** (`POST /bank-imports`): parsing, deduplica per `fingerprint`, livelli locali 1–3 e 5, poi redirect alla revisione.
3. **Revisione** (`/bank-imports/{id}`):
    - Riepilogo: righe nel file, spese con carta, duplicati saltati, periodo.
    - Riquadro **"Da inviare all'AI"**: esercenti distinti senza regola, ciascuno con checkbox. Pulsante "Categorizza con AI" (`POST /bank-imports/{id}/categorize`). Nascosto se `BANK_IMPORT_LLM=none`.
    - Tabella **"Da confermare"**: data, esercente, importo, select della categoria (solo categorie di spesa), origine della proposta (Memoria/Regola/Banca/AI) e confidenza. Cambiare la categoria di una riga la applica a tutte le righe dello stesso esercente nell'import. Opzione per riga "Escludi".
    - Sezione **"Categorizzate automaticamente"** (compressa, modificabile).
    - Pulsante **"Conferma import"**, attivo quando ogni riga ha una categoria o è esclusa.
4. **Conferma** (`POST /bank-imports/{id}/complete`), in una transazione DB:
    - le righe passano a `confirmed`
    - si creano/aggiornano le `merchant_rules` exact per ogni esercente confermato (`times_confirmed++`)
    - si ricalcolano le `actual_entries` toccate (categoria × mese)
    - l'import passa a `completed`
    - redirect alla pagina Consuntivo del mese più recente, con flash
5. **Eliminazione import** (`DELETE /bank-imports/{id}`): elimina le transazioni e ricalcola i consuntivi toccati. Serve come "annulla".
6. **Regole** (`/merchant-rules`): elenco e modifica di regole esercente e parole chiave, mappature delle categorie banca, flag "chiedi sempre".

Le route seguono le convenzioni esistenti: controller in `app/Http/Controllers/App/`, Form Request `Store`/`Update` separate, Wayfinder, pagine in `resources/js/pages/bank-imports/`.

## Test

- **Parser:** fixture ricavate dai due file `.anon` e ridotte a righe rappresentative (in `tests/Fixtures/bank/`). Test con Pest per:
    - filtro delle spese con carta (incluse le esclusioni: canone, addebito carta di credito, bonifici, SDD)
    - data operazione
    - importo
    - esercente
- **`MerchantNormalizer`** e **`PersonLikeMerchantDetector`:** dataset di casi reali dai campioni.
- **Pipeline di categorizzazione:** ordine dei livelli, `always_ask`, soglia di confidenza.
- **`LlmPayloadBuilder`:** assenza di importi e date, id casuali, esclusione delle persone.
- **Categorizer:** `Http::fake` per Ollama; per Claude, un binding finto dell'interfaccia e un test di mapping della risposta.
- **Ricalcolo consuntivi:** manual + imported, eliminazione a zero, ri-import senza doppi conteggi, eliminazione dell'import.
- **Feature test:** upload → revisione → conferma → `actual_entries` corrette.
- **Frontend:** test Vitest per la tabella di revisione (propagazione della categoria per esercente).

## Fasi di implementazione

1. Refactor di `SpreadsheetReader`, enum, migrazioni, modelli, factory e seeder.
2. `IngParser`, `IntesaParser`, `MerchantNormalizer`, `PersonLikeMerchantDetector` con i test.
3. Opzione C su `actual_entries`: migrazione, recalculator, pagina Consuntivo, backup.
4. Upload, revisione e conferma con i soli livelli locali (l'app è già usabile senza LLM).
5. Integrazione `MerchantCategorizer`: Claude, poi Ollama.
6. Pagina Regole.

## Decisioni sulle domande aperte

1. Storni/rimborsi su carta → importo negativo nella categoria dell'esercente.
2. Addebito della carta di credito ING → importato; regola iniziale verso "Telefono, TV e Internet".
3. ING → solo xlsx.

## Aggiornamenti successivi

- Colonne aggiunte a `bank_transactions`: `kind`, `accounting_date`. `amount` ha il segno della banca.
- `bank_imports.rows_card_expenses` → `rows_imported`.
- `merchant_rules.category_id` è nullable, e c'è la nuova colonna `exclude` (bool).
