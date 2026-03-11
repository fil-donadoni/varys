<?php

namespace App\Enums;

enum Month: int
{
    case January = 1;
    case February = 2;
    case March = 3;
    case April = 4;
    case May = 5;
    case June = 6;
    case July = 7;
    case August = 8;
    case September = 9;
    case October = 10;
    case November = 11;
    case December = 12;

    public function label(): string
    {
        return match ($this) {
            self::January => 'Gennaio',
            self::February => 'Febbraio',
            self::March => 'Marzo',
            self::April => 'Aprile',
            self::May => 'Maggio',
            self::June => 'Giugno',
            self::July => 'Luglio',
            self::August => 'Agosto',
            self::September => 'Settembre',
            self::October => 'Ottobre',
            self::November => 'Novembre',
            self::December => 'Dicembre',
        };
    }
}
