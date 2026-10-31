# Laravel Locale JSON Merger

Production-ready Laravel package for comparing and merging locale JSON files using the English locale as the master.

The package detects missing and extra keys across deeply nested JSON objects, preserves existing translations, and can safely write merged output for locales such as `tr.json`, `ja.json`, `vi.json`, `fr.json`, and `ar.json`.

No database or external translation API is required.

## Installation

```bash
composer require vendor/laravel-locale-json-merger
```

Laravel package auto-discovery registers the service provider automatically:

```text
Vendor\LocaleJsonMerger\LocaleJsonMergerServiceProvider
```

## Browser demo

Try the package in your browser with the included demo UI (check + merge against sample English/French locale JSON).

From the package root, after installing dependencies:

```bash
composer install
php -S 127.0.0.1:8080 demo/router.php
```

Then open:

```text
http://127.0.0.1:8080/
```

The demo serves a simple UI and local endpoints:

```text
GET  /              Demo UI
POST /api/check     Compare master and child JSON
POST /api/merge     Merge master into child JSON
```

Stop the server with `Ctrl+C`.

This demo is for local exploration only. In a real Laravel app, enable the optional HTTP API through `config/locale-json-merger.php` (disabled by default).

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish \
    --tag=locale-json-merger-config
```

Default configuration (`config/locale-json-merger.php`):

```php
return [
    'sort_keys' => true,

    'allow_overwrite' => false,

    'api' => [
        'enabled' => false,
        'prefix' => 'api/locale-json',
        'middleware' => ['api'],
    ],

    'json' => [
        'pretty_print' => true,
        'unescaped_unicode' => true,
        'unescaped_slashes' => true,
        'final_newline' => true,
    ],
];
```

| Option | Description |
| --- | --- |
| `sort_keys` | Alphabetically sort associative object keys recursively. Arrays are never sorted. |
| `allow_overwrite` | Allow writing over existing output files without `--force`. Default: `false`. |
| `api.enabled` | Enable the optional HTTP API. Default: `false`. |
| `api.prefix` | Route prefix for the API. |
| `api.middleware` | Middleware stack applied to API routes. |
| `json.*` | Encoding options used when writing merged JSON files. |

## PHP usage

```php
use Vendor\LocaleJsonMerger\Services\LocaleJsonMerger;

$merger = app(LocaleJsonMerger::class);

$master = [
    'welcome' => 'Welcome',
    'menu' => [
        'home' => 'Home',
        'settings' => 'Settings',
    ],
];

$child = [
    'welcome' => 'Bienvenue',
    'menu' => [
        'home' => 'Accueil',
    ],
];

$check = $merger->check($master, $child);
$check->missingKeys();   // ['menu.settings']
$check->extraKeys();     // []
$check->missingCount();  // 1
$check->extraCount();    // 0
$check->toArray();

$result = $merger->merge($master, $child);
$merged = $result->merged();
$result->missingKeys();
$result->extraKeys();
$result->typeConflicts();
```

Disable sorting:

```php
$merger->check($master, $child, false);
$merger->merge($master, $child, false);
```

## File usage

```php
use Vendor\LocaleJsonMerger\Services\JsonLocaleFileService;

$files = app(JsonLocaleFileService::class);

$check = $files->checkFiles(
    resource_path('lang/en.json'),
    resource_path('lang/fr.json'),
);

$result = $files->mergeFiles(
    masterPath: resource_path('lang/en.json'),
    childPath: resource_path('lang/fr.json'),
    outputPath: resource_path('lang/fr.merged.json'),
    sort: true,
    allowOverwrite: false,
);
```

Notes:

- Files are read as UTF-8 JSON with `JSON_THROW_ON_ERROR`.
- The root value must be an associative JSON object.
- Existing output files are not overwritten unless `allowOverwrite` is `true`.
- When `outputPath` is `null`, nothing is written unless overwrite of the child file is explicitly allowed.

## Artisan usage

### Check

```bash
php artisan locale:check resources/lang/en.json resources/lang/fr.json
```

Options:

```text
--json
--no-sort
```

Machine-readable output:

```bash
php artisan locale:check \
    resources/lang/en.json \
    resources/lang/fr.json \
    --json
```

Example:

```json
{
  "missing_keys": [
    "auth.login.password"
  ],
  "extra_keys": [
    "legacy.title"
  ],
  "missing_count": 1,
  "extra_count": 1,
  "type_conflicts": [],
  "type_conflict_count": 0
}
```

Exit codes:

| Code | Meaning |
| --- | --- |
| `0` | No missing keys |
| `1` | Missing keys found, invalid JSON, missing file, or other error |

### Merge

```bash
php artisan locale:merge \
    resources/lang/en.json \
    resources/lang/fr.json \
    --output=resources/lang/fr.merged.json
```

Options:

```text
--output=
--force
--dry-run
--no-sort
--json
```

Dry run (never writes a file):

```bash
php artisan locale:merge \
    resources/lang/en.json \
    resources/lang/fr.json \
    --dry-run
