<?php

namespace Tests\Unit;

use App\Support\Stock\LotNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LotNormalizerTest extends TestCase
{
    #[DataProvider('noLotAliases')]
    public function test_no_lot_aliases_return_canonical_value(mixed $value): void
    {
        $this->assertSame(LotNormalizer::NO_LOT, LotNormalizer::normalize($value));
    }

    /**
     * @return iterable<string, array{0:mixed}>
     */
    public static function noLotAliases(): iterable
    {
        yield 'null' => [null];
        yield 'empty' => [''];
        yield 'spaces' => ['   '];
        yield 'canonical' => ['NO LOTE'];
        yield 'title no lote' => ['No Lote'];
        yield 'lower no lote' => ['no lote'];
        yield 'sin lote upper' => ['SIN LOTE'];
        yield 'sin lote title' => ['Sin Lote'];
        yield 'irregular spaces' => [' sin   lote '];
    }

    #[DataProvider('realLots')]
    public function test_real_lots_are_not_reformatted(string $value, string $expected): void
    {
        $this->assertSame($expected, LotNormalizer::normalize($value));
    }

    /**
     * @return iterable<string, array{0:string, 1:string}>
     */
    public static function realLots(): iterable
    {
        yield 'real alphanumeric' => ['LL6E704', 'LL6E704'];
        yield 'real hyphen' => ['A-001', 'A-001'];
        yield 'not ambiguous hyphenated no lot' => ['SIN-LOTE-2026', 'SIN-LOTE-2026'];
        yield 'preserve case' => [' Lot-Mixto-01 ', 'Lot-Mixto-01'];
    }
}
