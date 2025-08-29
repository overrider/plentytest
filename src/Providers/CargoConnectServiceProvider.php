<?php

declare(strict_types=1);

namespace CargoConnect\Providers;

use CargoConnect\Constant;
use CargoConnect\Controllers\ShippingController;
use Plenty\Modules\Order\Shipping\Returns\Services\ReturnsServiceProviderService;
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
     * @param \Plenty\Modules\Order\Shipping\ServiceProvider\Services\ShippingServiceProviderService $shippingServiceProviderService
     * @param \Plenty\Modules\Order\Shipping\Returns\Services\ReturnsServiceProviderService $returnsServiceProviderService
     * @return void
     */
    public function boot(ShippingServiceProviderService $shippingServiceProviderService, ReturnsServiceProviderService $returnsServiceProviderService): void
    {
        $shippingServiceProviderService->registerShippingProvider(
            Constant::PLUGIN_NAME,
            [
                "de" => "CargoInternational Connect",
                'en' => "CargoInternational Connect"
            ],
            [
                "CargoConnect\\Controllers\\ShippingController@registerShipments",
                "CargoConnect\\Controllers\\ShippingController@deleteShipments",
                "CargoConnect\\Controllers\\ShippingController@getLabels"
            ]
        );

        $returnsServiceProviderService->registerReturnsProvider(
            "CargoInternational Connect",
            "CargoInternational Connect Retoure",
            ShippingController::class
        );
    }
}

