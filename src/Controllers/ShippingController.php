<?php

declare(strict_types=1);

/*
    "Error:0001 - API Key missing or invalid
    "Error:0002 - API URL missing or invalid
    "Error:1001 - Add at least 1 Package before submission"
    "Error:1002 - Order validation failed"
    "Error:1002 - Order validation failed, please handle inside Connect"
    "Error:1003 - Missing or invalid pickup address"
    "Error:1004 - Missing or invalid delivery address"
    "Error:9999 - Other issue, please handle inside Connect"

    "Success:1000 - Label created"
*/

namespace CargoConnect\Controllers;

use Plenty\Modules\Order\Address\Contracts\OrderAddressRepositoryContract;
use Plenty\Modules\Order\Models\Order;

use Plenty\Modules\Account\Address\Models\Address;
use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;

use Plenty\Modules\Cloud\Storage\Models\StorageObject;

use Plenty\Modules\Order\Contracts\OrderRepositoryContract;

use Plenty\Modules\Order\Shipping\Contracts\ParcelServicePresetRepositoryContract;
use Plenty\Modules\Order\Shipping\Information\Contracts\ShippingInformationRepositoryContract;
use Plenty\Modules\Order\Shipping\Package\Contracts\OrderShippingPackageRepositoryContract;
use Plenty\Modules\Order\Shipping\PackageType\Contracts\ShippingPackageTypeRepositoryContract;
use Plenty\Modules\Order\Shipping\ParcelService\Models\ParcelServicePreset;
use Plenty\Modules\Order\ShippingProfiles\Contracts\OrderShippingProfilesRepositoryContract;


use Plenty\Modules\Plugin\Storage\Contracts\StorageRepositoryContract;

use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\ConfigRepository;

/**
 * Class ShippingController
 */
class ShippingController extends Controller
{

    /**
     * @var Request
     */
    private $request;

    /**
     * @var OrderRepositoryContract $orderRepository
     */
    private $orderRepository;

    private $orderShippingProfilesRepository;

    /**
     * @var AddressRepositoryContract $addressRepository
     */
    private $addressRepository;

    private $orderAddressRepository;

    /**
     * @var OrderShippingPackageRepositoryContract $orderShippingPackage
     */
    private $orderShippingPackage;

    /**
     * @var ShippingInformationRepositoryContract
     */
    private $shippingInformationRepositoryContract;

    /**
     * @var StorageRepositoryContract $storageRepository
     */
    private $storageRepository;

    /**
     * @var ShippingPackageTypeRepositoryContract
     */
    private $shippingPackageTypeRepositoryContract;

    /**
     * @var array
     */
    private $createOrderResult = [];

    /**
     * @var ConfigRepository
     */
    private $config;

    private $plugin_revision = 52;

    /**
     * ShipmentController constructor.
     *
     * @param Request $request
     * @param OrderRepositoryContract $orderRepository
     * @param AddressRepositoryContract $addressRepositoryContract
     * @param \Plenty\Modules\Order\Address\Contracts\OrderAddressRepositoryContract $orderAddressRepositoryContract
     * @param OrderShippingPackageRepositoryContract $orderShippingPackage
     * @param StorageRepositoryContract $storageRepository
     * @param ShippingInformationRepositoryContract $shippingInformationRepositoryContract
     * @param ShippingPackageTypeRepositoryContract $shippingPackageTypeRepositoryContract
     * @param \Plenty\Modules\Order\ShippingProfiles\Contracts\OrderShippingProfilesRepositoryContract $orderShippingProfilesRepositoryContract
     * @param ConfigRepository $config
     */
    public function __construct(Request                                 $request,
                                OrderRepositoryContract                 $orderRepository,
                                AddressRepositoryContract               $addressRepositoryContract,
                                OrderAddressRepositoryContract          $orderAddressRepositoryContract,
                                OrderShippingPackageRepositoryContract  $orderShippingPackage,
                                StorageRepositoryContract               $storageRepository,
                                ShippingInformationRepositoryContract   $shippingInformationRepositoryContract,
                                ShippingPackageTypeRepositoryContract   $shippingPackageTypeRepositoryContract,
                                OrderShippingProfilesRepositoryContract $orderShippingProfilesRepositoryContract,
                                ConfigRepository                        $config
    )
    {
        $this->request = $request;
        $this->orderRepository = $orderRepository;
        $this->addressRepository = $addressRepositoryContract;
        $this->orderAddressRepository = $orderAddressRepositoryContract;
        $this->orderShippingPackage = $orderShippingPackage;
        $this->storageRepository = $storageRepository;
        $this->orderShippingProfilesRepository = $orderShippingProfilesRepositoryContract;

        $this->shippingInformationRepositoryContract = $shippingInformationRepositoryContract;
        $this->shippingPackageTypeRepositoryContract = $shippingPackageTypeRepositoryContract;

        $this->config = $config;
    }

