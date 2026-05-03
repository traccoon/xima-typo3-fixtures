<?php

declare(strict_types=1);

namespace Xima\XimaTypo3Fixtures\Fixture\Core;

use Xima\XimaTypo3Fixtures\Fixture\AbstractFixture;

class TextFixture extends AbstractFixture
{
    public function getCType(): string
    {
        return 'text';
    }

    public function getLabel(): string
    {
        return 'Text';
    }

    public function getFields(): array
    {
        return [
            'header' => 'Example Text Element',
            'header_layout' => 2,
            'bodytext' => self::LOREM_LONG,
        ];
    }
}
