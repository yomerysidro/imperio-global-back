<?php

namespace App\Services\Core;

class CodeGenerator
{
    public function generate() : string
    {
        $length = 4;
        $characters = mb_str_split('123456789');

        return collect(range(0, $length - 1))
            ->map(function () use ($characters) {
                return $characters[rand(0, count($characters) - 1)];
            })
            ->join('');
    }
}
