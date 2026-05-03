# xima-typo3-fixtures

A TYPO3 extension that generates a living styleguide — one page per content element type, populated with all defined variants as separate content elements. Fixture data is defined in YAML, keeping your ContentBlock configs clean.

## Requirements

- PHP `^8.2`
- TYPO3 `^13.4 || ^14.0`

## Installation

```bash
composer require xima/xima-typo3-fixtures
```

## Concept

The extension reads fixture definitions from YAML files and generates a three-level page tree:

```
/_styleguide                          ← root container
  /_styleguide/core                   ← one page per group
    /_styleguide/core/textmedia       ← one page per CE type
      CE: "Media oben"                ← one content element per variant
      CE: "Media links"
      CE: "Media rechts"
  /_styleguide/content-elements
    /_styleguide/content-elements/c08-text-media
      CE: "Bild oben"
      CE: "Bild links"
      ...
```

Re-generating replaces content on existing pages — orphaned IRRE children and file references are cleaned up automatically.

## CLI Commands

```bash
# Generate (or re-generate) the full styleguide
vendor/bin/typo3 fixtures:generate

# Remove the styleguide page tree entirely
vendor/bin/typo3 fixtures:clean

# Validate all fixture field names against the live TCA
vendor/bin/typo3 fixtures:validate
```

All commands accept `--pid` (parent page UID, defaults to first site root) and `--title` (styleguide root page title, default: `Styleguide`). `fixtures:generate` additionally accepts `--ctype` to regenerate a single CE type only.

`fixtures:validate` exits with code 1 on errors — suitable for CI pipelines.

## Defining Fixtures

### Option 1 — Per ContentBlock: `styleguide.yaml`

Place a `styleguide.yaml` next to the ContentBlock's `config.yaml`. Metadata (CType, label, group) is derived automatically from `config.yaml`.

```
ContentBlocks/ContentElements/c08-text-media/
  config.yaml        ← untouched
  styleguide.yaml    ← fixture data only
```

```yaml
# styleguide.yaml
variants:
  - label: 'Bild oben'
    fields:
      bodytext: 'EXT:my_ext/Resources/Private/Fixtures/lorem.txt'
      xima_image_position: top-center
      media: 'EXT:xima_typo3_fixtures/Resources/Public/Fixtures/placeholder.jpg'

  - label: 'Bild links'
    fields:
      bodytext: 'EXT:my_ext/Resources/Private/Fixtures/lorem.txt'
      xima_image_position: left
      media: 'EXT:xima_typo3_fixtures/Resources/Public/Fixtures/placeholder.jpg'
```

Skip a ContentBlock entirely:

```yaml
skip: true
```

Set a backend layout on the CE page:

```yaml
backend_layout: pagets__my_layout
variants:
  - ...
```

**Field names** must be the final `tt_content` column names (including any vendor prefix). `EXT:` paths to text files are read and inlined; `EXT:` paths to image/media files become FAL references (files outside `fileadmin/` are imported into `fileadmin/_fixtures/` automatically).

### Option 2 — Per Extension: `Configuration/Styleguide.yaml`

Works for any CType — ContentBlocks, core elements, or any custom element.

```yaml
# Configuration/Styleguide.yaml
- ctype: textmedia
  label: 'Text & Media'
  group: core
  variants:
    - label: 'Media oben'
      fields:
        imageorient: 0
        bodytext: 'EXT:my_ext/Resources/Private/Fixtures/lorem.txt'
        assets: 'EXT:xima_typo3_fixtures/Resources/Public/Fixtures/placeholder.jpg'

- ctype: my_custom_element
  skip: true
```

### Collections (IRRE)

Use the `collections` key to create inline child records. Foreign table and foreign field are resolved from TCA automatically.

```yaml
variants:
  - label: 'With tabs'
    fields:
      header: 'Tab element'
    collections:
      xima_c09tabs_items:
        - header: 'Tab 1'
          bodytext: 'Content of tab 1'
        - header: 'Tab 2'
          bodytext: 'Content of tab 2'
```

### Backward compatibility: inline `fixture:` in `config.yaml`

ContentBlocks that still use the old `fixture:` key inside `config.yaml` continue to work. When no sibling `styleguide.yaml` exists, the loader falls back to parsing `fixture:` inline. Migrate to `styleguide.yaml` going forward.

## Built-in Fixtures

The extension ships fixtures for the most common TYPO3 core content elements:

| CType | Variants |
|---|---|
| `header` | H1, H2, H3, H4, H5 |
| `textmedia` | Media oben, rechts/Wrap, links/Wrap, unten |
| `bullets` | Ungeordnet, Geordnet, Definition |
| `text` | 1 variant |
| `table` | 1 variant |
| `html` | 1 variant |

## Provided Assets

| Path | Description |
|---|---|
| `EXT:xima_typo3_fixtures/Resources/Public/Fixtures/placeholder.jpg` | 1200×800 JPEG placeholder |
| `EXT:xima_typo3_fixtures/Resources/Public/Fixtures/placeholder.svg` | SVG placeholder |
| `EXT:xima_typo3_fixtures/Resources/Private/Fixtures/lorem.txt` | Long Lorem Ipsum text |
| `EXT:xima_typo3_fixtures/Resources/Private/Fixtures/lorem-short.txt` | Short Lorem Ipsum text |

Use `placeholder.jpg` for fields that only allow raster formats (jpg/png/webp).

## Frontend Panel

A floating panel is injected on the styleguide pages, listing all content elements on the current page with jump links and a direct backend edit link per CE.

The panel is only active on pages within the styleguide page tree and only when a backend user is logged in.

## Custom PHP Fixtures

For more complex cases, implement `FixtureInterface` directly and register the class via DI tagging:

```php
use Xima\XimaTypo3Fixtures\Fixture\FixtureInterface;
use Xima\XimaTypo3Fixtures\Domain\Model\FixtureVariant;

class MyFixture implements FixtureInterface
{
    public function getCType(): string { return 'my_ctype'; }
    public function getLabel(): string { return 'My Element'; }
    public function getGroup(): string { return 'my-group'; }
    public function getBackendLayout(): string { return ''; }

    public function getVariants(): array
    {
        return [
            new FixtureVariant('Default', ['header' => 'Hello World']),
        ];
    }

    public function getFields(): array { return $this->getVariants()[0]->fields; }
}
```

```yaml
# Configuration/Services.yaml
_instanceof:
  Xima\XimaTypo3Fixtures\Fixture\FixtureInterface:
    tags:
      - name: xima.typo3fixtures.fixture
```

PHP fixtures registered this way take priority over YAML-defined fixtures for the same CType.

## Architecture

| Class | Description |
|---|---|
| `StyleguideLoader` | Scans all packages for `styleguide.yaml` / `Configuration/Styleguide.yaml` / inline `fixture:` and returns `FixtureInterface[]` |
| `FixtureRegistry` | Collects tagged PHP fixture services |
| `GeneratorService` | Builds the page tree, creates `tt_content` records, IRRE children, and FAL references |
| `FixtureVariant` | Value object: `label`, `fields`, `collections` |
| `FileFixtureReference` | Value object marking a field value as a FAL reference |

## Development

### Setup

```bash
ddev start
ddev composer install
ddev init-typo3
```

TYPO3 is available at `https://xima-typo3-fixtures.ddev.site/` (backend user: `admin` / `Passw0rd!`).

### Code Quality

```bash
ddev composer sca        # fix
ddev composer ci:sca     # check only
```
