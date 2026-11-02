<?php

declare(strict_types=1);

/**
 * Lightweight demo server for browser testing.
 *
 * Usage:
 *   php -S 127.0.0.1:8080 demo/router.php
 */

require dirname(__DIR__).'/vendor/autoload.php';

if (class_exists(Dotenv\Dotenv::class)) {
    Dotenv\Dotenv::createImmutable(dirname(__DIR__))->safeLoad();
}

use Vendor\LocaleJsonMerger\Exceptions\InvalidJsonException;
use Vendor\LocaleJsonMerger\Services\JsonLocaleFileService;
use Vendor\LocaleJsonMerger\Services\LocaleJsonMerger;
use Vendor\LocaleJsonMerger\Services\OpenAiLocaleTranslator;
use Vendor\LocaleJsonMerger\Support\RecursiveJsonMerger;

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($uri === '/' || $uri === '/index.html') {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    readfile(__DIR__.'/index.html');
    exit;
}

if ($uri === '/api/check' || $uri === '/api/merge' || $uri === '/api/translate-chunk' || $uri === '/api/locale-files' || $uri === '/api/locale-file') {
    $readOnlyEndpoint = $uri === '/api/locale-files' || $uri === '/api/locale-file';

    if (($readOnlyEndpoint && $method !== 'GET') || (! $readOnlyEndpoint && $method !== 'POST')) {
        jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
    }

    if ($uri === '/api/locale-files') {
        handleLocaleFiles();
    }

    if ($uri === '/api/locale-file') {
        handleLocaleFile();
    }

    if (str_ends_with($uri, '/translate-chunk')) {
        handleTranslationChunk();
    }

    handleApi(str_ends_with($uri, '/merge') ? 'merge' : 'check');
}

http_response_code(404);
header('Content-Type: application/json');
echo json_encode(['success' => false, 'message' => 'Not found.']);
exit;

function handleApi(string $action): void
{
    $raw = file_get_contents('php://input') ?: '';

    try {
        /** @var mixed $payload */
        $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        jsonResponse(['success' => false, 'message' => 'Malformed JSON: '.$exception->getMessage()], 400);
    }

    if (! is_array($payload)) {
        jsonResponse(['success' => false, 'message' => 'Request body must be a JSON object.'], 400);
    }

    foreach (['master_path', 'child_path', 'path', 'file', 'master_file', 'child_file'] as $blocked) {
        if (array_key_exists($blocked, $payload)) {
            jsonResponse([
                'success' => false,
                'message' => 'File paths are not accepted. Provide master_data and child_data JSON payloads.',
            ], 422);
        }
    }

    if (! array_key_exists('master_data', $payload) || ! array_key_exists('child_data', $payload)) {
        jsonResponse([
            'success' => false,
            'message' => 'Validation failed.',
            'errors' => [
                'master_data' => ['The master_data field is required.'],
                'child_data' => ['The child_data field is required.'],
            ],
        ], 422);
    }

    $fileService = new JsonLocaleFileService();
    $merger = new LocaleJsonMerger(new RecursiveJsonMerger(), new OpenAiLocaleTranslator());

    try {
        $master = normalizeObject($payload['master_data'], 'master_data', $fileService);
        $child = normalizeObject($payload['child_data'], 'child_data', $fileService);
    } catch (InvalidJsonException $exception) {
        jsonResponse(['success' => false, 'message' => $exception->getMessage()], 400);
    } catch (InvalidArgumentException $exception) {
        jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
    }

    $sort = array_key_exists('sort', $payload) ? (bool) $payload['sort'] : true;
    $targetLanguage = null;

    if (array_key_exists('target_language', $payload)) {
        if (! is_string($payload['target_language']) || mb_strlen($payload['target_language']) > 100) {
            jsonResponse(['success' => false, 'message' => 'target_language must be a string of at most 100 characters.'], 422);
        }

        $targetLanguage = trim($payload['target_language']);
        $targetLanguage = $targetLanguage === '' ? null : $targetLanguage;
    }

    if ($action === 'check') {
        $result = $merger->check($master, $child, $sort);
        jsonResponse([
            'success' => true,
            'missing_keys' => $result->missingKeys(),
            'extra_keys' => $result->extraKeys(),
            'missing_count' => $result->missingCount(),
            'extra_count' => $result->extraCount(),
            'type_conflicts' => $result->typeConflicts(),
            'type_conflict_count' => $result->typeConflictCount(),
        ]);
    }

    try {
        $result = $merger->merge($master, $child, $sort, $targetLanguage);
    } catch (Throwable $exception) {
        jsonResponse(['success' => false, 'message' => $exception->getMessage()], 500);
    }
    jsonResponse([
        'success' => true,
        'data' => $result->merged(),
        'missing_keys' => $result->missingKeys(),
        'extra_keys' => $result->extraKeys(),
        'missing_count' => $result->missingCount(),
        'extra_count' => $result->extraCount(),
        'type_conflicts' => $result->typeConflicts(),
        'type_conflict_count' => $result->typeConflictCount(),
    ]);
}

