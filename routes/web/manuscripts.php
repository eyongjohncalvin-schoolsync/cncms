<?php

declare(strict_types=1);

use App\Http\Controllers\ManuscriptController;
use Illuminate\Support\Facades\Route;

Route::get('manuscripts', [ManuscriptController::class, 'index'])->name('manuscripts.index');

// 'throttle:exports' (10/min/user, config/rate-limits.php) layers on top
// of the group-level 'throttle:web' (300/min) applied in routes/web.php —
// the tighter export limit always trips first, so this is the effective
// ceiling.
Route::get('manuscripts/export', [ManuscriptController::class, 'export'])
    ->name('manuscripts.export')
    ->middleware('throttle:exports');

Route::post('manuscripts/calculate', [ManuscriptController::class, 'calculate'])->name('manuscripts.calculate');

// The new one-click "just-triggered, watch it compute, then review and
// publish" screen calculate() now redirects to (task-scheduler.md's
// 2026-08-27 stage 3 addendum) — see ManuscriptController::runReview()'s
// doc comment. {run} binds by CommandRun's uuid route key
// (App\Models\CommandRun's #[RouteKey('uuid')]).
Route::get('manuscripts/runs/{run}', [ManuscriptController::class, 'runReview'])->name('manuscripts.runs.show');

// The pre-run "who hasn't paid" review list — see
// ManuscriptController::preRunReview()'s doc comment for the exact response
// shape. On-demand JSON, called only when an admin opens the review, not on
// every Manuscripts/Index.tsx page load. Registered above
// manuscripts/{customer}/send-bill so the literal 'pre-run-review' segment
// can never be mistaken for a {customer} route-model-bound uuid (not that it
// could be here anyway — that route is POST-only — but matching
// routes/web/manuscripts.php's existing "most specific path first" ordering
// convention).
//
// 'full' is the large-count companion (ManuscriptController::
// preRunReviewFull()'s doc comment) — a real Inertia page, registered above
// the plain JSON endpoint's route since it's the more specific of the two
// paths, matching this file's established ordering convention (Laravel's
// matcher doesn't actually require this given the differing segment counts,
// but it keeps the two related routes readable together).
Route::get('manuscripts/pre-run-review/full', [ManuscriptController::class, 'preRunReviewFull'])->name('manuscripts.pre-run-review.full');
Route::get('manuscripts/pre-run-review', [ManuscriptController::class, 'preRunReview'])->name('manuscripts.pre-run-review');

// Logs a `messages` row for the manual WhatsApp "Send Bill" action — see
// ManuscriptController::sendBill()'s doc comment. {customer} binds by
// Customer's uuid route key (App\Models\Customer's #[RouteKey('uuid')]),
// matching routes/web/customers.php's convention.
Route::post('manuscripts/{customer}/send-bill', [ManuscriptController::class, 'sendBill'])->name('manuscripts.send-bill');
