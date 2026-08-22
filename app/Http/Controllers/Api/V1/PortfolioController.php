<?php

namespace App\Http\Controllers\Api\V1;

use App\Models\PortfolioItem;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PortfolioController extends BaseApiController
{
    /**
     * List portfolio items for the authenticated freelancer.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $items = PortfolioItem::where('user_id', $user->id)
            ->with(['category:id,name,slug', 'skill:id,name,slug'])
            ->orderBy('display_order')
            ->orderByDesc('created_at')
            ->get();

        return $this->sendResponse($items, 'Portfolio items retrieved successfully.');
    }

    /**
     * Store a new portfolio item for the authenticated freelancer.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();

        if ($user->role !== 'freelancer' && $user->role !== 'admin') {
            return $this->sendForbidden('Only freelancers can create portfolio items.');
        }

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'category_id' => 'nullable|exists:categories,id',
            'skill_id' => 'nullable|exists:skills,id',
            'project_url' => 'nullable|url|max:255',
            'image' => 'nullable|file|image|max:5120',
            'image_url' => 'nullable|string|max:500',
            'display_order' => 'nullable|integer|min:0',
        ]);

        $imageUrl = $validated['image_url'] ?? null;

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $fileName = $user->id . '_portfolio_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $file->getClientOriginalExtension();
            $file->storeAs('portfolios', $fileName, 'public');
            $imageUrl = Storage::disk('public')->url('portfolios/' . $fileName);
        }

        $maxOrder = PortfolioItem::where('user_id', $user->id)->max('display_order') ?? 0;

        $item = PortfolioItem::create([
            'user_id' => $user->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'category_id' => $validated['category_id'] ?? null,
            'skill_id' => $validated['skill_id'] ?? null,
            'project_url' => $validated['project_url'] ?? null,
            'image_url' => $imageUrl,
            'display_order' => $validated['display_order'] ?? ($maxOrder + 1),
        ]);

        $item->load(['category:id,name,slug', 'skill:id,name,slug']);

        return $this->sendResponse($item, 'Portfolio item created successfully.', 201);
    }

    /**
     * Show a specific portfolio item.
     */
    public function show(Request $request, PortfolioItem $portfolio): JsonResponse
    {
        $portfolio->load(['category:id,name,slug', 'skill:id,name,slug', 'user:id,name,avatar']);

        return $this->sendResponse($portfolio, 'Portfolio item retrieved successfully.');
    }

    /**
     * Update an existing portfolio item.
     */
    public function update(Request $request, PortfolioItem $portfolio): JsonResponse
    {
        $user = $request->user();

        if ($portfolio->user_id !== $user->id && $user->role !== 'admin') {
            return $this->sendForbidden('You do not have permission to update this portfolio item.');
        }

        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string|max:5000',
            'category_id' => 'nullable|exists:categories,id',
            'skill_id' => 'nullable|exists:skills,id',
            'project_url' => 'nullable|url|max:255',
            'image' => 'nullable|file|image|max:5120',
            'image_url' => 'nullable|string|max:500',
            'display_order' => 'nullable|integer|min:0',
        ]);

        if ($request->hasFile('image')) {
            // Remove old image if exists
            if ($portfolio->image_url) {
                $oldPath = str_replace('/storage/', '', parse_url($portfolio->image_url, PHP_URL_PATH) ?? $portfolio->image_url);
                if (Storage::disk('public')->exists($oldPath)) {
                    Storage::disk('public')->delete($oldPath);
                }
            }

            $file = $request->file('image');
            $fileName = $user->id . '_portfolio_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $file->getClientOriginalExtension();
            $file->storeAs('portfolios', $fileName, 'public');
            $validated['image_url'] = Storage::disk('public')->url('portfolios/' . $fileName);
        }

        unset($validated['image']);
        $portfolio->update($validated);
        $portfolio->load(['category:id,name,slug', 'skill:id,name,slug']);

        return $this->sendResponse($portfolio->fresh(), 'Portfolio item updated successfully.');
    }

    /**
     * Delete a portfolio item.
     */
    public function destroy(Request $request, PortfolioItem $portfolio): JsonResponse
    {
        $user = $request->user();

        if ($portfolio->user_id !== $user->id && $user->role !== 'admin') {
            return $this->sendForbidden('You do not have permission to delete this portfolio item.');
        }

        if ($portfolio->image_url) {
            $oldPath = str_replace('/storage/', '', parse_url($portfolio->image_url, PHP_URL_PATH) ?? $portfolio->image_url);
            if (Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }
        }

        $portfolio->delete();

        return $this->sendResponse(null, 'Portfolio item deleted successfully.');
    }

    /**
     * Reorder portfolio items.
     */
    public function reorder(Request $request): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'items' => 'required|array',
            'items.*.id' => 'required|integer|exists:portfolio_items,id',
            'items.*.order' => 'required|integer|min:0',
        ]);

        foreach ($validated['items'] as $itemData) {
            PortfolioItem::where('id', $itemData['id'])
                ->where('user_id', $user->id)
                ->update(['display_order' => $itemData['order']]);
        }

        $items = PortfolioItem::where('user_id', $user->id)
            ->with(['category:id,name,slug', 'skill:id,name,slug'])
            ->orderBy('display_order')
            ->get();

        return $this->sendResponse($items, 'Portfolio items reordered successfully.');
    }

    /**
     * Public endpoint: list portfolio items for a specific freelancer.
     */
    public function publicIndex(int $userId): JsonResponse
    {
        $user = User::find($userId);
        if (!$user) {
            return $this->sendError('Freelancer not found.', [], 404);
        }

        $items = PortfolioItem::where('user_id', $userId)
            ->with(['category:id,name,slug', 'skill:id,name,slug'])
            ->orderBy('display_order')
            ->orderByDesc('created_at')
            ->get();

        return $this->sendResponse($items, 'Portfolio items retrieved successfully.');
    }
}