    /**
     * Registers shipment(s)
     *
     * @param Request $request
     * @param array $orderIds
     * @return array
     */
    public function registerShipments(Request $request, array $orderIds): array
    {
        $orderIds = $this->getOrderIds($request, $orderIds);
        $orderIds = $this->getOpenOrderIds($orderIds);
        $shipmentDate = date('Y-m-d');

        foreach($orderIds as $orderId)
        {
            $order = $this->orderRepository->findOrderById($orderId);

            /** @var Address $address */
            $address = $order->deliveryAddress;

            $receiverFirstName     = $address->firstName;
            $receiverLastName      = $address->lastName;
            $receiverStreet        = $address->street;
            $receiverNo            = $address->houseNumber;
            $receiverPostalCode    = $address->postalCode;
            $receiverTown          = $address->town;
            $receiverCountry       = $address->country->name;
            $receiverCompany       = $address->companyName;

            // reads sender data from plugin config. this is going to be changed in the future to retrieve data from backend ui settings
            $senderName           = $this->config->get('CargoConnect.senderName', 'plentymarkets GmbH - Timo Zenke');
            $senderStreet         = $this->config->get('CargoConnect.senderStreet', 'Bürgermeister-Brunner-Str.');
            $senderNo             = $this->config->get('CargoConnect.senderNo', '15');
            $senderPostalCode     = $this->config->get('CargoConnect.senderPostalCode', '34117');
            $senderTown           = $this->config->get('CargoConnect.senderTown', 'Kassel');
            $senderCountryID      = $this->config->get('CargoConnect.senderCountry', '0');
            $senderCountry        = ($senderCountryID == 0 ? 'Germany' : 'Austria');
            $shippingProfileId    = $order->shippingProfileId;

            // gets order shipping packages from current order
            $packages = $this->orderShippingPackage->listOrderShippingPackages($order->id);

            $cargoOrderPackages = [];

            // iterating through packages
            foreach($packages as $package)
            {
                // determine packageType
                $packageType = $this->shippingPackageTypeRepositoryContract->findShippingPackageTypeById($package->packageId);

                // weight
                $weight = $package->weight;

                // package dimensions
                list($length, $width, $height) = $this->getPackageDimensions($packageType);

                $cargoOrderPackages[] = [
                    'length' => $length,
                    'width' => $width,
                    'height' => $height,
                    'weight' => $weight,
                    'packageType' => $packageType->name
                ];

                $response = [
                    'labelUrl' => 'https://doc.phomemo.com/Labels-Sample.pdf',
                    'shipmentNumber' => (string) rand(100000, 999999),
                    'sequenceNumber' => $package->id,
                    'status' => 'shipment successfully registered'
                ];

                $shipmentItems = $this->handleAfterRegisterShipment($response['labelUrl'], $response['shipmentNumber'], (string) $response['sequenceNumber']);

                // adds result
                $this->createOrderResult[$orderId] = $this->buildResultArray(
                    true,
                    $this->getStatusMessage($response),
                    false,
                    $shipmentItems
                );

                /*try
                {
                    // shipping service providers API should be used here
                    $response = [
                        'labelUrl' => 'https://doc.phomemo.com/Labels-Sample.pdf',
                        'shipmentNumber' => (string) rand(100000, 999999),
                        'sequenceNumber' => $package->id,
                        'status' => 'shipment successfully registered'
                    ];

                    // handles the response
                    $shipmentItems = $this->handleAfterRegisterShipment($response['labelUrl'], $response['shipmentNumber'], $package->id);

                    // adds result
                    $this->createOrderResult[$orderId] = $this->buildResultArray(
                        true,
                        $this->getStatusMessage($response),
                        false,
                        $shipmentItems
                    );

                    // saves shipping information
                    $this->saveShippingInformation($orderId, $shipmentDate, $shipmentItems);
                }
                catch(\SoapFault $soapFault)
                {
                    $this->debugger($soapFault->getMessage());
                }*/

            }
        }

        // return all results to service
        return $this->createOrderResult;
    }

