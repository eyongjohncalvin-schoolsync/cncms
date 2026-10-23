<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\InstallationOffer;
use App\Models\User;
use App\Support\TenantContext;

/**
 * Settings → Installation offers (docs/plans/installation-and-promotions.md,
 * phase 2) — `installations.offers` gates the whole screen, including the
 * company's cap on free months per grant. Managers only pick an offer on
 * the add-customer form (`installations.manage`); setting the offers is
 * admin/owner (D8).
 */
class InstallationOfferPolicy
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('installations.offers');
    }

    public function create(User $user): bool
    {
        return $this->context->can('installations.offers');
    }

    public function update(User $user, InstallationOffer $installationOffer): bool
    {
        return $this->context->can('installations.offers');
    }

    public function delete(User $user, InstallationOffer $installationOffer): bool
    {
        return $this->context->can('installations.offers');
    }
}
