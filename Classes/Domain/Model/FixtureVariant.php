<?php

declare(strict_types=1);

namespace Xima\XimaTypo3Fixtures\Domain\Model;

/**
 * Represents one visual variant of a fixture — one content element on the styleguide page.
 * A fixture can expose multiple variants (e.g. textmedia with different image orientations).
 */
final class FixtureVariant
{
    /**
     * @param string $label  Human-readable variant name shown as the CE header.
     * @param array<string, mixed> $fields  tt_content field values; may contain FileFixtureReference objects.
     */
    public function __construct(
        public readonly string $label,
        public readonly array $fields,
    ) {}
}
