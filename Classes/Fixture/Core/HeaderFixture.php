<?php

declare(strict_types=1);

namespace Xima\XimaTypo3Fixtures\Fixture\Core;

use Xima\XimaTypo3Fixtures\Domain\Model\FixtureVariant;
use Xima\XimaTypo3Fixtures\Fixture\AbstractFixture;

class HeaderFixture extends AbstractFixture
{
    public function getCType(): string
    {
        return 'header';
    }

    public function getLabel(): string
    {
        return 'Header';
    }

    public function getVariants(): array
    {
        $subheader = 'An optional subheader line';
        return [
            new FixtureVariant('H1', ['header' => 'Heading Level 1', 'header_layout' => 1, 'subheader' => $subheader]),
            new FixtureVariant('H2', ['header' => 'Heading Level 2', 'header_layout' => 2, 'subheader' => $subheader]),
            new FixtureVariant('H3', ['header' => 'Heading Level 3', 'header_layout' => 3, 'subheader' => $subheader]),
            new FixtureVariant('H4', ['header' => 'Heading Level 4', 'header_layout' => 4, 'subheader' => $subheader]),
            new FixtureVariant('H5', ['header' => 'Heading Level 5', 'header_layout' => 5, 'subheader' => $subheader]),
        ];
    }

    public function getFields(): array
    {
        return $this->getVariants()[0]->fields;
    }
}
