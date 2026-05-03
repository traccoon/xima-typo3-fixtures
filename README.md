# xima-typo3-fixtures

A TYPO3 extension providing a backend module to configure a styleguide and generate content element variations with example content.

## Requirements

- PHP `^8.2`
- TYPO3 `^13.4 || ^14.0`

## Installation

```bash
composer require xima/xima-typo3-fixtures
```

## Features

- Backend module to select which content elements appear in the styleguide
- One-click generation of a styleguide page populated with example content
- Built-in fixtures for all common TYPO3 core content elements
- Extensible fixture system: custom fixtures can be added via the `FixtureInterface`

## Usage

1. Open the **Fixtures** module in the TYPO3 backend (Web section)
2. Select the content elements you want to include in the styleguide
3. Click **Generate Styleguide**
4. Navigate to the generated page (UID shown after generation) to review the result

Re-generating replaces all existing content on the styleguide page.

## Architecture

### Fixture system

Each content element type is represented by a fixture class implementing `FixtureInterface`:

```php
interface FixtureInterface
{
    public function getCType(): string;
    public function getLabel(): string;
    public function getFields(): array;
}
```

`getFields()` returns the `tt_content` field values used when creating the record. All built-in fixtures extend `AbstractFixture`, which provides shared Lorem Ipsum constants.

**Built-in fixtures (Phase 1):**

| Class | CType | Description |
|---|---|---|
| `HeaderFixture` | `header` | Header with subheader |
| `TextFixture` | `text` | Text with bodytext |
| `TextmediaFixture` | `textmedia` | Text & Media (text only, no media in Phase 1) |
| `BulletsFixture` | `bullets` | Unordered bullet list |
| `TableFixture` | `table` | Table with header row |
| `HtmlFixture` | `html` | Raw HTML block |

### Adding a custom fixture

Create a class implementing `FixtureInterface` anywhere in your extension:

```php
use Xima\XimaTypo3Fixtures\Fixture\FixtureInterface;

class MyCustomFixture implements FixtureInterface
{
    public function getCType(): string
    {
        return 'my_custom_ctype';
    }

    public function getLabel(): string
    {
        return 'My Custom Element';
    }

    public function getFields(): array
    {
        return [
            'header' => 'Example Headline',
            'bodytext' => '<p>Example content.</p>',
        ];
    }
}
```

Register it in your extension's `Configuration/Services.yaml`:

```yaml
_instanceof:
  Xima\XimaTypo3Fixtures\Fixture\FixtureInterface:
    tags:
      - name: xima.typo3fixtures.fixture
```

The fixture is then automatically picked up by `FixtureRegistry` and appears in the backend module.

### Services

| Class | Description |
|---|---|
| `FixtureRegistry` | Collects all tagged fixture services, provides lookup by CType |
| `GeneratorService` | Creates/reuses the styleguide page, clears old content, inserts new `tt_content` records via DataHandler |
| `ConfigurationRepository` | Persists the selected CTypes and styleguide page UID in `tx_ximatypo3fixtures_configuration` |

### Database

The extension adds one table:

**`tx_ximatypo3fixtures_configuration`**

| Field | Type | Description |
|---|---|---|
| `content_elements` | text | JSON array of selected CType strings |
| `styleguide_pid` | int | UID of the generated styleguide page |

Only one record is stored globally. Re-generating updates the existing record.

## Development

### Prerequisites

- [DDEV](https://ddev.readthedocs.io/en/stable/users/install/ddev-installation/)

### Setup

```bash
ddev start
ddev composer install
ddev init-typo3
```

TYPO3 is then available at `https://xima-typo3-fixtures.ddev.site/`.

The backend is available at `https://xima-typo3-fixtures.ddev.site/typo3`.

| Field | Value |
|---|---|
| Username | `admin` |
| Password | `Passw0rd!` |

### Code Quality

Run all static code analysis checks:

```bash
ddev composer ci:sca
```

Or fix issues automatically:

```bash
ddev composer sca
```

Individual commands:

| Command | Description |
|---|---|
| `ddev composer ci:php:lint` | PHP syntax check |
| `ddev composer ci:php:fixer` | PHP CS Fixer (dry-run) |
| `ddev composer ci:php:stan` | PHPStan static analysis |
| `ddev composer ci:composer:normalize` | Validate composer.json formatting |
| `ddev composer ci:editorconfig:lint` | EditorConfig validation |
| `ddev composer ci:language:lint` | XLF translation validation |
| `ddev composer ci:typoscript:lint` | TypoScript linting |
| `ddev composer ci:yaml:lint` | YAML linting |
| `ddev composer php:fixer` | PHP CS Fixer (auto-fix) |
| `ddev composer php:stan` | PHPStan with baseline update |

## Roadmap

- **Phase 2:** YAML-based fixture configuration in sitepackages — override built-in fixtures or define custom ones without writing PHP
- **Phase 2:** Media support — ship placeholder images and wire them as `sys_file_reference` on `textmedia` and `image` elements
- **Phase 2:** Multi-site support — per-site styleguide configuration
