<?php

declare(strict_types=1);

namespace Xima\XimaTypo3Fixtures\Service;

use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Package\PackageManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use Xima\XimaTypo3Fixtures\Domain\Model\FileFixtureReference;
use Xima\XimaTypo3Fixtures\Domain\Model\FixtureVariant;
use Xima\XimaTypo3Fixtures\Fixture\FixtureInterface;

/**
 * Scans all installed extensions for ContentBlocks definitions and creates
 * fixtures from either a block-level `fixture:` section or field-level `fixture:` properties.
 *
 * ── Block-level (preferred, supports variants) ──────────────────────────────
 *
 *   fixture:
 *     skip: true               # omit this CE from the styleguide entirely
 *     backend_layout: 'hero'   # optional: set on the CE subpage
 *     variants:
 *       - label: 'Media links'
 *         fields:
 *           bodytext: 'EXT:my_ext/Resources/Private/Fixtures/lorem.txt'
 *           media: 'EXT:my_ext/Resources/Public/Images/example.jpg'
 *           xima_image_position: 'left'
 *       - label: 'Media rechts'
 *         fields:
 *           xima_image_position: 'right'
 *
 *   Field names in variants.fields must be the final tt_content column names
 *   (already including any vendor prefix). EXT: paths are resolved automatically.
 *
 * ── Field-level (backward compatible, single variant) ───────────────────────
 *
 *   fields:
 *     - identifier: header
 *       useExistingField: true
 *       fixture: 'Example header'
 *     - identifier: phone
 *       type: Text
 *       fixture: '0800 - 420 30 40'     # column name is resolved with prefix rules
 */
class ContentBlocksLoader
{
    /** File-type field types resolved as FAL references rather than plain values. */
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

        $blockFixture = $config['fixture'] ?? null;

        // Block-level skip
        if (is_array($blockFixture) && !empty($blockFixture['skip'])) {
            return null;
        }

        // Determine variants either from block-level or field-level config
        if (is_array($blockFixture) && isset($blockFixture['variants'])) {
            $variants = $this->buildBlockLevelVariants($blockFixture['variants']);
        } else {
            $variants = $this->buildFieldLevelVariants($config);
        }

        if (empty($variants)) {
            return null;
        }

        $cType = isset($config['typeName']) ? (string)$config['typeName'] : $this->deriveCType($config['name']);
        $label = $config['title'] ?? $config['name'];
        $group = isset($config['group']) ? (string)$config['group'] : 'content-elements';
        $backendLayout = (string)($blockFixture['backend_layout'] ?? '');

