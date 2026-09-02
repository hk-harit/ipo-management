# IPO Management

A self-hosted back office for people (or small teams) who apply for IPOs on
behalf of others — track every applicant, the accounts and funds used to bid,
each application's status, and check allotment results straight from the
registrar. Built on [Laravel](https://laravel.com) and
[Filament](https://filamentphp.com).

## What it does

- **IPO tracking** — mainboard and SME issues, synced automatically from
  [ipoalerts.in](https://ipoalerts.in), Chittorgarh and InvestorGain (dates,
  price band, lot size, GMP, subscription figures), merged into one list.
- **Applicants, accounts & sources** — record who you're applying for, which
  bank/demat account and UPI ID an application uses, and (if you work with
  other people bidding through you) a "Source" portal where they can log in
  and see their own applications, ledger and dues.
- **Applications & funds** — one row per applicant per IPO: amount, status,
  shares allotted, sale price, commission — plus fund transfers and expenses
  so the books balance.
- **Allotment checking** — looks up an application's status directly from the
  registrar (Bigshare, MUFG Intime/Link Intime), solving the numeric captcha
  with OCR, and updates status/shares/refund automatically. Runs as a
  background queue job (individually or in bulk) so checking a batch of
  applications never blocks the admin panel.

## Stack

PHP 8.3+, Laravel 13, Filament 3, MySQL (or any Laravel-supported database),
Tailwind CSS 4 + Vite for the few non-Filament views.

## Getting started

```bash
composer install
composer run setup   # copies .env, generates APP_KEY, migrates the database
npm install
npm run build
php artisan make:filament-user   # create your admin login
composer run dev     # serves the app, a queue worker, and Vite together
```

Then open `http://localhost:8000` — it redirects to `/admin`.

### IPO feeds

The `ipos:sync` command (scheduled twice daily, or run manually) pulls IPO
listings from ipoalerts.in, Chittorgarh and InvestorGain and merges them into
your list. An [ipoalerts.in](https://ipoalerts.in) API key
(`IPOALERTS_API_KEY` in `.env`) is optional but recommended — the other two
feeds work without one. The scheduler needs `php artisan schedule:work`
running (already included in `composer run dev`) or a system cron entry
calling `schedule:run` every minute in production.

### Allotment checking

Bigshare's captcha is solved with Tesseract OCR. Install the `tesseract-ocr`
binary and it works out of the box — see
[`TesseractCaptchaSolver`](app/Services/AllotmentCheck/TesseractCaptchaSolver.php)
if you need to point it at a non-default binary path.

## Running tests

```bash
composer test
```

## Contributing

Issues and pull requests are welcome. This is a young project — if you're
planning a larger change, opening an issue first to talk it through is
appreciated.

## License

Licensed under the [MIT license](LICENSE).
