<?php

declare(strict_types=1);

namespace App\Service\TestProvider\Storage;

class Csv implements Storage
{
    public function getDataFile(): string
    {
        return 'var/data/provider_prices.csv';
    }

    public function store(array $providers): void
    {
        $fp = fopen($this->getDataFile(), 'w+b');

        if (count($providers)) {
            fputcsv($fp, array_keys($providers[0]));
        }

        foreach ($providers as $provider) {
            fputcsv($fp, $provider);
        }

        fclose($fp);
    }

    public function load(): array
    {
        $fp = fopen($this->getDataFile(), 'r');

        // skip headers
        fgetcsv($fp);

        $providers = [];
        while (($provider = fgetcsv($fp)) !== false) {
            $providers[] = [
                'name' => $provider[0],
                'region' => $provider[1],
                'email' => $provider[2],
                'phone' => $provider[3],
                'website' => $provider[4],
                'fit_to_fly_uri' => $provider[5],
                'day_2_and_8_uri' => $provider[7],
                'test_to_release_uri' => $provider[9],
            ];
        }
        fclose($fp);

        return $providers;
    }
}