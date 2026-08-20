<?php

declare(strict_types=1);

namespace App\Providers;

use App\Repositories\Contracts\AgentRepositoryInterface;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Repositories\Contracts\ZoneRepositoryInterface;
use App\Repositories\Eloquent\AgentRepository;
use App\Repositories\Eloquent\CustomerRepository;
use App\Repositories\Eloquent\PaymentRepository;
use App\Repositories\Eloquent\ZoneRepository;
use Illuminate\Support\ServiceProvider;

class RepositoryServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ZoneRepositoryInterface::class, ZoneRepository::class);
        $this->app->bind(CustomerRepositoryInterface::class, CustomerRepository::class);
        $this->app->bind(PaymentRepositoryInterface::class, PaymentRepository::class);
        $this->app->bind(AgentRepositoryInterface::class, AgentRepository::class);
    }
}
