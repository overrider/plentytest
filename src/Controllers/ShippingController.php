<?php

declare(strict_types=1);

namespace CargoConnect\Controllers;

use Plenty\Modules\Account\Address\Contracts\AddressRepositoryContract;
use Plenty\Modules\Account\Address\Models\Address;
use Plenty\Modules\Cloud\Storage\Models\StorageObject;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Order\Shipping\Contracts\ParcelServicePresetRepositoryContract;
use Plenty\Modules\Order\Shipping\Information\Contracts\ShippingInformationRepositoryContract;
use Plenty\Modules\Order\Shipping\Package\Contracts\OrderShippingPackageRepositoryContract;
use Plenty\Modules\Order\Shipping\PackageType\Contracts\ShippingPackageTypeRepositoryContract;
use Plenty\Modules\Order\Shipping\ParcelService\Models\ParcelServicePreset;
use Plenty\Modules\Plugin\Libs\Contracts\LibraryCallContract;
use Plenty\Modules\Plugin\Storage\Contracts\StorageRepositoryContract;
use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Log\Loggable;

/**
 * Class ShippingController
 */
class ShippingController extends Controller
{
    use Loggable;

    /**
     * @var string
     */
    const PLUGIN_NAME = "CargoConnect";

    /**
     * @var array
     */
    private array $createOrderResult = [];

    /**
     * ShipmentController constructor.
     *
     * @param Request $request
     * @param OrderRepositoryContract $orderRepository
     * @param AddressRepositoryContract $addressRepositoryContract
     * @param OrderShippingPackageRepositoryContract $orderShippingPackage
     * @param StorageRepositoryContract $storageRepository
     * @param ShippingInformationRepositoryContract $shippingInformationRepositoryContract
     * @param ShippingPackageTypeRepositoryContract $shippingPackageTypeRepositoryContract
     * @param ConfigRepository $config
     * @param \Plenty\Modules\Plugin\Libs\Contracts\LibraryCallContract $libraryCall
     */
    public function __construct(
        public Request $request,
        public OrderRepositoryContract $orderRepository,
        public AddressRepositoryContract $addressRepositoryContract,
        public OrderShippingPackageRepositoryContract $orderShippingPackage,
        public StorageRepositoryContract $storageRepository,
        public ShippingInformationRepositoryContract $shippingInformationRepositoryContract,
        public ShippingPackageTypeRepositoryContract $shippingPackageTypeRepositoryContract,
        public ConfigRepository $config,
        public LibraryCallContract $libraryCall
    )
    {
        parent::__construct();

        $this->getLogger(identifier: __METHOD__)
            ->info(code: 'CargoConnect', additionalInfo: ['status' => 'CargoConnect shipment controller initialized']);
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

            // gathering required data for registering the shipment

            /** @var Address $address */
            $address = $order->deliveryAddress;

            $receiverFirstName     = $address->firstName;
            $receiverLastName      = $address->lastName;
            $receiverStreet        = $address->street;
            $receiverNo            = $address->houseNumber;
            $receiverPostalCode    = $address->postalCode;
            $receiverTown          = $address->town;
            $receiverCountry       = $address->country->name; // or: $address->country->isoCode2

            // reads sender data from plugin config. this is going to be changed in the future to retrieve data from backend ui settings
            $senderName           = $this->config->get('CargoConnect.senderName', 'plentymarkets GmbH - Timo Zenke');
            $senderStreet         = $this->config->get('CargoConnect.senderStreet', 'Bürgermeister-Brunner-Str.');
            $senderNo             = $this->config->get('CargoConnect.senderNo', '15');
            $senderPostalCode     = $this->config->get('CargoConnect.senderPostalCode', '34117');
            $senderTown           = $this->config->get('CargoConnect.senderTown', 'Kassel');
            $senderCountryID      = $this->config->get('CargoConnect.senderCountry', '0');
            $senderCountry        = ($senderCountryID == 0 ? 'Germany' : 'Austria');

            // gets order shipping packages from current order
            $packages = $this->orderShippingPackage->listOrderShippingPackages($order->id);

            // iterating through packages
            foreach($packages as $package)
            {
                // weight
                $weight = $package->weight;

                // determine packageType
                $packageType = $this->shippingPackageTypeRepositoryContract->findShippingPackageTypeById($package->packageId);

                $this->getLogger(identifier: __METHOD__)
                    ->info(code: 'package data', additionalInfo: ['package' => $packageType, 'packageId' => $package->packageId]);

                // package dimensions
                list($length, $width, $height) = $this->getPackageDimensions($packageType);

                try
                {
                    // check wether we are in test or productive mode, use different login or connection data
                    $mode = $this->config->get('CargoConnect.mode', '0');

                    // shipping service providers API should be used here
                    $response = [
                        'labelUrl' => 'https://developers.plentymarkets.com/layout/plugins/production/plentypluginshowcase/images/landingpage/why-plugin-2.svg',
                        'shipmentNumber' => (string)rand(min: 1000000, max: 9999999),
                        'sequenceNumber' => "1",
                        'status' => 'shipment successfully registered'
                    ];

                    // handles the response
                    $shipmentItems = $this->handleAfterRegisterShipment($response['labelUrl'], $response['shipmentNumber'], $response['sequenceNumber']);

                    // adds result
                    $this->createOrderResult[$orderId] = $this->buildResultArray(
                        true,
                        $this->getStatusMessage($response),
                        false,
                        $shipmentItems
                    );

                    // saves shipping information
                    $this->saveShippingInformation(
                        orderId: $orderId,
                        shipmentDate: $shipmentDate,
                        shipmentItems: $shipmentItems
                    );
                }
                catch(\SoapFault $soapFault)
                {
                    // handle exception
                }
            }
        }

