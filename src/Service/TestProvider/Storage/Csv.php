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
            $tmp = array_values(array_slice($providers, 0, 1));
            fputcsv($fp, array_keys($tmp[0]));
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
                'reviews_count' => $provider[5],
                'reviews_url' => $provider[6],
                'reviews_score' => $provider[7],
                'fit_to_fly_uri' => $provider[8],
                'fit_to_fly_price' => $provider[9],
                'fit_to_fly_formatted' => $provider[10],
                'day_2_and_8_uri' => $provider[11],
                'day_2_and_8_price' => $provider[12],
                'day_2_and_8_formatted' => $provider[13],
                'test_to_release_uri' => $provider[14],
                'test_to_release_price' => $provider[15],
                'test_to_release_formatted' => $provider[16],
            ];
        }
        fclose($fp);

        return $providers;
    }
}