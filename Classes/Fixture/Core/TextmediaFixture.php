<?php

declare(strict_types=1);

namespace Xima\XimaTypo3Fixtures\Fixture\Core;

use Xima\XimaTypo3Fixtures\Domain\Model\FixtureVariant;
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

    public function getVariants(): array
    {
        $base = ['header_layout' => 2, 'bodytext' => self::LOREM_LONG];
        return [
            new FixtureVariant('Media oben',        $base + ['header' => 'Text & Media — oben',        'imageorient' => 0]),
            new FixtureVariant('Media rechts/Wrap',  $base + ['header' => 'Text & Media — rechts/Wrap', 'imageorient' => 17]),
            new FixtureVariant('Media links/Wrap',   $base + ['header' => 'Text & Media — links/Wrap',  'imageorient' => 18]),
            new FixtureVariant('Media unten',        $base + ['header' => 'Text & Media — unten',       'imageorient' => 8]),
        ];
    }

    public function getFields(): array
    {
        return $this->getVariants()[0]->fields;
    }
}
