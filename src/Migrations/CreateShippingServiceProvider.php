<?php

declare(strict_types=1);

namespace CargoConnect\Migrations;

use CargoConnect\Helpers\ShippingServiceProvider;
use Exception;
use Plenty\Modules\Order\Shipping\ServiceProvider\Contracts\ShippingServiceProviderRepositoryContract;
use Plenty\Plugin\Log\Loggable;

final readonly class CreateShippingServiceProvider
{
    use Loggable;

    /**
     * @param \Plenty\Modules\Order\Shipping\ServiceProvider\Contracts\ShippingServiceProviderRepositoryContract $shippingServiceProviderRepository
     */
    public function __construct(public ShippingServiceProviderRepositoryContract $shippingServiceProviderRepository)
    {
    }

    /**
     * @return void
     */
    public function run(): void
    {
        try {
            $this->shippingServiceProviderRepository->saveShippingServiceProvider(
                pluginName: ShippingServiceProvider::PLUGIN_NAME,
                shippingServiceProviderName: ShippingServiceProvider::SHIPPING_SERVICE_PROVIDER_NAME
            );
        } catch (Exception) {
            $this->getLogger(
                identifier: ShippingServiceProvider::PLUGIN_NAME
            )->critical(code: "Could not save or update shipping service provider");
        }
    }
}