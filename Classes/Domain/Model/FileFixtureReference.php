<?php

declare(strict_types=1);

namespace Xima\XimaTypo3Fixtures\Domain\Model;

/**
 * Represents a file fixture reference for FAL-backed fields.
 * Returned by fixtures for fields that store their value as sys_file_reference.
 */
final class FileFixtureReference
{
    /**
     * @param string $absolutePath Absolute path to the file on disk
     * @param string $fieldname    Field name used in sys_file_reference.fieldname
     */
    public function __construct(
        public readonly string $absolutePath,
        public readonly string $fieldname,
    ) {}
}
