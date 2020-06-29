<?php

namespace Tests\AppleAppstoreModule;

use Crm\ApiModule\Router\ApiIdentifier;
use Crm\AppleAppstoreModule\AppleAppstoreModule;
use Crm\AppleAppstoreModule\Repository\AppleAppstoreSubscriptionTypesRepository;
use Crm\AppleAppstoreModule\Seeders\PaymentGatewaysSeeder as AppleAppstorePaymentGatewaysSeeder;
use Crm\ApplicationModule\Config\ApplicationConfig;
use Crm\ApplicationModule\Event\EventsStorage;
use Crm\ApplicationModule\Seeders\ConfigsSeeder as ApplicationConfigsSeeder;
use Crm\ApplicationModule\Tests\DatabaseTestCase;
use Crm\PaymentsModule\Events\PaymentChangeStatusEvent;
use Crm\PaymentsModule\Events\PaymentStatusChangeHandler;
use Crm\PaymentsModule\Repository\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repository\PaymentMetaRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\SubscriptionsModule\Builder\SubscriptionTypeBuilder;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypeItemsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypeNamesRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesRepository;
use Crm\SubscriptionsModule\Seeders\SubscriptionExtensionMethodsSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionLengthMethodSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionTypeNamesSeeder;
use Crm\UsersModule\Repository\UserMetaRepository;
use Crm\UsersModule\Repository\UsersRepository;
use GuzzleHttp\RequestOptions;
use League\Event\Emitter;
use Nette\Database\Table\ActiveRow;
use Nette\Http\Response;
use Nette\Utils\DateTime;

class ServerToServerNotificationWebhookApiHandlerTest extends DatabaseTestCase
{
    const SUBSCRIPTION_TYPE_CODE = "apple_appstore_test_internal_subscription_type_code";
    const APPLE_ORIGINAL_TRANSACTION_ID = "hsalF_no_snur_SOcaM";
    const APPLE_PRODUCT_ID = "apple_appstore_test_product_id";

    /** @var ApplicationConfig */
    public $applicationConfig;

    /** @var AppleAppstoreSubscriptionTypesRepository */
    protected $appleAppstoreSubscriptionTypeRepository;

    /** @var PaymentsRepository */
    protected $paymentsRepository;

    /** @var PaymentMetaRepository */
    protected $paymentMetaRepository;

    /** @var SubscriptionTypesRepository */
    protected $subscriptionTypesRepository;

    /** @var SubscriptionTypeBuilder */
    protected $subscriptionTypeBuilder;

    /** @var UsersRepository */
    protected $usersRepository;

    /** @var UserMetaRepository */
    protected $userMetaRepository;

    /** @var EventsStorage */
    protected $eventsStorage;

    /** @var Emitter */
    protected $emitter;

    protected $subscriptionType;
    protected $user;

    private $tearDown = true;

    protected function requiredRepositories(): array
    {
        return [
            AppleAppstoreSubscriptionTypesRepository::class,

            SubscriptionTypesRepository::class,
            SubscriptionTypeBuilder::class,
            SubscriptionTypeItemsRepository::class,
            SubscriptionTypeNamesRepository::class, // must be present; otherwise subscription creation fails

            PaymentGatewaysRepository::class,
            PaymentsRepository::class,
            PaymentMetaRepository::class,

            UsersRepository::class,
            UserMetaRepository::class,

            // unused repositories; needed for proper DB cleanup in tearDown()
            SubscriptionsRepository::class
        ];
    }