```

`--output` is required unless `--dry-run` is used. Existing files are protected unless `--force` is provided or `allow_overwrite` is enabled in config.

## API usage

The HTTP API is **disabled by default**. Enable it in config:

```php
'api' => [
    'enabled' => true,
    'prefix' => 'api/locale-json',
    'middleware' => ['api'],
],
```

Routes:

```text
POST /api/locale-json/check
POST /api/locale-json/merge
```

### Request body

```json
{
  "master_data": {
    "welcome": "Welcome",
    "menu": {
      "home": "Home"
    }
  },
  "child_data": {
    "welcome": "Bienvenue"
  },
  "sort": true
}
```

JSON strings are also accepted:

```json
{
  "master_data": "{\"welcome\":\"Welcome\"}",
  "child_data": "{\"welcome\":\"Bienvenue\"}",
  "sort": true
}
```

Validation:

- `master_data` and `child_data` are required.
- Both must be associative JSON objects (or JSON strings that decode to objects).
- `sort` must be boolean when present.
- Server file paths are **never** accepted.

### Check response (`200`)

```json
{
  "success": true,
  "missing_keys": ["menu.home"],
  "extra_keys": [],
  "missing_count": 1,
  "extra_count": 0
}
```

### Merge response (`200`)

```json
{
  "success": true,
  "data": {
    "menu": {
      "home": "Home"
    },
    "welcome": "Bienvenue"
  },
  "missing_keys": ["menu.home"],
  "extra_keys": [],
  "missing_count": 1,
  "extra_count": 0
}
```

Status codes:

| Status | Meaning |
| --- | --- |
| `200` | Successful check or merge |
| `422` | Validation errors |
| `400` | Malformed JSON payload |
| `500` | Unexpected internal error |

Production responses do not expose stack traces or sensitive server paths.

## Merge behavior

### Master keys

The English master file defines the expected key structure. Missing master keys are added to the child using the English value as a temporary fallback.

### Missing keys

Reported as full dot-notation leaf paths:

```text
auth.login.title
dashboard.buttons.save
profile.address.city
```

### Extra keys

Keys that exist only in the child are preserved and reported as dot-notation paths.

### Existing translations

Child values always win for matching keys. Empty strings, `0`, `false`, and `null` are preserved.

### Nested objects

Associative objects are merged recursively.

### Arrays

Numeric arrays are opaque values:

- Not recursively merged
- Not sorted
- Order preserved
- Existing child arrays preserved
- Missing arrays copied from the master

### Null values

`null` is a valid value and is handled consistently like any other scalar.

### Type conflicts

**Master object + child scalar**

- Reported as a type conflict
- Child scalar is also listed as an extra/conflicted path
- Master object structure is used so nested missing keys can be detected and filled
- Conflict is never silently hidden

**Master scalar + child object**

- Reported as a type conflict
- Child object is preserved so data is never discarded
- Path is listed in extra/conflict information

### Sorting

When enabled (default):

- Associative object keys are sorted recursively with locale-independent string order
- Arrays are never sorted
- Missing/extra key lists are sorted for stable output

Disable with `$sort = false` or `--no-sort`.

## Safety

- Existing translations are never overwritten.
- Output files are not overwritten by default.
- Use `--force` (or `allow_overwrite`) for explicit overwrite.
- Public API routes never accept arbitrary server file paths.
- Dry runs never write files.
- Invalid JSON and missing files raise clear exceptions.

## CI usage

Fail CI when a locale is missing keys from English:

```bash
php artisan locale:check \
    resources/lang/en.json \
    resources/lang/fr.json
```

- Exit code `0`: locale is complete
- Exit code `1`: missing keys or file/JSON error

Example GitHub Actions step:

```yaml
- name: Check French locale completeness
  run: php artisan locale:check resources/lang/en.json resources/lang/fr.json
```

## Testing

```bash
composer install
vendor/bin/phpunit
```

## Package structure

```text
laravel-locale-json-merger/
├── composer.json
├── LICENSE
├── README.md
├── config/
│   └── locale-json-merger.php
├── demo/
│   ├── index.html
│   └── router.php
├── routes/
│   └── api.php
├── src/
│   ├── Console/
│   │   └── Commands/
│   │       ├── LocaleCheckCommand.php
│   │       └── LocaleMergeCommand.php
│   ├── Data/
│   │   ├── MergeCheckResult.php
│   │   └── MergeResult.php
│   ├── Exceptions/
│   │   ├── InvalidJsonException.php
│   │   └── LocaleFileException.php
│   ├── Http/
│   │   └── Controllers/
│   │       └── LocaleJsonController.php
│   ├── Services/
│   │   ├── JsonLocaleFileService.php
│   │   └── LocaleJsonMerger.php
│   ├── Support/
│   │   └── RecursiveJsonMerger.php
│   └── LocaleJsonMergerServiceProvider.php
└── tests/
    ├── Feature/
    │   ├── ArtisanCommandsTest.php
    │   └── LocaleJsonApiTest.php
    ├── Unit/
    │   ├── LocaleJsonMergerTest.php
    │   └── JsonLocaleFileServiceTest.php
    └── TestCase.php
```

## License

MIT
