<?php

declare(strict_types=1);

namespace Xima\XimaTypo3Fixtures\Fixture\Core;

use Xima\XimaTypo3Fixtures\Domain\Model\FileFixtureReference;
use Xima\XimaTypo3Fixtures\Domain\Model\FixtureVariant;
use Xima\XimaTypo3Fixtures\Fixture\AbstractFixture;

class TextmediaFixture extends AbstractFixture
{
    private const PLACEHOLDER = 'EXT:xima_typo3_fixtures/Resources/Public/Fixtures/placeholder.jpg';

    public function getCType(): string
    {
        return 'textmedia';
    }

    public function getLabel(): string
    {
        return 'Text & Media';
    }

    public function getGroup(): string
    {
        return 'core';
    }

    public function getVariants(): array
    {
        $media = new FileFixtureReference(
            \TYPO3\CMS\Core\Utility\GeneralUtility::getFileAbsFileName(self::PLACEHOLDER),
            'assets',
        );

        $withMedia = ['header_layout' => 2, 'bodytext' => self::LOREM_LONG, 'assets' => $media];
        $noMedia   = ['header_layout' => 2, 'bodytext' => self::LOREM_LONG];

        return [
            // ── All imageorient values ──────────────────────────────────────
            new FixtureVariant('Oben',               $withMedia + ['imageorient' => 0]),
            new FixtureVariant('Unten',              $withMedia + ['imageorient' => 8]),
            new FixtureVariant('Rechts / Wrap',      $withMedia + ['imageorient' => 17]),
            new FixtureVariant('Links / Wrap',       $withMedia + ['imageorient' => 18]),
            new FixtureVariant('Rechts / kein Wrap', $withMedia + ['imageorient' => 25]),
            new FixtureVariant('Links / kein Wrap',  $withMedia + ['imageorient' => 26]),
            // ── imagecolumns variants ───────────────────────────────────────
            new FixtureVariant('2 Spalten',          $withMedia + ['imageorient' => 0, 'imagecols' => 2]),
            new FixtureVariant('4 Spalten',          $withMedia + ['imageorient' => 0, 'imagecols' => 4]),
            // ── Edge cases ─────────────────────────────────────────────────
            new FixtureVariant('Kein Header',        $noMedia  + ['header' => '', 'imageorient' => 0, 'assets' => $media]),
            new FixtureVariant('Nur Text',           $noMedia),
        ];
    }

    public function getFields(): array
    {
        return $this->getVariants()[0]->fields;
    }
}
