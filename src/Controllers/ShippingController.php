<?php

declare(strict_types=1);

namespace CargoConnect\Controllers;

use CargoConnect\API\Address;
use CargoConnect\API\Package;
use DateTimeInterface;
use Plenty\Modules\Cloud\Storage\Models\StorageObject;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Order\Shipping\Contracts\ParcelServicePresetRepositoryContract;
use Plenty\Modules\Order\Shipping\Countries\Contracts\CountryRepositoryContract;
use Plenty\Modules\Order\Shipping\Information\Contracts\ShippingInformationRepositoryContract;
use Plenty\Modules\Order\Shipping\Package\Contracts\OrderShippingPackageRepositoryContract;
use Plenty\Modules\Order\Shipping\Package\Models\OrderShippingPackage;
use Plenty\Modules\Order\Shipping\PackageType\Contracts\ShippingPackageTypeRepositoryContract;
use Plenty\Modules\Order\Shipping\ParcelService\Models\ParcelServicePreset;
use Plenty\Modules\Plugin\Storage\Contracts\StorageRepositoryContract;
use Plenty\Plugin\ConfigRepository;
use Plenty\Plugin\Controller;
use Plenty\Plugin\Http\Request;
use Plenty\Plugin\Log\Loggable;
use Plenty\Plugin\Log\Reportable;

/**
 * Class ShippingController
 */
class ShippingController extends Controller
{
    use Loggable, Reportable;

    /**
     * The plugin key
     * @var string
     */
    private const PLUGIN_KEY = "CargoConnect";

    /**
     * The type IDs for phone
     * @var int
     */
    private const PHONE_TYPE_ID = 4;

    /**
     * The type IDs for email
     * @var int
     */
    private const EMAIL_TYPE_ID = 5;

    /**
     * The type ID for excluded items
     * @var int
     */
    private const TYPE_ID_EXCLUDED = 6;

    /**
     * @var array
     */
    private array $createOrderResult = [];

    /**
     * @param \Plenty\Modules\Order\Contracts\OrderRepositoryContract $orderRepository
     * @param \Plenty\Modules\Order\Shipping\Package\Contracts\OrderShippingPackageRepositoryContract $orderShippingPackage
     * @param \Plenty\Modules\Plugin\Storage\Contracts\StorageRepositoryContract $storageRepository
     * @param \Plenty\Modules\Order\Shipping\Information\Contracts\ShippingInformationRepositoryContract $shippingInformationRepositoryContract
     * @param \Plenty\Modules\Order\Shipping\PackageType\Contracts\ShippingPackageTypeRepositoryContract $shippingPackageTypeRepositoryContract
     * @param \Plenty\Modules\Order\Shipping\Countries\Contracts\CountryRepositoryContract $countryRepository
     * @param \Plenty\Plugin\ConfigRepository $config
     */
    public function __construct(
        public OrderRepositoryContract $orderRepository,
        public OrderShippingPackageRepositoryContract $orderShippingPackage,
        public StorageRepositoryContract $storageRepository,
        public ShippingInformationRepositoryContract $shippingInformationRepositoryContract,
        public ShippingPackageTypeRepositoryContract $shippingPackageTypeRepositoryContract,
        public CountryRepositoryContract $countryRepository,
        public ConfigRepository $config
    ) {
       parent::__construct();
    }

