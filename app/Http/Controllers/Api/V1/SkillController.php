<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\SkillResource;
use App\Models\Skill;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SkillController extends BaseApiController
{
    /**
     * List active skills, optionally filtered by category.
     * Public endpoint — no authentication required, read-only.
     */
    public function index(Request $request): JsonResponse
    {
        $skills = Skill::query()
            ->where('is_active', true)
            ->with('category')
            ->when($request->filled('category_id'), fn ($query) => $query->where('category_id', $request->input('category_id')))
            ->when($request->filled('search'), function ($query) use ($request) {
                $search = $request->input('search');

                return $query->where('name', 'like', "%{$search}%");
            })
            ->orderBy('name')
            ->get();

        return $this->sendResponse(
            SkillResource::collection($skills)->resolve($request),
            'Skills retrieved successfully.'
        );
    }
}
