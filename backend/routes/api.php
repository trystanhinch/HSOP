<?php

use App\Http\Controllers\Api\AvailabilityAdminController;
use App\Http\Controllers\Api\AccountingController;
use App\Http\Controllers\Api\AdminPmMessageController;
use App\Http\Controllers\Api\ActivityTimelineController;
use App\Http\Controllers\Api\AiSettingsController;
use App\Http\Controllers\Api\GmailOAuthController;
use App\Http\Controllers\Api\MessageTemplateController;
use App\Http\Controllers\Api\NotificationChannelController;
use App\Http\Controllers\Api\WorkflowAssistController;
use App\Http\Controllers\Api\CompanySourceController;
use App\Http\Controllers\Api\PricingRuleController;
use App\Http\Controllers\Api\CustomerPortalController;
use App\Http\Controllers\Api\AdminUserController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\DeveloperUnlockController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\ContractorLeadController;
use App\Http\Controllers\Api\ContractorController;
use App\Http\Controllers\Api\ContractorDocumentController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\InvoiceController;
use App\Http\Controllers\Api\JobController;
use App\Http\Controllers\Api\JobUpdateController;
use App\Http\Controllers\Api\LeadMessageController;
use App\Http\Controllers\Api\LeadController;
use App\Http\Controllers\Api\IntakeQuarantineController;
use App\Http\Controllers\Api\LearningSnapshotController;
use App\Http\Controllers\Api\MessageController;
use App\Http\Controllers\Api\NextActionController;
use App\Http\Controllers\Api\PaymentController;
use App\Http\Controllers\Api\PayoutController;
use App\Http\Controllers\Api\PmContractorMessageController;
use App\Http\Controllers\Api\ProfitReportController;
use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\Api\ScheduleController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\PaymentDestinationController;
use App\Http\Controllers\Api\PmBrandAssignmentController;
use App\Http\Controllers\Api\EmailLogController;
use App\Http\Controllers\Api\FinancialLedgerController;
use App\Http\Controllers\Api\FileController;
use App\Http\Controllers\Api\SmsLogController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\BrandContentController;
use App\Http\Controllers\Api\BrandContentImageController;
use App\Http\Controllers\Api\BrandContentWorkflowController;
use App\Http\Controllers\Api\BrandCustomPageController;
use App\Http\Controllers\Api\BrandLocationPageController;
use App\Http\Controllers\Api\ContentEditorBrandAssignmentController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register']);

Route::get('/files/{path}', [FileController::class, 'show'])->where('path', '.*');

Route::get('/quote/view/{token}', [QuoteController::class, 'viewByToken']);
Route::post('/quote/view/{token}/approve', [QuoteController::class, 'approveByToken']);
Route::post('/quote/view/{token}/reject', [QuoteController::class, 'rejectByToken']);

Route::get('/portal/{token}', [CustomerPortalController::class, 'show']);
Route::post('/portal/{token}/accept-quote', [CustomerPortalController::class, 'acceptQuote']);
Route::post('/portal/{token}/reject-quote', [CustomerPortalController::class, 'rejectQuote']);
Route::post('/portal/{token}/accept-completion', [CustomerPortalController::class, 'acceptCompletion']);
Route::post('/portal/{token}/request-revision', [CustomerPortalController::class, 'requestRevision']);
Route::post('/portal/{token}/notify-payment', [CustomerPortalController::class, 'notifyPayment']);
Route::get('/portal/{token}/payment-details', [CustomerPortalController::class, 'paymentDetails']);
Route::get('/portal/{token}/review', [\App\Http\Controllers\Api\ReviewFeedbackController::class, 'portalShow']);
Route::post('/portal/{token}/review', [\App\Http\Controllers\Api\ReviewFeedbackController::class, 'portalSubmit']);
Route::post('/portal/{token}/stripe/checkout', [\App\Http\Controllers\Api\StripeCheckoutController::class, 'portalCheckout']);
Route::post('/stripe/webhook', \App\Http\Controllers\Api\StripeWebhookController::class);

