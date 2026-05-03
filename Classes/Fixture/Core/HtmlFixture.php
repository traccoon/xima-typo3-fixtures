<?php

declare(strict_types=1);

namespace Xima\XimaTypo3Fixtures\Fixture\Core;

use Xima\XimaTypo3Fixtures\Fixture\AbstractFixture;

class HtmlFixture extends AbstractFixture
{
    public function getCType(): string
    {
        return 'html';
    }

    public function getLabel(): string
    {
        return 'HTML';
    }

    public function getFields(): array
    {
        return [
            'header' => 'Example HTML Element',
            'header_layout' => 100,
            'bodytext' => '<div class="example-html"><p>This is a <strong>plain HTML</strong> content element.</p><ul><li>Item A</li><li>Item B</li><li>Item C</li></ul></div>',
        ];
    }
}
