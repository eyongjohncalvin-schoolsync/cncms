<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Service;
use App\Models\User;
use App\Support\TenantContext;

/**
 * Settings -> Services catalogue (services.md sections 6-7) — one
 * permission for the whole surface, including its variants ("options")
 * sub-CRUD (services.md section 7: "variants are a detail of managing a
 * service, not a separate concern with its own audience" — so
 * SettingsServiceController's variant actions authorize against this same
 * policy's update()/delete(), never a dedicated ServiceVariantPolicy).
 */
class ServicePolicy
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('services.manage');
    }

    public function create(User $user): bool
    {
        return $this->context->can('services.manage');
    }

    public function update(User $user, Service $service): bool
    {
        return $this->context->can('services.manage');
    }

    public function delete(User $user, Service $service): bool
    {
        return $this->context->can('services.manage');
    }
}
