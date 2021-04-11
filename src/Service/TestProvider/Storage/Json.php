<?php

declare(strict_types=1);

namespace App\Service\TestProvider\Storage;

class Json implements Storage
{
    public function getDataFile(): string
    {
        return 'var/data/provider_prices.json';
    }

    public function store(array $providers): void
    {
        $fp = fopen($this->getDataFile(), 'w+b');
        fwrite($fp, json_encode($providers));
        fclose($fp);
    }

    public function load(): array
    {
        if (file_exists($this->getDataFile()) === false) {
            return [];
        }

        $content = file_get_contents($this->getDataFile());
        if ($content === false) {
            return [];
        }

        $providers = json_decode($content, true);
        if ($providers === null) {
            return [];
        }

        return $providers;
    }
}