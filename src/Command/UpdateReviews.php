<?php

declare(strict_types=1);

namespace App\Command;

use App\Service\ReviewsCache;
use App\Service\TestProvider\Storage\Json as TestProviderStorage;
use App\Service\TrustPilot;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateReviews extends Command
{
    private TestProviderStorage $storage;
    private ReviewsCache $cache;
    private TrustPilot $trustpilot;

    public function __construct(
        TestProviderStorage $storage,
        ReviewsCache $cache,
        TrustPilot $trustpilot
    ) {
        parent::__construct('uk-covid-test-providers:update-reviews');

        $this->storage = $storage;
        $this->cache = $cache;
        $this->trustpilot = $trustpilot;
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'limit',
                null,
                InputOption::VALUE_OPTIONAL,
                'Amount of providers which rating will be updated',
                10
            )
            ->setDescription('Updates reviews for specified amount of test providers');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $limit = (int) $input->getOption('limit');

        $providers = $this->storage->load();
        $this->cache->init($providers);
        $reviews = $this->cache->getReviewsToUpdate($limit);

        $sectionProgress = $output->section();

        $progressBar = new ProgressBar($sectionProgress, max(1, count($reviews)));
        $progressBar->setFormat(PHP_EOL . 'Website: %website%'. PHP_EOL . PHP_EOL . '%current%/%max% [%bar%] %percent:3s%% %elapsed:6s%/%estimated:-6s% %memory:6s%' . PHP_EOL);
        $progressBar->setMessage('', 'website');
        $progressBar->start();

        foreach ($reviews as &$review) {
            $progressBar->setMessage($review['website'], 'website');

            $data = $this->trustpilot->fetchData($review['website']);
            $this->cache->update($review['website'], $data['url'], $data['reviews'], $data['score']);

            sleep(rand(1, 5));

            $progressBar->advance();
        }
        $progressBar->finish();

        // Store reviews data in JSON storage
        $reviews = $this->cache->load();
        $providers = $this->storage->load();
        foreach ($reviews as $review) {
            foreach ($providers as &$provider) {
                if ($provider['website'] === $review['website']) {
                    $provider = $this->cache->mergeReviewsData($provider, $review);

                    break;
                }
            }
        }
        $this->storage->store($providers);

        return 0;
    }
}