    /**
     * @param \Plenty\Plugin\Http\Request $request
     * @param array $orderIds
     * @internal CargoConnectServiceProvider
     * @return array
     */
    public function registerShipments(Request $request, array $orderIds): array
    {
        $orderIds = $this->getOrderIds(
            request: $request,
            orderIds: $orderIds
        );

        $orderIds = $this->getOpenOrderIds(orderIds: $orderIds);

        $shipmentDate = date(format: "Y-m-d");

        /*$senderAddress = pluginApp(abstract: Address::class,parameters: [
            "forename" => $this->config->get(key: "CargoConnect.pickup_firstname"),
            "surname" => $this->config->get(key: "CargoConnect.pickup_lastname"),
            "street" => $this->config->get(key: "CargoConnect.pickup_street"),
            "country" => $this->config->get(key: "CargoConnect.pickup_country"),
            "postalCode" => $this->config->get(key: "CargoConnect.pickup_zip"),
            "city" => $this->config->get(key: "CargoConnect.pickup_city"),
            "phone" => $this->config->get(key: "CargoConnect.pickup_phone"),
            "email" => $this->config->get(key: "CargoConnect.pickup_email"),
            "company" => $this->config->get(key: "CargoConnect.pickup_company"),
        ]);*/

        foreach ($orderIds as $orderId)
        {
            $this->report(
                identifier: __METHOD__,
                code: "CargoConnect::Plenty.Order",
                references: ["orderId" => $orderId]
            );

            $order = $this->orderRepository->findOrderById(
                orderId: $orderId,
                with: [
                    "warehouseSender",
                    "warehouseSender.country",
                    "orderItems.variation.propertiesV2",
                    "shippingInformation",
                    "shippingPackages.items"
                ]
            );

            $items = [];

            $this->webhookLogger(message: json_encode(value: $order));

            foreach ($order->orderItems as $item) {
                if ($item->typeId !== self::TYPE_ID_EXCLUDED) {
                    $items[] = [
                        "number" => $item->itemVariationId,
                        "price" => $item->amounts[0]->purchasePrice ?? 0.00,
                        "quantity" => $item->quantity,
                        "name" => $item->orderItemName,
                        "variant_sku" => $item->variation->number ?? "",
                    ];
                }
            }

            $this->getLogger(identifier: __METHOD__)->addReference(
                referenceType: "orderId",
                referenceValue: $orderId
            )->debug(
                code: "CargoConnect::Plenty.Order",
                additionalInfo: ["order" => $order]
            );

            $senderName = explode(
                separator: " ",
                string: $order->warehouseSender->warehouseKeeperName
            );

            [$forename, $surname] = count($senderName) >= 2
                ? [$senderName[0], $senderName[1]]
                : [$this->config->get(key: "CargoConnect.pickup_firstname"), $this->config->get(key: "CargoConnect.pickup_lastname")];

            $senderAddress = pluginApp(abstract: Address::class,parameters: [
                "forename" => $forename,
                "surname" => $surname,
                "street" => "{$order->warehouseSender->address->address1} {$order->warehouseSender->address->address2}",
                "country" => $this->countryRepository->getCountryById(
                    countryId: $order->warehouseSender->address->countryId
                )->isoCode2,
                "postalCode" => $order->warehouseSender->address->postalCode,
                "city" => $order->warehouseSender->address->town,
                "phone" => $this->getWarehousePhone(
                    option: $order->warehouseSender->address->options
                ),
                "email" => $this->getWarehouseMail(
                    option: $order->warehouseSender->address->options
                ),
                "company" => $order->warehouseSender->name,
            ]);

            $receiverAddress = pluginApp(abstract: Address::class, parameters: [
                "forename" => $order->deliveryAddress->firstName,
                "surname" => $order->deliveryAddress->lastName,
                "street" => "{$order->deliveryAddress->street} {$order->deliveryAddress->houseNumber}",
                "country" => $order->deliveryAddress->country->isoCode2,
                "postalCode" => $order->deliveryAddress->postalCode,
                "city" => $order->deliveryAddress->town,
                "phone" => $order->deliveryAddress->phone,
                "email" => $order->deliveryAddress->email,
                "company" => $order->deliveryAddress->companyName
            ]);

            $packages = $this->orderShippingPackage->listOrderShippingPackages(
                orderId: $orderId
            );

            $connectParcels = [];

            foreach ($packages as $package) {
                // determine packageType
                $packageType = $this->shippingPackageTypeRepositoryContract->findShippingPackageTypeById(
                    shippingPackageTypeId: $package->packageId
                );

                // package dimensions
                list($length, $width, $height) = $this->getPackageDimensions(
                    packageType: $packageType
                );

                $this->webhookLogger(message: json_encode(value: $package));
                $this->webhookLogger(message: json_encode(value: $packageType));

                $connectParcels[] = pluginApp(abstract: Package::class, parameters: [
                    "type" => $packageType->name,
                    "length" => $length,
                    "width" => $width,
                    "height" => $height,
                    "weight" => $package->weight,
                    "colli" => 1,
                    "content" => "Inhalt"
                ]);
            }

            $this->webhookLogger(message: json_encode(value: $packages));
            $this->webhookLogger(message: json_encode(value: $connectParcels));

            // SUBMIT ORDER TO CARGOCONNECT AND GET RESPONSE
            $response = $this->submitCargoOrder(
                payload: [
                    "orderId" => $orderId,
                    "pickupDate" => $shipmentDate,
                    "sender" => $senderAddress->toArray(),
                    "receiver" => $receiverAddress->toArray(),
                    "shippingProfileName" => $this->getParcelServicePreset(
                        parcelServicePresetId: (int) $order->shippingProfileId
                    )->backendName,
                    "shippingProfileId" => $order->shippingProfileId,
                    "packages" => array_map(
                        callback: fn(Package $package) => $package->toArray(),
                        array: $connectParcels
                    ),
                    "items" => $items
                ]
            );

            if (isset($response["error"])) {
                $this->getLogger(identifier: __METHOD__)->error(
                    code: "CargoConnect::API.ERROR",
                    additionalInfo: ["response" => json_encode(value: $response)]
                );

                continue;
            } else {
                $this->getLogger(identifier: __METHOD__)->debug(
                    code: "CargoConnect::API.ORDER",
                    additionalInfo: ["response" => json_encode(value: $response)]
                );
            }

            $shipmentItems = [];

            if (isset($response["label"])) {
                $label = $response["label"];
                $this->getLogger(identifier: __METHOD__)->debug(
                    code: "CargoConnect::API.PDF",
                    additionalInfo: ["label" => $label]
                );

                foreach ($packages as $index => $package) {
                    $shipmentItems[] = $this->handleAfterRegisterShipment(
                        response: $response,
                        packageId: $package->id,
                        trackingIndex: $index
                    );
                }

                //$shipmentItems = $this->handleAfterRegisterShipment($response, $packages[0]->id);

                $this->createOrderResult[$orderId] = $this->buildResultArray(
                    success: true,
                    shipmentItems: $shipmentItems
                );

                $this->saveShippingInformation($orderId, $shipmentDate, $shipmentItems);
            } else {
                $this->createOrderResult[$orderId] = $this->buildResultArray(
                    statusMessage: $response["error"],
                    shipmentItems: $shipmentItems
                );
            }
        }

        return $this->createOrderResult;
    }

