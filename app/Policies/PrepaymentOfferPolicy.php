<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PrepaymentOffer;
use App\Models\User;
use App\Support\TenantContext;

/**
 * Prepayment offers: headquarters keeps the list (`payments.offers`, acting
 * for the company). Collectors only pick one when recording a payment; a
 * branch's own price for an offer is a branch setting (BranchPolicy).
 */
class PrepaymentOfferPolicy
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    public function viewAny(User $user): bool
    {
        return $this->context->can('payments.offers');
    }

    public function create(User $user): bool
    {
        return $this->context->can('payments.offers') && $this->context->actsForCompany();
    }

    public function update(User $user, PrepaymentOffer $offer): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, PrepaymentOffer $offer): bool
    {
        return $this->create($user);
    }
}
