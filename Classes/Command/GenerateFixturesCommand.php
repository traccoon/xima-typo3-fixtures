<?php

declare(strict_types=1);

namespace Xima\XimaTypo3Fixtures\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Xima\XimaTypo3Fixtures\Service\ContentBlocksLoader;
use Xima\XimaTypo3Fixtures\Service\FixtureRegistry;
use Xima\XimaTypo3Fixtures\Service\GeneratorService;

#[AsCommand(
    name: 'fixtures:generate',
    description: 'Generates a styleguide page populated with content element fixtures.',
)]
class GenerateFixturesCommand extends Command
{
    public function __construct(
        private readonly FixtureRegistry $fixtureRegistry,
        private readonly ContentBlocksLoader $contentBlocksLoader,
        private readonly GeneratorService $generatorService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'pid',
                null,
                InputOption::VALUE_REQUIRED,
                'Parent page UID under which the styleguide page is created (0 = root level)',
                0,
            )
            ->addOption(
                'title',
                null,
                InputOption::VALUE_REQUIRED,
                'Title of the styleguide page',
                'Styleguide',
            )
            ->addOption(
                'ctype',
                null,
                InputOption::VALUE_REQUIRED,
                'Comma-separated list of CTypes to generate (default: all)',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        foreach ($this->contentBlocksLoader->loadFixtures() as $fixture) {
            $this->fixtureRegistry->addFixture($fixture);
        }

        $fixtures = $this->fixtureRegistry->getAllFixtures();

        $ctypeFilter = $input->getOption('ctype');
        if (is_string($ctypeFilter) && $ctypeFilter !== '') {
            $allowedCTypes = array_map('trim', explode(',', $ctypeFilter));
            $fixtures = array_filter(
                $fixtures,
                static fn($fixture) => in_array($fixture->getCType(), $allowedCTypes, true),
            );
        }

        if (empty($fixtures)) {
            $io->warning('No fixtures found. Add example properties to ContentBlocks fields or check built-in fixtures.');
            return Command::SUCCESS;
        }

        $pid = (int)$input->getOption('pid');
        $title = (string)$input->getOption('title');

        $io->text(sprintf('Generating %d fixture(s) on styleguide page "%s"...', count($fixtures), $title));

        $pageUid = $this->generatorService->generate(array_values($fixtures), $pid, $title);

        $io->success(sprintf('Styleguide page generated successfully (UID: %d).', $pageUid));

        return Command::SUCCESS;
    }
}
