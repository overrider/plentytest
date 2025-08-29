<?php

declare(strict_types=1);

namespace CargoConnect\Migrations;

use CargoConnect\Constant;
use Exception;
use Plenty\Modules\Order\Shipping\ServiceProvider\Contracts\ShippingServiceProviderRepositoryContract;
use Plenty\Plugin\Log\Loggable;

class CreateShippingServiceProvider
{
    use Loggable;

    private ShippingServiceProviderRepositoryContract $shippingServiceProviderRepository;

    /**
     * @param \Plenty\Modules\Order\Shipping\ServiceProvider\Contracts\ShippingServiceProviderRepositoryContract $shippingServiceProviderRepository
     */
    public function __construct(ShippingServiceProviderRepositoryContract $shippingServiceProviderRepository)
    {
        $this->shippingServiceProviderRepository = $shippingServiceProviderRepository;
    }

    /**
     * @return void
     */
    public function run(): void
    {
        try {
            $this->shippingServiceProviderRepository->saveShippingServiceProvider(
                Constant::PLUGIN_NAME,
                "CargoConnectServiceProvider"
            );
        } catch (Exception $e) {
            $this->getLogger(Constant::PLUGIN_NAME)->critical(
                "Could not migrate/create new shipping provider: " . $e->getMessage(),
                [
                    "error" => $e->getTrace()
                ]
            );
        }
    }
}