        // return all results to service
        return $this->createOrderResult;
    }

    /**
     * @param \Plenty\Plugin\Http\Request $request
     * @param array $orderIds
     * @return array
     */
    public function getLabels(Request $request, array $orderIds): array
    {
        $orderIds = $this->getOrderIds($request, $orderIds);
        $labels = [];

        foreach ($orderIds as $orderId) {
            $results = $this->orderShippingPackage->listOrderShippingPackages($orderId);
            /* @var \Plenty\Modules\Order\Shipping\Package\Models\OrderShippingPackage $result */
            foreach ($results as $result) {

                $labelKey = explode(separator: '/', string: $result->labelPath)[1];

                if ($this->storageRepository->doesObjectExist(pluginName: self::PLUGIN_NAME, key: $labelKey)) {
                    $storageObject = $this->storageRepository->getObject(
                        pluginName: self::PLUGIN_NAME,
                        key: $labelKey
                    );

                    $labels[] = $storageObject->body;
                }
            }
        }

        return $labels;
    }

    /**
     * Cancels registered shipment(s)
     *
     * @param Request $request
     * @param array $orderIds
     * @return array
     */
    public function deleteShipments(Request $request, $orderIds)
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
                        $response = [];

                        $this->createOrderResult[$orderId] = $this->buildResultArray(
                            true,
                            $this->getStatusMessage($response)
                        );
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

        // return result array
        return $this->createOrderResult;
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
        $output = $this->download(fileUrl: $labelUrl);

        $this->getLogger(identifier: __METHOD__)->error(
            code: 'save to S3 data: ',
            additionalInfo: [
                'data'     => base64_encode($output),
                'key'      => $key,
                'labelUrl' => $labelUrl
            ]
        );

        return $this->storageRepository->uploadObject(self::PLUGIN_NAME, $key, $output);
    }

    /**
     * Returns the parcel service preset for the given Id.
     *
     * @param int $parcelServicePresetId
     * @return \Plenty\Modules\Order\Shipping\ParcelService\Models\ParcelServicePreset|null
     */
    private function getParcelServicePreset(int $parcelServicePresetId): ?ParcelServicePreset
    {
        /** @var ParcelServicePresetRepositoryContract $parcelServicePresetRepository */
        $parcelServicePresetRepository = pluginApp(ParcelServicePresetRepositoryContract::class);

        return $parcelServicePresetRepository->getPresetById($parcelServicePresetId);
    }

    /**
     * Returns a formatted status message
     *
     * @param array $response
     * @return string
     */
    private function getStatusMessage(array $response): string
    {
        return 'Code: '.$response['status']; // should contain error code and descriptive part
    }

    /**
     * Saves the shipping information
     *
     * @param $orderId
     * @param $shipmentDate
     * @param $shipmentItems
     */
    private function saveShippingInformation($orderId, $shipmentDate, $shipmentItems): void
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
     * @param array $orderIds
     * @return array
     */
    private function getOpenOrderIds(array $orderIds): array
    {
        $openOrderIds = [];

        foreach ($orderIds as $orderId)
        {
            $shippingInformation = $this->shippingInformationRepositoryContract->getShippingInformationByOrderId($orderId);
            if ($shippingInformation->shippingStatus == null || $shippingInformation->shippingStatus == 'open')
            {
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
    private function buildResultArray(bool $success = false, string$statusMessage = '', bool $newShippingPackage = false, array $shipmentItems = []): array
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
        return  [
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
            'label' => $labelUrl,
            'labelPath' => $labelUrl,
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
            $orderIds = $request->get(key: 'orderIds');
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
    private function handleAfterRegisterShipment(string $labelUrl, string $shipmentNumber, string $sequenceNumber): array
    {
        $shipmentItems = [];

        $key = $shipmentNumber . '.pdf';

        $storageObject = $this->saveLabelToS3(
            labelUrl: $labelUrl,
            key: $key
        );

        $objectUrl = $this->storageRepository->getObjectUrl(self::PLUGIN_NAME, $key);

        $this->getLogger(identifier: __FUNCTION__)->error(
            code: 'storage data: ',
            additionalInfo: [
                'storageObject' => $storageObject,
                'url' => $labelUrl,
                'fileUrl' => $objectUrl
            ]
        );

        $url = $objectUrl;

        $shipmentItems[] = $this->buildShipmentItems(
            labelUrl: $url,
            shipmentNumber: $shipmentNumber
        );

        $this->orderShippingPackage->updateOrderShippingPackage(
            orderShippingPackageId: (int)$sequenceNumber,
            data: $this->buildPackageInfo(packageNumber: $shipmentNumber, labelUrl: $storageObject->key)
        );

        return $shipmentItems;
    }

    /**
     * @param string $fileUrl
     * @return string
     */
    private function download(string $fileUrl): string
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $fileUrl);

        curl_setopt($ch, CURLOPT_HEADER, 0);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        $output = curl_exec($ch);

        curl_close($ch);

        return $output;
    }
}