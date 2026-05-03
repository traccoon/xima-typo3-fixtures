<?php

declare(strict_types=1);

namespace Xima\XimaTypo3Fixtures\Service;

use Xima\XimaTypo3Fixtures\Fixture\FixtureInterface;

class FixtureRegistry
{
    /** @var array<string, FixtureInterface> */
    private array $fixtures = [];

    /**
     * @param iterable<FixtureInterface> $fixtures
     */
    public function __construct(iterable $fixtures)
    {
        foreach ($fixtures as $fixture) {
            $this->fixtures[$fixture->getCType()] = $fixture;
        }
    }

    public function addFixture(FixtureInterface $fixture): void
    {
        $this->fixtures[$fixture->getCType()] = $fixture;
    }

    public function getFixture(string $cType): ?FixtureInterface
    {
        return $this->fixtures[$cType] ?? null;
    }

    /**
     * @return array<string, FixtureInterface>
     */
    public function getAllFixtures(): array
    {
        return $this->fixtures;
    }
}
