# Ryanair Layover Checker

CLI tool that finds how to fly from airport A to airport B using Ryanair — including connecting flights via layover airports when no direct route exists.

Ryanair doesn't sell connecting tickets, so if there's no direct flight you're on your own figuring out which airports connect. This tool does that for you: it finds all possible layover airports, checks flight availability on your date, pairs the legs with a minimum 1-hour connection time, converts all prices to EUR, and shows you the results.

## Requirements

- PHP 8.2+
- Composer

## Installation

```bash
git clone https://github.com/Tjitse-E/ryanair-layover-checker.git
cd ryanair-layover-checker
composer install
```

## Usage

```bash
php ryanair find:way --from=ATH --to=EDI --date=2026-06-01
```

### Options

| Option | Required | Default | Description |
|--------|----------|---------|-------------|
| `--from` | Yes | | Origin airport IATA code |
| `--to` | Yes | | Destination airport IATA code |
| `--date` | Yes | | Travel date (YYYY-MM-DD) |
| `--sort` | No | `duration` | Sort by `duration` or `price` |

### Examples

#### Direct flight

```
$ php ryanair find:way --from=BER --to=DUB --date=2026-05-10

Searching direct flights BER -> DUB on 2026-05-10...

Direct: BER -> DUB on 2026-05-10

  FR 5419  06:00 -> 07:20  (02:20)  EUR 56.99
  FR 3670  19:40 -> 21:00  (02:20)  EUR 69.99
```

#### Connecting flights (sorted by duration)

```
$ php ryanair find:way --from=ATH --to=EDI --date=2026-06-01

Searching direct flights ATH -> EDI on 2026-06-01...
No direct flights found. Searching connections...

Found 8 connecting route(s), sorted by total duration:

  Route 1: ATH -> STN -> EDI  (total EUR 236.22)
  |  FR 14  12:20 -> 14:10  ATH -> STN  EUR 213.73
  |  Layover: 1h35m at London Stansted
  |  RK 1273  15:45 -> 17:05  STN -> EDI  EUR 22.49 (GBP 19.56)
  |  Departs 12:20 -> Arrives 17:05  (total travel: 4h45m)

  Route 2: ATH -> MXP -> EDI  (total EUR 122.98)
  |  FR 8896  12:35 -> 14:15  ATH -> MXP  EUR 35.99
  |  Layover: 3h15m at Milan Malpensa
  |  FR 7053  17:30 -> 19:05  MXP -> EDI  EUR 86.99
  |  Departs 12:35 -> Arrives 19:05  (total travel: 6h30m)

  Route 3: ATH -> BUD -> EDI  (total EUR 179.54)
  |  FR 1242  17:25 -> 18:30  ATH -> BUD  EUR 35.99
  |  Layover: 3h40m at Budapest
  |  FR 7891  22:10 -> 00:10  BUD -> EDI  EUR 143.55 (HUF 54,411.00)
  |  Departs 17:25 -> Arrives 00:10  (total travel: 6h45m)
```

#### Connecting flights (sorted by price)

```
$ php ryanair find:way --from=ATH --to=EDI --date=2026-06-01 --sort=price

Searching direct flights ATH -> EDI on 2026-06-01...
No direct flights found. Searching connections...

Found 8 connecting route(s), sorted by price:

  Route 1: ATH -> WMI -> EDI  (total EUR 100.95)
  |  FR 3324  10:00 -> 11:55  ATH -> WMI  EUR 43.99
  |  Layover: 11h10m at Warsaw Modlin
  |  FR 4525  23:05 -> 00:50  WMI -> EDI  EUR 56.96 (PLN 240.00)
  |  Departs 10:00 -> Arrives 00:50  (total travel: 14h50m)

  Route 2: ATH -> MXP -> EDI  (total EUR 122.98)
  |  FR 8896  12:35 -> 14:15  ATH -> MXP  EUR 35.99
  |  Layover: 3h15m at Milan Malpensa
  |  FR 7053  17:30 -> 19:05  MXP -> EDI  EUR 86.99
  |  Departs 12:35 -> Arrives 19:05  (total travel: 6h30m)
```

Non-EUR prices are automatically converted using ECB exchange rates. The original price is shown in parentheses.

## How it works

1. Checks if a direct Ryanair route exists between the two airports
2. If yes, shows direct flights with times and prices
3. If no direct route:
   - Fetches all destinations from the origin airport
   - Fetches all destinations from the destination airport
   - Finds the intersection — airports that connect both
   - Fetches flight availability for all candidates concurrently
   - Pairs flights with a minimum 1-hour layover
   - Converts prices to EUR and sorts results

## Ryanair API

Uses Ryanair's public-facing API (no authentication required):

| Endpoint | Purpose |
|----------|---------|
| `/api/farfnd/v4/oneWayFares/{from}/{to}/availabilities` | Check if route exists + available dates |
| `/api/booking/v4/en-gb/availability` | Flight times and fares for a specific date |
| `/api/views/locate/searchWidget/routes/en/airport/{code}` | All destinations from an airport |

## Built with

- [Laravel Zero](https://laravel-zero.com) — micro-framework for console applications
- [Guzzle](https://docs.guzzlephp.org) — HTTP client with concurrent request support
- [Frankfurter API](https://frankfurter.dev) — currency conversion using ECB rates

## License

MIT
