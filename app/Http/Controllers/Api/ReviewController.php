<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function index(Request $request, Organization $organization)
    {
        $this->authorize('view', $organization);

        $reviews = $organization->reviews()
            ->orderByDesc('reviewed_at')
            ->paginate(50);

        return response()->json($reviews);
    }
}
