<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ReviewsCache;
use App\Service\TestProvider\PriceExtractor;
use App\Service\TestProvider\Repository;
use App\Service\TestProvider\Storage\Csv as StorageCsv;
use App\Service\TestProvider\Storage\Json as StorageJson;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

class ParsePrices extends Command
{
    private Repository $repository;
    private PriceExtractor $priceExtractor;
    private ReviewsCache $reviews;
    private StorageCsv $storageCsv;
    private StorageJson $storageJson;
    private LoggerInterface $logger;
    private ConsoleSectionOutput $sectionErrors;

    public function __construct(
        Repository $repository,
        PriceExtractor $priceExtractor,
        ReviewsCache $reviews,
        StorageCsv $storageCsv,
        StorageJson $storageJson,
        LoggerInterface $logger
    ) {
        parent::__construct('uk-covid-test-providers:parse-prices');

        $this->repository = $repository;
        $this->priceExtractor = $priceExtractor;
        $this->reviews = $reviews;
        $this->storageCsv = $storageCsv;
        $this->storageJson = $storageJson;
        $this->logger = $logger;
    }

    protected function configure(): void
    {
        $this->setDescription('Parses the list of providers and tries to extract the prices for each testing type');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $storageFile = $this->storageCsv->getDataFile();
        if (!is_writable(dirname($storageFile))) {
            $io->error('Data file "' . $storageFile . '" is not writable');

            return 1;
        }

        $reviews = $this->reviews->load();
        $providers = $this->repository->fetchUnique();

        $sectionProgress = $output->section();
        $this->sectionErrors = $output->section();

        $progressBar = new ProgressBar($sectionProgress, max(1, count($providers)));
        $progressBar->setFormat(PHP_EOL . 'Provider: %provider%' . PHP_EOL . 'Website: %website%'. PHP_EOL . PHP_EOL . '%current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%' . PHP_EOL);
        $progressBar->setMessage('', 'provider');
        $progressBar->setMessage('', 'website');
        $progressBar->start();

        $this->priceExtractor->setErrorHandler([$this, 'error']);
        $this->priceExtractor->fetchGovUkPrices();

        $providersWithoutPricesCount = 0;
        $providersWithPriceData = [];
        foreach ($providers as $provider) {
            $progressBar->setMessage($provider['name'], 'provider');
            $progressBar->setMessage($provider['website'], 'website');

            $prices = $this->priceExtractor->fetchPrices($provider);
            if ($this->priceExtractor->areDefault($prices)) {
                $providersWithoutPricesCount++;
            }

            if (isset($reviews[$provider['website']])) {
                $review = $reviews[$provider['website']];
                $provider = $this->reviews->mergeReviewsData($provider, $review);
            }

            $provider = $this->priceExtractor->mergePricesData($provider, $prices);
            $providersWithPriceData[] = $provider;

            $progressBar->advance();
        }
        $progressBar->finish();

        // Store to CSV first
        $this->storageCsv->store($providersWithPriceData);

        // Front-end will continue to use old JSON, if something went wrong
        // and there is no data in CSV file
        $updatedProviders = $this->storageCsv->load();
        if (count($updatedProviders)) {
            $this->storageJson->store($updatedProviders);
        }

        $io->newLine();
        if ($providersWithoutPricesCount) {
            $io->caution('Unable to parse any prices for ' . $providersWithoutPricesCount . ' providers');
        }
        $providersWithPricesCount = count($providersWithPriceData) - $providersWithoutPricesCount;
        $io->success('Prices parsed and stored for ' . $providersWithPricesCount . ' providers');

        return 0;
    }

    public function error(string $message): void
    {
        $this->logger->warning($message);
        $this->sectionErrors->writeln('<error>' . $message . '</error>');
    }
}