<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Requests\StoreServiceRequest;
use App\Http\Requests\StoreServiceVariantRequest;
use App\Http\Requests\UpdateServiceRequest;
use App\Http\Requests\UpdateServiceVariantRequest;
use App\Models\Service;
use App\Models\ServiceVariant;
use App\Services\CustomerSubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Settings -> Services — the company's service catalogue (services.md
 * sections 6-7): add/edit/deactivate/delete a service, add/edit/deactivate/
 * delete its "options" (variants — e.g. a TV channel broadcast), and push a
 * catalogue price out to every current subscriber. `services.manage`
 * gates the whole surface, variants included (ServicePolicy's class doc).
 */
class SettingsServiceController extends Controller
{
    public function __construct(
        private readonly CustomerSubscriptionService $subscriptions,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', Service::class);

        return Inertia::render('Settings/Services', [
            'services' => $this->shapeServices(),
        ]);
    }

    public function store(StoreServiceRequest $request): RedirectResponse
    {
        $data = $request->validated();

        if ($data['is_default'] ?? false) {
            $this->clearExistingDefault();
        }

        Service::query()->create($data);

        return redirect()->route('settings.services.index')->with('success', 'Service added.');
    }

    public function update(UpdateServiceRequest $request, Service $service): RedirectResponse
    {
        $data = $request->validated();

        if ($data['is_default'] ?? false) {
            $this->clearExistingDefault($service->id);
        }

        $service->update($data);

        return redirect()->route('settings.services.index')->with('success', 'Service updated.');
    }

    /**
     * Blocked while any customer subscribes to this service — mirrors
     * App\Services\CustomerService::delete()'s billing-history guard.
     * `restrictOnDelete()` on customer_service.service_id is the DB-level
     * backstop; this is the friendly 422 in front of it.
     */
    public function destroy(Service $service): RedirectResponse
    {
        $this->authorize('delete', $service);

        $subscriberCount = $service->customers()->count();

        if ($subscriberCount > 0) {
            throw ValidationException::withMessages([
                'service' => ["{$subscriberCount} customer(s) subscribe to \"{$service->name}\" — deactivate it instead of deleting it."],
            ]);
        }

        $service->delete();

        return redirect()->route('settings.services.index')->with('success', 'Service deleted.');
    }

    /**
     * "Apply new price to all N current subscribers" — services.md
     * section 6/7.
     */
    public function applyPrice(Service $service): RedirectResponse
    {
        $this->authorize('update', $service);

        $affected = $this->subscriptions->applyCataloguePriceToSubscribers($service);

        return redirect()->route('settings.services.index')->with(
            'success',
            $affected === 1 ? '1 subscriber repriced.' : "{$affected} subscribers repriced."
        );
    }

    // -----------------------------------------------------------------
    // Options ("variants" — services.md section 4), nested under a service
    // -----------------------------------------------------------------

    public function storeVariant(StoreServiceVariantRequest $request, Service $service): RedirectResponse
    {
        $service->variants()->create($request->validated());

        return redirect()->route('settings.services.index')->with('success', 'Option added.');
    }

    public function updateVariant(UpdateServiceVariantRequest $request, Service $service, ServiceVariant $variant): RedirectResponse
    {
        $this->assertVariantBelongsToService($service, $variant);

        $variant->update($request->validated());

        return redirect()->route('settings.services.index')->with('success', 'Option updated.');
    }

    public function destroyVariant(Service $service, ServiceVariant $variant): RedirectResponse
    {
        $this->authorize('update', $service);
        $this->assertVariantBelongsToService($service, $variant);

        $subscriberCount = DB::table('customer_service')->where('service_variant_id', $variant->id)->count();

        if ($subscriberCount > 0) {
            throw ValidationException::withMessages([
                'variant' => ["{$subscriberCount} customer(s) hold \"{$variant->name}\" — deactivate it instead of deleting it."],
            ]);
        }

        $variant->delete();

        return redirect()->route('settings.services.index')->with('success', 'Option deleted.');
    }

    public function applyVariantPrice(Service $service, ServiceVariant $variant): RedirectResponse
    {
        $this->authorize('update', $service);
        $this->assertVariantBelongsToService($service, $variant);

        $affected = $this->subscriptions->applyVariantPriceToSubscribers($variant);

        return redirect()->route('settings.services.index')->with(
            'success',
            $affected === 1 ? '1 subscriber repriced.' : "{$affected} subscribers repriced."
        );
    }

    /**
     * Route model binding resolves {service} and {variant} independently
     * by uuid — nothing stops a client from pairing a real variant uuid
     * with the WRONG service's uuid in the URL. Checked explicitly rather
     * than relying on Laravel's implicit route-scoping (which needs the
     * relation-name convention to line up and is easy to silently miss).
     */
    private function assertVariantBelongsToService(Service $service, ServiceVariant $variant): void
    {
        abort_unless($variant->service_id === $service->id, 404);
    }

    /**
     * `uq_services_single_default` allows at most one `is_default = true`
     * row — clear whichever row currently holds it before setting a new
     * one, so a client's `is_default: true` never trips the constraint.
     * $exceptServiceId excludes the row being updated (it may already BE
     * the default and simply not be changing).
     */
    private function clearExistingDefault(?int $exceptServiceId = null): void
    {
        Service::query()
            ->where('is_default', true)
            ->when($exceptServiceId !== null, fn ($query) => $query->where('id', '!=', $exceptServiceId))
            ->update(['is_default' => false]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function shapeServices(): array
    {
        return Service::query()
            ->ordered()
            ->withCount('customers')
            ->with(['variants' => fn ($query) => $query->ordered()])
            ->get()
            ->map(fn (Service $service): array => [
                'uuid' => $service->uuid,
                'name' => $service->name,
                'description' => $service->description,
                'price' => $service->price,
                'is_default' => $service->is_default,
                'active' => $service->active,
                'subscriber_count' => $service->customers_count,
                'variants' => $service->variants->map(fn (ServiceVariant $variant): array => [
                    'uuid' => $variant->uuid,
                    'name' => $variant->name,
                    'price' => $variant->price,
                    'active' => $variant->active,
                    'subscriber_count' => DB::table('customer_service')->where('service_variant_id', $variant->id)->count(),
                ])->values()->all(),
            ])
            ->values()
            ->all();
    }
}
