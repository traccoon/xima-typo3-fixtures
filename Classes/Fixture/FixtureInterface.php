<?php

declare(strict_types=1);

namespace Xima\XimaTypo3Fixtures\Fixture;

interface FixtureInterface
{
    public function getCType(): string;

    public function getLabel(): string;

    /**
     * Returns the tt_content field values for this fixture.
     *
     * @return array<string, mixed>
     */
    public function getFields(): array;
}