    public function requiredSeeders(): array
    {
        return [
            ApplicationConfigsSeeder::class,

            // extension and method seeders must be present; otherwise subscription_type creation fails
            SubscriptionExtensionMethodsSeeder::class,
            SubscriptionLengthMethodSeeder::class,
            // must be present; otherwise subscription creation fails
            SubscriptionTypeNamesSeeder::class,

            AppleAppstorePaymentGatewaysSeeder::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->applicationConfig = $this->inject(ApplicationConfig::class);

        $this->appleAppstoreSubscriptionTypeRepository = $this->getRepository(AppleAppstoreSubscriptionTypesRepository::class);

        $this->paymentsRepository = $this->getRepository(PaymentsRepository::class);
        $this->paymentMetaRepository = $this->getRepository(PaymentMetaRepository::class);

        $this->subscriptionTypesRepository = $this->getRepository(SubscriptionTypesRepository::class);
        $this->subscriptionTypeBuilder = $this->getRepository(SubscriptionTypeBuilder::class);

        $this->usersRepository = $this->getRepository(UsersRepository::class);
        $this->userMetaRepository = $this->getRepository(UserMetaRepository::class);

        // register EventStorage and Emitter; otherwise settings from whole project are used and all events handlers are fired...
        $this->eventsStorage = $this->inject(EventsStorage::class);
        $this->eventsStorage->register('payment_change_status', PaymentChangeStatusEvent::class);
        $this->emitter = $this->inject(Emitter::class);
        $this->emitter->removeAllListeners(\Crm\SubscriptionsModule\Events\NewSubscriptionEvent::class);
        $this->emitter->addListener(
            PaymentChangeStatusEvent::class,
            $this->inject(PaymentStatusChangeHandler::class)
        );
    }

    /**
     * Prepare data for initial buy and test that payment was created.
     *
     * This function will be run in multiple tests to preload first payment.
     * InitialBuy is properly tested by testInitialBuySucessful().
     */
    public function prepareInitialBuyData(): array
    {
        // prepare subscription type, map it to apple product
        $this->loadSubscriptionType();
        $this->mapAppleProductToSubscriptionType(self::APPLE_PRODUCT_ID, $this->subscriptionType);
        // prepare user & map user to original transaction ID used in tests
        $this->loadUser();
        $this->userMetaRepository->add($this->user, AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID, self::APPLE_ORIGINAL_TRANSACTION_ID);

        // must be in future because of Crm\PaywallModule\Events\SubscriptionChangeHandler check against actual date
        $originalPurchaseDate = new DateTime("2066-01-02 15:04:05");
        // purchase date is same as original purchase date for INITIAL_BUY
        $purchaseDate = (clone $originalPurchaseDate);
        $expiresDate = (clone $originalPurchaseDate)->modify("1 month");

        $requestData = [
            "notification_type" => "INITIAL_BUY", // not using AppleAppStoreModule constant to see if we match it correctly
            "unified_receipt" => (object) [
                "environment" => "Sandbox",
                "latest_receipt" => "placeholder",
                "latest_receipt_info" => (object)[
                    "expires_date_ms" => $this->covertToTimestampWithMilliseconds($expiresDate),
                    "original_purchase_date_ms" => $this->covertToTimestampWithMilliseconds($originalPurchaseDate),
                    "original_transaction_id" => self::APPLE_ORIGINAL_TRANSACTION_ID,
                    "product_id" => self::APPLE_PRODUCT_ID,
                    "purchase_date_ms" => $this->covertToTimestampWithMilliseconds($purchaseDate),
                    "quantity" => "1",
                    // transaction ID is same for INITIAL_BUY
                    "transaction_id" => self::APPLE_ORIGINAL_TRANSACTION_ID,
                ],
                "pending_renewal_info" => [],
                "status" => 0
            ]
        ];
        $requestData["unified_receipt"]->latest_receipt = base64_encode(json_encode($requestData["unified_receipt"]->latest_receipt_info));

        $apiResult = $this->callApi($requestData);
        // assert response of API
        $this->assertEquals(Response::S200_OK, $apiResult->getStatusCode());

        // load payment by original_transaction_id
        $paymentMetas = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            $requestData["unified_receipt"]->latest_receipt_info->original_transaction_id
        );
        $this->assertCount(1, $paymentMetas, "Exactly one `payment_meta` should contain expected `original_transaction_id`.");

        // return last payment
        $paymentMeta = reset($paymentMetas);
        $payment = $paymentMeta->payment;

        return ["request_data" => $requestData, "payment" => $payment];
    }

    public function testInitialBuySucessful()
    {
        list("request_data" => $initialBuyRequestData, "payment" => $payment) = $this->prepareInitialBuyData();

        $this->assertEquals($this->subscriptionType->id, $payment->subscription_type_id);
        $this->assertEquals(
            $initialBuyRequestData["unified_receipt"]->latest_receipt_info->purchase_date_ms,
            $this->covertToTimestampWithMilliseconds($payment->subscription_start_at)
        );
        $this->assertEquals(
            $initialBuyRequestData["unified_receipt"]->latest_receipt_info->expires_date_ms,
            $this->covertToTimestampWithMilliseconds($payment->subscription_end_at)
        );
        $this->assertEquals($this->user->id, $payment->user_id);

        // check additional payment metas
        $this->assertEquals(
            $initialBuyRequestData["unified_receipt"]->latest_receipt_info->original_transaction_id,
            ($this->paymentMetaRepository->findByPaymentAndKey($payment, AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID))->value
        );
        $this->assertEquals(
            $initialBuyRequestData["unified_receipt"]->latest_receipt_info->product_id,
            ($this->paymentMetaRepository->findByPaymentAndKey($payment, AppleAppstoreModule::META_KEY_PRODUCT_ID))->value
        );
    }

