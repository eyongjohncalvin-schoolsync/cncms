<?php

declare(strict_types=1);

namespace App\Providers;

use App\Repositories\Contracts\AgentRepositoryInterface;
use App\Repositories\Contracts\ArrearsAdjustmentRepositoryInterface;
use App\Repositories\Contracts\AuditLogRepositoryInterface;
use App\Repositories\Contracts\BranchRepositoryInterface;
use App\Repositories\Contracts\ComplaintRepositoryInterface;
use App\Repositories\Contracts\CustomerRepositoryInterface;
use App\Repositories\Contracts\ExpenditureRepositoryInterface;
use App\Repositories\Contracts\ExpenseCategoryRepositoryInterface;
use App\Repositories\Contracts\ManuscriptRepositoryInterface;
use App\Repositories\Contracts\NotificationRepositoryInterface;
use App\Repositories\Contracts\PaymentRepositoryInterface;
use App\Repositories\Contracts\PaymentVerificationRepositoryInterface;
use App\Repositories\Contracts\PushTokenRepositoryInterface;
use App\Repositories\Contracts\ZoneRepositoryInterface;
use App\Repositories\Eloquent\AgentRepository;
use App\Repositories\Eloquent\ArrearsAdjustmentRepository;
use App\Repositories\Eloquent\AuditLogRepository;
use App\Repositories\Eloquent\BranchRepository;
use App\Repositories\Eloquent\ComplaintRepository;
use App\Repositories\Eloquent\CustomerRepository;
use App\Repositories\Eloquent\ExpenditureRepository;
use App\Repositories\Eloquent\ExpenseCategoryRepository;
use App\Repositories\Eloquent\ManuscriptRepository;
use App\Repositories\Eloquent\NotificationRepository;
use App\Repositories\Eloquent\PaymentRepository;
use App\Repositories\Eloquent\PaymentVerificationRepository;
use App\Repositories\Eloquent\PushTokenRepository;
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
        $this->app->bind(BranchRepositoryInterface::class, BranchRepository::class);
        $this->app->bind(CustomerRepositoryInterface::class, CustomerRepository::class);
        $this->app->bind(PaymentRepositoryInterface::class, PaymentRepository::class);
        $this->app->bind(PaymentVerificationRepositoryInterface::class, PaymentVerificationRepository::class);
        $this->app->bind(AgentRepositoryInterface::class, AgentRepository::class);
        $this->app->bind(ManuscriptRepositoryInterface::class, ManuscriptRepository::class);
        $this->app->bind(AuditLogRepositoryInterface::class, AuditLogRepository::class);
        $this->app->bind(ExpenditureRepositoryInterface::class, ExpenditureRepository::class);
        $this->app->bind(ExpenseCategoryRepositoryInterface::class, ExpenseCategoryRepository::class);
        $this->app->bind(ComplaintRepositoryInterface::class, ComplaintRepository::class);
        $this->app->bind(NotificationRepositoryInterface::class, NotificationRepository::class);
        $this->app->bind(ArrearsAdjustmentRepositoryInterface::class, ArrearsAdjustmentRepository::class);
        $this->app->bind(PushTokenRepositoryInterface::class, PushTokenRepository::class);
    }
}
