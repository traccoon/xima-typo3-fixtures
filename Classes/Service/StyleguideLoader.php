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
 * Loads styleguide fixtures from two sources:
 *
 * ── 1. Per-ContentBlock: styleguide.yaml next to config.yaml ─────────────────
 *
 *   ContentBlocks/ContentElements/my-block/config.yaml     ← untouched
 *   ContentBlocks/ContentElements/my-block/styleguide.yaml ← fixture data only
 *
 *   styleguide.yaml format:
 *     backend_layout: ''   # optional
 *     variants:
 *       - label: 'Variant A'
 *         fields:
 *           bodytext: 'EXT:my_ext/Resources/Private/Fixtures/lorem.txt'
 *           xima_image_position: left
 *           media: 'EXT:my_ext/Resources/Public/Fixtures/placeholder.svg'
 *
 *   skip a ContentBlock entirely:
 *     skip: true
 *
 *   Metadata (ctype, label, group) is derived automatically from the sibling
 *   config.yaml. Field names in `variants.fields` must be the final tt_content
 *   column names (already including any vendor prefix).
 *
 * ── 2. Extension-level: Configuration/Styleguide.yaml ───────────────────────
 *
 *   Works for any CType — ContentBlocks, core CEs, or any custom element.
 *
 *   Configuration/Styleguide.yaml format:
 *     - ctype: textmedia
 *       label: 'Text & Media'   # optional
 *       group: core             # optional, default: content-elements
 *       backend_layout: ''      # optional
 *       variants:
 *         - label: 'Media oben'
 *           fields:
 *             imageorient: 0
 *             bodytext: 'EXT:my_ext/...'
 *
 *     - ctype: xima_job-list
 *       skip: true
 *
 * ── 3. Backward compatible: inline fixture: in ContentBlocks config.yaml ─────
 *
 *   Still supported (field-level and block-level fixture: sections) when no
 *   sibling styleguide.yaml exists. Migrate to styleguide.yaml going forward.
 */
class StyleguideLoader
{
    /** Field types that produce FAL references rather than plain column values. */
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
        $seen = [];

        foreach ($this->packageManager->getAvailablePackages() as $package) {
            $packagePath = $package->getPackagePath();

            // ── Source 1: per-ContentBlock styleguide.yaml ──────────────────
            $contentBlocksPath = $packagePath . 'ContentBlocks/';
            if (is_dir($contentBlocksPath)) {
                foreach (glob($contentBlocksPath . '{*/,*/*/}styleguide.yaml', GLOB_BRACE) ?: [] as $file) {
                    $fixture = $this->createFromContentBlockStyleguide($file);
                    if ($fixture !== null && !isset($seen[$fixture->getCType()])) {
                        $fixtures[] = $fixture;
                        $seen[$fixture->getCType()] = true;
                    }
                }

                // ── Source 3: backward-compat inline fixture: in config.yaml ─
                foreach (glob($contentBlocksPath . '{*/,*/*/}config.yaml', GLOB_BRACE) ?: [] as $file) {
                    if (file_exists(dirname($file) . '/styleguide.yaml')) {
                        continue; // already handled above
                    }
                    $fixture = $this->createFromContentBlockConfig($file);
                    if ($fixture !== null && !isset($seen[$fixture->getCType()])) {
                        $fixtures[] = $fixture;
                        $seen[$fixture->getCType()] = true;
                    }
                }
            }

            // ── Source 2: extension-level Configuration/Styleguide.yaml ─────
            $extStyleguide = $packagePath . 'Configuration/Styleguide.yaml';
            if (file_exists($extStyleguide)) {
                foreach ($this->loadFromExtensionStyleguide($extStyleguide) as $fixture) {
                    if (!isset($seen[$fixture->getCType()])) {
                        $fixtures[] = $fixture;
                        $seen[$fixture->getCType()] = true;
                    }
                }
            }
        }

