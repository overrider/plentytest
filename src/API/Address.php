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
}