function handleTranslationChunk(): void
{
    $raw = file_get_contents('php://input') ?: '';

    try {
        /** @var mixed $payload */
        $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        jsonResponse(['success' => false, 'message' => 'Malformed JSON: '.$exception->getMessage()], 400);
    }

    if (! is_array($payload) || ! is_string($payload['target_language'] ?? null) || ! is_array($payload['items'] ?? null)) {
        jsonResponse(['success' => false, 'message' => 'target_language and items are required.'], 422);
    }

    $items = [];

    foreach ($payload['items'] as $item) {
        if (! is_array($item) || ! is_int($item['id'] ?? null) || ! is_string($item['path'] ?? null) || ! is_string($item['text'] ?? null)) {
            jsonResponse(['success' => false, 'message' => 'Each translation item requires id, path, and text.'], 422);
        }

        if (! isset($item['key_path'])) {
            $item['key_path'] = explode('.', $item['path']);
        }

        if (! is_array($item['key_path']) || array_filter($item['key_path'], static fn (mixed $key): bool => ! is_string($key)) !== []) {
            jsonResponse(['success' => false, 'message' => 'Translation item key_path must contain string keys.'], 422);
        }

        $items[] = $item;
    }

    try {
        $translator = new OpenAiLocaleTranslator();
        $translations = $translator->translateBatch($items, trim($payload['target_language']));
    } catch (Throwable $exception) {
        jsonResponse(['success' => false, 'message' => $exception->getMessage()], 500);
    }

    jsonResponse(['success' => true, 'translations' => $translations]);
}

function localeDirectory(): string
{
    $configured = $_ENV['LOCALE_DIRECTORY'] ?? $_SERVER['LOCALE_DIRECTORY'] ?? getenv('LOCALE_DIRECTORY');

    return is_string($configured) && $configured !== ''
        ? rtrim($configured, '/')
        : dirname(__DIR__).'/storage/app/locales';
}

function handleLocaleFiles(): void
{
    $directory = localeDirectory();
    $files = [];

    if (is_dir($directory)) {
        foreach (glob($directory.'/*.json') ?: [] as $path) {
            $files[] = basename($path);
        }
    }

    sort($files, SORT_STRING);
    jsonResponse(['success' => true, 'directory' => 'storage/app/locales', 'files' => $files]);
}

function handleLocaleFile(): void
{
    $filename = $_GET['name'] ?? '';

    if (! is_string($filename) || basename($filename) !== $filename || ! str_ends_with($filename, '.json')) {
        jsonResponse(['success' => false, 'message' => 'A valid JSON locale filename is required.'], 422);
    }

    $path = localeDirectory().'/'.$filename;

    if (! is_file($path) || ! is_readable($path)) {
        jsonResponse(['success' => false, 'message' => 'Locale file not found.'], 404);
    }

    try {
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        jsonResponse(['success' => false, 'message' => 'Invalid locale JSON: '.$exception->getMessage()], 400);
    }

    if (! is_array($data) || ($data !== [] && array_is_list($data))) {
        jsonResponse(['success' => false, 'message' => 'Locale JSON must contain an object at the root.'], 422);
    }

    jsonResponse(['success' => true, 'filename' => $filename, 'data' => $data]);
}

/**
 * @return array<string, mixed>
 */
function normalizeObject(mixed $value, string $field, JsonLocaleFileService $fileService): array
{
    if (is_array($value)) {
        if ($value !== [] && array_is_list($value)) {
            throw new InvalidArgumentException("The {$field} field must be an associative JSON object.");
        }

        /** @var array<string, mixed> $value */
        return $value;
    }

    if (! is_string($value)) {
        throw new InvalidArgumentException("The {$field} field must be a JSON object or JSON string.");
    }

    return $fileService->decodeAssociativeObject($value);
}

/**
 * @param  array<string, mixed>  $data
 */
function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
