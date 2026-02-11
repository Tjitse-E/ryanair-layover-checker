<?php

namespace App\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class RyanairApiClient
{
    private const BASE_URL = 'https://www.ryanair.com/api';

    private Client $client;

    public function __construct()
    {
        $this->client = new Client([
            'base_uri' => self::BASE_URL,
            'timeout' => 15,
            'headers' => [
                'Accept' => 'application/json',
            ],
        ]);
    }

    public function getAvailableDates(string $from, string $to): array
    {
        try {
            $response = $this->client->get("/api/farfnd/v4/oneWayFares/{$from}/{$to}/availabilities");
            return json_decode($response->getBody()->getContents(), true) ?? [];
        } catch (GuzzleException) {
            return [];
        }
    }

    public function getFlights(string $from, string $to, string $date): array
    {
        try {
            $response = $this->client->get('/api/booking/v4/en-gb/availability', [
                'query' => [
                    'ADT' => 1,
                    'CHD' => 0,
                    'DateIn' => '',
                    'DateOut' => $date,
                    'Destination' => $to,
                    'Disc' => 0,
                    'INF' => 0,
                    'Origin' => $from,
                    'TEEN' => 0,
                    'promoCode' => '',
                    'IncludeConnectingFlights' => 'false',
                    'FlexDaysBeforeOut' => 0,
                    'FlexDaysOut' => 0,
                    'FlexDaysBeforeIn' => 0,
                    'FlexDaysIn' => 0,
                    'RoundTrip' => 'false',
                    'ToUs' => 'AGREED',
                ],
            ]);
        } catch (GuzzleException) {
            return [];
        }

        $data = json_decode($response->getBody()->getContents(), true);
        $currency = $data['currency'] ?? 'EUR';
        $flights = [];

        foreach ($data['trips'] ?? [] as $trip) {
            foreach ($trip['dates'] ?? [] as $dateEntry) {
                foreach ($dateEntry['flights'] ?? [] as $flight) {
                    if (empty($flight['regularFare'])) {
                        continue;
                    }

                    $flights[] = [
                        'flightNumber' => $flight['flightNumber'] ?? '',
                        'departureTime' => $flight['time'][0] ?? '',
                        'arrivalTime' => $flight['time'][1] ?? '',
                        'duration' => $flight['duration'] ?? '',
                        'price' => $flight['regularFare']['fares'][0]['amount'] ?? null,
                        'currency' => $currency,
                        'origin' => $trip['origin'] ?? $from,
                        'destination' => $trip['destination'] ?? $to,
                        'originName' => $trip['originName'] ?? $from,
                        'destinationName' => $trip['destinationName'] ?? $to,
                    ];
                }
            }
        }

        return $flights;
    }

    public function getDestinations(string $airportCode): array
    {
        try {
            $response = $this->client->get("/api/views/locate/searchWidget/routes/en/airport/{$airportCode}");
        } catch (GuzzleException) {
            return [];
        }

        $routes = json_decode($response->getBody()->getContents(), true) ?? [];
        $codes = [];

        foreach ($routes as $route) {
            $code = $route['arrivalAirport']['code'] ?? null;
            if ($code) {
                $codes[$code] = $route['arrivalAirport']['name'] ?? $code;
            }
        }

        return $codes;
    }
}
