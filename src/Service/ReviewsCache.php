<?php

declare(strict_types=1);

namespace App\Service;

use App\Service\TestProvider\Storage\Json;

class ReviewsCache extends Json
{
    public function getDataFile(): string
    {
        return 'var/data/reviews.json';
    }

    public function init(array $providers): void
    {
        if (!file_exists($this->getDataFile())) {
            $this->store([]);
        }

        $reviews = $this->load();

        foreach ($providers as $provider) {
            if (!isset($reviews[$provider['website']])) {
                $reviews[$provider['website']] = [
                    'website' => $provider['website'],
                    'url' => TrustPilot::BASE_URL,
                    'count' => 0,
                    'score' => null,
                    'updated_at' => 0,
                ];
            }
        }

        $this->store($reviews);
    }

    public function getReviewsToUpdate(int $limit): array
    {
        $reviews = $this->load();

        usort($reviews, static function ($a, $b) {
            return min(1, max(-1, $a['updated_at'] - $b['updated_at']));
        });

        return array_splice($reviews, 0, $limit);
    }

    public function update(string $website, string $url, int $count, ?float $score): void
    {
        $reviews = $this->load();
        if (!isset($reviews[$website])) {
            return;
        }

        $reviews[$website]['url'] = $url;
        $reviews[$website]['count'] = $count;
        if (null !== $score) {
            $reviews[$website]['score'] = number_format($score, 2);
        }
        $reviews[$website]['updated_at'] = time();

        $this->store($reviews);
    }
}