<?php

declare(strict_types=1);

namespace App\Service\TestProvider;

use Goutte\Client;
use Symfony\Component\DomCrawler\Crawler;

class Repository
{
    #private const PROVIDERS_URL = 'https://assets.publishing.service.gov.uk/government/uploads/system/uploads/attachment_data/file/977035/covid-private-testing-providers-general-testing-080421.csv/preview';
    #private const PROVIDERS_CSS_SELECTOR = '#page > div.csv-preview > div > table > tbody > tr:not(:first-child)';
    private const PROVIDERS_URL = 'https://assets.publishing.service.gov.uk/government/uploads/system/uploads/attachment_data/file/979653/covid-private-testing-providers-general-testing-220421.csv';
    private const PROVIDERS_CSS_SELECTOR = 'tr.govuk-table__row:not(:first-child)';

    public function fetchUnique(): array
    {
        $providers = [];
        $unique = $this->makeUniqueBy($this->fetchAll(), 'website');

        foreach ($unique as $provider) {
            if (filter_var($provider['website'], FILTER_VALIDATE_URL) === false) {
                continue;
            }

            $providers[] = $provider;
        }

        return $providers;
    }

    private function fetchAll(): array
    {
        $handle = fopen(self::PROVIDERS_URL, 'r');
        // Skip headers
        fgetcsv($handle);
        fgetcsv($handle);

        $providers = [];
        while (($data = fgetcsv($handle)) !== false) {
            $providers[] = [
                'name' => $data[0],
                'region' => $data[1],
                'emails' => $data[2],
                'phone' => $data[3],
                'website' => $data[4],
                'reviews_count' => 0,
                'reviews_url' => null,
                'reviews_score' => null,
            ];
        }

        return $providers;
    }

    private function extractField(Crawler $provider, int $cell): string
    {
        return trim($provider->filter('td:nth-child(' . $cell . ')')->html());
    }

    private function makeUniqueBy(array $providers, string $field): array
    {
        $unique = [];

        foreach ($providers as $provider) {
            if ($provider[$field] && !isset($unique[$provider[$field]])) {
                $unique[$provider[$field]] = $provider;
            }
        }

        return $unique;
    }
}