    public function testCancellationSuccessful()
    {
        list("request_data" => $initialBuyRequestData, "payment" => $initPayment) = $this->prepareInitialBuyData();

        $cancellationDate = (new DateTime($initPayment->subscription_start_at))->modify("+1 day");

        $requestData = [
            "notification_type" => "CANCEL", // not using AppleAppStoreModule constant to see if we match it correctly
            "unified_receipt" => (object) [
                "environment" => "Sandbox",
                "latest_receipt" => "placeholder",
                "latest_receipt_info" => (object) [
                    "cancellation_date_ms" => $this->covertToTimestampWithMilliseconds($cancellationDate),
                    "cancellation_reason" => 1,
                    "original_purchase_date_ms" => $initialBuyRequestData["unified_receipt"]->latest_receipt_info->original_purchase_date_ms,
                    "original_transaction_id" => $initialBuyRequestData["unified_receipt"]->latest_receipt_info->original_transaction_id,
                    "product_id" => $initialBuyRequestData["unified_receipt"]->latest_receipt_info->product_id,
                    "transaction_id" => $initialBuyRequestData["unified_receipt"]->latest_receipt_info->transaction_id,
                ],
                "pending_renewal_info" => [],
                "status" => 0
            ]
        ];
        $requestData["unified_receipt"]->latest_receipt = base64_encode(json_encode($requestData["unified_receipt"]->latest_receipt_info));

        $apiResult = $this->callApi($requestData);
        // assert response of API
        $this->assertEquals(Response::S200_OK, $apiResult->getStatusCode());

        // reload payment after changes
        $cancelledPayment = $this->paymentsRepository->find($initPayment->id);
        $this->assertNotFalse($cancelledPayment);
        $this->assertEquals(PaymentsRepository::STATUS_REFUND, $cancelledPayment->status); // TODO: maybe we need new state? -> PREPAID_REFUND?
        $this->assertStringContainsStringIgnoringCase($cancellationDate->format('Y-m-d H:i:s'), $cancelledPayment->note);

        // check cancellation details in payment meta
        $this->assertEquals(
            $cancellationDate->format('Y-m-d H:i:s'),
            $this->paymentMetaRepository->findByPaymentAndKey($cancelledPayment, AppleAppstoreModule::META_KEY_CANCELLATION_DATE)->value
        );
        $this->assertEquals(
            $requestData["unified_receipt"]->latest_receipt_info->cancellation_reason,
            $this->paymentMetaRepository->findByPaymentAndKey($cancelledPayment, AppleAppstoreModule::META_KEY_CANCELLATION_REASON)->value
        );
    }

