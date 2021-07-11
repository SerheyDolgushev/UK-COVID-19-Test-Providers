<?php

declare(strict_types=1);

namespace App\Service\TestProvider;

use Exception;
use Goutte\Client;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\Exception\TransportException;

class PriceExtractor
{
    private const DAY2AND8_PRICES_URL = 'https://www.gov.uk/guidance/providers-of-day-2-and-day-8-coronavirus-testing-for-international-arrivals';
    private const DAY2AND8_CSS_SELECTOR = '#contents > div.gem-c-govspeak.govuk-govspeak > div > table > tbody > tr';

    private const EXCLUDE_URLS = ['localhost', '127.0.0.1', 'www.gov.uk'];
    private const TEST_TYPES = [
        'fit_to_fly' => ['fit', 'fly',],
        'day_2_and_8' => ['2', 'two', '8', 'eight', 'arrival',],
        'test_to_release' => ['release', 'covid',],
    ];
    private const PRICE_ELEMENT_CSS_SELECTORS = [
        // https://www.thehealthhub.com/
        '.price',
    ];
    private const PRICE_REG_EXP = '/(\£([\d]+(\.[\d]{2})?))/u';
    private const PRICE_LIMITS = [
        'fit_to_fly' => [50, 250],
        'day_2_and_8' => [40, 450],
        'test_to_release' => [50, 500]
    ];

    private $errorHandler;
    private $govUkPrices = ['day_2_and_8' => []];

    public function setErrorHandler(callable $errorHandler): void
    {
        $this->errorHandler = $errorHandler;
    }

    public function fetchGovUkPrices(): void
    {
        $crawler = (new Client())->request('GET', self::DAY2AND8_PRICES_URL);

        $providers =  $crawler->filter(self::DAY2AND8_CSS_SELECTOR)->each(function (Crawler $node) {
            try {
                $website = trim($node->filter('td:nth-child(1) > a')->first()->attr('href'), '/');
            } catch (Exception $e) {
                $website = null;
            }

            try {
                $price = $node->filter('td:nth-child(5)')->text();
                $price = (int) str_replace('£', '', $price);
            } catch (Exception $e) {
                $price = 0;
            }

            return ['website' => $website, 'price' => $price];
        });

        foreach ($providers as $provider) {
            if (
                null === $provider['website']
                || $provider['price'] <= 0
                || isset($this->govUkPrices['day_2_and_8'][$provider['website']])
            ) {
                continue;
            }

            $this->govUkPrices['day_2_and_8'][$provider['website']] = $provider['price'];
        }
    }

    public function fetchPrices(array $provider): array
    {
        $prices = $this->defaultPrices();

        if ($this->isSkipUri($provider['website'])) {
            return $prices;
        }

        try {
            $links = $this->fetchAllLinks($provider);
        } catch(TransportException $e) {
            if (is_callable($this->errorHandler)) {
                call_user_func($this->errorHandler, $e->getMessage());
            }

            return $prices;
        }

        foreach (self::TEST_TYPES as $type => $testTypeWords) {
            foreach ($this->getMatchedLinks($testTypeWords, $links) as ['link' => $link]) {
                $testTypePrices = $this->getPossiblePrices($link['uri'], $link['text'], $type);

                if (count($testTypePrices)) {
                    // Use the lowest price
                    $price = (int) reset($testTypePrices);

                    $prices[$type] = [
                        'uri' => $link['uri'],
                        'price' => $price,
                        'formatted' => '£' . number_format((float) $price, 2),
                    ];

                    break;
                }
            }
        }

        $providerUrl = trim($provider['website'], '/');
        if (
            isset($this->govUkPrices['day_2_and_8'][$providerUrl])
            && $prices['day_2_and_8']['price'] < $this->govUkPrices['day_2_and_8'][$providerUrl]
        ) {
            // There is no chance extracted price is lower
            $price = $this->govUkPrices['day_2_and_8'][$providerUrl];

            $prices['day_2_and_8'] = [
                'uri' => $providerUrl,
                'price' => $price,
                'formatted' => '£' . number_format((float) $price, 2),
            ];
        }

        return $prices;
    }

