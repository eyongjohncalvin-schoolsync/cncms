<?php

declare(strict_types=1);

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\LandlordActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only, paginated log of platform-level landlord actions
 * (App\Models\LandlordActivity), newest first, optionally filtered to one
 * kind of subject.
 */
class ActivityController extends Controller
{
    private const array SUBJECT_TYPES = ['tenant', 'maintenance', 'user'];

    public function index(Request $request): Response
    {
        $subject = in_array($request->query('subject'), self::SUBJECT_TYPES, true) ? $request->query('subject') : null;

        $activities = LandlordActivity::query()
            ->with('user:id,name')
            ->when($subject, fn ($query) => $query->where('subject_type', $subject))
            ->latest('created_at')
            ->latest('id')
            ->paginate(50)
            ->withQueryString();

        return Inertia::render('Landlord/Activity/Index', [
            'activities' => [
                'data' => self::shape($activities->getCollection()),
                'links' => $activities->linkCollection(),
                'total' => $activities->total(),
            ],
            'filters' => ['subject' => $subject],
        ]);
    }

    /**
     * @param  Collection<int, LandlordActivity>  $activities
     * @return list<array{id: int, action: string, summary: string, subject_type: string|null, subject_id: string|null, details: array<string, mixed>|null, user: string|null, ip_address: string|null, created_at: string|null}>
     */
    public static function shape(Collection $activities): array
    {
        return $activities->map(fn (LandlordActivity $activity) => [
            'id' => $activity->id,
            'action' => $activity->action,
            'summary' => $activity->summary,
            'subject_type' => $activity->subject_type,
            'subject_id' => $activity->subject_id,
            'details' => $activity->details,
            'user' => $activity->user?->name,
            'ip_address' => $activity->ip_address,
            'created_at' => $activity->created_at?->toIso8601String(),
        ])->values()->all();
    }
}
