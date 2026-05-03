<?php

declare(strict_types=1);

namespace Xima\XimaTypo3Fixtures\Fixture\Core;

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

    public function getFields(): array
    {
        return [
            'header' => 'Example Header',
            'header_layout' => 1,
            'subheader' => 'An optional subheader line',
        ];
    }
}
