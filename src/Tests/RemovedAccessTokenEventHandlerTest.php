<?php

namespace Crm\AppleAppstoreModule\Tests;

use Crm\AppleAppstoreModule\AppleAppstoreModule;
use Crm\AppleAppstoreModule\Events\RemovedAccessTokenEventHandler;
use Crm\AppleAppstoreModule\Gateways\AppleAppstoreGateway;
use Crm\AppleAppstoreModule\Repository\AppleAppstoreOriginalTransactionsRepository;
use Crm\AppleAppstoreModule\Repository\AppleAppstoreTransactionDeviceTokensRepository;
use Crm\AppleAppstoreModule\Seeders\PaymentGatewaysSeeder;
use Crm\ApplicationModule\Tests\DatabaseTestCase;
use Crm\PaymentsModule\PaymentItem\PaymentItemContainer;
use Crm\PaymentsModule\Repository\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repository\PaymentItemMetaRepository;
use Crm\PaymentsModule\Repository\PaymentMetaRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\SubscriptionsModule\Builder\SubscriptionTypeBuilder;
use Crm\SubscriptionsModule\PaymentItem\SubscriptionTypePaymentItem;
use Crm\SubscriptionsModule\Repository\SubscriptionTypeItemsRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesRepository;
use Crm\SubscriptionsModule\Seeders\SubscriptionExtensionMethodsSeeder;
use Crm\SubscriptionsModule\Seeders\SubscriptionLengthMethodSeeder;
use Crm\UsersModule\Auth\UserManager;
use Crm\UsersModule\Events\RemovedAccessTokenEvent;
use Crm\UsersModule\Repositories\DeviceTokensRepository;
use Crm\UsersModule\Repository\AccessTokensRepository;
use Crm\UsersModule\Repository\UsersRepository;
use League\Event\Emitter;
use Nette\Database\Table\ActiveRow;

class RemovedAccessTokenEventHandlerTest extends DatabaseTestCase
{
    /** @var ActiveRow */
    private $paymentGateway;

    /** @var Emitter */
    private $emitter;

    /** @var AccessTokensRepository */
    private $accessTokensRepository;

    /** @var AppleAppstoreTransactionDeviceTokensRepository */
    private $transactionDeviceTokensRepository;

    /** @var DeviceTokensRepository */
    private $deviceTokensRepository;

    /** @var PaymentMetaRepository */
    private $paymentMetaRepository;

    /** @var AppleAppstoreOriginalTransactionsRepository */
    private $appleAppstoreOriginalTransactionsRepository;

    /** @var UserManager */
    private $userManager;

    protected function requiredRepositories(): array
    {
        return [
            UsersRepository::class,
            AccessTokensRepository::class,
            DeviceTokensRepository::class,
            AppleAppstoreTransactionDeviceTokensRepository::class,
            PaymentMetaRepository::class,
            PaymentItemMetaRepository::class,
            PaymentGatewaysRepository::class,
            AppleAppstoreOriginalTransactionsRepository::class,
            PaymentsRepository::class,
            SubscriptionTypesRepository::class,
            SubscriptionTypeItemsRepository::class,
        ];
    }

    protected function requiredSeeders(): array
    {
        return [
            PaymentGatewaysSeeder::class,
            SubscriptionExtensionMethodsSeeder::class,
            SubscriptionLengthMethodSeeder::class,
        ];
    }

    public function setUp(): void
    {
        parent::setUp();

        /** @var PaymentGatewaysRepository $paymentGatewaysRepository */
        $paymentGatewaysRepository = $this->getRepository(PaymentGatewaysRepository::class);
        $this->paymentGateway = $paymentGatewaysRepository->findByCode(AppleAppstoreGateway::GATEWAY_CODE);
        $this->accessTokensRepository = $this->inject(AccessTokensRepository::class);
        $this->userManager = $this->inject(UserManager::class);
        $this->transactionDeviceTokensRepository = $this->getRepository(AppleAppstoreTransactionDeviceTokensRepository::class);
        $this->deviceTokensRepository = $this->getRepository(DeviceTokensRepository::class);
        $this->paymentMetaRepository = $this->getRepository(PaymentMetaRepository::class);
        $this->appleAppstoreOriginalTransactionsRepository = $this->getRepository(AppleAppstoreOriginalTransactionsRepository::class);

        $this->emitter = $this->inject(Emitter::class);
        $this->emitter->addListener(
            RemovedAccessTokenEvent::class,
            $this->inject(RemovedAccessTokenEventHandler::class)
        );
    }

    protected function tearDown(): void
    {
        $this->emitter->removeListener(
            RemovedAccessTokenEvent::class,
            $this->inject(RemovedAccessTokenEventHandler::class)
        );

        parent::tearDown();
    }

    public function testRegularSignOut()
    {
        // login user
        $user1 = $this->getUser(1);
        $this->accessTokensRepository->add($user1);
        $this->accessTokensRepository->add($user1);

        // logout and verify
        $this->userManager->logoutUser($user1);
        $this->assertCount(0, $this->accessTokensRepository->allUserTokens($user1->id));
    }

