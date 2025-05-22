<?php

declare(strict_types=1);

namespace CargoConnect\Controllers;

use DateTimeInterface;
use Plenty\Modules\Cloud\Storage\Models\StorageObject;
use Plenty\Modules\Order\Contracts\OrderRepositoryContract;
use Plenty\Modules\Order\Shipping\Contracts\ParcelServicePresetRepositoryContract;
use Plenty\Modules\Order\Shipping\Information\Contracts\ShippingInformationRepositoryContract;
use Plenty\Modules\Order\Shipping\Package\Contracts\OrderShippingPackageRepositoryContract;
use Plenty\Modules\Order\Shipping\Package\Models\OrderShippingPackage;
use Plenty\Modules\Order\Shipping\ParcelService\Models\ParcelServicePreset;
use Plenty\Modules\Plugin\Storage\Contracts\StorageRepositoryContract;
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
    const PLUGIN_KEY = "CargoConnect";

    /**
     * @var array
     */
    private array $createOrderResult = [];

    /**
     * @param \Plenty\Modules\Order\Contracts\OrderRepositoryContract $orderRepository
     * @param \Plenty\Modules\Order\Shipping\Package\Contracts\OrderShippingPackageRepositoryContract $orderShippingPackage
     * @param \Plenty\Modules\Plugin\Storage\Contracts\StorageRepositoryContract $storageRepository
     * @param \Plenty\Modules\Order\Shipping\Information\Contracts\ShippingInformationRepositoryContract $shippingInformationRepositoryContract
     */
    public function __construct(
        public OrderRepositoryContract $orderRepository,
        public OrderShippingPackageRepositoryContract $orderShippingPackage,
        public StorageRepositoryContract $storageRepository,
        public ShippingInformationRepositoryContract $shippingInformationRepositoryContract,
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

        foreach ($orderIds as $orderId)
        {
            $this->report(
                identifier: __METHOD__,
                code: "GoExpress::Plenty.Order",
                references: ["orderId" => $orderId]
            );

            $order = $this->orderRepository->findOrderById(
                orderId: $orderId
            );

            $this->getLogger(identifier: __METHOD__)->addReference(
                referenceType: "orderId",
                referenceValue: $orderId
            )->debug(
                code: "CargoConnect::Plenty.Order",
                additionalInfo: ["order" => $order]
            );

            $packages = $this->orderShippingPackage->listOrderShippingPackages(
                orderId: $orderId
            );

            $shipmentItems = $this->handleAfterRegisterShipment([], $packages[0]->id);

            $this->createOrderResult[$orderId] = $this->buildResultArray(
                true,
                "",
                false,
                $shipmentItems
            );

            $this->saveShippingInformation(
                orderId: $orderId,
                shipmentDate: $shipmentDate,
                shipmentItems: $shipmentItems
            );
        }

        return $this->createOrderResult;
    }

    /**
     * Retrieve labels from S3 storage
     *
     * @param Request $request
     * @param array $orderIds
     * @internal see GoExpressServiceProvider
     * @return array
     */
    public function getLabels(Request $request, $orderIds): array
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
                    code: 'CargoConnect::Webservice.S3Storage',
                    additionalInfo: ['labelKey' => $labelKey]
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
     * @return array
     */
    private function handleAfterRegisterShipment($response, int $packageId): array
    {
        $shipmentItems = [];

        $shipmentNumber = (string) rand(
            min: 100000,
            max: 999999
        );

        $label = $this->download(
            fileUrl: "https://doc.phomemo.com/Labels-Sample.pdf"
        );

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
     * @deprecated since v0.1.2
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