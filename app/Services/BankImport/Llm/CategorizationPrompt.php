<?php

namespace App\Services\BankImport\Llm;

use App\Enums\CategoryType;

/**
 * Prompt, JSON schema and response parsing shared by every LLM provider.
 */
final class CategorizationPrompt
{
    public const string SYSTEM = <<<'TXT'
Sei un assistente che classifica movimenti bancari italiani in categorie di budget familiare.
Ricevi un elenco di esercenti o controparti (nomi normalizzati, senza importi né date) e l'elenco delle categorie disponibili.
Per ogni esercente scegli l'id della categoria più adatta tra quelle fornite, rispettando la direzione:
le "uscita" vanno in categorie di tipo uscita, le "entrata" in categorie di tipo entrata.
Il campo "suggerimento_banca", se presente, è la categoria proposta dalla banca: usalo come indizio, spesso è impreciso.
Regole:
- Usa category_id 0 se non riconosci l'esercente o nessuna categoria è adatta.
- Imposta ambiguous a true per esercenti che vendono di tutto (marketplace, grandi magazzini, PayPal generico, Amazon).
- confidence è un numero tra 0 e 1: quanto sei sicuro della scelta.
- Rispondi per tutti gli id ricevuti, senza aggiungerne altri.
TXT;

    /**
     * @param  list<MerchantInput>  $merchants
     * @param  list<CategoryOption>  $categories
     */
    public static function userMessage(array $merchants, array $categories): string
    {
        return (string) json_encode([
            'categorie' => array_map(fn (CategoryOption $c): array => [
                'id' => $c->id,
                'nome' => $c->name,
                'tipo' => $c->type === CategoryType::Income ? 'entrata' : 'uscita',
            ], $categories),
            'esercenti' => array_map(fn (MerchantInput $m): array => array_filter([
                'id' => $m->id,
                'nome' => $m->name,
                'direzione' => $m->direction,
                'suggerimento_banca' => $m->bankCategory,
            ], fn (?string $value): bool => $value !== null), $merchants),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }

    /**
     * @param  list<CategoryOption>  $categories
     * @return array<string, mixed>
     */
    public static function schema(array $categories): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'results' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'id' => ['type' => 'string'],
                            'category_id' => ['type' => 'integer', 'enum' => [0, ...array_map(fn (CategoryOption $c): int => $c->id, $categories)]],
                            'confidence' => ['type' => 'number'],
                            'ambiguous' => ['type' => 'boolean'],
                        ],
                        'required' => ['id', 'category_id', 'confidence', 'ambiguous'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['results'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Validates the model output: unknown ids and categories are dropped, confidence is clamped.
     *
     * @param  list<MerchantInput>  $merchants
     * @param  list<CategoryOption>  $categories
     * @return list<MerchantSuggestion>
     */
    public static function parse(string $json, array $merchants, array $categories): array
    {
        $data = json_decode($json, true);

        if (! is_array($data) || ! is_array($data['results'] ?? null)) {
            throw new CategorizerException('Risposta del modello non valida.');
        }

        $ids = array_flip(array_map(fn (MerchantInput $m): string => $m->id, $merchants));
        $categoryIds = array_flip(array_map(fn (CategoryOption $c): int => $c->id, $categories));
        $suggestions = [];

        foreach ($data['results'] as $row) {
            if (! is_array($row) || ! is_string($row['id'] ?? null) || ! isset($ids[$row['id']])) {
                continue;
            }

            $categoryId = is_int($row['category_id'] ?? null) && isset($categoryIds[$row['category_id']]) ? $row['category_id'] : null;
            $confidence = is_numeric($row['confidence'] ?? null) ? max(0.0, min(1.0, (float) $row['confidence'])) : 0.0;

            $suggestions[] = new MerchantSuggestion($row['id'], $categoryId, $confidence, (bool) ($row['ambiguous'] ?? false));
        }

        return $suggestions;
    }
}
