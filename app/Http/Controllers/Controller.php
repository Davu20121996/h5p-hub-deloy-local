<?php

namespace App\Http\Controllers;

abstract class Controller
{
    /** @return list<string> */
    protected function plainTextRules(bool $required = true, int $max = 255): array
    {
        return [$required ? 'required' : 'nullable', 'string', 'max:'.$max, 'regex:/\A[^<>\x00-\x08\x0B\x0C\x0E-\x1F\x7F]*\z/u'];
    }
}