        return new class($cType, $label, $group, $backendLayout, $variants) implements FixtureInterface {
            public function __construct(
                private readonly string $cType,
                private readonly string $label,
                private readonly string $group,
                private readonly string $backendLayout,
                private readonly array $variants,
            ) {}

            public function getCType(): string { return $this->cType; }
            public function getLabel(): string { return $this->label; }
            public function getGroup(): string { return $this->group; }
            public function getBackendLayout(): string { return $this->backendLayout; }

            /** @return FixtureVariant[] */
            public function getVariants(): array { return $this->variants; }

            public function getFields(): array { return $this->variants[0]->fields; }
        };
    }

    /**
     * Builds variants from the block-level `fixture.variants` array.
     * Field names are used as-is (final tt_content column names).
     * EXT: paths are resolved; file paths become FileFixtureReference objects.
     *
     * @param array<mixed> $variantDefinitions
     * @return FixtureVariant[]
     */
    private function buildBlockLevelVariants(array $variantDefinitions): array
    {
        $variants = [];
        foreach ($variantDefinitions as $def) {
            if (!is_array($def) || !isset($def['label'])) {
                continue;
            }
            $fields = [];
            foreach ((array)($def['fields'] ?? []) as $column => $value) {
                $fields[(string)$column] = $this->resolveValue((string)$column, $value);
            }
            $variants[] = new FixtureVariant((string)$def['label'], $fields);
        }
        return $variants;
    }

    /**
     * Backward-compatible: builds a single variant from field-level `fixture:` properties.
     *
     * @param array<string, mixed> $config
     * @return FixtureVariant[]
     */
    private function buildFieldLevelVariants(array $config): array
    {
        $prefixInfo = $this->resolvePrefixInfo($config);
        $fields = $this->extractFieldLevelFixtures($config['fields'] ?? [], $prefixInfo);
        if (empty($fields)) {
            return [];
        }
        $label = $config['title'] ?? $config['name'];
        return [new FixtureVariant((string)$label, $fields)];
    }

    /**
     * Resolves a single value: EXT: text paths are read, EXT: file paths become
     * FileFixtureReference, everything else is returned as-is.
     */
    private function resolveValue(string $columnName, mixed $value): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        if (str_starts_with($value, 'EXT:')) {
            $absolutePath = GeneralUtility::getFileAbsFileName($value);
            if ($absolutePath === '' || !file_exists($absolutePath)) {
                return $value;
            }
            if ($this->looksLikeMediaFile($absolutePath)) {
                return new FileFixtureReference($absolutePath, $columnName);
            }
            return trim(file_get_contents($absolutePath) ?: '');
        }

        return $value;
    }

    private function looksLikeMediaFile(string $path): bool
    {
        return in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), [
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'mp4', 'webm', 'pdf',
        ], true);
    }

    // ── Field-level (backward compat) helpers ────────────────────────────────

    /**
     * @param array<mixed> $fieldDefinitions
     * @param array{prefix: string} $prefixInfo
     * @return array<string, mixed>
     */
    private function extractFieldLevelFixtures(array $fieldDefinitions, array $prefixInfo): array
    {
        $fields = [];
        foreach ($fieldDefinitions as $field) {
            if (!is_array($field) || !isset($field['identifier'], $field['fixture'])) {
                continue;
            }

            $identifier = (string)$field['identifier'];
            $useExisting = !empty($field['useExistingField']);
            $columnName = $useExisting ? $identifier : $prefixInfo['prefix'] . $identifier;
            $isFileField = in_array($field['type'] ?? '', self::FILE_FIELD_TYPES, true);

            if ($isFileField) {
                $ref = $this->resolveFileReference((string)$field['fixture'], $columnName);
                if ($ref !== null) {
                    $fields[$columnName] = $ref;
                }
            } else {
                $fields[$columnName] = $this->resolveTextValue($field['fixture']);
            }
        }
        return $fields;
    }

    private function resolveFileReference(string $value, string $fieldname): ?FileFixtureReference
    {
        $absolutePath = str_starts_with($value, 'EXT:')
            ? GeneralUtility::getFileAbsFileName($value)
            : $value;

        return ($absolutePath !== '' && is_file($absolutePath))
            ? new FileFixtureReference($absolutePath, $fieldname)
            : null;
    }

    private function resolveTextValue(mixed $value): mixed
    {
        if (!is_string($value) || !str_starts_with($value, 'EXT:')) {
            return $value;
        }
        $absolutePath = GeneralUtility::getFileAbsFileName($value);
        if ($absolutePath !== '' && is_file($absolutePath)) {
            return trim(file_get_contents($absolutePath) ?: '');
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $config
     * @return array{prefix: string}
     */
    private function resolvePrefixInfo(array $config): array
    {
        if (empty($config['prefixFields'])) {
            return ['prefix' => ''];
        }

        $name = (string)$config['name'];
        [$vendor, $block] = array_pad(explode('/', $name, 2), 2, '');
        $vendor = strtolower($vendor);
        $prefixType = strtolower((string)($config['prefixType'] ?? 'vendor'));

        if ($prefixType === 'full') {
            $blockSlug = strtolower(str_replace(['-', '/'], '', (string)$block));
            return ['prefix' => $vendor . '_' . $blockSlug . '_'];
        }

        return ['prefix' => $vendor . '_'];
    }

    private function deriveCType(string $name): string
    {
        return str_replace(['-', '/'], '_', strtolower($name));
    }
}
