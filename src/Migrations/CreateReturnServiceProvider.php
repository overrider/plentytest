<?php

declare(strict_types=1);

namespace CargoConnect\Migrations;

use CargoConnect\Constant;
use Exception;
use Plenty\Modules\Order\Shipping\Returns\Contracts\ReturnsServiceProviderRepositoryContract;
use Plenty\Plugin\Log\Loggable;

class CreateReturnServiceProvider
{
    use Loggable;

    private ReturnsServiceProviderRepositoryContract $returnsServiceProviderRepository;

    /**
     * @param \Plenty\Modules\Order\Shipping\Returns\Contracts\ReturnsServiceProviderRepositoryContract $returnsServiceProviderRepositoryContract
     */
    public function __construct(ReturnsServiceProviderRepositoryContract $returnsServiceProviderRepositoryContract)
    {
        $this->returnsServiceProviderRepository = $returnsServiceProviderRepositoryContract;
    }

    /**
     * @return void
     */
    public function run(): void
    {
        try {
            $this->returnsServiceProviderRepository->saveReturnsServiceProvider(Constant::PLUGIN_NAME);
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