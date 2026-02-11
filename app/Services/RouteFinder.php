<?php

namespace App\Services;

use Carbon\Carbon;
use GuzzleHttp\Client;
use GuzzleHttp\Promise\Utils;

class RouteFinder
{
    private const MIN_LAYOVER_MINUTES = 60;

    public function __construct(
        private RyanairApiClient $api,
        private CurrencyConverter $converter,
    ) {}

    public function findDirect(string $from, string $to, string $date): ?array
    {
        $availableDates = $this->api->getAvailableDates($from, $to);

        if (empty($availableDates) || !in_array($date, $availableDates)) {
            return null;
        }

        $flights = $this->api->getFlights($from, $to, $date);

        if (empty($flights)) {
            return null;
        }

        // Add EUR price to each flight
        foreach ($flights as &$flight) {
            $flight['priceEur'] = $this->converter->toEur($flight['price'], $flight['currency']);
        }

        return $flights;
    }

    public function findConnections(string $from, string $to, string $date, string $sort = 'price'): array
    {
        $fromDestinations = $this->api->getDestinations($from);
        $toDestinations = $this->api->getDestinations($to);

        $candidates = array_intersect_key($fromDestinations, $toDestinations);

        if (empty($candidates)) {
            return [];
        }

        // Fetch flights concurrently using Guzzle promises
        $client = new Client(['timeout' => 15, 'headers' => ['Accept' => 'application/json']]);
        $query = fn(string $origin, string $dest) => [
            'query' => [
                'ADT' => 1, 'CHD' => 0, 'DateIn' => '', 'DateOut' => $date,
                'Destination' => $dest, 'Disc' => 0, 'INF' => 0, 'Origin' => $origin,
                'TEEN' => 0, 'promoCode' => '', 'IncludeConnectingFlights' => 'false',
                'FlexDaysBeforeOut' => 0, 'FlexDaysOut' => 0,
                'FlexDaysBeforeIn' => 0, 'FlexDaysIn' => 0,
                'RoundTrip' => 'false', 'ToUs' => 'AGREED',
            ],
        ];

        $leg1Promises = [];
        $leg2Promises = [];

        foreach (array_keys($candidates) as $code) {
            $leg1Promises[$code] = $client->getAsync(
                'https://www.ryanair.com/api/booking/v4/en-gb/availability',
                $query($from, $code)
            );
            $leg2Promises[$code] = $client->getAsync(
                'https://www.ryanair.com/api/booking/v4/en-gb/availability',
                $query($code, $to)
            );
        }

        $leg1Responses = Utils::settle($leg1Promises)->wait();
        $leg2Responses = Utils::settle($leg2Promises)->wait();

        // Pair flights with valid layover times
        $routes = [];

        foreach ($candidates as $layoverCode => $layoverName) {
            $outbound = $this->parseFlightsFromSettled($leg1Responses[$layoverCode] ?? null);
            $inbound = $this->parseFlightsFromSettled($leg2Responses[$layoverCode] ?? null);

            if (empty($outbound) || empty($inbound)) {
                continue;
            }

            foreach ($outbound as $leg1) {
                foreach ($inbound as $leg2) {
                    $leg1Arrival = Carbon::parse($leg1['arrivalTime']);
                    $leg2Departure = Carbon::parse($leg2['departureTime']);

                    $layoverMinutes = (int) $leg1Arrival->diffInMinutes($leg2Departure, false);

                    if ($layoverMinutes < self::MIN_LAYOVER_MINUTES) {
                        continue;
                    }

                    $leg1Eur = $this->converter->toEur($leg1['price'] ?? 0, $leg1['currency']);
                    $leg2Eur = $this->converter->toEur($leg2['price'] ?? 0, $leg2['currency']);
                    $totalEur = ($leg1Eur !== null && $leg2Eur !== null)
                        ? round($leg1Eur + $leg2Eur, 2)
                        : null;

                    $totalDuration = (int) Carbon::parse($leg1['departureTime'])
                        ->diffInMinutes(Carbon::parse($leg2['arrivalTime']));

                    $routes[] = [
                        'layoverCode' => $layoverCode,
                        'layoverName' => $layoverName,
                        'leg1' => array_merge($leg1, ['priceEur' => $leg1Eur]),
                        'leg2' => array_merge($leg2, ['priceEur' => $leg2Eur]),
                        'layoverMinutes' => $layoverMinutes,
                        'totalPriceEur' => $totalEur,
                        'totalDurationMinutes' => $totalDuration,
                    ];
                }
            }
        }

        if ($sort === 'duration') {
            usort($routes, fn($a, $b) => $a['totalDurationMinutes'] <=> $b['totalDurationMinutes']);
        } else {
            usort($routes, function ($a, $b) {
                if ($a['totalPriceEur'] === null && $b['totalPriceEur'] === null) return 0;
                if ($a['totalPriceEur'] === null) return 1;
                if ($b['totalPriceEur'] === null) return -1;

                return $a['totalPriceEur'] <=> $b['totalPriceEur'];
            });
        }

        return $routes;
    }

    private function parseFlightsFromSettled(?array $settled): array
    {
        if (!$settled || $settled['state'] !== 'fulfilled') {
            return [];
        }

        $body = $settled['value']->getBody()->getContents();
        $data = json_decode($body, true);
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
                        'origin' => $trip['origin'] ?? '',
                        'destination' => $trip['destination'] ?? '',
                        'originName' => $trip['originName'] ?? '',
                        'destinationName' => $trip['destinationName'] ?? '',
                    ];
                }
            }
        }

        return $flights;
    }
}