    public function testDidRecover()
    {
        list("request_data" => $initialBuyRequestData, "payment" => $initPayment) = $this->prepareInitialBuyData();

        // between end time of previous subscription and "purchase date" of recovered payment will be gap
        $originalExpiresDate = new DateTime($initPayment->subscription_end_at);
        $purchaseDate = (clone $originalExpiresDate)->modify("+1 day");
        $expiresDate = (clone $purchaseDate)->modify('+1 month');

        $requestData = [
            "notification_type" => "DID_RECOVER", // not using AppleAppStoreModule constant to see if we match it correctly
            "unified_receipt" => (object) [
                "environment" => "Sandbox",
                "latest_receipt" => "placeholder",
                "latest_receipt_info" => (object) [
                    "expires_date_ms" => $this->covertToTimestampWithMilliseconds($expiresDate),
                    // original purchase date and original transaction ID will be same as initial buy payment
                    "original_purchase_date_ms" => $initialBuyRequestData["unified_receipt"]->latest_receipt_info->original_purchase_date_ms,
                    "original_transaction_id" => $initialBuyRequestData["unified_receipt"]->latest_receipt_info->original_transaction_id,
                    "product_id" => $initialBuyRequestData["unified_receipt"]->latest_receipt_info->product_id,
                    "purchase_date_ms" => $this->covertToTimestampWithMilliseconds($purchaseDate),
                    "quantity" => "1",
                    "transaction_id" => $initialBuyRequestData["unified_receipt"]->latest_receipt_info->transaction_id,
                ],
                "pending_renewal_info" => [],
                "status" => 0
            ]
        ];
        $requestData["unified_receipt"]->latest_receipt = base64_encode(json_encode($requestData["unified_receipt"]->latest_receipt_info));

        $apiResult = $this->callApi($requestData);
        // assert response of API
        $this->assertEquals(Response::S200_OK, $apiResult->getStatusCode());

        // load payments by original transaction_id
        $paymentMetas = $this->paymentMetaRepository->findAllByMeta(
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            $requestData["unified_receipt"]->latest_receipt_info->original_transaction_id
        );
        $this->assertCount(2, $paymentMetas, "Exactly two payments should have `payment_meta` with expected `original_transaction_id`.");

        // metas are ordered descending by ID; load recovered payment first
        $recoveredPayment = reset($paymentMetas)->payment;
        $initPaymentReloaded = next($paymentMetas)->payment;

        // check original payment; should be intact
        $this->assertEquals($initPayment->status, $initPaymentReloaded->status);
        $this->assertEquals($initPayment->subscription_start_at, $initPaymentReloaded->subscription_start_at);
        $this->assertEquals($initPayment->subscription_end_at, $initPaymentReloaded->subscription_end_at);
        $this->assertEquals($initPayment->subscription_type_id, $initPaymentReloaded->subscription_type_id);

        // check new recovered payment
        // user, subscription type should be same as first payment
        $this->assertEquals(PaymentsRepository::STATUS_PREPAID, $recoveredPayment->status);
        $this->assertEquals($initPayment->subscription_type_id, $recoveredPayment->subscription_type_id);
        $this->assertEquals($initPayment->user_id, $recoveredPayment->user_id);
        // dates will be set by request payload
        $this->assertEquals(
            $requestData["unified_receipt"]->latest_receipt_info->purchase_date_ms,
            $this->covertToTimestampWithMilliseconds($recoveredPayment->subscription_start_at)
        );
        $this->assertEquals(
            $requestData["unified_receipt"]->latest_receipt_info->expires_date_ms,
            $this->covertToTimestampWithMilliseconds($recoveredPayment->subscription_end_at)
        );
    }

    /* HELPER FUNCTION ************************************************ */

    private function loadSubscriptionType()
    {
        if (!$this->subscriptionType) {
            $subscriptionType = $this->subscriptionTypesRepository->findByCode(self::SUBSCRIPTION_TYPE_CODE);
            if (!$subscriptionType) {
                $subscriptionType = $this->subscriptionTypeBuilder->createNew()
                    ->setName('apple appstore test subscription month')
                    ->setUserLabel('apple appstore test subscription month')
                    ->setPrice(6.99)
                    ->setCode(self::SUBSCRIPTION_TYPE_CODE)
                    ->setLength(31)
                    ->setActive(true)
                    ->save();
            }
            $this->subscriptionType = $subscriptionType;
        }
        return $this->subscriptionType;
    }

    private function loadUser()
    {
        if (!$this->user) {
            $email = 'apple.appstore+test1@example.com';
            $user = $this->usersRepository->getByEmail($email);
            if (!$user) {
                $user = $this->usersRepository->add($email, 'MacOSrunsOnFlash', 'Apple', 'Appstore');
            }
            $this->user = $user;
        }
        return $this->user;
    }

    private function mapAppleProductToSubscriptionType(string $appleProductID, ActiveRow $subscriptionType)
    {
        $this->appleAppstoreSubscriptionTypeRepository->add($appleProductID, $subscriptionType);
    }

    private function covertToTimestampWithMilliseconds(DateTime $datetime): string
    {
        return (string) floor($datetime->format("U.u")*1000);
    }

    private function callApi(array $data): \Psr\Http\Message\ResponseInterface
    {
        $apiIdentifier = new ApiIdentifier('1', 'apple-appstore', 'webhook');
        $baseUrl = $this->applicationConfig->get('site_url') . '/api';
        $client = new \GuzzleHttp\Client();

        return $client->request('POST', $baseUrl . $apiIdentifier->getApiPath(), [ RequestOptions::JSON => $data ]);
    }
}
