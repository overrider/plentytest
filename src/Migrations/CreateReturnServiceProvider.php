<?php

declare(strict_types=1);

namespace CargoConnect\Migrations;

use CargoConnect\Helpers\ShippingServiceProvider;
use Exception;
use Plenty\Modules\Order\Shipping\Returns\Contracts\ReturnsServiceProviderRepositoryContract;
use Plenty\Plugin\Log\Loggable;

class CreateReturnServiceProvider
{
    use Loggable;

    public function __construct(public ReturnsServiceProviderRepositoryContract $returnsServiceProviderRepositoryContract)
    {
    }

    /**
     * @return void
     */
    public function run(): void
    {
        try {
            $this->returnsServiceProviderRepositoryContract->saveReturnsServiceProvider(ShippingServiceProvider::PLUGIN_NAME);
        } catch (Exception) {
            $this->getLogger(
                identifier: ShippingServiceProvider::PLUGIN_NAME
            )->critical(code: "Could not save or update shipping service provider");
        }
    }
}