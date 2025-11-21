<?php

namespace App\Tests;

use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Survos\SaisBundle\Service\SaisClientService;
use function Symfony\Component\String\u;

class SaisServiceTest extends TestCase
{
    #[TestWith([100, 1], 'small')]
    #[TestWith([1000, 1])]
    #[TestWith([5000, 5])]
    #[TestWith([10000, 10])]
    #[TestWith([82444, 83])]
    #[TestWith([100_000, 100])]
    public function testBinCount(int $approx, int $expected): void
    {

//        $x = SaisClientService::calculatePath($xxh3);
        $actual = SaisClientService::calculateBinCount($approx);
        $this->assertSame($expected, $actual);
    }

    #[TestWith([100, '0'])]
    #[TestWith([1000, '0'], 1000)]
    #[TestWith([11000, '1'], 11000)]
    #[TestWith([130000, 'B'], 130000)]
    #[TestWith([500000, '3F'], 500000)]
    #[TestWith([6500000, '7f'], 6500000)]
    #[TestWith([380000, '4j'], 380000)]
    #[TestWith([920000, '6f'], 920000)]
    public function testBinAssignment(int $approx, string $expected): void
    {

        $xxh3 = SaisClientService::calculateCode('abc.com', 'root');
        $actual = SaisClientService::calculatePath($approx, $xxh3);
        $actual = u($actual)->before('/')->toString();
        $this->assertSame($expected, $actual);
    }


}
