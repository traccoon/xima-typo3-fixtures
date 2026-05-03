<?php

declare(strict_types=1);

namespace Xima\XimaTypo3Fixtures\Fixture;

abstract class AbstractFixture implements FixtureInterface
{
    protected const LOREM_SHORT = 'Lorem ipsum dolor sit amet, consectetur adipiscing elit.';

    protected const LOREM_LONG = '<p>Lorem ipsum dolor sit amet, consectetur adipiscing elit. Sed do eiusmod tempor incididunt ut labore et dolore magna aliqua.</p><p>Ut enim ad minim veniam, quis nostrud exercitation ullamco laboris nisi ut aliquip ex ea commodo consequat. Duis aute irure dolor in reprehenderit in voluptate velit esse cillum dolore eu fugiat nulla pariatur.</p>';

    public function getLabel(): string
    {
        return $this->getCType();
    }
}
