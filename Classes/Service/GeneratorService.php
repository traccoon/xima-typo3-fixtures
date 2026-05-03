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
     * Generates the styleguide page tree:
     *   /_styleguide              ← root container page
     *     /_styleguide/core       ← subpage per group
     *     /_styleguide/my-group
     *     …
     *
     * Returns the UID of the root styleguide page.
     *
     * @param FixtureInterface[] $fixtures
     */
    public function generate(array $fixtures, int $pid = 1, string $title = 'Styleguide'): int
    {
        $rootUid = $this->getOrCreatePage($pid, $title, $this->buildRootSlug($title));
        if ($rootUid === 0) {
            return 0;
        }

        // Group fixtures by their group identifier
        $groups = [];
        foreach ($fixtures as $fixture) {
            $groups[$fixture->getGroup()][] = $fixture;
        }

        foreach ($groups as $group => $groupFixtures) {
            $groupTitle = $this->formatGroupTitle($group);
            $groupSlug = $this->buildRootSlug($title) . '/' . $this->slugify($group);
            $groupUid = $this->getOrCreatePage($rootUid, $groupTitle, $groupSlug);
            if ($groupUid === 0) {
                continue;
            }
            $this->clearExistingContent($groupUid);
            foreach ($groupFixtures as $fixture) {
                $this->createContentElement($groupUid, $fixture);
            }
        }

        return $rootUid;
    }

    private function getOrCreatePage(int $pid, string $title, string $slug): int
    {
        $existingUid = $this->findPage($pid, $title);
        if ($existingUid > 0) {
            $this->connectionPool->getConnectionForTable('pages')->update(
                'pages',
                ['slug' => $slug, 'tstamp' => time()],
                ['uid' => $existingUid],
            );
            return $existingUid;
        }

        $connection = $this->connectionPool->getConnectionForTable('pages');
        $connection->insert('pages', [
            'pid' => $pid,
            'title' => $title,
            'slug' => $slug,
            'hidden' => 0,
            'doktype' => 1,
            'sorting' => 256,
            'tstamp' => time(),
            'crdate' => time(),
        ]);

        return (int)$connection->lastInsertId();
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

    private function clearExistingContent(int $pid): void
    {
        $this->connectionPool->getConnectionForTable('tt_content')->update(
            'tt_content',
            ['deleted' => 1],
            ['pid' => $pid, 'deleted' => 0],
        );
    }

    private function createContentElement(int $pid, FixtureInterface $fixture): void
    {
        $allFields = $fixture->getFields();

        $fileReferences = [];
        $scalarFields = [];
        foreach ($allFields as $fieldName => $value) {
            if ($value instanceof FileFixtureReference) {
                $fileReferences[$fieldName] = $value;
            } else {
                $scalarFields[$fieldName] = $value;
            }
        }

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

    /**
     * Builds the root slug: /_styleguide (with underscore prefix).
     */
    private function buildRootSlug(string $title): string
    {
        return '/_' . $this->slugify($title);
    }

    /**
     * Converts a string to a URL-safe slug segment.
     * Example: "dkfz_common" → "dkfz-common", "My Group" → "my-group"
     */
    private function slugify(string $value): string
    {
        $slug = strtolower($value);
        $slug = str_replace('_', '-', $slug);
        $slug = (string)preg_replace('/[^a-z0-9-]+/', '-', $slug);
        return trim($slug, '-');
    }

    /**
     * Converts a group identifier to a human-readable title.
     * Example: "dkfz_common" → "Dkfz Common", "core" → "Core"
     */
    private function formatGroupTitle(string $group): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $group));
    }
}
