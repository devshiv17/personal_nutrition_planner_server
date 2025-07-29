<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

abstract class BaseApiController extends Controller
{
    /**
     * Return a successful response
     */
    protected function successResponse(mixed $data = null, string $message = 'Success', int $statusCode = Response::HTTP_OK): JsonResponse
    {
        $response = [
            'success' => true,
            'message' => $message,
        ];

        if ($data !== null) {
            $response['data'] = $data;
        }

        return response()->json($response, $statusCode);
    }

    /**
     * Return an error response
     */
    protected function errorResponse(string $message = 'An error occurred', int $statusCode = Response::HTTP_BAD_REQUEST, mixed $errors = null): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $message,
        ];

        if ($errors !== null) {
            $response['errors'] = $errors;
        }

        return response()->json($response, $statusCode);
    }

    /**
     * Return a validation error response
     */
    protected function validationErrorResponse(ValidationException $exception): JsonResponse
    {
        return $this->errorResponse(
            'Validation failed',
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $exception->errors()
        );
    }

    /**
     * Return a not found response
     */
    protected function notFoundResponse(string $message = 'Resource not found'): JsonResponse
    {
        return $this->errorResponse($message, Response::HTTP_NOT_FOUND);
    }

    /**
     * Return an unauthorized response
     */
    protected function unauthorizedResponse(string $message = 'Unauthorized'): JsonResponse
    {
        return $this->errorResponse($message, Response::HTTP_UNAUTHORIZED);
    }

    /**
     * Return a forbidden response
     */
    protected function forbiddenResponse(string $message = 'Forbidden'): JsonResponse
    {
        return $this->errorResponse($message, Response::HTTP_FORBIDDEN);
    }

    /**
     * Return a server error response
     */
    protected function serverErrorResponse(string $message = 'Internal server error'): JsonResponse
    {
        return $this->errorResponse($message, Response::HTTP_INTERNAL_SERVER_ERROR);
    }

    /**
     * Handle exceptions and return appropriate response
     */
    protected function handleException(\Throwable $exception, string $defaultMessage = 'An error occurred'): JsonResponse
    {
        // Log the exception
        Log::error('API Exception', [
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'trace' => $exception->getTraceAsString(),
        ]);

        // Handle specific exception types
        if ($exception instanceof ValidationException) {
            return $this->validationErrorResponse($exception);
        }

        // In production, don't expose sensitive error details
        if (app()->environment('production')) {
            return $this->serverErrorResponse($defaultMessage);
        }

        // In development, return detailed error information
        return $this->errorResponse(
            $exception->getMessage(),
            Response::HTTP_INTERNAL_SERVER_ERROR,
            [
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
                'trace' => config('app.debug') ? $exception->getTraceAsString() : null,
            ]
        );
    }

    /**
     * Paginate response
     */
    protected function paginatedResponse($paginator, string $message = 'Data retrieved successfully'): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
                'has_more_pages' => $paginator->hasMorePages(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ]);
    }

    /**
     * Transform data with optional transformer
     */
    protected function transformData($data, callable $transformer = null): mixed
    {
        if ($transformer === null) {
            return $data;
        }

        if (is_iterable($data)) {
            return collect($data)->map($transformer)->toArray();
        }

        return $transformer($data);
    }

    /**
     * Validate request data
     */
    protected function validateRequest(Request $request, array $rules, array $messages = []): array
    {
        try {
            return $request->validate($rules, $messages);
        } catch (ValidationException $e) {
            throw $e;
        }
    }

    /**
     * Get authenticated user
     */
    protected function getAuthenticatedUser(): \App\Models\User
    {
        return auth()->user();
    }

    /**
     * Check if user owns resource
     */
    protected function userOwnsResource($resource, string $userIdField = 'user_id'): bool
    {
        if (!$resource) {
            return false;
        }

        return $resource->{$userIdField} === $this->getAuthenticatedUser()->id;
    }

    /**
     * Get pagination parameters from request
     */
    protected function getPaginationParams(Request $request): array
    {
        return [
            'page' => $request->get('page', 1),
            'per_page' => min($request->get('per_page', 15), 100), // Max 100 items per page
        ];
    }

    /**
     * Apply common filters to query
     */
    protected function applyFilters($query, Request $request, array $allowedFilters = []): mixed
    {
        foreach ($allowedFilters as $filter => $column) {
            if ($request->has($filter) && $request->get($filter) !== null) {
                $value = $request->get($filter);
                
                if (is_array($column)) {
                    // Custom filter logic
                    $filterMethod = $column['method'] ?? 'where';
                    $filterColumn = $column['column'] ?? $filter;
                    $query->{$filterMethod}($filterColumn, $value);
                } else {
                    // Simple where clause
                    $query->where($column, $value);
                }
            }
        }

        return $query;
    }

    /**
     * Apply search to query
     */
    protected function applySearch($query, Request $request, array $searchableColumns = []): mixed
    {
        $searchTerm = $request->get('search');
        
        if ($searchTerm && !empty($searchableColumns)) {
            $query->where(function ($q) use ($searchTerm, $searchableColumns) {
                foreach ($searchableColumns as $column) {
                    $q->orWhere($column, 'ILIKE', "%{$searchTerm}%");
                }
            });
        }

        return $query;
    }

    /**
     * Apply sorting to query
     */
    protected function applySorting($query, Request $request, array $allowedSortColumns = [], string $defaultSort = 'created_at', string $defaultDirection = 'desc'): mixed
    {
        $sortBy = $request->get('sort_by', $defaultSort);
        $sortDirection = $request->get('sort_direction', $defaultDirection);

        // Validate sort direction
        if (!in_array(strtolower($sortDirection), ['asc', 'desc'])) {
            $sortDirection = $defaultDirection;
        }

        // Validate sort column
        if (!empty($allowedSortColumns) && !in_array($sortBy, $allowedSortColumns)) {
            $sortBy = $defaultSort;
        }

        return $query->orderBy($sortBy, $sortDirection);
    }
}