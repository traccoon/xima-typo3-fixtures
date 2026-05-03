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
     * @param array<string, list<array<string, mixed>>> $collections
     *   Inline (IRRE) child records keyed by the tt_content inline field name.
     *   Each entry is a list of field-value arrays for the child table.
     *   The foreign_table and foreign_field are resolved from TCA at generate-time.
     *
     *   Example:
     *     ['xima_c09tabs_items' => [
     *         ['header' => 'Tab 1', 'bodytext' => 'Content 1'],
     *         ['header' => 'Tab 2', 'bodytext' => 'Content 2'],
     *     ]]
     */
    public function __construct(
        public readonly string $label,
        public readonly array $fields,
        public readonly array $collections = [],
    ) {}
}
