<?php

declare(strict_types=1);

namespace Xima\XimaTypo3Fixtures\Fixture\Core;

use Xima\XimaTypo3Fixtures\Fixture\AbstractFixture;

class TableFixture extends AbstractFixture
{
    public function getCType(): string
    {
        return 'table';
    }

    public function getLabel(): string
    {
        return 'Table';
    }

    public function getFields(): array
    {
        return [
            'header' => 'Example Table',
            'header_layout' => 2,
            'bodytext' => "Column A|Column B|Column C\nRow 1 / A|Row 1 / B|Row 1 / C\nRow 2 / A|Row 2 / B|Row 2 / C\nRow 3 / A|Row 3 / B|Row 3 / C",
            'table_header_position' => 1,
            'table_delimiter' => 124,
        ];
    }
}
