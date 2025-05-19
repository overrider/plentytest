<?php declare(strict_types = 1);

namespace CargoConnect\Providers;

use Plenty\Modules\Order\Shipping\ServiceProvider\Services\ShippingServiceProviderService;
use Plenty\Plugin\ServiceProvider;

/**
 * Class CargoConnectServiceProvider
 * @package CargoConnect\Providers
 */
class CargoConnectServiceProvider extends ServiceProvider
{

	/**
	 * Register the service provider.
	 */
	public function register(): void
	{
        // add REST routes by registering a RouteServiceProvider if necessary
        // $this->getApplication()->register(CargoConnectRouteServiceProvider::class);
    }

    public function boot(ShippingServiceProviderService $shippingServiceProviderService): void
    {
        $shippingServiceProviderService->registerShippingProvider(
            'CargoConnect',
            [
                'de' => 'Cargo International Connect',
                'en' => 'Cargo International Connect'
            ],
            [
                'CargoConnect\\Controllers\\ShippingController@registerShipments',
                'CargoConnect\\Controllers\\ShippingController@deleteShipments',
            ]
        );
    }
}
