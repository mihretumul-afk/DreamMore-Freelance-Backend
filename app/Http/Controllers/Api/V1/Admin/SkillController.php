<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Skill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SkillController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $query = Skill::with('category');

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category_id')) {
            $query->where('category_id', $request->input('category_id'));
        }

        $skills = $query->orderBy('name')->paginate(15);

        return $this->sendResponse(
            $skills,
            'Skills retrieved successfully.',
            200,
            [
                'current_page' => $skills->currentPage(),
                'last_page' => $skills->lastPage(),
                'per_page' => $skills->perPage(),
                'total' => $skills->total(),
            ]
        );
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => 'nullable|string|max:255|unique:skills,slug',
            'category_id' => 'nullable|exists:categories,id',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ]);

        if (empty($validated['slug'])) {
            $validated['slug'] = Str::slug($validated['name']);
            if (Skill::where('slug', $validated['slug'])->exists()) {
                $validated['slug'] .= '-' . Str::random(4);
            }
        }

        $validated['is_active'] = $request->boolean('is_active', true);

        $skill = Skill::create($validated);

        return $this->sendResponse($skill->load('category'), 'Skill created successfully.', 201);
    }

    public function update(Request $request, Skill $skill): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'slug' => 'nullable|string|max:255|unique:skills,slug,' . $skill->id,
            'category_id' => 'nullable|exists:categories,id',
            'description' => 'nullable|string|max:1000',
            'is_active' => 'boolean',
        ]);

        if (isset($validated['is_active'])) {
            $validated['is_active'] = $request->boolean('is_active');
        }

        $skill->update($validated);

        return $this->sendResponse($skill->fresh()->load('category'), 'Skill updated successfully.');
    }

    public function destroy(Skill $skill): JsonResponse
    {
        $skill->delete();

        return $this->sendResponse(null, 'Skill deleted successfully.');
    }
}
