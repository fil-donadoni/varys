<?php

namespace App\Enums;

enum CategorizationSource: string
{
    case Memory = 'memory';
    case SimilarMemory = 'similar';
    case Keyword = 'keyword';
    case Bank = 'bank';
    case Llm = 'llm';
    case Manual = 'manual';

    public function label(): string
    {
        return match ($this) {
            self::Memory => 'Memoria',
            self::SimilarMemory => 'Memoria (simile)',
            self::Keyword => 'Regola',
            self::Bank => 'Banca',
            self::Llm => 'AI',
            self::Manual => 'Manuale',
        };
    }
}
