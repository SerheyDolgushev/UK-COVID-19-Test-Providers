<?php

declare(strict_types=1);

namespace App\Service\TestProvider\Storage;

interface Storage
{
    public function getDataFile(): string;

    public function store(array $providers): void;

    public function load(): array;
}