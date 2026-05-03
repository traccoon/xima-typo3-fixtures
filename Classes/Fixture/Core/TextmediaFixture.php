<?php

declare(strict_types=1);

namespace Xima\XimaTypo3Fixtures\Fixture\Core;

use Xima\XimaTypo3Fixtures\Fixture\AbstractFixture;

class TextmediaFixture extends AbstractFixture
{
    public function getCType(): string
    {
        return 'textmedia';
    }

    public function getLabel(): string
    {
        return 'Text & Media';
    }

    public function getFields(): array
    {
        return [
            'header' => 'Example Text & Media Element',
            'header_layout' => 2,
            'bodytext' => self::LOREM_LONG,
            'imageorient' => 17,
        ];
    }
}