    public function areDefault(array $prices): bool
    {
        foreach ($prices as $price) {
            if ($price['price'] > 0) {
                return false;
            }
        }

        return true;
    }

    public function mergePricesData(array $provider, array $prices): array
    {
        foreach ($prices as $type => $priceInfo) {
            foreach ($priceInfo as $key => $value) {
                $provider[$type . '_' . $key] = $value;
            }
        }

        return $provider;
    }

    private function defaultPrices(): array
    {
        $prices = [];

        foreach (array_keys(self::TEST_TYPES) as $type ) {
            $prices[$type] = ['uri' => null, 'price' => 0, 'formatted' => ''];
        }

        return $prices;
    }

    private function isSkipUri(string $uri): bool
    {
        if (strpos($uri, 'http') !== 0) {
            return true;
        }

        foreach (self::EXCLUDE_URLS as $part) {
            if (strpos($uri, $part) !== false) {
                return true;
            }
        }

        return false;
    }

    private function fetchAllLinks(array $provider): array
    {
        $links = [];

        $crawler = (new Client())->request('GET', $provider['website']);

        foreach ($crawler->filter('a')->links() as $link) {
            if ($this->isSkipUri($link->getUri())) {
                continue;
            }

            $links[] = [
                'uri' => $link->getUri(),
                'text' => trim($link->getNode()->textContent),
                'dom_element' => $link->getNode(),
            ];
        }

        return $links;
    }

    private function getMatchedLinks(array $testTypeWords, array $links): array
    {
        $matchedLinks = [];

        foreach ($links as $link) {
            $linkTextWords = explode(' ', strtolower($link['text']));
            $linkUriWords = explode(' ', str_replace(['-', '_'], ' ', strtolower($link['uri'])));

            $matchedTextWords = array_intersect($testTypeWords, $linkTextWords);
            $matchedUriWords = array_intersect($testTypeWords, $linkUriWords);
            $matchedWords = array_unique(array_merge($matchedTextWords, $matchedUriWords));
            if (count($matchedWords)) {
                $matchedLinks[] = ['link' => $link, 'matched_words' => $matchedWords];
            }
        }

        usort($matchedLinks, static function ($a, $b) {
            return min(1, max(-1, count($b['matched_words']) - count($a['matched_words'])));
        });

        return $matchedLinks;
    }

    private function getPossiblePrices($linkUrl, $linkText, string $testType): array
    {
        // Try to extract prices from the link text
        $prices = $this->parsePrices($linkText, $testType);
        if (count($prices)) {
            return $prices;
        }

        // Try to open the link
        try {
            $crawler = (new Client())->request('GET', $linkUrl);
        } catch(TransportException $e) {
            if (is_callable($this->errorHandler)) {
                call_user_func($this->errorHandler, $e->getMessage());
            }

            return [];
        }

        // Try to extract price from "price" DOM elements
        foreach (self::PRICE_ELEMENT_CSS_SELECTORS as $priceSelector) {
            $priceElements = $crawler->filter($priceSelector);
            if ($priceElements->count()) {
                $prices = $this->parsePrices($priceElements->html(), $testType);
                if (count($prices)) {
                    return $prices;
                }
            }
        }

        // Try to extract prices from link page
        if ($crawler->count()) {
            return $this->parsePrices($crawler->html(), $testType);
        }

        return [];
    }

    private function parsePrices(string $from, string $testType): array
    {
        $from = str_replace(['£</span>', '£ '], '£', $from);

        if (preg_match_all(self::PRICE_REG_EXP, $from,$matches)) {
            $prices = $matches[2];
            sort($prices);

            $priceLimits = self::PRICE_LIMITS[$testType];
            $prices = array_filter($prices, static function($price) use ($priceLimits) {
                return $price >= $priceLimits[0] && $price <= $priceLimits[1];
            });

            return $prices;
        }

        return [];
    }
}