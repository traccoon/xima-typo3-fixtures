<?php

declare(strict_types=1);

namespace Xima\XimaTypo3Fixtures\Fixture\Core;

use Xima\XimaTypo3Fixtures\Fixture\AbstractFixture;

class BulletsFixture extends AbstractFixture
{
    public function getCType(): string
    {
        return 'bullets';
    }

    public function getLabel(): string
    {
        return 'Bullet List';
    }

    public function getFields(): array
    {
        return [
            'header' => 'Example Bullet List',
            'header_layout' => 2,
            'bodytext' => "First list item\nSecond list item\nThird list item\nFourth list item",
            'bullets_type' => 0,
        ];
    }
}