    /**
     * Retrieve labels from S3 storage
     *
     * @param Request $request
     * @param mixed $orderIds
     * @internal see CargoConnectServiceProvider
     * @return array
     */
    public function getLabels(Request $request, mixed $orderIds): array
    {
        $orderIds = $this->getOrderIds(
            request: $request,
            orderIds: $orderIds
        );

        $labels = [];

        foreach ($orderIds as $orderId) {
            $results = $this->orderShippingPackage->listOrderShippingPackages(
                orderId: $orderId
            );

            /** @var OrderShippingPackage $result */
            foreach ($results as $result) {
                if (!strlen(string: $result->labelPath)) {
                    continue;
                }
                $labelKey = explode(
                    separator: "/",
                    string: $result->labelPath
                )[1];

                $this->getLogger(identifier: __METHOD__)->debug(
                    code: "CargoConnect::Webservice.S3Storage",
                    additionalInfo: ["labelKey" => $labelKey]
                );

                if ($this->storageRepository->doesObjectExist(pluginName: self::PLUGIN_KEY, key: $labelKey)) {
                    $storageObject = $this->storageRepository->getObject(
                        pluginName: self::PLUGIN_KEY,
                        key: $labelKey
                    );
                    $labels[] = $storageObject->body;
                }
            }
        }
        return $labels;
    }

