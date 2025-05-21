<?php

declare(strict_types=1);

namespace CargoConnect\Providers;

use CargoConnect\Helpers\ShippingServiceProvider;
use Plenty\Log\Services\ReferenceContainer;
use Plenty\Log\Exceptions\ReferenceTypeException;
use Plenty\Modules\Order\Shipping\ServiceProvider\Services\ShippingServiceProviderService;
use Plenty\Plugin\ServiceProvider;

final class CargoConnectServiceProvider extends ServiceProvider
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
        try {
            $referenceContainer->add(referenceTypes: [
                "CargoConnect" => "CargoConnect"
            ]);
        } catch (ReferenceTypeException $exception) {}

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