        return $fixtures;
    }

    // ── Source 1 ─────────────────────────────────────────────────────────────

    private function createFromContentBlockStyleguide(string $styleguideFile): ?FixtureInterface
    {
        $config = Yaml::parseFile($styleguideFile);
        if (!is_array($config)) {
            return null;
        }

        if (!empty($config['skip'])) {
            return null;
        }

        $configFile = dirname($styleguideFile) . '/config.yaml';
        if (!file_exists($configFile)) {
            return null;
        }

        $blockConfig = Yaml::parseFile($configFile);
        if (!is_array($blockConfig) || !isset($blockConfig['name'])) {
            return null;
        }

        $variants = $this->buildVariants($config['variants'] ?? []);
        if (empty($variants)) {
            return null;
        }

        $cType = isset($blockConfig['typeName'])
            ? (string)$blockConfig['typeName']
            : $this->deriveCType((string)$blockConfig['name']);
        $label = (string)($blockConfig['title'] ?? $blockConfig['name']);
        $group = isset($blockConfig['group']) ? (string)$blockConfig['group'] : 'content-elements';
        $backendLayout = (string)($config['backend_layout'] ?? '');

        return $this->makeFixture($cType, $label, $group, $backendLayout, $variants);
    }

    // ── Source 2 ─────────────────────────────────────────────────────────────

    /**
     * @return FixtureInterface[]
     */
    private function loadFromExtensionStyleguide(string $file): array
    {
        $config = Yaml::parseFile($file);
        if (!is_array($config)) {
            return [];
        }

        $fixtures = [];
        foreach ($config as $entry) {
            if (!is_array($entry) || !isset($entry['ctype'])) {
                continue;
            }

            if (!empty($entry['skip'])) {
                continue;
            }

            $variants = $this->buildVariants($entry['variants'] ?? []);
            if (empty($variants)) {
                continue;
            }

            $cType = (string)$entry['ctype'];
            $label = (string)($entry['label'] ?? $cType);
            $group = (string)($entry['group'] ?? 'content-elements');
            $backendLayout = (string)($entry['backend_layout'] ?? '');

            $fixtures[] = $this->makeFixture($cType, $label, $group, $backendLayout, $variants);
        }

        return $fixtures;
    }

    // ── Source 3: backward-compat ContentBlocks config.yaml ──────────────────

    private function createFromContentBlockConfig(string $configFile): ?FixtureInterface
    {
        $config = Yaml::parseFile($configFile);
        if (!is_array($config) || !isset($config['name'])) {
            return null;
        }

        $blockFixture = $config['fixture'] ?? null;

        if (is_array($blockFixture) && !empty($blockFixture['skip'])) {
            return null;
        }

        if (is_array($blockFixture) && isset($blockFixture['variants'])) {
            $variants = $this->buildVariants($blockFixture['variants']);
        } else {
            $variants = $this->buildFieldLevelVariants($config);
        }

        if (empty($variants)) {
            return null;
        }

        $cType = isset($config['typeName'])
            ? (string)$config['typeName']
            : $this->deriveCType((string)$config['name']);
        $label = (string)($config['title'] ?? $config['name']);
        $group = isset($config['group']) ? (string)$config['group'] : 'content-elements';
        $backendLayout = (string)($blockFixture['backend_layout'] ?? '');

        return $this->makeFixture($cType, $label, $group, $backendLayout, $variants);
    }

    // ── Shared variant building ───────────────────────────────────────────────

    /**
     * @param array<mixed> $variantDefinitions
     * @return FixtureVariant[]
     */
    private function buildVariants(array $variantDefinitions): array
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

            $collections = [];
            foreach ((array)($def['collections'] ?? []) as $fieldName => $items) {
                if (!is_array($items)) {
                    continue;
                }
                $rows = [];
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $row = [];
                    foreach ($item as $col => $value) {
                        $row[(string)$col] = $this->resolveValue((string)$col, $value);
                    }
                    $rows[] = $row;
                }
                $collections[(string)$fieldName] = $rows;
            }

            $variants[] = new FixtureVariant((string)$def['label'], $fields, $collections);
        }
        return $variants;
    }

    /**
     * Resolves a value: EXT: text paths are read, EXT: media paths become
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

    // ── Backward-compat: field-level fixture: in ContentBlocks config.yaml ───

    /**
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
        $label = (string)($config['title'] ?? $config['name']);
        return [new FixtureVariant($label, $fields)];
    }

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

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function makeFixture(
        string $cType,
        string $label,
        string $group,
        string $backendLayout,
        array $variants,
    ): FixtureInterface {
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

    private function deriveCType(string $name): string
    {
        return str_replace(['-', '/'], '_', strtolower($name));
    }
}
