<?php

declare(strict_types=1);

namespace App\Service\TestProvider;

use Goutte\Client;
use Symfony\Component\DomCrawler\Crawler;

class Repository
{
    private const PROVIDERS_URL = 'https://assets.publishing.service.gov.uk/government/uploads/system/uploads/attachment_data/file/977035/covid-private-testing-providers-general-testing-080421.csv/preview';
    private const PROVIDERS_CSS_SELECTOR = '#page > div.csv-preview > div > table > tbody > tr:not(:first-child)';

    public function fetchUnique(): array
    {
        return $this->makeUniqueBy($this->fetchAll(), 'website');
    }

    private function fetchAll(): array
    {
        $crawler = (new Client())->request('GET', self::PROVIDERS_URL);

        return $crawler->filter(self::PROVIDERS_CSS_SELECTOR)->each(function (Crawler $node) {
            return [
                'name' => $this->extractField($node, 1),
                'region' => $this->extractField($node, 2),
                'emails' => $this->extractField($node, 3),
                'phone' => $this->extractField($node, 4),
                'website' => $this->extractField($node, 5),
                'reviews_count' => 0,
                'reviews_score' => null,
            ];
        });
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