<?php

declare(strict_types=1);

/**
 * Lightweight demo server for browser testing.
 *
 * Usage:
 *   php -S 127.0.0.1:8080 demo/router.php
 */

require dirname(__DIR__).'/vendor/autoload.php';

use Vendor\LocaleJsonMerger\Exceptions\InvalidJsonException;
use Vendor\LocaleJsonMerger\Services\JsonLocaleFileService;
use Vendor\LocaleJsonMerger\Services\LocaleJsonMerger;

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
    readfile(__DIR__.'/index.html');
    exit;
}

if ($uri === '/api/check' || $uri === '/api/merge') {
    if ($method !== 'POST') {
        jsonResponse(['success' => false, 'message' => 'Method not allowed.'], 405);
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
    $merger = new LocaleJsonMerger();

    try {
        $master = normalizeObject($payload['master_data'], 'master_data', $fileService);
        $child = normalizeObject($payload['child_data'], 'child_data', $fileService);
    } catch (InvalidJsonException $exception) {
        jsonResponse(['success' => false, 'message' => $exception->getMessage()], 400);
    } catch (InvalidArgumentException $exception) {
        jsonResponse(['success' => false, 'message' => $exception->getMessage()], 422);
    }

    $sort = array_key_exists('sort', $payload) ? (bool) $payload['sort'] : true;

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

    $result = $merger->merge($master, $child, $sort);
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
