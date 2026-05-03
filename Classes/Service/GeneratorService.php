<?php

declare(strict_types=1);

namespace Xima\XimaTypo3Fixtures\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\FileInterface;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use Xima\XimaTypo3Fixtures\Domain\Model\FileFixtureReference;
use Xima\XimaTypo3Fixtures\Domain\Model\FixtureVariant;
use Xima\XimaTypo3Fixtures\Fixture\FixtureInterface;

class GeneratorService
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly ResourceFactory $resourceFactory,
    ) {}

    /**
     * Builds a three-level styleguide page tree and populates each CE page with
     * all variants of the corresponding fixture.
     *
     * Structure:
     *   /_styleguide                     ← root container
     *     /_styleguide/{group}           ← one page per group
     *       /_styleguide/{group}/{ctype} ← one page per CE, N content elements (variants)
     *
     * Returns the UID of the root styleguide page.
     *
     * @param FixtureInterface[] $fixtures
     */
    public function generate(array $fixtures, int $pid = 1, string $title = 'Styleguide'): int
    {
        $rootSlug = '/_' . $this->slugify($title);
        $rootUid = $this->getOrCreatePage($pid, $title, $rootSlug);
        if ($rootUid === 0) {
            return 0;
        }

        $groups = [];
        foreach ($fixtures as $fixture) {
            $groups[$fixture->getGroup()][] = $fixture;
        }

        foreach ($groups as $group => $groupFixtures) {
            $groupTitle = $this->formatGroupTitle($group);
            $groupSlug = $rootSlug . '/' . $this->slugify($group);
            $groupUid = $this->getOrCreatePage($rootUid, $groupTitle, $groupSlug);
            if ($groupUid === 0) {
                continue;
            }

            foreach ($groupFixtures as $fixture) {
                $ceTitle = $fixture->getLabel();
                $ceSlug = $groupSlug . '/' . $this->slugify($fixture->getCType());
                $ceUid = $this->getOrCreatePage($groupUid, $ceTitle, $ceSlug);
                if ($ceUid === 0) {
                    continue;
                }

                $this->setBackendLayout($ceUid, $fixture->getBackendLayout());
                $this->clearExistingContent($ceUid);

                foreach ($fixture->getVariants() as $variant) {
                    $this->createContentElement($ceUid, $fixture->getCType(), $variant);
                }
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

    private function setBackendLayout(int $uid, string $backendLayout): void
    {
        if ($backendLayout === '') {
            return;
        }

        $this->connectionPool->getConnectionForTable('pages')->update(
            'pages',
            ['backend_layout' => $backendLayout],
            ['uid' => $uid],
        );
    }

    private function clearExistingContent(int $pid): void
    {
        foreach ($this->getContentUidsOnPage($pid) as $ceUid) {
            $this->deleteRecordRelations($ceUid, 'tt_content');
        }

        $this->connectionPool->getConnectionForTable('tt_content')->update(
            'tt_content',
            ['deleted' => 1],
            ['pid' => $pid, 'deleted' => 0],
        );
    }

    /**
     * Soft-deletes all tt_content records on the given pages, plus their
     * collection children and sys_file_reference entries.
     * Called by CleanFixturesCommand.
     *
     * @param int[] $pageUids
     * @return int number of deleted tt_content records
     */
    public function deleteContentOnPages(array $pageUids): int
    {
        $total = 0;
        foreach ($pageUids as $pid) {
            $ceUids = $this->getContentUidsOnPage($pid);
            foreach ($ceUids as $ceUid) {
                $this->deleteRecordRelations($ceUid, 'tt_content');
            }
            $total += $this->connectionPool->getConnectionForTable('tt_content')->update(
                'tt_content',
                ['deleted' => 1],
                ['pid' => $pid, 'deleted' => 0],
            );
        }
        return $total;
    }

    /**
     * Recursively soft-deletes all relations of a record:
     *   – sys_file_reference rows pointing to it
     *   – IRRE child records (and their own file references)
     */
    private function deleteRecordRelations(int $uid, string $table): void
    {
        // 1. Soft-delete FAL references for this record
        $this->connectionPool->getConnectionForTable('sys_file_reference')->update(
            'sys_file_reference',
            ['deleted' => 1],
            ['uid_foreign' => $uid, 'tablenames' => $table, 'deleted' => 0],
        );

        // 2. Soft-delete IRRE child records and their own file references
        foreach ($GLOBALS['TCA'][$table]['columns'] ?? [] as $column) {
            $config = $column['config'] ?? [];
            if (($config['type'] ?? '') !== 'inline') {
                continue;
            }

            $foreignTable = (string)($config['foreign_table'] ?? '');
            $foreignField = (string)($config['foreign_field'] ?? '');
            if ($foreignTable === '' || $foreignField === '') {
                continue;
            }

            foreach ($this->getInlineChildUids($foreignTable, $foreignField, $uid) as $childUid) {
                $this->deleteRecordRelations($childUid, $foreignTable);
            }

            $this->connectionPool->getConnectionForTable($foreignTable)->update(
                $foreignTable,
                ['deleted' => 1],
                [$foreignField => $uid, 'deleted' => 0],
            );
        }
    }

    /**
     * @return int[]
     */
    private function getContentUidsOnPage(int $pid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $qb->getRestrictions()->removeAll();
        return array_map('intval', $qb
            ->select('uid')
            ->from('tt_content')
            ->where(
                $qb->expr()->eq('pid', $qb->createNamedParameter($pid, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchFirstColumn());
    }

    /**
     * @return int[]
     */
    private function getInlineChildUids(string $foreignTable, string $foreignField, int $parentUid): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable($foreignTable);
        $qb->getRestrictions()->removeAll();
        return array_map('intval', $qb
            ->select('uid')
            ->from($foreignTable)
            ->where(
                $qb->expr()->eq($foreignField, $qb->createNamedParameter($parentUid, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )
            ->executeQuery()
            ->fetchFirstColumn());
    }

    private function createContentElement(int $pid, string $cType, FixtureVariant $variant): void
    {
        $fileReferences = [];
        $scalarFields = [];

        foreach ($variant->fields as $fieldName => $value) {
            if ($value instanceof FileFixtureReference) {
                $fileReferences[$fieldName] = $value;
            } else {
                $scalarFields[$fieldName] = $value;
            }
        }

        foreach ($fileReferences as $fieldName => $ref) {
            $scalarFields[$fieldName] = ($scalarFields[$fieldName] ?? 0) + 1;
        }

        // Inline (IRRE) collection count fields
        foreach ($variant->collections as $fieldName => $items) {
            $scalarFields[$fieldName] = count($items);
        }

        // Use variant label as CE header if no header field is set
        if (!isset($scalarFields['header'])) {
            $scalarFields['header'] = $variant->label;
        }

        $row = array_merge(
            [
                'pid' => $pid,
                'CType' => $cType,
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

        foreach ($variant->collections as $fieldName => $items) {
            $this->createCollectionRecords($contentElementUid, $pid, $fieldName, $items);
        }
    }

    /**
     * Creates IRRE child records for an inline collection field.
     * Looks up foreign_table and foreign_field from TCA to avoid hard-coding.
     *
     * @param array<int, array<string, mixed>> $items
     */
    private function createCollectionRecords(int $ceUid, int $pid, string $fieldName, array $items): void
    {
        $tcaConfig = $GLOBALS['TCA']['tt_content']['columns'][$fieldName]['config'] ?? [];
        if (($tcaConfig['type'] ?? '') !== 'inline') {
            return;
        }

        $foreignTable = (string)($tcaConfig['foreign_table'] ?? '');
        $foreignField = (string)($tcaConfig['foreign_field'] ?? 'foreign_table_parent_uid');
        if ($foreignTable === '') {
            return;
        }

        $connection = $this->connectionPool->getConnectionForTable($foreignTable);

        foreach ($items as $sorting => $item) {
            $fileReferences = [];
            $scalarFields = [];

            foreach ($item as $col => $value) {
                if ($value instanceof FileFixtureReference) {
                    $fileReferences[$col] = $value;
                } else {
                    $scalarFields[$col] = $value;
                }
            }

            foreach ($fileReferences as $col => $ref) {
                $scalarFields[$col] = ($scalarFields[$col] ?? 0) + 1;
            }

            $connection->insert($foreignTable, array_merge(
                [
                    'pid' => $pid,
                    $foreignField => $ceUid,
                    'sorting' => ($sorting + 1) * 256,
                    'hidden' => 0,
                    'tstamp' => time(),
                    'crdate' => time(),
                ],
                $scalarFields,
            ));

            $childUid = (int)$connection->lastInsertId();

            foreach ($fileReferences as $ref) {
                $this->createFileReference($childUid, $ref, $foreignTable);
            }
        }
    }

    private function createFileReference(
        int $recordUid,
        FileFixtureReference $ref,
        string $tableName = 'tt_content',
    ): void {
        $file = $this->resolveOrImportFile($ref->absolutePath);
        if ($file === null) {
            return;
        }

        $this->connectionPool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'uid_local' => $file->getUid(),
            'uid_foreign' => $recordUid,
            'tablenames' => $tableName,
            'fieldname' => $ref->fieldname,
            'pid' => 0,
            'tstamp' => time(),
            'crdate' => time(),
            'sorting_foreign' => 1,
        ]);
    }

    /**
     * Tries to resolve a file via FAL. If the file lives outside any configured
     * storage (e.g. vendor/), it is copied into fileadmin/_fixtures/ automatically.
     */
    private function resolveOrImportFile(string $absolutePath): ?FileInterface
    {
        if (!is_file($absolutePath)) {
            return null;
        }

        // Try direct FAL lookup (works when the file is already inside a storage)
        try {
            $file = $this->resourceFactory->retrieveFileOrFolderObject($absolutePath);
            if ($file instanceof FileInterface) {
                return $file;
            }
        } catch (\Exception) {
            // Fall through to import
        }

        // File is outside any storage (e.g. vendor/) — import into fileadmin/_fixtures/
        try {
            $storage = $this->resourceFactory->getStorageObject(1);
            $folderIdentifier = '/_fixtures/';
            $folder = $storage->hasFolder($folderIdentifier)
                ? $storage->getFolder($folderIdentifier)
                : $storage->createFolder('_fixtures');

            $fileName = basename($absolutePath);
            if ($folder->hasFile($fileName)) {
                return $folder->getFile($fileName);
            }

            return $storage->addFile($absolutePath, $folder, $fileName);
        } catch (\Exception) {
            return null;
        }
    }

    private function slugify(string $value): string
    {
        $slug = strtolower($value);
        $slug = str_replace('_', '-', $slug);
        $slug = (string)preg_replace('/[^a-z0-9-]+/', '-', $slug);
        return trim($slug, '-');
    }

    private function formatGroupTitle(string $group): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $group));
    }
}
