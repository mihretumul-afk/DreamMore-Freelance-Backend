<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;

trait ApiResponseTrait
{
    /**
     * Return a standardized success JSON response.
     */
    public function sendResponse(mixed $data = null, string $message = 'Operation successful.', int $code = 200, array $meta = []): JsonResponse
    {
        $response = [
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ];

        if (!empty($meta)) {
            $response['meta'] = $meta;
        }

        return response()->json($response, $code);
    }

    /**
     * Return a standardized error JSON response.
     */
    public function sendError(string $error = 'An error occurred.', array $errorMessages = [], int $code = 400): JsonResponse
    {
        $response = [
            'success' => false,
            'message' => $error,
        ];

        if (!empty($errorMessages)) {
            $response['errors'] = $errorMessages;
        }

        return response()->json($response, $code);
    }

    /**
     * Return unauthorized response.
     */
    public function sendUnauthorized(string $message = 'Unauthenticated.'): JsonResponse
    {
        return $this->sendError($message, [], 401);
    }

    /**
     * Return forbidden response.
     */
    public function sendForbidden(string $message = 'Forbidden.'): JsonResponse
    {
        return $this->sendError($message, [], 403);
    }
}
