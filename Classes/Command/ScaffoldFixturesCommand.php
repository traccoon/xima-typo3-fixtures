<?php

declare(strict_types=1);

namespace Xima\XimaTypo3Fixtures\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Package\PackageManager;

#[AsCommand(
    name: 'fixtures:scaffold',
    description: 'Interactively creates styleguide.yaml files for ContentBlocks.',
)]
class ScaffoldFixturesCommand extends Command
{
    /** Field types that produce FAL references. */
    private const FILE_TYPES = ['File', 'Image'];

    /** Field types that are purely organizational — unwrap their children. */
    private const WRAPPER_TYPES = ['Palette', 'Tab'];

    /** Field types to skip entirely. */
    private const SKIP_TYPES = ['Linebreak', 'Slug', 'Uuid', 'Hidden', 'FlexForm'];

    public function __construct(
        private readonly PackageManager $packageManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite existing styleguide.yaml files.')
            ->addOption('all', null, InputOption::VALUE_NONE, 'Scaffold all blocks without an existing styleguide.yaml non-interactively.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $force = (bool)$input->getOption('force');
        $scaffoldAll = (bool)$input->getOption('all');

        $blocks = $this->findContentBlocks();

        if ($blocks === []) {
            $io->warning('No ContentBlocks found in any available package.');
            return Command::SUCCESS;
        }

        // Split into existing / missing
        $withFile = [];
        $withoutFile = [];
        foreach ($blocks as $block) {
            $hasFile = file_exists(dirname($block['configFile']) . '/styleguide.yaml');
            if ($hasFile && !$force) {
                $withFile[] = $block;
            } else {
                $withoutFile[] = $block;
            }
        }

        // Status overview
        $io->section('ContentBlock Overview');
        foreach ($withFile as $block) {
            $io->writeln(sprintf('  <info>✔</info> %s', $block['name']));
        }
        foreach ($withoutFile as $i => $block) {
            $io->writeln(sprintf('  <comment>[%d]</comment> %s', $i + 1, $block['name']));
        }
        $io->newLine();

        if ($withoutFile === []) {
            $io->success('All ContentBlocks already have a styleguide.yaml. Use --force to overwrite.');
            return Command::SUCCESS;
        }

        // Select which blocks to scaffold
        if ($scaffoldAll) {
            $selected = $withoutFile;
        } else {
            $answer = $io->ask(
                sprintf('Enter block numbers to scaffold (comma-separated, 1–%d) or "all"', count($withoutFile)),
                'all',
            );

            if (trim((string)$answer) === 'all') {
                $selected = $withoutFile;
            } else {
                $numbers = array_map('trim', explode(',', (string)$answer));
                $selected = [];
                foreach ($numbers as $num) {
                    $idx = (int)$num - 1;
                    if (isset($withoutFile[$idx])) {
                        $selected[] = $withoutFile[$idx];
                    }
                }
            }
        }

        if ($selected === []) {
            $io->warning('No blocks selected.');
            return Command::SUCCESS;
        }

        foreach ($selected as $block) {
            $this->scaffoldBlock($block, $io, $force, $scaffoldAll);
        }

        $io->success(sprintf('Done. Run "fixtures:validate" to verify the generated files.'));
        return Command::SUCCESS;
    }

    // ── Block discovery ───────────────────────────────────────────────────────

    /**
     * @return array<int, array{name: string, ctype: string, title: string, configFile: string}>
     */
    private function findContentBlocks(): array
    {
        $blocks = [];
        foreach ($this->packageManager->getAvailablePackages() as $package) {
            $cbPath = $package->getPackagePath() . 'ContentBlocks/';
            if (!is_dir($cbPath)) {
                continue;
            }

            foreach (glob($cbPath . '{*/,*/*/}config.yaml', GLOB_BRACE) ?: [] as $configFile) {
                $config = Yaml::parseFile($configFile);
                if (!is_array($config) || !isset($config['name'])) {
                    continue;
                }

                $name = (string)$config['name'];
                $cType = isset($config['typeName'])
                    ? (string)$config['typeName']
                    : str_replace(['-', '/'], '_', strtolower($name));

                $blocks[] = [
                    'name' => $name,
                    'ctype' => $cType,
                    'title' => (string)($config['title'] ?? $name),
                    'configFile' => $configFile,
                ];
            }
        }

        usort($blocks, fn($a, $b) => strcmp($a['name'], $b['name']));
        return $blocks;
    }

    // ── Scaffold a single block ───────────────────────────────────────────────

    /**
     * @param array{name: string, ctype: string, title: string, configFile: string} $block
     */
    private function scaffoldBlock(array $block, SymfonyStyle $io, bool $force, bool $nonInteractive): void
    {
        $styleguideFile = dirname($block['configFile']) . '/styleguide.yaml';

        if (file_exists($styleguideFile) && !$force) {
            $io->warning(sprintf('Skipping %s — styleguide.yaml already exists.', $block['name']));
            return;
        }

        $io->section(sprintf('Scaffolding: %s  [%s]', $block['name'], $block['ctype']));

        $config = Yaml::parseFile($block['configFile']);
        $prefix = $this->resolvePrefix($config);

        [$scalarFields, $collectionFields] = $this->extractFields(
            $config['fields'] ?? [],
            $prefix,
        );

        // Show field suggestions
        if ($scalarFields !== []) {
            $io->writeln('<comment>Scalar fields:</comment>');
            foreach ($scalarFields as $col => $meta) {
                $io->writeln(sprintf(
                    '  <info>%-40s</info> <fg=gray>%s</> → <comment>%s</comment>',
                    $col,
                    $meta['type'],
                    $meta['suggestion'],
                ));
            }
        }
        if ($collectionFields !== []) {
            $io->writeln('<comment>Collection fields:</comment>');
            foreach ($collectionFields as $col => $meta) {
                $subNames = implode(', ', array_keys($meta['subFields']));
                $io->writeln(sprintf('  <info>%s</info>  sub-fields: %s', $col, $subNames));
            }
        }
        $io->newLine();

        // Build variants
        $variants = [];
        $variantNum = 1;

        do {
            $defaultLabel = $variantNum === 1 ? $block['title'] : sprintf('Variant %d', $variantNum);

            $label = $nonInteractive
                ? $defaultLabel
                : (string)($io->ask(sprintf('Variant %d — label', $variantNum), $defaultLabel) ?? $defaultLabel);

            $fields = [];
            foreach ($scalarFields as $col => $meta) {
                if ($nonInteractive) {
                    $fields[$col] = $meta['suggestion'];
                } else {
                    $value = $io->ask(sprintf('  %s', $col), $meta['suggestion']);
                    if ($value !== null && $value !== '') {
                        $fields[$col] = $value;
                    }
                }
            }

            $collections = [];
            foreach ($collectionFields as $col => $meta) {
                $wantCollection = $nonInteractive || $io->confirm(
                    sprintf('  Add collection items for "%s"?', $col),
                    true,
                );

                if (!$wantCollection) {
                    continue;
                }

                $items = [];
                $itemNum = 1;
                $addAnother = true;

                while ($addAnother) {
                    $item = [];
                    foreach ($meta['subFields'] as $subCol => $subMeta) {
                        if ($nonInteractive) {
                            $item[$subCol] = $subMeta['suggestion'];
                        } else {
                            $val = $io->ask(
                                sprintf('    Item %d: %s', $itemNum, $subCol),
                                $subMeta['suggestion'],
                            );
                            if ($val !== null && $val !== '') {
                                $item[$subCol] = $val;
                            }
                        }
                    }
                    $items[] = $item;
                    $itemNum++;

                    $addAnother = !$nonInteractive && $io->confirm('    Add another item?', false);
                }

                $collections[$col] = $items;
            }

            $variant = ['label' => $label, 'fields' => $fields];
            if ($collections !== []) {
                $variant['collections'] = $collections;
            }
            $variants[] = $variant;
            $variantNum++;

            $addVariant = !$nonInteractive && $io->confirm('Add another variant?', false);
        } while ($addVariant ?? false);

        // Write file
        $data = ['variants' => $variants];
        file_put_contents($styleguideFile, Yaml::dump($data, 4, 2));

        $io->writeln(sprintf('  <info>✔</info> Written: %s', $styleguideFile));
        $io->newLine();
    }

    // ── Field extraction ──────────────────────────────────────────────────────

    /**
     * @param array<mixed> $fieldDefs
     * @return array{array<string, array{type: string, suggestion: string}>, array<string, array{subFields: array<string, array{type: string, suggestion: string}>}>}
     */
    private function extractFields(array $fieldDefs, string $prefix): array
    {
        $scalars = [];
        $collections = [];

        foreach ($fieldDefs as $field) {
            if (!is_array($field) || !isset($field['identifier'])) {
                continue;
            }

            $type = (string)($field['type'] ?? 'Text');
            $identifier = (string)$field['identifier'];

            if (in_array($type, self::SKIP_TYPES, true)) {
                continue;
            }

            // Organizational wrappers — recurse into their fields
            if (in_array($type, self::WRAPPER_TYPES, true)) {
                [$subScalars, $subCollections] = $this->extractFields(
                    $field['fields'] ?? [],
                    $prefix,
                );
                $scalars = array_merge($scalars, $subScalars);
                $collections = array_merge($collections, $subCollections);
                continue;
            }

            $useExisting = !empty($field['useExistingField']);
            $columnName = $useExisting ? $identifier : $prefix . $identifier;

            if ($type === 'Collection') {
                [$subScalars] = $this->extractFields($field['fields'] ?? [], '');
                $collections[$columnName] = ['subFields' => $subScalars];
                continue;
            }

            $scalars[$columnName] = [
                'type' => $type,
                'suggestion' => $this->suggest($type, $field),
            ];
        }

        return [$scalars, $collections];
    }

    /**
     * Returns a type-appropriate placeholder value for the given ContentBlocks field.
     *
     * @param array<string, mixed> $field
     */
    private function suggest(string $type, array $field): string
    {
        return match ($type) {
            'RichText'
                => 'EXT:xima_typo3_fixtures/Resources/Private/Fixtures/lorem.txt',
            'Text', 'Textarea'
                => 'EXT:xima_typo3_fixtures/Resources/Private/Fixtures/lorem-short.txt',
            'Image', 'File'
                => 'EXT:xima_typo3_fixtures/Resources/Public/Fixtures/placeholder.jpg',
            'Select', 'Radio'
                => (string)($field['items'][0]['value'] ?? $field['items'][0] ?? '0'),
            'Checkbox', 'Toggle', 'Language', 'Category'
                => '0',
            'Number', 'Integer'
                => '1',
            'Email'
                => 'example@example.com',
            'Telephone'
                => '+49 123 456789',
            'Url', 'Link'
                => 'https://example.com',
            'Color'
                => '#336699',
            'Json'
                => '{}',
            default
                => '',
        };
    }

    // ── Prefix resolution (mirrors StyleguideLoader logic) ────────────────────

    /**
     * @param array<string, mixed> $config
     */
    private function resolvePrefix(array $config): string
    {
        if (empty($config['prefixFields'])) {
            return '';
        }

        $name = (string)$config['name'];
        [$vendor, $block] = array_pad(explode('/', $name, 2), 2, '');
        $vendor = strtolower($vendor);
        $prefixType = strtolower((string)($config['prefixType'] ?? 'vendor'));

        if ($prefixType === 'full') {
            $blockSlug = strtolower(str_replace(['-', '/'], '', (string)$block));
            return $vendor . '_' . $blockSlug . '_';
        }

        return $vendor . '_';
    }
}
