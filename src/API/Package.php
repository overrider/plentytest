<?php

declare(strict_types=1);

namespace CargoConnect\API;

class Package
{
    /**
     * @param string $type
     * @param float $length
     * @param float $width
     * @param float $height
     * @param float $weight
     * @param int $colli
     * @param string $content
     */
    public function __construct(
        public string $type,
        public float $length,
        public float $width,
        public float $height,
        public float $weight,
        public int $colli,
        public string $content,
    ) {}

    /**
     * @return array
     */
    public function toArray(): array
    {
        return [
            'package' => $this->type,
            'length' => $this->length,
            'width' => $this->width,
            'height' => $this->height,
            'weight' => $this->weight,
            'colli' => $this->colli,
            'contents' => $this->content
        ];
    }
}