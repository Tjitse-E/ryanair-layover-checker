<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class CurrencyConverter
{
    /** @var array<string, float> Rates: how many units of currency X per 1 EUR */
    private array $rates = [];

    private bool $loaded = false;

    public function loadRates(): void
    {
        if ($this->loaded) {
            return;
        }

        try {
            $client = new Client(['timeout' => 10]);
            $response = $client->get('https://api.frankfurter.dev/v1/latest', [
                'query' => ['base' => 'EUR'],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);
            $this->rates = $data['rates'] ?? [];
            $this->rates['EUR'] = 1.0;
            $this->loaded = true;
        } catch (GuzzleException) {
            // Fallback: at least EUR works
            $this->rates = ['EUR' => 1.0];
            $this->loaded = true;
        }
    }

    /**
     * Convert an amount from a given currency to EUR.
     * Returns null if the currency is unknown.
     */
    public function toEur(float $amount, string $currency): ?float
    {
        $this->loadRates();

        $currency = strtoupper($currency);

        if ($currency === 'EUR') {
            return $amount;
        }

        $rate = $this->rates[$currency] ?? null;

        if ($rate === null || $rate == 0) {
            return null;
        }

        return round($amount / $rate, 2);
    }
}
