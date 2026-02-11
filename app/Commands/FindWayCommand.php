<?php

namespace App\Commands;

use App\Services\CurrencyConverter;
use App\Services\RyanairApiClient;
use App\Services\RouteFinder;
use Carbon\Carbon;
use LaravelZero\Framework\Commands\Command;

class FindWayCommand extends Command
{
    protected $signature = 'find:way
        {--from= : Origin airport IATA code (e.g. BER)}
        {--to= : Destination airport IATA code (e.g. DUB)}
        {--date= : Travel date in YYYY-MM-DD format}
        {--sort=duration : Sort by "duration" or "price"}';

    protected $description = 'Find a way to fly from A to B using Ryanair, including layover connections';

    public function handle(): int
    {
        $from = strtoupper($this->option('from'));
        $to = strtoupper($this->option('to'));
        $date = $this->option('date');

        if (!$from || !$to || !$date) {
            $this->error('All options are required: --from, --to, --date');
            return self::FAILURE;
        }

        if (!preg_match('/^[A-Z]{3}$/', $from) || !preg_match('/^[A-Z]{3}$/', $to)) {
            $this->error('Airport codes must be 3 letter IATA codes (e.g. BER, DUB)');
            return self::FAILURE;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $this->error('Date must be in YYYY-MM-DD format');
            return self::FAILURE;
        }

        $api = new RyanairApiClient();
        $converter = new CurrencyConverter();
        $finder = new RouteFinder($api, $converter);

        // Try direct flights first
        $this->info("Searching direct flights {$from} -> {$to} on {$date}...");

        $directFlights = $finder->findDirect($from, $to, $date);

        if ($directFlights) {
            $this->newLine();
            $this->line("<fg=green>Direct: {$from} -> {$to} on {$date}</>");
            $this->newLine();

            foreach ($directFlights as $flight) {
                $this->renderFlight($flight);
            }

            return self::SUCCESS;
        }

        // No direct — search connections
        $this->line("No direct flights found. Searching connections...");
        $this->newLine();

        $sort = strtolower($this->option('sort'));
        if (!in_array($sort, ['price', 'duration'])) {
            $this->error('Sort must be "price" or "duration"');
            return self::FAILURE;
        }

        $routes = $finder->findConnections($from, $to, $date, $sort);

        if (empty($routes)) {
            $this->warn("No routes found from {$from} to {$to} on {$date}.");
            return self::FAILURE;
        }

        $sortLabel = $sort === 'duration' ? 'total duration' : 'price';
        $this->info("Found " . count($routes) . " connecting route(s), sorted by {$sortLabel}:");
        $this->newLine();

        foreach ($routes as $i => $route) {
            $num = $i + 1;
            $totalStr = $route['totalPriceEur'] !== null
                ? 'EUR ' . number_format($route['totalPriceEur'], 2)
                : '?';

            $this->line("<fg=green>  Route {$num}: {$from} -> {$route['layoverCode']} -> {$to}  (total {$totalStr})</>");

            $this->renderFlightLeg($route['leg1'], '  |  ');

            $layoverHours = intdiv($route['layoverMinutes'], 60);
            $layoverMins = $route['layoverMinutes'] % 60;
            $layoverStr = $layoverHours > 0 ? "{$layoverHours}h{$layoverMins}m" : "{$layoverMins}m";
            $this->line("  |  <fg=yellow>Layover: {$layoverStr} at {$route['layoverName']}</>");

            $this->renderFlightLeg($route['leg2'], '  |  ');

            $depTime = $this->formatTime($route['leg1']['departureTime']);
            $arrTime = $this->formatTime($route['leg2']['arrivalTime']);
            $totalHours = intdiv($route['totalDurationMinutes'], 60);
            $totalMins = $route['totalDurationMinutes'] % 60;
            $totalTravelStr = $totalHours > 0 ? "{$totalHours}h{$totalMins}m" : "{$totalMins}m";
            $this->line("  |  <fg=cyan>Departs {$depTime} -> Arrives {$arrTime}  (total travel: {$totalTravelStr})</>");
            $this->newLine();
        }

        return self::SUCCESS;
    }

    private function renderFlight(array $flight): void
    {
        $dep = $this->formatTime($flight['departureTime']);
        $arr = $this->formatTime($flight['arrivalTime']);
        $duration = $flight['duration'];
        $price = $this->formatPrice($flight);

        $this->line("  {$flight['flightNumber']}  {$dep} -> {$arr}  ({$duration})  {$price}");
    }

    private function renderFlightLeg(array $flight, string $prefix = ''): void
    {
        $dep = $this->formatTime($flight['departureTime']);
        $arr = $this->formatTime($flight['arrivalTime']);
        $price = $this->formatPrice($flight);

        $this->line("{$prefix}{$flight['flightNumber']}  {$dep} -> {$arr}  {$flight['origin']} -> {$flight['destination']}  {$price}");
    }

    private function formatPrice(array $flight): string
    {
        $eurPrice = isset($flight['priceEur']) ? 'EUR ' . number_format($flight['priceEur'], 2) : null;

        if ($flight['currency'] === 'EUR') {
            return $eurPrice ?? 'EUR ' . number_format($flight['price'], 2);
        }

        $original = $flight['currency'] . ' ' . number_format($flight['price'], 2);

        return $eurPrice !== null ? "{$eurPrice} ({$original})" : $original;
    }

    private function formatTime(string $datetime): string
    {
        return Carbon::parse($datetime)->format('H:i');
    }
}