Route::middleware(['auth:sanctum', 'active.user', 'restrict.content_editor'])->group(function () {
    Route::post('/logout', [AuthController::class, 'logout'])->name('api.logout');
    Route::get('/me', [AuthController::class, 'me'])->name('api.me');

    Route::get('/brand-content', [BrandContentController::class, 'show'])
        ->middleware('role:content_editor,owner')
        ->name('api.brand-content.show');
    Route::put('/brand-content', [BrandContentController::class, 'update'])
        ->middleware('role:content_editor,owner')
        ->name('api.brand-content.update');
    // A-06/A-22: brand identity preview — owner/PM only.
    Route::get('/brand-preview', [BrandContentController::class, 'preview'])
        ->middleware('role:owner,pm')
        ->name('api.brand-preview');

    Route::middleware('role:content_editor,owner')->group(function () {
        Route::post('/brand-content/images', [BrandContentImageController::class, 'upload'])
            ->name('api.brand-content.images.upload');
        Route::put('/brand-content/images', [BrandContentImageController::class, 'updateMeta'])
            ->name('api.brand-content.images.meta');
        Route::delete('/brand-content/images', [BrandContentImageController::class, 'destroy'])
            ->name('api.brand-content.images.destroy');

        Route::get('/brand-content/locations', [BrandLocationPageController::class, 'index'])
            ->name('api.brand-content.locations.index');
        Route::post('/brand-content/locations', [BrandLocationPageController::class, 'store'])
            ->name('api.brand-content.locations.store');
        Route::put('/brand-content/locations/{locationPage}', [BrandLocationPageController::class, 'update'])
            ->name('api.brand-content.locations.update');
        Route::delete('/brand-content/locations/{locationPage}', [BrandLocationPageController::class, 'destroy'])
            ->name('api.brand-content.locations.destroy');

        Route::get('/brand-content/pages', [BrandCustomPageController::class, 'index'])
            ->name('api.brand-content.pages.index');
        Route::post('/brand-content/pages', [BrandCustomPageController::class, 'store'])
            ->name('api.brand-content.pages.store');
        Route::post('/brand-content/pages/duplicate', [BrandCustomPageController::class, 'duplicate'])
            ->name('api.brand-content.pages.duplicate');
        Route::put('/brand-content/pages/{brandPage}', [BrandCustomPageController::class, 'update'])
            ->name('api.brand-content.pages.update');
        Route::delete('/brand-content/pages/{brandPage}', [BrandCustomPageController::class, 'destroy'])
            ->name('api.brand-content.pages.destroy');

        // A-36 workflow / preview / revisions / redirects
        Route::get('/brand-content/section-types', [BrandContentWorkflowController::class, 'sectionTypes'])
            ->name('api.brand-content.section-types');
        Route::get('/brand-content/page-key-labels', [BrandContentWorkflowController::class, 'pageKeyLabels'])
            ->name('api.brand-content.page-key-labels');
        Route::get('/brand-content/redirects', [BrandContentWorkflowController::class, 'redirects'])
            ->name('api.brand-content.redirects.index');
        Route::post('/brand-content/redirects', [BrandContentWorkflowController::class, 'storeRedirect'])
            ->name('api.brand-content.redirects.store');

        Route::post('/brand-content/locations/{locationPage}/workflow', [BrandContentWorkflowController::class, 'transitionLocation'])
            ->name('api.brand-content.locations.workflow');
        Route::get('/brand-content/locations/{locationPage}/preview', [BrandContentWorkflowController::class, 'previewLocation'])
            ->name('api.brand-content.locations.preview');
        Route::get('/brand-content/locations/{locationPage}/revisions', [BrandContentWorkflowController::class, 'revisionsLocation'])
            ->name('api.brand-content.locations.revisions');
        Route::post('/brand-content/locations/{locationPage}/revisions/{revision}/rollback', [BrandContentWorkflowController::class, 'rollbackLocation'])
            ->name('api.brand-content.locations.rollback');

        Route::post('/brand-content/pages/{brandPage}/workflow', [BrandContentWorkflowController::class, 'transitionPage'])
            ->name('api.brand-content.pages.workflow');
        Route::get('/brand-content/pages/{brandPage}/preview', [BrandContentWorkflowController::class, 'previewPage'])
            ->name('api.brand-content.pages.preview');
        Route::get('/brand-content/pages/{brandPage}/revisions', [BrandContentWorkflowController::class, 'revisionsPage'])
            ->name('api.brand-content.pages.revisions');
        Route::post('/brand-content/pages/{brandPage}/revisions/{revision}/rollback', [BrandContentWorkflowController::class, 'rollbackPage'])
            ->name('api.brand-content.pages.rollback');
    });

    Route::get('/me/content-editor-brands', [ContentEditorBrandAssignmentController::class, 'mine'])
        ->middleware('role:content_editor,owner')
        ->name('api.me.content-editor-brands');

    Route::get('/dashboard/admin/kpis', [DashboardController::class, 'admin'])->middleware('role:owner');
    Route::get('/dashboard/pm/kpis', [DashboardController::class, 'pm'])->middleware('role:pm');
    Route::get('/dashboard/contractor/kpis', [DashboardController::class, 'contractor'])->middleware('role:contractor');
    Route::get('/dashboard/customer/summary', [DashboardController::class, 'customer'])->middleware('role:customer');
    Route::get('/dashboard/kpis', [DashboardController::class, 'kpis'])->middleware('role:owner');

    // A-23 — diagnostics require developer permission + password unlock (not mere owner).
    Route::get('/admin/developer/status', [DeveloperUnlockController::class, 'status'])->middleware('role:owner');
    Route::post('/admin/developer/unlock', [DeveloperUnlockController::class, 'unlock'])->middleware('role:owner');
    Route::get('/admin/database-overview', [AdminController::class, 'databaseOverview'])->middleware(['role:owner', 'developer']);
    Route::get('/admin/test-data', [\App\Http\Controllers\Api\TestDataController::class, 'summary'])->middleware('role:owner');
    Route::post('/admin/test-data/dry-run', [\App\Http\Controllers\Api\TestDataController::class, 'dryRun'])->middleware('role:owner');
    Route::post('/admin/test-data/apply', [\App\Http\Controllers\Api\TestDataController::class, 'apply'])->middleware('role:owner');

    Route::get('/companies', [CompanyController::class, 'index']);

    Route::get('/me/contractor', [ContractorController::class, 'me'])->middleware('role:contractor');
    Route::get('/me/contractor/availability', [\App\Http\Controllers\Api\ContractorPortalController::class, 'availability'])->middleware('role:contractor');
    Route::put('/me/contractor/availability', [\App\Http\Controllers\Api\ContractorPortalController::class, 'updateAvailability'])->middleware('role:contractor');
    Route::get('/me/contractor/onboarding', [\App\Http\Controllers\Api\ContractorPortalController::class, 'onboarding'])->middleware('role:contractor');

    Route::get('/users/pms', [UserController::class, 'pms'])->middleware('role:owner,pm');
    Route::get('/users/contractors', [UserController::class, 'contractors'])->middleware('role:owner,pm');
    Route::get('/users', [UserController::class, 'index'])->middleware('role:owner');
    Route::post('/users', [UserController::class, 'store'])->middleware('role:owner');

    Route::get('/leads', [LeadController::class, 'index']);
    Route::get('/leads/review-count', [LeadController::class, 'reviewCount'])->middleware('role:owner');
    Route::get('/leads/duplicate-groups/{groupId}', [LeadController::class, 'duplicateGroup'])->middleware('role:owner');
    Route::post('/leads/regroup-duplicates', [LeadController::class, 'regroupDuplicates'])->middleware('role:owner');
    Route::post('/leads/merge', [LeadController::class, 'merge'])->middleware('role:owner');
    Route::post('/leads/bulk-ignore', [LeadController::class, 'bulkIgnore'])->middleware('role:owner');
    Route::get('/intake-quarantine/pending-count', [IntakeQuarantineController::class, 'pendingCount'])->middleware('role:owner');
    Route::get('/intake-quarantine', [IntakeQuarantineController::class, 'index'])->middleware('role:owner');
    Route::get('/intake-quarantine/{intakeQuarantine}', [IntakeQuarantineController::class, 'show'])->middleware('role:owner');
    Route::post('/intake-quarantine/{intakeQuarantine}/approve', [IntakeQuarantineController::class, 'approve'])->middleware('role:owner');
    Route::post('/intake-quarantine/{intakeQuarantine}/ignore', [IntakeQuarantineController::class, 'ignore'])->middleware('role:owner');
    Route::post('/leads', [LeadController::class, 'store'])->middleware('role:owner,pm');
    Route::get('/leads/{lead}', [LeadController::class, 'show']);
    Route::put('/leads/{lead}', [LeadController::class, 'update'])->middleware('role:owner,pm');
    Route::post('/leads/{lead}/price-estimate-override', [LearningSnapshotController::class, 'overrideLeadEstimate'])
        ->middleware('role:owner,pm');
    Route::post('/leads/{lead}/price-estimate-recalculate', [LearningSnapshotController::class, 'recalculateLeadEstimate'])
        ->middleware('role:owner,pm');
    Route::get('/leads/{lead}/learning-snapshot', [LearningSnapshotController::class, 'forLead'])
        ->middleware('role:owner,pm');
    Route::post('/leads/{lead}/resolve-review', [LeadController::class, 'resolveReview'])->middleware('role:owner');
    Route::delete('/leads/{lead}', [LeadController::class, 'destroy'])->middleware('role:owner,pm');
    Route::post('/leads/{lead}/convert-to-job', [LeadController::class, 'convertToJob'])->middleware('role:owner,pm');
    Route::post('/leads/{lead}/send-quote', [LeadController::class, 'sendQuote'])->middleware('role:owner,pm');
    Route::post('/leads/{lead}/schedule-site-visit', [LeadController::class, 'scheduleSiteVisit'])->middleware('role:owner,pm');
    Route::post('/leads/{lead}/submit-price', [LeadController::class, 'submitPrice'])->middleware('role:contractor');

    Route::get('/leads/{lead}/next-action', [NextActionController::class, 'showForLead']);
    Route::put('/leads/{lead}/next-action', [NextActionController::class, 'updateForLead'])->middleware('role:owner,pm');
    Route::get('/leads/{lead}/timeline', [ActivityTimelineController::class, 'indexForLead']);
    Route::post('/leads/{lead}/timeline', [ActivityTimelineController::class, 'storeForLead'])->middleware('role:owner,pm');

    Route::get('/jobs/{job}/next-action', [NextActionController::class, 'showForJob']);
    Route::put('/jobs/{job}/next-action', [NextActionController::class, 'updateForJob'])->middleware('role:owner,pm');
    Route::get('/jobs/{job}/timeline', [ActivityTimelineController::class, 'indexForJob']);
    Route::post('/jobs/{job}/timeline', [ActivityTimelineController::class, 'storeForJob'])->middleware('role:owner,pm');

    Route::get('/jobs/search', [JobController::class, 'search']);
    Route::get('/jobs', [JobController::class, 'index']);
    Route::post('/jobs', [JobController::class, 'store'])->middleware('role:owner,pm');
    Route::get('/jobs/{job}', [JobController::class, 'show']);
    Route::get('/jobs/{job}/learning-snapshot', [LearningSnapshotController::class, 'forJob'])
        ->middleware('role:owner,pm');
    Route::put('/jobs/{job}', [JobController::class, 'update'])->middleware('role:owner,pm');
    Route::delete('/jobs/{job}', [JobController::class, 'destroy'])->middleware('role:owner');
    Route::post('/jobs/{job}/assign-pm', [JobController::class, 'assignPm'])->middleware('role:owner');
    Route::post('/jobs/{job}/assign-contractor', [JobController::class, 'assignContractor'])->middleware('role:owner,pm');
    Route::post('/jobs/{job}/schedule', [JobController::class, 'schedule'])->middleware('role:owner,pm');
    Route::post('/jobs/{job}/submit-price', [JobController::class, 'submitPrice'])->middleware('role:contractor');
    Route::post('/jobs/{job}/approve-price', [JobController::class, 'approvePrice'])->middleware('role:owner,pm');
    Route::post('/jobs/{job}/mark-ready-for-review', [JobController::class, 'markReadyForReview'])->middleware('role:contractor');
    Route::post('/jobs/{job}/contractor-complete', [JobController::class, 'contractorComplete'])->middleware('role:contractor');
    Route::post('/jobs/{job}/mark-complete', [JobController::class, 'markComplete'])->middleware('role:owner,pm');
    Route::post('/jobs/{job}/accept-completion', [JobController::class, 'acceptCompletion'])->middleware('role:customer,owner,pm');
    Route::post('/jobs/{job}/request-revision', [JobController::class, 'requestRevision'])->middleware('role:customer');
    Route::post('/jobs/{job}/request-corrections', [JobController::class, 'requestCorrections'])->middleware('role:owner,pm');
    Route::post('/jobs/{job}/notify-etransfer-sent', [JobController::class, 'notifyEtransferSent'])->middleware('role:customer,owner');
    Route::post('/jobs/{job}/confirm-payment', [JobController::class, 'confirmPayment'])->middleware('role:owner');
    Route::get('/jobs/{job}/payment-details', [JobController::class, 'paymentDetails'])->middleware('role:customer,owner,pm');
    Route::put('/jobs/{job}/split', [JobController::class, 'updateSplit'])->middleware('role:owner');
    Route::get('/jobs/{job}/activity-log', [JobController::class, 'activityLog']);

    Route::get('/jobs/{job}/updates', [JobUpdateController::class, 'index']);
    Route::post('/jobs/{job}/updates', [JobUpdateController::class, 'store']);

    Route::get('/quotes', [QuoteController::class, 'index']);
    Route::post('/quotes', [QuoteController::class, 'store'])->middleware('role:owner,pm');
    Route::get('/quotes/{quote}', [QuoteController::class, 'show']);
    Route::put('/quotes/{quote}', [QuoteController::class, 'update'])->middleware('role:owner,pm');
    Route::post('/quotes/{quote}/send', [QuoteController::class, 'send'])->middleware('role:owner,pm');
    Route::post('/quotes/{quote}/resend', [QuoteController::class, 'resend'])->middleware('role:owner,pm');
    Route::post('/quotes/{quote}/revise', [QuoteController::class, 'revise'])->middleware('role:owner,pm');
    Route::post('/quotes/{quote}/follow-up', [QuoteController::class, 'followUp'])->middleware('role:owner,pm');
    Route::post('/quotes/{quote}/expire', [QuoteController::class, 'expire'])->middleware('role:owner,pm');
    Route::post('/quotes/{quote}/mark-declined', [QuoteController::class, 'markDeclined'])->middleware('role:owner,pm');
    Route::post('/quotes/{quote}/internal-review', [QuoteController::class, 'markInternalReview'])->middleware('role:owner,pm');
    Route::post('/quotes/{quote}/approve', [QuoteController::class, 'approve'])->middleware('role:customer');
    Route::post('/quotes/{quote}/reject', [QuoteController::class, 'reject'])->middleware('role:customer');

    Route::get('/invoices', [InvoiceController::class, 'index']);
    Route::post('/invoices', [InvoiceController::class, 'store'])->middleware('role:owner,pm');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show']);
    Route::put('/invoices/{invoice}', [InvoiceController::class, 'update'])->middleware('role:owner,pm');
    Route::post('/invoices/{invoice}/mark-paid', [PaymentController::class, 'markPaid'])->middleware('role:owner');
    Route::post('/invoices/{invoice}/send', [InvoiceController::class, 'send'])->middleware('role:owner,pm');
    Route::post('/invoices/{invoice}/payment-link', [InvoiceController::class, 'paymentLink'])->middleware('role:owner,pm');
    Route::post('/invoices/{invoice}/record-contact', [InvoiceController::class, 'recordContact'])->middleware('role:owner,pm');
    Route::get('/invoices/{invoice}/payments', [PaymentController::class, 'history']);
    Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'pdf']);
    Route::post('/quotes/{quote}/create-invoice', [InvoiceController::class, 'fromQuote'])->middleware('role:owner,pm');
    Route::post('/jobs/{job}/create-invoice', [InvoiceController::class, 'fromJob'])->middleware('role:owner,pm');

    Route::get('/accounting/dashboard', [AccountingController::class, 'dashboard'])->middleware('role:owner');
    Route::get('/accounting/export', [AccountingController::class, 'export'])->middleware('role:owner');
    Route::get('/accounting/source-performance', [\App\Http\Controllers\Api\OpsReportController::class, 'sourcePerformance'])->middleware('role:owner');
    Route::post('/accounting/invoices/{invoice}/mock-pay', [AccountingController::class, 'mockPay'])->middleware('role:owner');
    Route::post('/accounting/payouts/{payout}/mock-transfer', [AccountingController::class, 'mockTransfer'])->middleware('role:owner');
    Route::post('/accounting/payouts/{payout}/execute-transfer', [AccountingController::class, 'executeTransfer'])->middleware('role:owner');

    Route::get('/ledger/summary', [FinancialLedgerController::class, 'summary'])->middleware('role:owner');
    Route::get('/ledger/drilldown', [FinancialLedgerController::class, 'drilldown'])->middleware('role:owner');
    Route::get('/ledger/payout-groups', [FinancialLedgerController::class, 'payoutGroups'])->middleware('role:owner');
    Route::get('/ledger/payouts/job/{jobId}', [FinancialLedgerController::class, 'payoutJob'])->middleware('role:owner');

    Route::get('/stripe/connect/status', [\App\Http\Controllers\Api\StripeConnectController::class, 'status'])->middleware('role:owner,pm,contractor');
    Route::post('/stripe/connect/start', [\App\Http\Controllers\Api\StripeConnectController::class, 'start'])->middleware('role:pm,contractor');
    Route::post('/stripe/connect/refresh', [\App\Http\Controllers\Api\StripeConnectController::class, 'refresh'])->middleware('role:pm,contractor');
    Route::post('/stripe/connect/sync', [\App\Http\Controllers\Api\StripeConnectController::class, 'sync'])->middleware('role:owner,pm,contractor');
    Route::post('/jobs/{job}/stripe/checkout', [\App\Http\Controllers\Api\StripeCheckoutController::class, 'jobCheckout'])->middleware('role:owner,pm,customer');
    Route::get('/reviews', [\App\Http\Controllers\Api\ReviewFeedbackController::class, 'index'])->middleware('role:owner,pm');
    Route::put('/reviews/{reviewFeedback}/follow-up', [\App\Http\Controllers\Api\ReviewFeedbackController::class, 'updateFollowUp'])->middleware('role:owner,pm');

    Route::get('/ops-reports', [\App\Http\Controllers\Api\OpsReportController::class, 'index'])->middleware('role:owner');
    Route::post('/ops-reports/generate', [\App\Http\Controllers\Api\OpsReportController::class, 'generate'])->middleware('role:owner');
    Route::get('/ops-reports/{aiOpsReport}', [\App\Http\Controllers\Api\OpsReportController::class, 'show'])->middleware('role:owner');

    Route::get('/command-center/sessions', [\App\Http\Controllers\Api\CommandCenterController::class, 'sessions'])->middleware('role:owner,pm');
    Route::post('/command-center/sessions', [\App\Http\Controllers\Api\CommandCenterController::class, 'storeSession'])->middleware('role:owner,pm');
    Route::get('/command-center/sessions/{aiCommandSession}', [\App\Http\Controllers\Api\CommandCenterController::class, 'show'])->middleware('role:owner,pm');
    Route::patch('/command-center/sessions/{aiCommandSession}', [\App\Http\Controllers\Api\CommandCenterController::class, 'rename'])->middleware('role:owner,pm');
    Route::delete('/command-center/sessions/{aiCommandSession}', [\App\Http\Controllers\Api\CommandCenterController::class, 'destroy'])->middleware('role:owner,pm');
    Route::post('/command-center/ask', [\App\Http\Controllers\Api\CommandCenterController::class, 'ask'])->middleware('role:owner,pm');
    Route::post('/command-center/confirm', [\App\Http\Controllers\Api\CommandCenterController::class, 'confirm'])->middleware('role:owner,pm');
    Route::get('/command-center/saved-queries', [\App\Http\Controllers\Api\CommandCenterController::class, 'savedQueries'])->middleware('role:owner,pm');
    Route::post('/command-center/saved-queries', [\App\Http\Controllers\Api\CommandCenterController::class, 'storeSavedQuery'])->middleware('role:owner,pm');
    Route::delete('/command-center/saved-queries/{savedQuery}', [\App\Http\Controllers\Api\CommandCenterController::class, 'destroySavedQuery'])->middleware('role:owner,pm');

    Route::post('/ai/actions/evaluate', [\App\Http\Controllers\Api\AiActionGateController::class, 'evaluate'])->middleware('role:owner,pm');
    Route::post('/ai/actions/run', [\App\Http\Controllers\Api\AiActionGateController::class, 'run'])->middleware('role:owner,pm');
    Route::post('/ai/actions/{aiActionLog}/retry', [\App\Http\Controllers\Api\AiActionGateController::class, 'retry'])->middleware('role:owner,pm');

    Route::get('/jobs/{job}/messages', [MessageController::class, 'index']);
    Route::post('/jobs/{job}/messages', [MessageController::class, 'store']);
    Route::get('/leads/{lead}/messages', [LeadMessageController::class, 'index'])->middleware('role:owner,pm,contractor');
    Route::post('/leads/{lead}/messages', [LeadMessageController::class, 'store'])->middleware('role:owner,pm,contractor');

    Route::get('/contractors', [ContractorController::class, 'index']);
    Route::get('/contractors/{id}', [ContractorController::class, 'show']);
    Route::put('/contractors/{id}', [ContractorController::class, 'update'])->middleware('role:owner,pm');
    Route::get('/contractors/{id}/documents', [ContractorDocumentController::class, 'index']);
    Route::post('/contractors/{id}/documents', [ContractorDocumentController::class, 'upload']);
    Route::put('/contractors/{id}/documents/{doc}/review', [ContractorDocumentController::class, 'review'])->middleware('role:owner,pm');
    Route::get('/compliance/pending-review', [ContractorDocumentController::class, 'pendingReview'])->middleware('role:owner,pm');

    // CT-04: Site visit workflow
    Route::get('/site-visits/{siteVisit}', [\App\Http\Controllers\Api\SiteVisitWorkflowController::class, 'show']);
    Route::post('/site-visits/{siteVisit}/accept', [\App\Http\Controllers\Api\SiteVisitWorkflowController::class, 'accept'])->middleware('role:contractor');
    Route::post('/site-visits/{siteVisit}/decline', [\App\Http\Controllers\Api\SiteVisitWorkflowController::class, 'decline'])->middleware('role:contractor');
    Route::post('/site-visits/{siteVisit}/draft', [\App\Http\Controllers\Api\SiteVisitWorkflowController::class, 'saveDraft'])->middleware('role:contractor');
    Route::post('/site-visits/{siteVisit}/submit-price', [\App\Http\Controllers\Api\SiteVisitWorkflowController::class, 'submitPrice'])->middleware('role:contractor');
    Route::post('/site-visits/{siteVisit}/request-revision', [\App\Http\Controllers\Api\SiteVisitWorkflowController::class, 'requestRevision'])->middleware('role:owner,pm');
    Route::post('/site-visits/{siteVisit}/revise', [\App\Http\Controllers\Api\SiteVisitWorkflowController::class, 'revise'])->middleware('role:contractor');
    Route::post('/site-visits/{siteVisit}/complete', [\App\Http\Controllers\Api\SiteVisitWorkflowController::class, 'markComplete'])->middleware('role:contractor');
    Route::post('/site-visits/{siteVisit}/photos', [\App\Http\Controllers\Api\SiteVisitWorkflowController::class, 'uploadPhoto'])->middleware('role:contractor');

    Route::get('/customers', [CustomerController::class, 'index'])->middleware('role:owner,pm');
    Route::get('/customers/duplicate-groups/{groupId}', [CustomerController::class, 'duplicateGroup'])->middleware('role:owner');
    Route::post('/customers/merge', [CustomerController::class, 'merge'])->middleware('role:owner');
    Route::get('/customers/{id}', [CustomerController::class, 'show'])->middleware('role:owner,pm');
    Route::put('/customers/{id}', [CustomerController::class, 'update'])->middleware('role:owner');
    Route::get('/customers/{id}/export', [CustomerController::class, 'export'])->middleware('role:owner');
    Route::delete('/customers/{id}', [CustomerController::class, 'destroy'])->middleware('role:owner');

    Route::get('/admin-pm-messages/conversations', [AdminPmMessageController::class, 'conversations'])->middleware('role:owner,pm');
    Route::get('/admin-pm-messages/with/{userId}', [AdminPmMessageController::class, 'thread'])->middleware('role:owner,pm');
    Route::post('/admin-pm-messages/with/{userId}', [AdminPmMessageController::class, 'store'])->middleware('role:owner,pm');

    Route::get('/pm-contractor-messages/conversations', [PmContractorMessageController::class, 'conversations'])->middleware('role:owner,pm,contractor');
    Route::get('/pm-contractor-messages/with/{userId}', [PmContractorMessageController::class, 'thread'])->middleware('role:owner,pm,contractor');
    Route::post('/pm-contractor-messages/with/{userId}', [PmContractorMessageController::class, 'store'])->middleware('role:owner,pm,contractor');

    Route::get('/contractor/leads', [ContractorLeadController::class, 'index'])->middleware('role:contractor');

    Route::get('/pm-meetings', [PmMeetingController::class, 'index'])->middleware('role:owner,pm');
    Route::post('/pm-meetings', [PmMeetingController::class, 'store'])->middleware('role:owner');
    Route::put('/pm-meetings/{pmMeeting}', [PmMeetingController::class, 'update'])->middleware('role:owner');
    Route::delete('/pm-meetings/{pmMeeting}', [PmMeetingController::class, 'destroy'])->middleware('role:owner');

    Route::get('/payouts', [PayoutController::class, 'index']);
    Route::get('/payouts/{payout}', [PayoutController::class, 'show']);
    Route::put('/payouts/{payout}/approve', [PayoutController::class, 'approve'])->middleware('role:owner');
    Route::put('/payouts/{payout}/hold', [PayoutController::class, 'hold'])->middleware('role:owner');
    Route::put('/payouts/{payout}/release', [PayoutController::class, 'release'])->middleware('role:owner');
    Route::put('/payouts/{payout}/retry', [PayoutController::class, 'retry'])->middleware('role:owner');
    Route::put('/payouts/{payout}/mark-paid', [PayoutController::class, 'markPaid'])->middleware('role:owner');
    Route::put('/payouts/{payout}', [PayoutController::class, 'update'])->middleware('role:owner');

    Route::get('/reports/profit-breakdown', [ProfitReportController::class, 'profitBreakdown'])->middleware('role:owner');

    Route::get('/schedule', [ScheduleController::class, 'index']);
    Route::post('/schedule/conflicts/check', [ScheduleController::class, 'checkConflict'])->middleware('role:owner,pm');

    Route::get('/settings', [SettingsController::class, 'index'])->middleware('role:owner');
    Route::post('/settings', [SettingsController::class, 'update'])->middleware('role:owner');
    Route::get('/settings/pricing-preview', [SettingsController::class, 'pricingPreview'])->middleware('role:owner');

    // A-03 — customer payment destinations (owner-only; separate from contractor payout/Connect)
    Route::get('/payment-destinations', [PaymentDestinationController::class, 'index'])->middleware('role:owner');
    Route::post('/payment-destinations', [PaymentDestinationController::class, 'store'])->middleware('role:owner');
    Route::put('/payment-destinations/{paymentDestination}', [PaymentDestinationController::class, 'update'])->middleware('role:owner');

    Route::middleware('role:owner')->group(function () {
        Route::get('/company-sources', [CompanySourceController::class, 'index']);
        Route::post('/company-sources', [CompanySourceController::class, 'store']);
        Route::post('/company-sources/test-parser', [CompanySourceController::class, 'testParser']);
        Route::get('/company-sources/{companySource}', [CompanySourceController::class, 'show']);
        Route::put('/company-sources/{companySource}', [CompanySourceController::class, 'update']);
        Route::delete('/company-sources/{companySource}', [CompanySourceController::class, 'destroy']);
        Route::get('/company-sources/{companySource}/health', [CompanySourceController::class, 'health']);
        Route::get('/company-sources/{companySource}/versions', [CompanySourceController::class, 'versions']);

        Route::get('/pricing-rules/brands', [PricingRuleController::class, 'brands']);
        Route::post('/pricing-rules/preview', [PricingRuleController::class, 'preview']);
        Route::get('/pricing-rules', [PricingRuleController::class, 'index']);
        Route::post('/pricing-rules', [PricingRuleController::class, 'store']);
        Route::get('/pricing-rules/{pricingRule}', [PricingRuleController::class, 'show']);
        Route::put('/pricing-rules/{pricingRule}', [PricingRuleController::class, 'update']);
        Route::delete('/pricing-rules/{pricingRule}', [PricingRuleController::class, 'destroy']);

        Route::get('/ai/settings', [AiSettingsController::class, 'index']);
        Route::put('/ai/settings', [AiSettingsController::class, 'update']);
        Route::get('/ai/action-logs', [AiSettingsController::class, 'actionLogs']);
        Route::get('/ai/action-logs/filters', [AiSettingsController::class, 'actionLogFilters']);
        Route::post('/ai/action-logs/test', [AiSettingsController::class, 'storeTestLog']);
        Route::get('/ai/conversation-logs', [AiSettingsController::class, 'conversationLogs']);
        Route::get('/ai/conversation-logs/{id}/content', [AiSettingsController::class, 'revealConversationContent']);

        Route::get('/oauth/gmail/status', [GmailOAuthController::class, 'status']);
        Route::get('/oauth/gmail/initiate', [GmailOAuthController::class, 'initiate']);
        Route::post('/oauth/gmail/disconnect', [GmailOAuthController::class, 'disconnect']);
        Route::post('/oauth/gmail/fetch-now', [GmailOAuthController::class, 'fetchNow']);

        Route::get('/workflow/thresholds', [WorkflowAssistController::class, 'thresholds']);
        Route::put('/workflow/thresholds', [WorkflowAssistController::class, 'updateThresholds']);
        Route::post('/workflow/thresholds/preview', [WorkflowAssistController::class, 'previewThresholds']);
        Route::put('/workflow/business-hours', [WorkflowAssistController::class, 'upsertBusinessHours']);
        Route::get('/message-templates', [MessageTemplateController::class, 'index']);
        Route::post('/message-templates', [MessageTemplateController::class, 'store']);
        Route::put('/message-templates/{messageTemplate}', [MessageTemplateController::class, 'update']);
        Route::post('/message-templates/{messageTemplate}/preview', [MessageTemplateController::class, 'preview']);
        Route::post('/message-templates/{messageTemplate}/test-send', [MessageTemplateController::class, 'testSend']);
        Route::get('/message-templates/{messageTemplate}/versions', [MessageTemplateController::class, 'versions']);
        Route::post('/message-templates/{messageTemplate}/versions/{version}/restore', [MessageTemplateController::class, 'restore']);

        Route::get('/notification-channels/health', [NotificationChannelController::class, 'health']);
        Route::post('/notification-channels/test-sms', [NotificationChannelController::class, 'testSms']);
        Route::post('/notification-channels/test-email', [NotificationChannelController::class, 'testEmail']);
    });

    Route::middleware('role:owner,pm')->group(function () {
        Route::get('/me/pm-brands', [PmBrandAssignmentController::class, 'mine']);

        Route::get('/availability/brands', [AvailabilityAdminController::class, 'brands']);
        Route::get('/availability/windows', [AvailabilityAdminController::class, 'windows']);
        Route::post('/availability/windows', [AvailabilityAdminController::class, 'storeWindow']);
        Route::put('/availability/windows/{availabilityWindow}', [AvailabilityAdminController::class, 'updateWindow']);
        Route::delete('/availability/windows/{availabilityWindow}', [AvailabilityAdminController::class, 'destroyWindow']);
        Route::get('/availability/windows/{availabilityWindow}/deactivation-preview', [AvailabilityAdminController::class, 'deactivationPreview']);
        Route::post('/availability/windows/{availabilityWindow}/resolve-deactivate', [AvailabilityAdminController::class, 'resolveAndDeactivate']);
        Route::post('/availability/windows/{availabilityWindow}/duplicate', [AvailabilityAdminController::class, 'duplicateWindow']);
        Route::get('/availability/bookings', [AvailabilityAdminController::class, 'bookings']);

        Route::post('/leads/{lead}/ai/call-prep', [WorkflowAssistController::class, 'callPrep']);
        Route::post('/leads/{lead}/ai/draft-message', [WorkflowAssistController::class, 'draftMessage']);
        Route::post('/leads/{lead}/ai/quote-prep', [WorkflowAssistController::class, 'quotePrep']);
    });

    Route::get('/sms-logs', [SmsLogController::class, 'index'])->middleware('role:owner');
    Route::post('/sms-logs/{smsLog}/retry', [SmsLogController::class, 'retry'])->middleware('role:owner');
    Route::get('/email-logs', [EmailLogController::class, 'index'])->middleware('role:owner');
    Route::post('/email-logs/{emailLog}/retry', [EmailLogController::class, 'retry'])->middleware('role:owner');
    Route::put('/users/{user}/toggle-sms', [UserController::class, 'toggleSms'])->middleware('role:owner');

    Route::middleware('role:owner')->prefix('admin')->group(function () {
        Route::get('/users', [AdminUserController::class, 'index']);
        Route::post('/users', [AdminUserController::class, 'store']);
        Route::delete('/users/{user}', [AdminUserController::class, 'destroy']);
        Route::post('/users/{user}/suspend', [AdminUserController::class, 'suspend']);
        Route::post('/users/{user}/reactivate', [AdminUserController::class, 'reactivate']);
        Route::post('/users/{user}/resend-invite', [AdminUserController::class, 'resendInvite']);
        Route::post('/users/{user}/reset-password', [AdminUserController::class, 'resetPassword']);
        Route::post('/users/{user}/developer', [AdminUserController::class, 'setDeveloper']);

        Route::get('/pm-brand-assignments', [PmBrandAssignmentController::class, 'index']);
        Route::get('/pm-brand-assignments/{user}', [PmBrandAssignmentController::class, 'show']);
        Route::put('/pm-brand-assignments/{user}', [PmBrandAssignmentController::class, 'sync']);

        Route::get('/content-editor-brand-assignments/{user}', [ContentEditorBrandAssignmentController::class, 'show']);
        Route::put('/content-editor-brand-assignments/{user}', [ContentEditorBrandAssignmentController::class, 'update']);
    });
});
