<?php

declare(strict_types=1);

namespace CargoConnect\Providers;

use CargoConnect\Helpers\ShippingServiceProvider;
use Plenty\Log\Services\ReferenceContainer;
use Plenty\Modules\Order\Shipping\ServiceProvider\Services\ShippingServiceProviderService;
use Plenty\Plugin\ServiceProvider;

class CargoConnectServiceProvider extends ServiceProvider
{
    /**
     * @return void
     */
    public function register(): void
    {
    }

    /**
     * @param \Plenty\Log\Services\ReferenceContainer $referenceContainer
     * @param \Plenty\Modules\Order\Shipping\ServiceProvider\Services\ShippingServiceProviderService $shippingServiceProviderService
     * @return void
     */
    public function boot(ReferenceContainer $referenceContainer, ShippingServiceProviderService $shippingServiceProviderService): void
    {
        $referenceContainer->add(referenceTypes: [
            "CargoConnect" => "CargoConnect"
        ]);

        $shippingServiceProviderService->registerShippingProvider(
            shippingServiceProviderCode: ShippingServiceProvider::PLUGIN_NAME,
            shippingServiceProviderNames: [
                'de' => ShippingServiceProvider::SHIPPING_SERVICE_PROVIDER_NAME,
                'en' => ShippingServiceProvider::SHIPPING_SERVICE_PROVIDER_NAME
            ],
            shippingServiceProviderClasses: [
                'CargoConnect\\Controllers\\ShippingController@registerShipments',
                'CargoConnect\\Controllers\\ShippingController@getLabels'
            ]
        );
    }
}