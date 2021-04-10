<?php

declare(strict_types=1);

namespace App\Service;

use Goutte\Client;
use Symfony\Component\HttpClient\Exception\TransportException;

class TrustPilot
{
    public const BASE_URL = 'https://www.trustpilot.com/review/';

    public function fetchData(string $url): array
    {
        $data = ['url' => self::BASE_URL, 'reviews' => 0, 'score' => null];

        $domain = parse_url($url, PHP_URL_HOST);
        $domain = str_replace('www.', '', $domain);
        if (!$domain) {
            return $data;
        }

        $data['url'] = self::BASE_URL . $domain;
        try {
            $crawler = (new Client())->request('GET', $data['url']);
        } catch(TransportException $e) {
            return $data;
        }

        $review = $crawler->filter('.header--inline');
        if ($review->count()) {
            $data['reviews'] = (int) $review->text();
        }

        $score = $crawler->filter('.header_trustscore');
        if ($data['reviews'] && $score->count()) {
            $data['score'] = (float) $score->text();
        }

        return $data;
    }
}