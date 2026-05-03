<?php

declare(strict_types=1);

namespace Xima\XimaTypo3Fixtures\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;

#[AsCommand(
    name: 'fixtures:clean',
    description: 'Removes all generated styleguide pages and their content elements.',
)]
class CleanFixturesCommand extends Command
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
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
                'Parent page UID under which the styleguide page lives. Defaults to the root page of the first configured site.',
            )
            ->addOption(
                'title',
                null,
                InputOption::VALUE_REQUIRED,
                'Title of the styleguide root page to remove.',
                'Styleguide',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $pid = $this->resolvePid($input, $io);
        $title = (string)$input->getOption('title');

        $rootUid = $this->findPage($pid, $title);
        if ($rootUid === 0) {
            $io->warning(sprintf('No styleguide page "%s" found under pid=%d — nothing to clean.', $title, $pid));
            return Command::SUCCESS;
        }

        $descendantUids = $this->collectDescendantUids($rootUid);
        $allPageUids = array_merge([$rootUid], $descendantUids);

        $ceCount = 0;
        foreach ($allPageUids as $pageUid) {
            $ceCount += $this->connectionPool->getConnectionForTable('tt_content')->update(
                'tt_content',
                ['deleted' => 1],
                ['pid' => $pageUid, 'deleted' => 0],
            );
        }

        foreach ($allPageUids as $pageUid) {
            $this->connectionPool->getConnectionForTable('pages')->update(
                'pages',
                ['deleted' => 1],
                ['uid' => $pageUid],
            );
        }

        $io->success(sprintf(
            'Removed styleguide page "%s" (uid=%d) with %d subpage(s) and %d content element(s).',
            $title,
            $rootUid,
            count($descendantUids),
            $ceCount,
        ));

        return Command::SUCCESS;
    }

    /**
     * Recursively collects all descendant page UIDs (breadth-first).
     *
     * @return int[]
     */
    private function collectDescendantUids(int $parentUid): array
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $childUids = array_map(
            'intval',
            $queryBuilder
                ->select('uid')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($parentUid, Connection::PARAM_INT)),
                    $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
                )
                ->executeQuery()
                ->fetchFirstColumn(),
        );

        $all = $childUids;
        foreach ($childUids as $uid) {
            $all = array_merge($all, $this->collectDescendantUids($uid));
        }

        return $all;
    }

    private function findPage(int $pid, string $title): int
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll();
        $uid = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->eq('pid', $queryBuilder->createNamedParameter($pid, Connection::PARAM_INT)),
                $queryBuilder->expr()->eq('title', $queryBuilder->createNamedParameter($title)),
                $queryBuilder->expr()->eq('deleted', $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchOne();

        return (int)($uid ?: 0);
    }

    private function resolvePid(InputInterface $input, SymfonyStyle $io): int
    {
        $pidOption = $input->getOption('pid');
        if ($pidOption !== null) {
            return (int)$pidOption;
        }

        $sites = $this->siteFinder->getAllSites();
        if ($sites === []) {
            return 0;
        }

        usort($sites, static fn($a, $b) => $a->getRootPageId() <=> $b->getRootPageId());
        $site = $sites[0];
        $io->note(sprintf('Using root page of site "%s" (pid=%d).', $site->getIdentifier(), $site->getRootPageId()));

        return $site->getRootPageId();
    }
}
