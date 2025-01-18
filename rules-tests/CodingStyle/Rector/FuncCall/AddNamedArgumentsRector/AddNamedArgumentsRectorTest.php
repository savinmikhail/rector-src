<?php

declare(strict_types=1);

namespace Rector\Tests\CodingStyle\Rector\FuncCall\AddNamedArgumentsRector;

use Iterator;
use PHPUnit\Framework\Attributes\DataProvider;
use Rector\CodingStyle\Rector\FuncCall\AddNamedArgumentsRector;
use Rector\Testing\PHPUnit\AbstractRectorTestCase;

final class AddNamedArgumentsRectorTest extends AbstractRectorTestCase
{
    #[DataProvider('provideCases')]
    public function test(string $filePath): void
    {
        $this->doTestFile($filePath);
    }

    /**
     * @return Iterator<array<int, string>>
     */
    public static function provideCases(): Iterator
    {
        return self::yieldFilesFromDirectory(__DIR__ . '/Fixture');
    }

    public function provideConfigFilePath(): string
    {
        return __DIR__ . '/config/rector.php';
    }

    protected function getRectorClass(): string
    {
        return AddNamedArgumentsRector::class;
    }
}
