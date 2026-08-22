<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\CategoryResource;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CategoryController extends BaseApiController
{
    /**
     * List active marketplace categories with their skills and real counts.
     * Public endpoint — no authentication required, read-only.
     */
    public function index(Request $request): JsonResponse
    {
        $categories = Category::query()
            ->where('is_active', true)
            ->with('skills')
            ->withCount(['jobs' => fn ($query) => $query->where('status', 'open')->whereHas('employer', fn ($eq) => $eq->where('status', 'active'))])
            ->withCount('skills')
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->input('search');

                return $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->orderBy('name')
            ->get();

        return $this->sendResponse(
            CategoryResource::collection($categories),
            'Categories retrieved successfully.'
        );
    }
}
