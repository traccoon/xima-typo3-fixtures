<?php

declare(strict_types=1);

namespace Xima\XimaTypo3Fixtures\Service;

use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Xima\XimaTypo3Fixtures\Domain\Model\FileFixtureReference;
use Xima\XimaTypo3Fixtures\Fixture\FixtureInterface;

/**
 * Scans all installed extensions for ContentBlocks definitions
 * and creates fixtures from fields with a 'fixture' property.
 *
 * Supported value formats in config.yaml:
 *   fixture: 'Plain text or HTML inline'
 *   fixture: 'EXT:my_ext/Resources/Private/Fixtures/my-text.html'
 *
 * For File-type fields, the fixture value must be an EXT: path to an image or file:
 *   - identifier: image
 *     type: File
 *     fixture: 'EXT:my_ext/Resources/Public/Images/example.jpg'
 *
 * Omit the fixture property to skip a field entirely.
 */
class ContentBlocksLoader
{
    /** File-type field types that result in FAL references instead of plain values. */
    private const FILE_FIELD_TYPES = ['File', 'Image'];

    public function __construct(
        private readonly PackageManager $packageManager,
    ) {}

    /**
     * @return FixtureInterface[]
     */
    public function loadFixtures(): array
    {
        $fixtures = [];

        foreach ($this->packageManager->getAvailablePackages() as $package) {
            $contentBlocksPath = $package->getPackagePath() . 'ContentBlocks/';
            if (!is_dir($contentBlocksPath)) {
                continue;
            }

            foreach (glob($contentBlocksPath . '{*/,*/*/}config.yaml', GLOB_BRACE) ?: [] as $configFile) {
                $fixture = $this->createFixtureFromConfigFile($configFile);
                if ($fixture !== null) {
                    $fixtures[] = $fixture;
                }
            }
        }

        return $fixtures;
    }

    private function createFixtureFromConfigFile(string $configFile): ?FixtureInterface
    {
        $config = Yaml::parseFile($configFile);
        if (!is_array($config) || !isset($config['name'])) {
            return null;
        }

        $fields = $this->extractFixtureFields($config['fields'] ?? []);
        if (empty($fields)) {
            return null;
        }

        $cType = isset($config['typeName']) ? (string)$config['typeName'] : $this->deriveCType($config['name']);
        $label = $config['title'] ?? $config['name'];
        $group = isset($config['group']) ? (string)$config['group'] : 'content-elements';

        return new class($cType, $label, $group, $fields) implements FixtureInterface {
            public function __construct(
                private readonly string $cType,
                private readonly string $label,
                private readonly string $group,
                private readonly array $fields,
            ) {}

            public function getCType(): string
            {
                return $this->cType;
            }

            public function getLabel(): string
            {
                return $this->label;
            }

            public function getGroup(): string
            {
                return $this->group;
            }

            public function getFields(): array
            {
                return $this->fields;
            }
        };
    }

    /**
     * Extracts fields that have a 'fixture' property and resolves their values.
     * File-type fields return FileFixtureReference objects; all others return scalars.
     *
     * @param array<mixed> $fieldDefinitions
     * @return array<string, mixed>
     */
    private function extractFixtureFields(array $fieldDefinitions): array
    {
        $fields = [];
        foreach ($fieldDefinitions as $field) {
            if (!is_array($field) || !isset($field['identifier'], $field['fixture'])) {
                continue;
            }

            $identifier = (string)$field['identifier'];
            $isFileField = in_array($field['type'] ?? '', self::FILE_FIELD_TYPES, true);

            if ($isFileField) {
                $ref = $this->resolveFileReference((string)$field['fixture'], $identifier);
                if ($ref !== null) {
                    $fields[$identifier] = $ref;
                }
            } else {
                $fields[$identifier] = $this->resolveTextValue($field['fixture']);
            }
        }
        return $fields;
    }

    /**
     * Resolves a file fixture value to a FileFixtureReference.
     * Supports EXT: paths. Returns null if the file cannot be resolved.
     */
    private function resolveFileReference(string $value, string $fieldname): ?FileFixtureReference
    {
        if (str_starts_with($value, 'EXT:')) {
            $absolutePath = GeneralUtility::getFileAbsFileName($value);
        } else {
            $absolutePath = $value;
        }

        if ($absolutePath !== '' && is_file($absolutePath)) {
            return new FileFixtureReference($absolutePath, $fieldname);
        }

        return null;
    }

    /**
     * Resolves a text fixture value: reads the file contents if it's an EXT: path,
     * otherwise returns the value as-is.
     */
    private function resolveTextValue(mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        if (str_starts_with($value, 'EXT:')) {
            $absolutePath = GeneralUtility::getFileAbsFileName($value);
            if ($absolutePath !== '' && is_file($absolutePath)) {
                return trim(file_get_contents($absolutePath) ?: '');
            }
        }

        return $value;
    }

    /**
     * Derives the TYPO3 CType from a ContentBlocks name.
     * Example: "my-vendor/my-element" → "my_vendor_my_element"
     */
    private function deriveCType(string $name): string
    {
        return str_replace(['-', '/'], '_', strtolower($name));
    }
}
