<?php

declare(strict_types=1);

namespace Xima\XimaTypo3Fixtures\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Xima\XimaTypo3Fixtures\Service\FixtureRegistry;
use Xima\XimaTypo3Fixtures\Service\StyleguideLoader;

#[AsCommand(
    name: 'fixtures:validate',
    description: 'Validates all fixture field names against the tt_content TCA.',
)]
class ValidateFixturesCommand extends Command
{
    public function __construct(
        private readonly FixtureRegistry $fixtureRegistry,
        private readonly StyleguideLoader $styleguideLoader,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach ($this->styleguideLoader->loadFixtures() as $fixture) {
            $this->fixtureRegistry->addFixture($fixture);
        }

        $tcaColumns = array_keys($GLOBALS['TCA']['tt_content']['columns'] ?? []);
        if ($tcaColumns === []) {
            $io->error('tt_content TCA is empty — run this command in a booted TYPO3 context.');
            return Command::FAILURE;
        }

        $totalErrors = 0;
        $totalOk = 0;

        foreach ($this->fixtureRegistry->getAllFixtures() as $fixture) {
            $errors = [];
            $warnings = [];

            // Collect all values used per field across all variants (for Select coverage)
            $coveredValues = [];
            foreach ($fixture->getVariants() as $variant) {
                foreach ($variant->fields as $fieldName => $value) {
                    $coveredValues[$fieldName][] = $value;
                }
            }

            // ── Select-field coverage check ──────────────────────────────────
            foreach ($coveredValues as $fieldName => $usedValues) {
                $fieldConfig = $GLOBALS['TCA']['tt_content']['columns'][$fieldName]['config'] ?? [];
                if (($fieldConfig['type'] ?? '') !== 'select') {
                    continue;
                }
                $tcaItems = $fieldConfig['items'] ?? [];
                $allValues = array_map(
                    static fn($item) => (string)($item['value'] ?? $item[1] ?? ''),
                    $tcaItems,
                );
                $allValues = array_filter($allValues, static fn($v) => $v !== '' && $v !== '--div--');
                $usedStrings = array_map('strval', $usedValues);
                foreach (array_diff($allValues, $usedStrings) as $missing) {
                    $warnings[] = sprintf('Select "%s": value "%s" not covered by any variant', $fieldName, $missing);
                }
            }

            foreach ($fixture->getVariants() as $variant) {
                // ── Scalar + file fields in tt_content ──────────────────────
                foreach (array_keys($variant->fields) as $fieldName) {
                    if (!in_array($fieldName, $tcaColumns, true)) {
                        $errors[] = sprintf(
                            'Variant "%s": field "%s" not found in tt_content%s',
                            $variant->label,
                            $fieldName,
                            $this->suggest($fieldName, $tcaColumns),
                        );
                    }
                }

                // ── Collection (IRRE) fields ─────────────────────────────────
                foreach ($variant->collections as $fieldName => $items) {
                    // Is the field present in tt_content and of type inline?
                    if (!in_array($fieldName, $tcaColumns, true)) {
                        $errors[] = sprintf(
                            'Variant "%s": collection field "%s" not found in tt_content%s',
                            $variant->label,
                            $fieldName,
                            $this->suggest($fieldName, $tcaColumns),
                        );
                        continue;
                    }

                    $config = $GLOBALS['TCA']['tt_content']['columns'][$fieldName]['config'] ?? [];
                    if (($config['type'] ?? '') !== 'inline') {
                        $errors[] = sprintf(
                            'Variant "%s": field "%s" is not an inline field in tt_content TCA (type: %s)',
                            $variant->label,
                            $fieldName,
                            $config['type'] ?? 'unknown',
                        );
                        continue;
                    }

                    $foreignTable = (string)($config['foreign_table'] ?? '');
                    if ($foreignTable === '') {
                        continue;
                    }

                    $foreignColumns = array_keys($GLOBALS['TCA'][$foreignTable]['columns'] ?? []);

                    foreach ($items as $i => $item) {
                        foreach (array_keys($item) as $col) {
                            if (!in_array($col, $foreignColumns, true)) {
                                $errors[] = sprintf(
                                    'Variant "%s", collection "%s" item #%d: field "%s" not found in %s%s',
                                    $variant->label,
                                    $fieldName,
                                    $i + 1,
                                    $col,
                                    $foreignTable,
                                    $this->suggest($col, $foreignColumns),
                                );
                            }
                        }
                    }
                }
            }

            if ($errors === []) {
                $variantCount = count($fixture->getVariants());
                $icon = $warnings !== [] ? '<comment>⚠</comment>' : '<info>✔</info>';
                $io->writeln(sprintf(
                    '  %s <options=bold>%s</> (%d variant%s)',
                    $icon,
                    $fixture->getCType(),
                    $variantCount,
                    $variantCount !== 1 ? 's' : '',
                ));
                foreach ($warnings as $warning) {
                    $io->writeln('      <comment>↳ ' . $warning . '</comment>');
                }
                $totalOk++;
            } else {
                $io->writeln(sprintf('  <error>✘</error> <options=bold>%s</>', $fixture->getCType()));
                foreach ($errors as $error) {
                    $io->writeln('      <comment>' . $error . '</comment>');
                }
                foreach ($warnings as $warning) {
                    $io->writeln('      <comment>↳ ' . $warning . '</comment>');
                }
                $totalErrors += count($errors);
            }
        }

        $io->newLine();

        if ($totalErrors > 0) {
            $io->error(sprintf(
                '%d field error(s) found. Fix the field names in your styleguide.yaml.',
                $totalErrors,
            ));
            return Command::FAILURE;
        }

        $io->success(sprintf('All %d fixture(s) valid.', $totalOk));
        return Command::SUCCESS;
    }

    /**
     * Returns a " — did you mean X?" hint string, or empty string if no close match found.
     *
     * @param string[] $candidates
     */
    private function suggest(string $field, array $candidates): string
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;
        $threshold = max(3, (int)(strlen($field) / 3));

        foreach ($candidates as $candidate) {
            $distance = levenshtein($field, $candidate);
            if ($distance < $bestDistance && $distance <= $threshold) {
                $bestDistance = $distance;
                $best = $candidate;
            }
        }

        return $best !== null ? sprintf(' — did you mean "%s"?', $best) : '';
    }
}
