<?php

declare(strict_types=1);

namespace Vendor\LocaleJsonMerger\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;
use Vendor\LocaleJsonMerger\Exceptions\InvalidJsonException;
use Vendor\LocaleJsonMerger\Services\JsonLocaleFileService;
use Vendor\LocaleJsonMerger\Services\LocaleJsonMerger;
use Throwable;

final class LocaleJsonController extends Controller
{
    public function __construct(
        private readonly LocaleJsonMerger $merger,
        private readonly JsonLocaleFileService $fileService,
    ) {
    }

    public function check(Request $request): JsonResponse
    {
        try {
            [$master, $child, $sort] = $this->resolvePayload($request);
            $result = $this->merger->check($master, $child, $sort);

            return response()->json([
                'success' => true,
                'missing_keys' => $result->missingKeys(),
                'extra_keys' => $result->extraKeys(),
                'missing_count' => $result->missingCount(),
                'extra_count' => $result->extraCount(),
                'type_conflicts' => $result->typeConflicts(),
                'type_conflict_count' => $result->typeConflictCount(),
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (InvalidJsonException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 400);
        } catch (Throwable) {
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred while checking locale data.',
            ], 500);
        }
    }

    public function merge(Request $request): JsonResponse
    {
        try {
            [$master, $child, $sort, $targetLanguage] = $this->resolvePayload($request);
            $result = $this->merger->merge($master, $child, $sort, $targetLanguage);

            return response()->json([
                'success' => true,
                'data' => $result->merged(),
                'missing_keys' => $result->missingKeys(),
                'extra_keys' => $result->extraKeys(),
                'missing_count' => $result->missingCount(),
                'extra_count' => $result->extraCount(),
                'type_conflicts' => $result->typeConflicts(),
                'type_conflict_count' => $result->typeConflictCount(),
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (InvalidJsonException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 400);
        } catch (Throwable) {
            return response()->json([
                'success' => false,
                'message' => 'An unexpected error occurred while merging locale data.',
            ], 500);
        }
    }

    /**
    * @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: bool, 3: string|null}
     *
     * @throws ValidationException
     * @throws InvalidJsonException
     */
    private function resolvePayload(Request $request): array
    {
        // Reject any attempt to pass server file paths through the public API.
        if ($request->hasAny(['master_path', 'child_path', 'path', 'file', 'master_file', 'child_file'])) {
            throw ValidationException::withMessages([
                'master_data' => ['File paths are not accepted. Provide master_data and child_data JSON payloads.'],
            ]);
        }

        $validator = Validator::make($request->all(), [
            'master_data' => ['required'],
            'child_data' => ['required'],
            'sort' => ['sometimes', 'boolean'],
            'target_language' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        /** @var mixed $masterRaw */
        $masterRaw = $request->input('master_data');
        /** @var mixed $childRaw */
        $childRaw = $request->input('child_data');
        $sort = $request->boolean('sort', (bool) config('locale-json-merger.sort_keys', true));
        $targetLanguage = $request->input('target_language');
        $targetLanguage = is_string($targetLanguage) && trim($targetLanguage) !== '' ? trim($targetLanguage) : null;

        $master = $this->normalizeObject($masterRaw, 'master_data');
        $child = $this->normalizeObject($childRaw, 'child_data');

        return [$master, $child, $sort, $targetLanguage];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ValidationException
     * @throws InvalidJsonException
     */
    private function normalizeObject(mixed $value, string $field): array
    {
        if (is_array($value)) {
            if ($value !== [] && array_is_list($value)) {
                throw ValidationException::withMessages([
                    $field => ['The '.$field.' field must be an associative JSON object.'],
                ]);
            }

            /** @var array<string, mixed> $value */
            return $value;
        }

        if (! is_string($value)) {
            throw ValidationException::withMessages([
                $field => ['The '.$field.' field must be a JSON object or JSON string.'],
            ]);
        }

        try {
            return $this->fileService->decodeAssociativeObject($value);
        } catch (InvalidJsonException $exception) {
            // Malformed JSON string → 400; wrong root type → 422 via validation.
            if (str_contains($exception->getMessage(), 'associative object')) {
                throw ValidationException::withMessages([
                    $field => [$exception->getMessage()],
                ]);
            }

            throw $exception;
        } catch (JsonException $exception) {
            throw InvalidJsonException::forPayload($exception->getMessage());
        }
    }
}
