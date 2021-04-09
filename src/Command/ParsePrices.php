<?php

declare(strict_types=1);

namespace App\Command;

use Goutte\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpClient\Exception\TransportException;

class ParsePrices extends Command
{
    private const PROVIDERS_URL = 'https://assets.publishing.service.gov.uk/government/uploads/system/uploads/attachment_data/file/977035/covid-private-testing-providers-general-testing-080421.csv/preview';
    private const PROVIDERS_CSS_SELECTOR = '#page > div.csv-preview > div > table > tbody > tr:not(:first-child)';
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
        'day_2_and_8' => [151, 450],
        'test_to_release' => [75, 500]
    ];
    private const DATA_FILE_CSV = 'var/data/provider_prices.csv';
    private const DATA_FILE_JSON = 'var/data/provider_prices.json';

    private LoggerInterface $logger;
    private ConsoleSectionOutput $sectionErrors;

    public function __construct(LoggerInterface $logger)
    {
        parent::__construct('uk-covid-test-providers:parse-prices');

        $this->logger = $logger;
    }

    protected function configure(): void
    {
        $this->setDescription('Parses the list of providers and tries to extract the prices for each testing type');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        if (!is_writable(dirname(self::DATA_FILE_CSV))) {
            $io->error('Data file "' . self::DATA_FILE_CSV . '" is not writable');

            return 1;
        }

        $providers = $this->getProviders();

        $sectionProgress = $output->section();
        $this->sectionErrors = $output->section();

        $progressBar = new ProgressBar($sectionProgress, max(1, count($providers)));
        $progressBar->setFormat(PHP_EOL . 'Provider: %provider%' . PHP_EOL . 'Website: %website%'. PHP_EOL . PHP_EOL . '%current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%' . PHP_EOL);
        $progressBar->setMessage('', 'provider');
        $progressBar->setMessage('', 'website');
        $progressBar->start();

        $providersWithoutPricesCount = 0;
        $data = [];
        foreach ($providers as $provider) {
            $progressBar->setMessage($provider['name'], 'provider');
            $progressBar->setMessage($provider['website'], 'website');

            $prices = $this->getProviderPrices($provider);
            if (!$this->hasAnyPrice($prices)) {
                $providersWithoutPricesCount++;
            }

            $data[] = $this->mergePriceInfo($provider, $prices);

            $progressBar->advance();
        }
        $progressBar->finish();

        $this->storePrices($data);
        $this->convertPricesToJson();

        $io->newLine();
        if ($providersWithoutPricesCount) {
            $io->caution('Unable to parse any prices for ' . $providersWithoutPricesCount . ' providers');
        }
        $io->success([
            'Prices parsed for ' . (count($data) - $providersWithoutPricesCount) . ' providers',
            'And they are saved into ' . self::DATA_FILE_CSV,
        ]);

        return 0;
    }

    private function getProviders(): array
    {
        $crawler = $this->getCrawler(self::PROVIDERS_URL);

        $allProviders = $crawler->filter(self::PROVIDERS_CSS_SELECTOR)->each(function (Crawler $node) {
            return [
                'name' => $this->getProviderField($node, 1),
                'region' => $this->getProviderField($node, 2),
                'emails' => $this->getProviderField($node, 3),
                'phone' => $this->getProviderField($node, 4),
                'website' => $this->getProviderField($node, 5),
            ];
        });

        $uniqueByWebsite = [];
        foreach ($allProviders as $provider) {
            if ($provider['website'] && !isset($uniqueByWebsite[$provider['website']])) {
                $uniqueByWebsite[$provider['website']] = $provider;
            }
        }

        return $uniqueByWebsite;
    }

    private function getCrawler(string $url): Crawler
    {
        $client = new Client();

        return $client->request('GET', $url);
    }

    private function getProviderField(Crawler $provider, int $cell): string
    {
        return trim($provider->filter('td:nth-child(' . $cell . ')')->html());
    }

    private function getProviderPrices(array $provider): array
    {
        $prices = [];
        foreach (array_keys(self::TEST_TYPES) as $type ) {
            $prices[$type] = ['uri' => null, 'price' => 0];
        }

        if ($this->doSkipUri($provider['website'])) {
            return $prices;
        }

        try {
            $crawler = $this->getCrawler($provider['website']);
        } catch(TransportException $e) {
            $this->error($e->getMessage());

            return $prices;
        }

        $links = [];
        foreach ($crawler->filter('a')->links() as $link) {
            if ($this->doSkipUri($link->getUri())) {
                continue;
            }

            $links[] = [
                'uri' => $link->getUri(),
                'text' => trim($link->getNode()->textContent),
                'dom_element' => $link->getNode(),
            ];
        }

        foreach (self::TEST_TYPES as $type => $testTypeWords) {
            foreach ($this->getMatchedLinks($testTypeWords, $links) as ['link' => $link]) {
                $testTypePrices = $this->getPrices($link['uri'], $link['text'], $type);

                if (count($testTypePrices)) {
                    // Use the lowest price
                    $prices[$type] = [
                        'uri' => $link['uri'],
                        'price' => (int) reset($testTypePrices),
                    ];

                    break;
                }
            }
        }

        return $prices;
    }

    private function doSkipUri(string $uri): bool
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

    private function getPrices($linkUrl, $linkText, string $testType): array
    {
        // Try to extract prices from the link text
        $prices = $this->parsePrices($linkText, $testType);
        if (count($prices)){
            return $prices;
        }

        // Try to open the link
        try {
            $crawler = $this->getCrawler($linkUrl);
        } catch(TransportException $e) {
            $this->error($e->getMessage());

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

    private function hasAnyPrice(array $prices): bool
    {
        foreach ($prices as $price) {
            if ($price['price'] > 0) {
                return true;
            }
        }

        return false;
    }

    private function mergePriceInfo(array $provider, array $prices): array
    {
        foreach ($prices as $type => $priceInfo) {
            foreach ($priceInfo as $key => $value) {
                $provider[$type . '_' . $key] = $value;
            }
        }

        return $provider;
    }

    private function error(string $message): void
    {
        $this->logger->warning($message);
        $this->sectionErrors->writeln('<error>' . $message . '</error>');
    }

    private function storePrices(array $data): void
    {
        $fp = fopen(self::DATA_FILE_CSV, 'w+b');

        // headers
        if (count($data)) {
            fputcsv($fp, array_keys($data[0]));
        }

        foreach ($data as $provider) {
            fputcsv($fp, $provider);
        }

        fclose($fp);
    }

    private function convertPricesToJson(): void
    {
        $priceFields = ['fit_to_fly' => 6, 'day_2_and_8' => 8, 'test_to_release' => 10];

        $fp = fopen(self::DATA_FILE_CSV, 'r');
        // skip headers
        fgetcsv($fp);

        $data = [];
        while (($provider = fgetcsv($fp)) !== false) {
            $item = [
                'name' => $provider[0],
                'region' => $provider[1],
                'email' => $provider[2],
                'phone' => $provider[3],
                'website' => $provider[4],
                'fit_to_fly_uri' => $provider[5],
                'day_2_and_8_uri' => $provider[7],
                'test_to_release_uri' => $provider[9],
            ];

            foreach ($priceFields as $priceField => $csvIndex) {
                $item[$priceField . '_price'] = (int) $provider[$csvIndex];
                $item[$priceField . '_formatted'] = $item[$priceField . '_price']  > 0 ? '£' . number_format((float) $provider[$csvIndex], 2) : '';
            }

            $data[] = $item;
        }
        fclose($fp);

        $fp = fopen(self::DATA_FILE_JSON, 'w+b');
        fwrite($fp, json_encode($data));
        fclose($fp);
    }
}