    /**
     * Handling of response values, fires S3 storage and updates order shipping package
     *
     * @param $response
     * @param integer $packageId
     * @param integer $trackingIndex
     * @return array
     */
    private function handleAfterRegisterShipment($response, int $packageId, int $trackingIndex): array
    {
        $shipmentItems = [];

        $shipmentNumber = $response["tracking"][$trackingIndex];

        $label = base64_decode(string: $response["label"]);

        $retrievePage = $this->retrieveLabelPage(
            label: $label,
            page: $trackingIndex
        );

        $this->webhookLogger(message: json_encode(value: $retrievePage));

        if (isset($retrievePage["label"])) {
            $label = base64_decode(string: $retrievePage["label"]);
        }

        $this->getLogger(
            identifier: __METHOD__
        )->debug(
            code: "CargoConnect::Webservice.S3Storage",
            additionalInfo: [
                "length" => strlen(
                    string: $label
                )
            ]
        );

        $storageObject = $this->saveLabelToS3(
            label: $label,
            key: $packageId . ".pdf"
        );

        $this->getLogger(
            identifier: __METHOD__
        )->debug(
            code: "CargoConnect::Webservice.S3Storage",
            additionalInfo: [
                "storageObject" => json_encode(
                    value: $storageObject
                )
            ]
        );

        $shipmentItems[] = $this->buildShipmentItems(
            labelUrl:  "https://doc.phomemo.com/Labels-Sample.pdf",
            shipmentNumber: $shipmentNumber
        );

        $this->orderShippingPackage->updateOrderShippingPackage(
            orderShippingPackageId: $packageId,
            data: $this->buildPackageInfo(
                packageNumber: $shipmentNumber,
                labelUrl: $storageObject->key
            )
        );

        return $shipmentItems;
    }

    /**
     * Retrieves the label file from PDFs response and saves it in S3 storage
     *
     * @param string $label
     * @param string $key
     * @return StorageObject
     */
    private function saveLabelToS3(string $label, string $key): StorageObject
    {
        return $this->storageRepository->uploadObject(
            pluginName: self::PLUGIN_KEY,
            key: $key,
            body: $label
        );
    }

    /**
     * Returns the parcel service preset for the given Id.
     *
     * @param int $parcelServicePresetId
     * @return ParcelServicePreset
     */
    private function getParcelServicePreset(int $parcelServicePresetId): ParcelServicePreset
    {
        /** @var ParcelServicePresetRepositoryContract $parcelServicePresetRepository */
        $parcelServicePresetRepository = pluginApp(abstract: ParcelServicePresetRepositoryContract::class);

        return $parcelServicePresetRepository->getPresetById(
            presetId: $parcelServicePresetId
        );
    }

    /**
     * @param int $orderId
     * @param string $shipmentDate
     * @param array $shipmentItems
     * @return void
     */
    private function saveShippingInformation(int $orderId, string $shipmentDate, array $shipmentItems): void
    {
        $transactionIds = [];

        foreach ($shipmentItems as $shipmentItem) {
            $transactionIds[] = $shipmentItem["shipmentNumber"];
        }

        $shipmentAt = date(
            format: DateTimeInterface::W3C,
            timestamp: strtotime(
                datetime: $shipmentDate
            )
        );

        $registrationAt = date(
            format: DateTimeInterface::W3C
        );

        $data = [
            "orderId" => $orderId,
            "transactionId" => implode(
                separator: ",",
                array: $transactionIds
            ),
            "shippingServiceProvider" => self::PLUGIN_KEY,
            "shippingStatus" => "registered",
            "shippingCosts" => 0.00,
            "additionalData" => $shipmentItems,
            "registrationAt" => $registrationAt,
            "shipmentAt" => $shipmentAt
        ];

        $this->shippingInformationRepositoryContract->saveShippingInformation(
            data: $data
        );
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
            $shippingInformation = $this->shippingInformationRepositoryContract->getShippingInformationByOrderId(
                orderId: $orderId
            );

            if ($shippingInformation->shippingStatus == null || $shippingInformation->shippingStatus == "open") {
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
    private function buildResultArray(bool $success = false, string $statusMessage = "", bool $newShippingPackage = false, array $shipmentItems = []): array
    {
        return [
            "success" => $success,
            "message" => $statusMessage,
            "newPackagenumber" => $newShippingPackage,
            "packages" => $shipmentItems,
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
            "labelUrl" => $labelUrl,
            "shipmentNumber" => $shipmentNumber,
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
            "packageNumber" => $packageNumber,
            "label" => $labelUrl
        ];
    }

    /**
     * Returns all order ids from request object
     *
     * @param Request $request
     * @param mixed $orderIds
     * @return array
     */
    private function getOrderIds(Request $request, mixed $orderIds): array
    {
        if (is_numeric(value: $orderIds)) {
            $orderIds = [$orderIds];
        } else if (!is_array(value: $orderIds)) {
            $orderIds = $request->get(key: "orderIds");
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
        if ($packageType->length > 0 && $packageType->width > 0 && $packageType->height > 0) {
            $length = $packageType->length;
            $width = $packageType->width;
            $height = $packageType->height;
        } else {
            $length = null;
            $width = null;
            $height = null;
        }
        return [$length, $width, $height];
    }

    /**
     * @param array $payload
     * @return array
     */
    private function submitCargoOrder(array $payload): array
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
/*        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Authorization: Bearer " . $this->config->get(key: "CargoConnect.api_token"),
            "Content-Type: application/json"
        ));*/
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Authorization: Bearer " . "1d610778-3d82-4dcc-a69f-876e67e57126|u1PXi6RKqNPWjSta8HAerc7cgNeHYDHEvZYxIBDz9da98a55",
            "Content-Type: application/json"
        ));
       /* curl_setopt($ch, CURLOPT_URL, $this->config->get(key: "CargoConnect.api_url")); */
        curl_setopt($ch, CURLOPT_URL, "https://staging.spedition.de/api/plentyconnect/submit-order");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(value: $payload));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

