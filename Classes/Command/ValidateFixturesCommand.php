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

            foreach ($fixture->getVariants() as $variant) {
                foreach (array_keys($variant->fields) as $fieldName) {
                    if (!in_array($fieldName, $tcaColumns, true)) {
                        $suggestion = $this->findClosestMatch($fieldName, $tcaColumns);
                        $errors[] = sprintf(
                            'Variant "%s": field "%s" not found in tt_content%s',
                            $variant->label,
                            $fieldName,
                            $suggestion !== null ? sprintf(' — did you mean "%s"?', $suggestion) : '',
                        );
                    }
                }
            }

            if ($errors === []) {
                $io->writeln(sprintf(
                    '  <info>✔</info> <options=bold>%s</> (%d variant%s)',
                    $fixture->getCType(),
                    count($fixture->getVariants()),
                    count($fixture->getVariants()) !== 1 ? 's' : '',
                ));
                $totalOk++;
            } else {
                $io->writeln(sprintf('  <error>✘</error> <options=bold>%s</>', $fixture->getCType()));
                foreach ($errors as $error) {
                    $io->writeln('      <comment>' . $error . '</comment>');
                }
                $totalErrors += count($errors);
            }
        }

        $io->newLine();

        if ($totalErrors > 0) {
            $io->error(sprintf(
                '%d field error(s) across %d fixture(s). Fix the field names in your styleguide.yaml.',
                $totalErrors,
                count($this->fixtureRegistry->getAllFixtures()),
            ));
            return Command::FAILURE;
        }

        $io->success(sprintf('All %d fixture(s) valid.', $totalOk));
        return Command::SUCCESS;
    }

    /**
     * Returns the closest TCA column name if within an acceptable edit distance,
     * null otherwise.
     *
     * @param string[] $candidates
     */
    private function findClosestMatch(string $field, array $candidates): ?string
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

        return $best;
    }
}
