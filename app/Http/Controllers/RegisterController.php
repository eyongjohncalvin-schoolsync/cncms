<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreRegistrationRequest;
use App\Http\Requests\StoreWorkspaceRequest;
use App\Models\User;
use App\Services\WorkspaceProvisioningService;
use App\Support\GeneratesUsername;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Self-service registration and workspace creation — see
 * .ai/skills/cncms/cncms-context/references/self-service-onboarding.md.
 * Deliberately reachable by guests (create/store) as well as by an
 * authenticated-but-tenant-less user (workspace/storeWorkspace, e.g. a
 * fresh Google sign-up — see App\Http\Controllers\GoogleAuthController),
 * so routes/web/register.php sits outside the ['auth', 'tenant.web'] group
 * entirely and applies 'guest' / 'auth' per-route instead.
 */
class RegisterController extends Controller
{
    use GeneratesUsername;

    public function __construct(
        private readonly WorkspaceProvisioningService $provisioning,
    ) {}

    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    /**
     * One combined submit: creates the central User AND provisions the new
     * workspace (Tenant) in the same request. `email_verified_at` stays
     * null — a classic email/password signup isn't Google-verified, unlike
     * the GoogleAuthController path.
     */
    public function store(StoreRegistrationRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $user = User::query()->create([
            'name' => $data['name'],
            'username' => $this->generateUsername($data['email'], $data['name']),
            'email' => $data['email'],
            // The 'password' attribute is cast to 'hashed' on the User
            // model, so Eloquent hashes this automatically on save.
            'password' => $data['password'],
            'status' => 'active',
        ]);

        $this->provisioning->provision($user, $data);

        Auth::login($user);

        $request->session()->regenerate();

        return redirect()->route('workspace.pending');
    }

    /**
     * Company-info-ONLY form for an already-authenticated user with no
     * workspace yet (arrived via Google OAuth with no matching tenant).
     */
    public function workspace(): Response
    {
        return Inertia::render('Auth/RegisterWorkspace');
    }

    public function storeWorkspace(StoreWorkspaceRequest $request): RedirectResponse
    {
        $this->provisioning->provision($request->user(), $request->validated());

        return redirect()->route('workspace.pending');
    }
}
