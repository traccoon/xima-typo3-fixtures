<?php

declare(strict_types=1);

namespace Xima\XimaTypo3Fixtures\Fixture;

use Xima\XimaTypo3Fixtures\Domain\Model\FixtureVariant;

interface FixtureInterface
{
    public function getCType(): string;

    public function getLabel(): string;

    /**
     * Returns the group identifier used to create a group subpage.
     * Core fixtures return 'core'; ContentBlocks fixtures return the block's group property.
     */
    public function getGroup(): string;

    /**
     * Returns the TYPO3 backend_layout value to set on the CE subpage.
     * Return an empty string to leave the layout at its default.
     */
    public function getBackendLayout(): string;

    /**
     * Returns all visual variants for this fixture.
     * Each variant produces one content element on the styleguide page.
     * At least one variant must be returned.
     *
     * @return FixtureVariant[]
     */
    public function getVariants(): array;

    /**
     * Shortcut returning the fields of the first variant.
     * Kept for backward compatibility with simple single-variant fixtures.
     *
     * @return array<string, mixed>
     */
    public function getFields(): array;
}
