<?php

declare(strict_types=1);

namespace CargoConnect\API;

class Address
{
    public function __construct(
        public string $forename,
        public string $surname,
        public string $street,
        public string $country,
        public string $postalCode,
        public string $city,
        public ?string $phone = null,
        public ?string $email = null,
        public ?string $company = null
    ) {}

    /**
     * @return array
     */
    public function toArray(): array
    {
        return [
            "firstname" => $this->forename,
            "lastname" => $this->surname,
            "street" => $this->street,
            "country" => $this->country,
            "zip" => $this->postalCode,
            "city" => $this->city,
            "phone" => (string) $this->phone,
            "email" => $this->email,
            "company" => $this->company
        ];
    }
}