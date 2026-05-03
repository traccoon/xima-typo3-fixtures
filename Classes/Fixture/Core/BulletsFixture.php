<?php

declare(strict_types=1);

namespace Xima\XimaTypo3Fixtures\Fixture\Core;

use Xima\XimaTypo3Fixtures\Domain\Model\FixtureVariant;
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

    public function getVariants(): array
    {
        $items = "First list item\nSecond list item\nThird list item\nFourth list item";
        $base = ['header_layout' => 2, 'bodytext' => $items];
        return [
            new FixtureVariant('Ungeordnet',     $base + ['header' => 'Bullet List — ungeordnet',   'bullets_type' => 0]),
            new FixtureVariant('Geordnet',       $base + ['header' => 'Bullet List — geordnet',     'bullets_type' => 1]),
            new FixtureVariant('Definitionsliste', $base + ['header' => 'Bullet List — Definition', 'bullets_type' => 2]),
        ];
    }

    public function getFields(): array
    {
        return $this->getVariants()[0]->fields;
    }
}
