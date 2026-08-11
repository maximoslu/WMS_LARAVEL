<?php

namespace Tests\Feature;

use App\Exceptions\NotificationDeliveryInProgressException;
use App\Exceptions\PermanentNotificationDeliveryException;
use App\Exceptions\TransientNotificationDeliveryException;
use App\Jobs\ProcessAccessRequestEmailJob;
use App\Jobs\ProcessBookingSubmittedNotificationsJob;
use App\Jobs\ProcessMerchandiseRequestSubmittedNotificationsJob;
use App\Jobs\SendPasswordResetEmailJob;
use App\Models\AccessRequest;
use App\Models\Booking;
use App\Models\MerchandiseRequest;
use App\Models\NotificationDelivery;
use App\Services\BrevoMailService;
use App\Services\MerchandiseRequests\MerchandiseRequestNotificationService;
use App\Services\Notifications\NotificationDeliveryService;
use App\Services\Notifications\NotificationFailureClassifier;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class NotificationDeliveryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_migration_is_reversible_and_preserves_unique_identity_constraint(): void
    {
        $migration = require database_path('migrations/2026_08_11_000002_create_notification_deliveries_table.php');

        $this->assertTrue(Schema::hasTable('notification_deliveries'));
        $migration->down();
        $this->assertFalse(Schema::hasTable('notification_deliveries'));
        $migration->up();

        $service = app(NotificationDeliveryService::class);
        $send = fn (): ?string => null;
        $service->deliver('order.submitted', 'order', 10, 'submitted', 'mail', 'same@example.test', $send);
        $service->deliver('order.submitted', 'order', 10, 'submitted', 'mail', ' SAME@example.test ', $send);

        $this->assertDatabaseCount('notification_deliveries', 1);
    }

    public function test_same_event_and_recipient_is_sent_once_but_other_recipient_or_event_is_independent(): void
    {
        $service = app(NotificationDeliveryService::class);
        $sendCount = 0;
        $send = function () use (&$sendCount): ?string {
            $sendCount++;

            return 'provider-message-'.$sendCount;
        };

        $this->assertTrue($service->deliver('order.submitted', 'order', 10, 'submitted', 'mail', 'one@example.test', $send));
        $this->assertFalse($service->deliver('order.submitted', 'order', 10, 'submitted', 'mail', 'ONE@example.test', $send));
        $this->assertTrue($service->deliver('order.submitted', 'order', 10, 'submitted', 'mail', 'two@example.test', $send));
        $this->assertTrue($service->deliver('order.submitted', 'order', 11, 'submitted', 'mail', 'one@example.test', $send));

        $this->assertSame(3, $sendCount);
        $this->assertDatabaseCount('notification_deliveries', 3);
        $this->assertSame(3, NotificationDelivery::query()->where('status', NotificationDelivery::STATUS_SENT)->count());
    }

    public function test_transient_failure_is_rethrown_and_successful_retry_uses_the_same_delivery(): void
    {
        $service = app(NotificationDeliveryService::class);
        $attempts = 0;
        $send = function () use (&$attempts): ?string {
            $attempts++;

            if ($attempts === 1) {
                throw new TransientNotificationDeliveryException('Proveedor temporalmente no disponible.');
            }

            return 'accepted-message-id';
        };

        try {
            $service->deliver('dispatch.completed', 'goods_dispatch', 20, 'completed', 'mail', 'client@example.test', $send);
            $this->fail('El primer intento transitorio no debe convertirse en exito.');
        } catch (TransientNotificationDeliveryException) {
            // Laravel debe recibir la excepcion para reintentar el job.
        }

        $failed = NotificationDelivery::query()->sole();
        $this->assertSame(NotificationDelivery::STATUS_FAILED, $failed->status);
        $this->assertSame(1, $failed->attempts);
        $this->assertNotNull($failed->failed_at);

        $this->assertTrue($service->deliver('dispatch.completed', 'goods_dispatch', 20, 'completed', 'mail', 'client@example.test', $send));

        $sent = NotificationDelivery::query()->sole();
        $this->assertSame(NotificationDelivery::STATUS_SENT, $sent->status);
        $this->assertSame(2, $sent->attempts);
        $this->assertSame('accepted-message-id', $sent->provider_message_id);
        $this->assertNull($sent->last_error);
    }

    public function test_retry_after_a_local_post_send_exception_does_not_send_again(): void
    {
        $service = app(NotificationDeliveryService::class);
        $sendCount = 0;
        $send = function () use (&$sendCount): ?string {
            $sendCount++;

            return 'accepted';
        };

        try {
            $service->deliver('receipt.document', 'goods_receipt', 30, 'document-v1', 'mail', 'client@example.test', $send);
            throw new RuntimeException('Fallo local posterior al registro sent.');
        } catch (RuntimeException) {
            // Simula que el job falla despues de que la entrega ya quedo confirmada.
        }

        $this->assertFalse($service->deliver('receipt.document', 'goods_receipt', 30, 'document-v1', 'mail', 'client@example.test', $send));
        $this->assertSame(1, $sendCount);
        $this->assertSame(NotificationDelivery::STATUS_SENT, NotificationDelivery::query()->sole()->status);
    }

    public function test_second_worker_cannot_claim_a_fresh_processing_identity(): void
    {
        $service = app(NotificationDeliveryService::class);
        $innerAttempted = false;

        $this->expectException(NotificationDeliveryInProgressException::class);

        $service->deliver(
            'booking.submitted',
            'booking',
            40,
            'submitted',
            'database',
            'user:99',
            function () use ($service, &$innerAttempted): ?string {
                $innerAttempted = true;
                $service->deliver(
                    'booking.submitted',
                    'booking',
                    40,
                    'submitted',
                    'database',
                    'user:99',
                    fn (): ?string => null,
                );

                return null;
            },
        );

        $this->assertTrue($innerAttempted);
    }

    public function test_recipient_is_only_persisted_as_a_hash(): void
    {
        $service = app(NotificationDeliveryService::class);
        $email = 'Sensitive.Person@Example.test';

        $service->deliver('order.submitted', 'order', 50, 'submitted', 'mail', $email, fn (): ?string => null);

        $delivery = NotificationDelivery::query()->sole();
        $this->assertSame(hash('sha256', strtolower($email)), $delivery->recipient_hash);
        $this->assertStringNotContainsStringIgnoringCase('sensitive.person', json_encode($delivery->getAttributes(), JSON_THROW_ON_ERROR));
    }

    public function test_persisted_error_redacts_configured_provider_secrets(): void
    {
        config([
            'services.brevo.key' => 'secret-brevo-key',
            'mail.mailers.smtp.password' => 'secret-smtp-password',
        ]);

        try {
            app(NotificationDeliveryService::class)->deliver(
                'test.failed',
                'test',
                1,
                'v1',
                'mail',
                'person@example.test',
                fn (): never => throw new RuntimeException('secret-brevo-key secret-smtp-password'),
            );
        } catch (RuntimeException) {
            // Expected delivery failure.
        }

        $error = (string) NotificationDelivery::query()->sole()->last_error;
        $this->assertStringNotContainsString('secret-brevo-key', $error);
        $this->assertStringNotContainsString('secret-smtp-password', $error);
        $this->assertStringContainsString('[redacted]', $error);
    }

    public function test_notification_jobs_expose_limited_retry_policy_and_rethrow_transient_errors(): void
    {
        $request = MerchandiseRequest::factory()->create(['status' => MerchandiseRequest::STATUS_PENDING]);
        $service = $this->mock(MerchandiseRequestNotificationService::class);
        $service->shouldReceive('deliverSubmittedNotifications')
            ->once()
            ->andThrow(new TransientNotificationDeliveryException('timeout'));
        $job = new ProcessMerchandiseRequestSubmittedNotificationsJob($request->id);

        $this->assertSame(4, $job->tries);
        $this->assertSame([30, 120, 300], $job->backoff());
        $this->assertSame(60, $job->timeout);
        $this->expectException(TransientNotificationDeliveryException::class);

        $job->handle($service);
    }

    public function test_permanent_failure_classifier_does_not_classify_transient_provider_errors(): void
    {
        $classifier = app(NotificationFailureClassifier::class);

        $this->assertTrue($classifier->isPermanent(new PermanentNotificationDeliveryException('invalid recipient')));
        $this->assertFalse($classifier->isPermanent(new TransientNotificationDeliveryException('HTTP 503')));
        $this->assertTrue(Schema::hasTable('failed_jobs'));
        $this->assertSame('database-uuids', config('queue.failed.driver'));
    }

    public function test_after_commit_job_is_not_inserted_when_the_business_transaction_rolls_back(): void
    {
        config(['queue.default' => 'database']);
        $booking = Booking::factory()->create();

        DB::beginTransaction();

        try {
            ProcessBookingSubmittedNotificationsJob::dispatch($booking->id)->afterCommit();
        } finally {
            DB::rollBack();
        }

        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('notification_deliveries', 0);
    }

    public function test_access_request_job_is_idempotent_when_dispatched_twice(): void
    {
        $this->configureBrevo();
        config(['wms.access_request_notification_email' => 'admin@example.test']);
        Http::fake(['https://api.brevo.com/*' => Http::response(['messageId' => 'access-1'], 201)]);
        $accessRequest = AccessRequest::factory()->create(['email' => 'applicant@example.test']);
        $job = new ProcessAccessRequestEmailJob($accessRequest->id, ProcessAccessRequestEmailJob::SUBMITTED);

        $job->handle(app(BrevoMailService::class), app(NotificationDeliveryService::class));
        $job->handle(app(BrevoMailService::class), app(NotificationDeliveryService::class));

        Http::assertSentCount(1);
        $this->assertDatabaseHas('notification_deliveries', [
            'type' => 'access_request.submitted',
            'status' => NotificationDelivery::STATUS_SENT,
            'attempts' => 1,
        ]);
    }

    public function test_password_reset_job_payload_is_encrypted_and_retry_is_idempotent(): void
    {
        $this->configureBrevo();
        Http::fake(['https://api.brevo.com/*' => Http::response(['messageId' => 'password-1'], 201)]);
        $job = new SendPasswordResetEmailJob(
            123,
            'person@example.test',
            'https://wms.example.test/reset-password/secret-token',
            hash('sha256', 'secret-token'),
        );

        $this->assertInstanceOf(ShouldBeEncrypted::class, $job);
        $job->handle(app(BrevoMailService::class), app(NotificationDeliveryService::class));
        $job->handle(app(BrevoMailService::class), app(NotificationDeliveryService::class));

        Http::assertSentCount(1);
        $this->assertDatabaseHas('notification_deliveries', [
            'type' => 'auth.password_reset_requested',
            'source_id' => '123',
            'status' => NotificationDelivery::STATUS_SENT,
            'attempts' => 1,
        ]);
    }

    private function configureBrevo(): void
    {
        config([
            'services.brevo.key' => 'test-brevo-key',
            'services.brevo.base_url' => 'https://api.brevo.com/v3',
            'mail.from.address' => 'system@example.test',
            'mail.from.name' => 'MAXIMO WMS',
        ]);
    }
}
