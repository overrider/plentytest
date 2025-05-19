<?php declare(strict_types = 1);

namespace CargoConnect\Providers;

use Plenty\Plugin\RouteServiceProvider;
use Plenty\Plugin\Routing\Router;

/**
 * Class CargoConnectRouteServiceProvider
 * @package CargoConnect\Providers
 */
class CargoConnectRouteServiceProvider extends RouteServiceProvider
{
    /**
     * @param Router $router
     */
    public function map(Router $router): void
    {
        $router->post('shipment/cargo_connect/register_shipments', [
            'middleware' => 'oauth',
            'uses'       => 'CargoConnect\Controllers\ShipmentController@registerShipments'
        ]);
  	}
}
