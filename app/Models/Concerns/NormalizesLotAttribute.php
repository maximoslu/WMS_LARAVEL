<?php

namespace App\Models\Concerns;

use App\Support\Stock\LotNormalizer;

trait NormalizesLotAttribute
{
    public function getLotAttribute(mixed $value): string
    {
        return LotNormalizer::normalize($value);
    }

    public function setLotAttribute(mixed $value): void
    {
        $this->attributes['lot'] = LotNormalizer::normalize($value);
    }
}