    public function testSingleDeviceLinked()
    {
        // login user
        $user1 = $this->getUser(1);
        $accessToken1 = $this->accessTokensRepository->add($user1);
        $accessToken2 = $this->accessTokensRepository->add($user1);

        // pair user with device
        $deviceToken = $this->deviceTokensRepository->generate('foo');
        $this->accessTokensRepository->pairWithDeviceToken($accessToken1, $deviceToken);

        // pair transaction with device
        $originalTransactionId = '123';
        $originalTransactionRow = $this->appleAppstoreOriginalTransactionsRepository->add($originalTransactionId, 'fake');
        $this->transactionDeviceTokensRepository->add($originalTransactionRow, $deviceToken);

        $payment = $this->createPayment($user1);
        $this->paymentMetaRepository->add(
            $payment,
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            $originalTransactionId
        );

        // logout and verify:
        //   - device token should still be linked to the user with original transaction ID
        //   - device token should be used on newly created backend-only access token
        $this->userManager->logoutUser($user1);
        $userTokens = $this->accessTokensRepository->allUserTokens($user1->id)->fetchAll();
        $this->assertCount(1, $userTokens);
        $token = reset($userTokens);
        $this->assertNotEquals($token->token, $accessToken1->token);
        $this->assertNotEquals($token->token, $accessToken2->token);
    }

    public function testMultipleDevicesLinked()
    {
        // login user
        $user1 = $this->getUser(1);
        $accessToken1 = $this->accessTokensRepository->add($user1);
        $accessToken2 = $this->accessTokensRepository->add($user1);
        $accessToken3 = $this->accessTokensRepository->add($user1);

        // pair user with device
        $deviceToken1 = $this->deviceTokensRepository->generate('foo');
        $this->accessTokensRepository->pairWithDeviceToken($accessToken1, $deviceToken1);
        $deviceToken2 = $this->deviceTokensRepository->generate('bar');
        $this->accessTokensRepository->pairWithDeviceToken($accessToken2, $deviceToken2);

        // pair transaction with device
        $originalTransactionId = '123';
        $originalTransactionRow = $this->appleAppstoreOriginalTransactionsRepository->add($originalTransactionId, 'fake');
        $this->transactionDeviceTokensRepository->add($originalTransactionRow, $deviceToken1); // linked during verifyPurchase
        $this->transactionDeviceTokensRepository->add($originalTransactionRow, $deviceToken2); // linked during restorePurchase

        $payment = $this->createPayment($user1);
        $this->paymentMetaRepository->add(
            $payment,
            AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
            $originalTransactionId
        );

        // logout and verify:
        //   - device token should still be linked to the user with original transaction ID
        //   - device token should be used on newly created backend-only access token
        $this->userManager->logoutUser($user1);
        $userTokens = $this->accessTokensRepository->allUserTokens($user1->id)->fetchAll();
        $this->assertCount(2, $userTokens);
    }

    public function testMultipleTransactionsLinked()
    {
        // this is probably only theoretical scenario, but let's test; login user
        $user1 = $this->getUser(1);
        $accessToken1 = $this->accessTokensRepository->add($user1);
        $accessToken2 = $this->accessTokensRepository->add($user1);

        // pair user with device
        $deviceToken = $this->deviceTokensRepository->generate('foo');
        $this->accessTokensRepository->pairWithDeviceToken($accessToken1, $deviceToken);

        // pair transactions with device
        foreach (['123' => 'fake', '456' => 'test'] as $originalTransactionId => $receipt) {
            $originalTransactionRow = $this->appleAppstoreOriginalTransactionsRepository->add($originalTransactionId, $receipt);
            $this->transactionDeviceTokensRepository->add($originalTransactionRow, $deviceToken);
            $payment = $this->createPayment($user1);
            $this->paymentMetaRepository->add(
                $payment,
                AppleAppstoreModule::META_KEY_ORIGINAL_TRANSACTION_ID,
                $originalTransactionId
            );
        }

        // logout and verify:
        //   - device token should still be linked to the user with original transaction ID
        //   - device token should be used on newly created backend-only access token
        $this->userManager->logoutUser($user1);
        $userTokens = $this->accessTokensRepository->allUserTokens($user1->id)->fetchAll();
        $this->assertCount(1, $userTokens);
    }

    protected function createPayment($user)
    {
        $paymentItemContainer = (new PaymentItemContainer())
            ->addItems(SubscriptionTypePaymentItem::fromSubscriptionType($this->getSubscriptionType()));
        $paymentsRepository = $this->getRepository(PaymentsRepository::class);
        $payment = $paymentsRepository->add(
            $this->getSubscriptionType(),
            $this->getPaymentGateway(),
            $user,
            $paymentItemContainer
        );
        return $payment;
    }

    /** @var ActiveRow */
    private $users;

    protected function getUser($id)
    {
        if (!isset($this->users[$id])) {
            $usersRepository = $this->getRepository(UsersRepository::class);
            $this->users[$id] = $usersRepository->add('asfsaoihf@afasf.sk', 'q039uewt', '', '', '', 1);
        }
        return $this->users[$id];
    }

    protected function getPaymentGateway()
    {
        return $this->paymentGateway;
    }

    /** @var ActiveRow */
    private $subscriptionType;

    protected function getSubscriptionType()
    {
        if (!$this->subscriptionType) {
            $subscriptionTypeBuilder = $this->container->getByType(SubscriptionTypeBuilder::class);
            $this->subscriptionType = $subscriptionTypeBuilder->createNew()
                ->setName('my subscription type')
                ->setUserLabel('my subscription type')
                ->setPrice(12.2)
                ->setCode('my_subscription_type')
                ->setLength(31)
                ->setActive(true)
                ->save();
        }
        return $this->subscriptionType;
    }
}