    public function _post($endpoint, $params)
    {
        $api_token = $this->config->get('CargoConnect.api_token', "");
        $api_url = $this->config->get('CargoConnect.api_url', "");
        $api_url .= $endpoint;

        $json_data = json_encode($params);

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Authorization: Bearer " . $api_token,
            "Content-Type: application/json"
        ));
        curl_setopt($ch, CURLOPT_URL, $api_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

        $response = curl_exec($ch);

        $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $header = substr($response, 0, $header_size);

        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpcode !== 200) {
            return false;
        }

        $body = substr($response, $header_size);

        $err_no = curl_errno($ch);
        $err = curl_error($ch);

        curl_close($ch);

        if ($err_no) {
            echo 'Error:' . curl_error($ch);
        }

        $headers = [];

        $headerLines = explode("\r\n", $header);
        foreach ($headerLines as $line) {
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(': ', $line, 2);
                $headers[$key] = $value;
            }
        }

        return json_decode($body, TRUE);
    }

    public function _get()
    {
    }

    /**
     * Cancels registered shipment(s)
     *
     * @param Request $request
     * @param array<int> $orderIds
     * @return array
     */
    public function deleteShipments(Request $request, array $orderIds): array
    {
        $orderIds = $this->getOrderIds($request, $orderIds);

        foreach ($orderIds as $orderId)
        {
            $shippingInformation = $this->shippingInformationRepositoryContract->getShippingInformationByOrderId($orderId);

            if (isset($shippingInformation->additionalData) && is_array($shippingInformation->additionalData))
            {
                foreach ($shippingInformation->additionalData as $additionalData)
                {
                    try
                    {
                        $shipmentNumber = $additionalData['shipmentNumber'];

                        // use the shipping service provider's API here
                        $response = '';

                        $this->createOrderResult[$orderId] = $this->buildResultArray(
                            true,
                            $this->getStatusMessage($response),
                            false,
                            null);

                    }
                    catch(\SoapFault $soapFault)
                    {
                        // exception handling
                    }

                }

                // resets the shipping information of current order
                $this->shippingInformationRepositoryContract->resetShippingInformation($orderId);
            }


        }

        return $this->createOrderResult;


        /*
        $orderIds = $this->getOrderIds($request, $orderIds);

        $token = $this->config->get('CargoConnect.api_token');

        $APP_URL = 'https://staging.spedition.de/api/plentymarkets/ping';

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Authorization: Bearer " . $token,
            "Content-Type: application/json"
        ));

        $data = array(
            "myData" => "Delete Shipments",
            "order_ids" => $orderIds
        );

        $json_data = json_encode($data);

        curl_setopt($ch, CURLOPT_URL, $APP_URL);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
        curl_exec($ch);
        curl_close($ch);

        $response = [
            'status' => 'order deleted ok',
        ];

        $this->createOrderResult[123] = $this->buildResultArray(
            true,
            $this->getStatusMessage($response),
            false,
            null
        );



        // return result array
        return $this->createOrderResult;
        */
    }


    /**
     * Retrieves the label file from a given URL and saves it in S3 storage
     *
     * @param $labelUrl
     * @param $key
     * @return StorageObject
     */
    private function saveLabelToS3($labelUrl, $key): StorageObject
    {
        $ch = curl_init();

        // Set URL to download
        curl_setopt($ch, CURLOPT_URL, $labelUrl);

        // Include header in result? (0 = yes, 1 = no)
        curl_setopt($ch, CURLOPT_HEADER, 0);

        // Should cURL return or print out the data? (true = return, false = print)
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        // Timeout in seconds
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        // Download the given URL, and return output
        $output = curl_exec($ch);

        // Close the cURL resource, and free system resources
        curl_close($ch);

        return $this->storageRepository->uploadObject('CargoConnect', $key, $output);
    }

    /**
     * Returns the parcel service preset for the given Id.
     *
     * @param int $parcelServicePresetId
     * @return ParcelServicePreset
     */
    private function getParcelServicePreset(int $parcelServicePresetId): ?ParcelServicePreset
    {
        /** @var ParcelServicePresetRepositoryContract $parcelServicePresetRepository */
        $parcelServicePresetRepository = pluginApp(ParcelServicePresetRepositoryContract::class);

        $parcelServicePreset = $parcelServicePresetRepository->getPresetById($parcelServicePresetId);

        if($parcelServicePreset)
        {
            return $parcelServicePreset;
        }

        return null;

    }

    /**
     * Returns a formatted status message
     *
     * @param array $response
     * @return string
     */
    private function getStatusMessage(array $response): string
    {
        return 'Code: ' . $response['status']; // should contain error code and descriptive part
    }

    /**
     * Saves the shipping information
     *
     * @param $orderId
     * @param $shipmentDate
     * @param $shipmentItems
     */
    private function saveShippingInformation($orderId, $shipmentDate, $shipmentItems)
    {
        $transactionIds = [];

        foreach ($shipmentItems as $shipmentItem)
        {
            $transactionIds[] = $shipmentItem['shipmentNumber'];

        }

        $shipmentAt = date(\DateTime::W3C, strtotime($shipmentDate));
        $registrationAt = date(\DateTime::W3C);

        $data = [
            'orderId' => $orderId,
            'transactionId' => implode(',', $transactionIds),
            'shippingServiceProvider' => 'CargoConnect',
            'shippingStatus' => 'registered',
            'shippingCosts' => 0.00,
            'additionalData' => $shipmentItems,
            'registrationAt' => $registrationAt,
            'shipmentAt' => $shipmentAt

        ];
        $this->shippingInformationRepositoryContract->saveShippingInformation($data);
    }

    /**
     * Returns all order ids with shipping status 'open'
     *
     * @param array<int> $orderIds
     * @return array
     */
    private function getOpenOrderIds(array $orderIds): array
    {
        $openOrderIds = [];
        foreach ($orderIds as $orderId) {

            $shippingInformation = $this->shippingInformationRepositoryContract->getShippingInformationByOrderId($orderId);

            if ($shippingInformation->shippingStatus == null || $shippingInformation->shippingStatus == 'open') {
                $openOrderIds[] = $orderId;
            }
        }
        return $openOrderIds;
    }


    /**
     * Returns an array in the structure demanded by plenty service
     *
     * @param bool $success
     * @param string $statusMessage
     * @param bool $newShippingPackage
     * @param array $shipmentItems
     * @return array
     */
    private function buildResultArray(bool $success = false, string $statusMessage = '', bool $newShippingPackage = false, array $shipmentItems = []): array
    {
        return [
            'success' => $success,
            'message' => $statusMessage,
            'newPackagenumber' => $newShippingPackage,
            'packages' => $shipmentItems,
        ];
    }

    /**
     * Returns shipment array
     *
     * @param string $labelUrl
     * @param string $shipmentNumber
     * @return array
     */
    private function buildShipmentItems(string $labelUrl, string $shipmentNumber): array
    {
        return [
            'labelUrl' => $labelUrl,
            'shipmentNumber' => $shipmentNumber,
        ];
    }

    /**
     * Returns package info
     *
     * @param string $packageNumber
     * @param string $labelUrl
     * @return array
     */
    private function buildPackageInfo(string $packageNumber, string $labelUrl): array
    {
        return [
            'packageNumber' => $packageNumber,
            'label' => $labelUrl
        ];
    }

    /**
     * Returns all order ids from request object
     *
     * @param Request $request
     * @param $orderIds
     * @return array
     */
    private function getOrderIds(Request $request, $orderIds): array
    {
        if (is_numeric($orderIds))
        {
            $orderIds = array($orderIds);
        }
        else if (!is_array($orderIds))
        {
            $orderIds = $request->get('orderIds');
        }
        return $orderIds;
    }

    /**
     * Returns the package dimensions by package type
     *
     * @param $packageType
     * @return array
     */
    private function getPackageDimensions($packageType): array
    {
        if ($packageType->length > 0 && $packageType->width > 0 && $packageType->height > 0)
        {
            $length = $packageType->length;
            $width = $packageType->width;
            $height = $packageType->height;
        }
        else
        {
            $length = null;
            $width = null;
            $height = null;
        }
        return array($length, $width, $height);
    }


    /**
     * Handling of response values, fires S3 storage and updates order shipping package
     *
     * @param string $labelUrl
     * @param string $shipmentNumber
     * @param string $sequenceNumber
     * @return array
     */
    private function handleAfterRegisterShipment(string $labelUrl, string $shipmentNumber, string $sequenceNumber)
    {
        $logData = [
            'label_url' => $labelUrl,
            'shipment_number' => $shipmentNumber,
            'sequence_number' => $sequenceNumber,
            'timestamp' => now()->toDateTimeString()
        ];

        $shipmentItems = [];

        $storageObject = $this->saveLabelToS3(
            $labelUrl,
            $shipmentNumber . '.pdf'
        );

        $logData['s3_storage_key'] = $storageObject->key ?? null;

        $shipmentItems[] = $this->buildShipmentItems(
            $labelUrl,
            $shipmentNumber
        );

        $logData['shipment_items'] = $shipmentItems;

        // Update shipping package
        $packageInfo = $this->buildPackageInfo(
            $shipmentNumber,
            $storageObject->key
        );

        $this->orderShippingPackage->updateOrderShippingPackage(
            (int)$sequenceNumber,
            $packageInfo
        );

        $logData['package_info'] = $packageInfo;

        $this->debugger(json_encode($logData));

        return $shipmentItems;
    }


    private function debugger(string $message): void
    {
        $url = 'https://dead-yottabyte-31.webhook.cool';

        $data = [
            "message" => $message
        ];

        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json'
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            echo 'Error: ' . curl_error($ch);
        } else {
            echo 'Response: ' . $response;
        }

        curl_close($ch);
    }
}

