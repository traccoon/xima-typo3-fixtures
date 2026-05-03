<?php

declare(strict_types=1);

namespace Xima\XimaTypo3Fixtures\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use Xima\XimaTypo3Fixtures\Domain\Model\FileFixtureReference;
use Xima\XimaTypo3Fixtures\Fixture\FixtureInterface;

class GeneratorService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ResourceFactory $resourceFactory,
    ) {}

    /**
     * Generates the styleguide page with content elements for all given fixtures.
     * Reuses an existing page with the given title under $pid if one exists.
     * Returns the UID of the styleguide page.
     *
     * @param FixtureInterface[] $fixtures
     */
    public function generate(array $fixtures, int $pid = 0, string $title = 'Styleguide'): int
    {
        $styleguidePageUid = $this->getOrCreateStyleguidePage($pid, $title);
        if ($styleguidePageUid === 0) {
            return 0;
        }

        $this->clearExistingContent($styleguidePageUid);

        foreach ($fixtures as $fixture) {
            $this->createContentElement($styleguidePageUid, $fixture);
        }

        return $styleguidePageUid;
    }

    private function getOrCreateStyleguidePage(int $pid, string $title): int
    {
        $existingUid = $this->findStyleguidePage($pid, $title);
        if ($existingUid > 0) {
            // Ensure slug is correct even if the page was created by an older version
            $this->connectionPool->getConnectionForTable('pages')->update(
                'pages',
                ['slug' => $this->buildSlug($pid, $title), 'tstamp' => time()],
                ['uid' => $existingUid],
            );
            return $existingUid;
        }

        $connection = $this->connectionPool->getConnectionForTable('pages');
        $connection->insert('pages', [
            'pid' => $pid,
            'title' => $title,
            'slug' => $this->buildSlug($pid, $title),
            'hidden' => 0,
            'doktype' => 1,
            'sorting' => 256,
            'tstamp' => time(),
            'crdate' => time(),
        ]);

        return (int)$connection->lastInsertId();
    }

    private function buildSlug(int $pid, string $title): string
    {
        if ($pid === 0) {
            return '/';
        }

        $segment = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $title) ?? $title);
        return '/' . trim($segment, '-');
    }

    private function findStyleguidePage(int $pid, string $title): int
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

    private function clearExistingContent(int $pid): void
    {
        $connection = $this->connectionPool->getConnectionForTable('tt_content');
        $connection->update(
            'tt_content',
            ['deleted' => 1],
            ['pid' => $pid, 'deleted' => 0],
        );
    }

    private function createContentElement(int $pid, FixtureInterface $fixture): void
    {
        $allFields = $fixture->getFields();

        // Separate file references from plain scalar fields
        $fileReferences = [];
        $scalarFields = [];
        foreach ($allFields as $fieldName => $value) {
            if ($value instanceof FileFixtureReference) {
                $fileReferences[$fieldName] = $value;
            } else {
                $scalarFields[$fieldName] = $value;
            }
        }

        // Pre-fill the file count fields so the CE renders the correct number of assets
        foreach ($fileReferences as $fieldName => $ref) {
            $scalarFields[$fieldName] = ($scalarFields[$fieldName] ?? 0) + 1;
        }

        $row = array_merge(
            [
                'pid' => $pid,
                'CType' => $fixture->getCType(),
                'colPos' => 0,
                'hidden' => 0,
                'tstamp' => time(),
                'crdate' => time(),
            ],
            $scalarFields,
        );

        $connection = $this->connectionPool->getConnectionForTable('tt_content');
        $connection->insert('tt_content', $row);
        $contentElementUid = (int)$connection->lastInsertId();

        foreach ($fileReferences as $ref) {
            $this->createFileReference($contentElementUid, $ref);
        }
    }

    /**
     * Indexes the file in FAL (if not already) and creates a sys_file_reference record.
     */
    private function createFileReference(int $contentElementUid, FileFixtureReference $ref): void
    {
        try {
            $file = $this->resourceFactory->retrieveFileOrFolderObject($ref->absolutePath);
        } catch (FileDoesNotExistException) {
            return;
        }

        if ($file === null || !method_exists($file, 'getUid')) {
            return;
        }

        $this->connectionPool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'uid_local' => $file->getUid(),
            'uid_foreign' => $contentElementUid,
            'tablenames' => 'tt_content',
            'fieldname' => $ref->fieldname,
            'table_local' => 'sys_file',
            'pid' => 0,
            'tstamp' => time(),
            'crdate' => time(),
            'sorting_foreign' => 1,
        ]);
    }
}
