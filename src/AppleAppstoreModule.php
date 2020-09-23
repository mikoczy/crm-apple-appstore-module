<?php

namespace Crm\AppleAppstoreModule;

use Crm\ApiModule\Api\ApiRoutersContainerInterface;
use Crm\ApiModule\Router\ApiIdentifier;
use Crm\ApiModule\Router\ApiRoute;
use Crm\AppleAppstoreModule\Api\VerifyPurchaseApiHandler;
use Crm\ApplicationModule\CrmModule;
use Crm\ApplicationModule\SeederManager;
use Crm\UsersModule\Auth\UserTokenAuthorization;
use League\Event\Emitter;

class AppleAppstoreModule extends CrmModule
{
    public const META_KEY_ORIGINAL_TRANSACTION_ID = 'apple_appstore_original_transaction_id';
    public const META_KEY_PRODUCT_ID = 'apple_appstore_product_id';
    public const META_KEY_TRANSACTION_ID = 'apple_appstore_transaction_id';
    public const META_KEY_CANCELLATION_DATE = 'apple_appstore_cancellation_date';
    public const META_KEY_CANCELLATION_REASON = 'apple_appstore_cancellation_reason';

    public const USER_SOURCE_APP = 'ios-app';

    public function registerApiCalls(ApiRoutersContainerInterface $apiRoutersContainer)
    {
        $apiRoutersContainer->attachRouter(
            new ApiRoute(
                new ApiIdentifier('1', 'apple-appstore', 'webhook'),
                \Crm\AppleAppstoreModule\Api\ServerToServerNotificationWebhookApiHandler::class,
                \Crm\ApiModule\Authorization\NoAuthorization::class
            )
        );

        $apiRoutersContainer->attachRouter(
            new ApiRoute(
                new ApiIdentifier('1', 'apple-appstore', 'verify-purchase'),
                VerifyPurchaseApiHandler::class,
                UserTokenAuthorization::class
            )
        );
    }

    public function registerSeeders(SeederManager $seederManager)
    {
        $seederManager->addSeeder($this->getInstance(\Crm\AppleAppstoreModule\Seeders\ConfigsSeeder::class));
        $seederManager->addSeeder($this->getInstance(\Crm\AppleAppstoreModule\Seeders\PaymentGatewaysSeeder::class));
    }

    public function registerEventHandlers(Emitter $emitter)
    {
        $emitter->addListener(
            \Crm\UsersModule\Events\RemovedAccessTokenEvent::class,
            $this->getInstance(\Crm\AppleAppstoreModule\Events\RemovedAccessTokenEventHandler::class)
        );
    }
}
