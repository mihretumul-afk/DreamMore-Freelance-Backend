<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Category;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class CategoryController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Category::withCount('skills');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        $categories = $query->orderBy('name')->get();

        return $this->sendResponse($categories, 'Categories retrieved successfully.');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:categories,slug',
            'description' => 'nullable|string|max:1000',
            'icon' => 'nullable|string|max:100',
            'is_active' => 'boolean',
        ]);

        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
            // Ensure uniqueness.
            if (Category::where('slug', $validated['slug'])->exists()) {
                $validated['slug'] .= '-' . Str::random(4);
            }
        }

        $validated['is_active'] = $request->boolean('is_active', true);

        $category = Category::create($validated);

        return $this->sendResponse($category, 'Category created successfully.', 201);
    }

    public function update(Request $request, Category $category): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'slug' => 'nullable|string|max:255|unique:categories,slug,' . $category->id,
            'description' => 'nullable|string|max:1000',
            'icon' => 'nullable|string|max:100',
            'is_active' => 'boolean',
        ]);

        if (isset($validated['is_active'])) {
            $validated['is_active'] = $request->boolean('is_active');
        }

        $category->update($validated);

        return $this->sendResponse($category->fresh(), 'Category updated successfully.');
    }

    public function destroy(Category $category): JsonResponse
    {
        $category->delete();

        return $this->sendResponse(null, 'Category deleted successfully.');
    }
}
