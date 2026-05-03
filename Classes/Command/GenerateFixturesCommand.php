<?php

declare(strict_types=1);

namespace Xima\XimaTypo3Fixtures\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Site\SiteFinder;
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
        private readonly SiteFinder $siteFinder,
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
                'Parent page UID under which the styleguide page is created. Defaults to the root page of the first configured site.',
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
            $io->warning('No fixtures found. Add fixture properties to ContentBlocks fields or check built-in fixtures.');
            return Command::SUCCESS;
        }

        $pid = $this->resolvePid($input, $io);
        $title = (string)$input->getOption('title');

        $io->text(sprintf('Generating %d fixture(s) on styleguide page "%s"...', count($fixtures), $title));

        $pageUid = $this->generatorService->generate(array_values($fixtures), $pid, $title);

        $io->success(sprintf('Styleguide page generated successfully (UID: %d).', $pageUid));

        return Command::SUCCESS;
    }

    /**
     * Resolves the parent page UID.
     * If --pid is given explicitly, use that value.
     * Otherwise, auto-detect the root page of the first configured site.
     */
    private function resolvePid(InputInterface $input, SymfonyStyle $io): int
    {
        $pidOption = $input->getOption('pid');
        if ($pidOption !== null) {
            return (int)$pidOption;
        }

        $sites = $this->siteFinder->getAllSites();
        if ($sites === []) {
            $io->note('No site configuration found. Creating styleguide page at tree root (pid=0).');
            return 0;
        }

        $site = reset($sites);
        $rootPageId = $site->getRootPageId();
        $io->note(sprintf('Using root page of site "%s" (pid=%d).', $site->getIdentifier(), $rootPageId));

        return $rootPageId;
    }
}