/*
 $start_time_ts = microtime(true);

        $api_token = $this->config->get('CargoConnect.api_token', false);
        $api_url = $this->config->get('CargoConnect.api_url', false);

        $this->_post("/logmessage", ['message' => 'Hello world']);

        // return $this->createOrderResult[144] = $this->buildResultArray(false, "Error:0001 - Preflight failed", false, null);

		$orderIds = $this->getOrderIds($request, $orderIds);
		//$orderIds = $this->getOpenOrderIds($orderIds);
		$shipmentDate = date('Y-m-d');

		foreach($orderIds as $orderId) {
			//$order = $this->orderRepository->findOrderById($orderId);
            // $order = $this->orderRepository->findById($orderId, [
            //     'addresses',
            //     'sender',
            //     'location',
            //     'relation',
            //     'reference',
            //     'comments',
            // ], false);

            // gathering required data for registering the shipment
            $order = $this->orderRepository->findById($orderId, [
                'comments',
                'location',
                'relation',
                'reference'
            ]);

            $pickup_address = $order->warehouseSender;
            $delivery_address = $order->deliveryAddress;
            $billing_address = $order->billingAddress;
            $tags = $order->tags;
            $iw_shipping_profile_id = $order->shippingProfileId;

            //$warehouse_address1 = $this->orderAddressRepository->findAddress($orderId);

            // $receiverCountry       = $address->country->name; // or: $address->country->isoCode2
            #$shipping_information = $order->shippingInformation;
			#$shipping_information1 = $this->shippingInformationRepositoryContract->getShippingInformationByOrderId($orderId);
            #$shipping_information2 = $this->orderShippingProfilesRepository->getCombinations($orderId, true);

            $shipping_information = $this->getParcelServicePreset($iw_shipping_profile_id);

            $shipping_packages = $order->shippingPackages;

            $default_pickup_address = [
                'pickup_company' => $this->config->get('CargoConnect.pickup_company', ""),
                'pickup_department' => $this->config->get('CargoConnect.pickup_department', ""),
                'pickup_firstname' => $this->config->get('CargoConnect.pickup_firstname', ""),
                'pickup_lastname' => $this->config->get('CargoConnect.pickup_lastname', ""),
                'pickup_street' => $this->config->get('CargoConnect.pickup_street', ""),
                'pickup_city' => $this->config->get('CargoConnect.pickup_city', ""),
                'pickup_zip' => $this->config->get('CargoConnect.pickup_zip', ""),
                'pickup_country' => $this->config->get('CargoConnect.pickup_country', ""),
                'pickup_email' => $this->config->get('CargoConnect.pickup_email', ""),
                'pickup_phone' => $this->config->get('CargoConnect.pickup_phone', ""),
            ];

            $packages = $this->orderShippingPackage->listOrderShippingPackages($order->id);

            // Return an error if there is no package information. In practice, this won't happen,
            // since Plenty appears to add a package automatically when submitting an order
            if(count($packages) == 0){
                $this->createOrderResult[$orderId] = $this->buildResultArray(false, "Error:1001 - Add at least 1 Package before submission", false, null);
                continue;
            }

            $package_infos = [];
            $package_id = null;
            foreach($packages as $package){
                $packageType = $this->shippingPackageTypeRepositoryContract->findShippingPackageTypeById($package->packageId);
                $length = $packageType->length;
                $width = $packageType->width;
                $height = $packageType->height;
                $weight = $package->weight;

                $package_infos[] = [
                    'package' => $package,
                    'package_type' => $packageType,
                ];
                $package_id = $package->id;
            }

            // iterating through packages

            foreach($packages as $package)
            {
                // weight
                $weight = $package->weight;

                // determine packageType
                $packageType = $this->shippingPackageTypeRepositoryContract->findShippingPackageTypeById($package->packageId);

                // package dimensions
                list($length, $width, $height) = $this->getPackageDimensions($packageType);

                // check wether we are in test or productive mode, use different login or connection data
                $mode = $this->config->get('CargoConnect.mode', '0');

                // shipping service providers API should be used here
                $response = [
                    'labelUrl' => 'https://developers.plentymarkets.com/layout/plugins/production/plentypluginshowcase/images/landingpage/why-plugin-2.svg',
                    'shipmentNumber' => '1111112222223333',
                    'sequenceNumber' => 1,
                    'status' => 'shipment sucessfully registered'
                ];

                // handles the response
                $shipmentItems = $this->handleAfterRegisterShipment($response['labelUrl'], $response['shipmentNumber'], $package->id);

                // adds result
                $this->createOrderResult[$orderId] = $this->buildResultArray(
                    true,
                    $this->getStatusMessage($response),
                    false,
                    $shipmentItems);

                // saves shipping information
                $this->saveShippingInformation($orderId, $shipmentDate, $shipmentItems);
            }



$end_time_ts = microtime(true);
$time_diff = $end_time_ts - $start_time_ts;
$execution_time = number_format($time_diff, 2);

$params = [
    'order' => $order,
    'delivery_address' => $delivery_address,
    'billing_address' => $billing_address,
    'pickup_address' => $pickup_address,
    'default_pickup_address' => $default_pickup_address,
    'tags' => $tags,
    'packages' => $packages,
    'package_infos' => $package_infos,
    'shipping_information' => $shipping_information,
    'shipping_packages' => $shipping_packages,
    'iw_shipping_profile_id' => $iw_shipping_profile_id,
    'plugin_revision' => $this->plugin_revision,
    'execution_time' => $execution_time,
];

$res = $this->_post("/submit-order", $params);

// dies muss von $res kommen
$response = [
    'labelUrl' => 'https://backpack.ironwhale.com/label.pdf',
    'shipmentNumber' => '12345678912341',
    'sequenceNumber' => $package_id,
    'status' => 'shipment sucessfully registered'
];

//marker

$shipmentItems = $this->handleAfterRegisterShipment($response['labelUrl'], $response['shipmentNumber'], $package_id);
$this->_post("/logmessage", ['shipmentItems' => $shipmentItems]);

// adds result
$this->createOrderResult[$orderId] = $this->buildResultArray(true, $this->getStatusMessage($response), false, $shipmentItems);
//$this->createOrderResult[$orderId] = $this->buildResultArray(true, "Label erstellt", false, null);

// saves shipping information
$this->saveShippingInformation($orderId, $shipmentDate, $shipmentItems);
}

// return all results to service
return $this->createOrderResult;
//marker
// probably we need to add the labelBase64 somewhere...
 */