        $response = curl_exec($ch);

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $body = substr($response, $headerSize);

        curl_close($ch);

        $this->getLogger(
            identifier: __METHOD__
        )->debug(
            code: "CargoConnect::Webservice.Order.Response",
            additionalInfo: [
                "orderResponse" => json_encode(
                    value: $body
                )
            ]
        );

        return json_decode(
            json: $body,
            associative: true
        );
    }

    /**
     * @param string $label
     * @param int $page
     * @return array
     */
    private function retrieveLabelPage(string $label, int $page): array
    {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        /*        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                    "Authorization: Bearer " . $this->config->get(key: "CargoConnect.api_token"),
                    "Content-Type: application/json"
                ));*/
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            "Authorization: Bearer " . "1d610778-3d82-4dcc-a69f-876e67e57126|u1PXi6RKqNPWjSta8HAerc7cgNeHYDHEvZYxIBDz9da98a55",
            "Content-Type: application/json"
        ));
        /* curl_setopt($ch, CURLOPT_URL, $this->config->get(key: "CargoConnect.api_url")); */
        curl_setopt($ch, CURLOPT_URL, "https://staging.spedition.de/api/plentyconnect/retrieve-label-page");
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(value: [
            "base64" => $label,
            "page" => $page
        ]));
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);

        $response = curl_exec($ch);

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $body = substr($response, $headerSize);

        curl_close($ch);

        $this->getLogger(
            identifier: __METHOD__
        )->debug(
            code: "CargoConnect::Webservice.Order.Response.Label",
            additionalInfo: [
                "orderResponse" => json_encode(
                    value: $body
                )
            ]
        );

        return json_decode(
            json: $body,
            associative: true
        );
    }

    /**
     * @param array $option
     * @return string
     */
    private function getWarehousePhone(array $option): string
    {
        $phone = array_reduce(array: $option, callback: function ($carry, $item) {
            return $carry ?? (($item['typeId'] ?? null) === self::PHONE_TYPE_ID ? $item['value'] ?? null : null);
        });

        return $phone ?? $this->config->get(
            key: "CargoConnect.pickup_phone"
        );
    }

    /**
     * @param array $option
     * @return string
     */
    private function getWarehouseMail(array $option): string
    {
        $email = array_reduce(array: $option, callback: function ($carry, $item) {
            return $carry ?? (($item['typeId'] ?? null) === self::EMAIL_TYPE_ID ? $item['value'] ?? null : null);
        });

        return $email ?? $this->config->get(
            key: "CargoConnect.pickup_email"
        );
    }

    private function webhookLogger(string $message): void
    {
        $url = 'https://aloof-disease-59.webhook.cool';

        $payload = $message;

        $ch = curl_init($url);

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload),
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);

        curl_exec($ch);

        curl_close($ch);
    }
}