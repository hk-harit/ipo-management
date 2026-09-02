<?php

namespace App\Services\AllotmentCheck;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Bigshare Services — another SEBI-registered registrar. Unlike MUFG Intime,
 * their public "IPO Allotment Status" page guards the lookup with a numeric
 * image captcha, so this mints one, reads it with a CaptchaSolver, and — since
 * an OCR guess is sometimes just wrong — retries with a fresh captcha a few
 * times before giving up.
 *
 * @see https://ipo.bigshareonline.com/ipo_status.aspx
 */
class BigshareChecker implements AllotmentChecker
{
    private const CAPTCHA_URL = 'https://ipo.bigshareonline.com/Captcha.ashx';

    private const LOOKUP_URL = 'https://ipo.bigshareonline.com/Data.aspx/FetchIpodetails';

    private const ORIGIN = 'https://ipo.bigshareonline.com';

    /** One initial attempt plus this many retries with a fresh captcha. */
    private const MAX_ATTEMPTS = 4;

    public function __construct(private readonly CaptchaSolver $captchaSolver) {}

    public function check(string $registrarClientId, string $pan): ?AllotmentCheckResult
    {
        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            ['token' => $token, 'image' => $image] = $this->fetchCaptcha();

            $answer = $this->captchaSolver->solve($image);

            if (blank($answer)) {
                continue; // OCR read nothing usable — try again with a new captcha
            }

            // FetchIpodetails is a classic ASP.NET page method: the real payload
            // is wrapped in a "d" key, unlike Captcha.ashx's plain JSON.
            $data = $this->lookup($registrarClientId, $pan, $token, $answer)->json('d') ?? [];

            if (($data['Status'] ?? null) === 'OK') {
                return $this->parse($data);
            }

            $message = $data['Message'] ?? 'unknown error';

            // A wrong captcha guess is worth retrying; anything else is not.
            if ($attempt < self::MAX_ATTEMPTS && str_contains(strtolower($message), 'captcha')) {
                continue;
            }

            throw new AllotmentCheckException("Bigshare: {$message}");
        }

        throw new AllotmentCheckException('Bigshare: could not solve the captcha after '.self::MAX_ATTEMPTS.' attempts.');
    }

    /** @return array{token: string, image: string} the token and raw (decoded) captcha image bytes */
    private function fetchCaptcha(): array
    {
        $response = $this->client()->get(self::CAPTCHA_URL, ['_' => (string) (int) (microtime(true) * 1000)]);

        if ($response->failed()) {
            throw new AllotmentCheckException("Bigshare: couldn't fetch a captcha (HTTP {$response->status()}).");
        }

        $token = $response->json('token');
        $dataUri = $response->json('image');

        if (blank($token) || blank($dataUri)) {
            throw new AllotmentCheckException('Bigshare: captcha response had no token/image in it.');
        }

        // "data:image/png;base64,...." — the API returns a data URI, not raw base64.
        $base64 = str_contains($dataUri, ',') ? substr($dataUri, strpos($dataUri, ',') + 1) : $dataUri;

        return ['token' => $token, 'image' => base64_decode($base64)];
    }

    private function lookup(string $registrarClientId, string $pan, string $captchaToken, string $captchaAnswer): Response
    {
        $response = $this->client()->post(self::LOOKUP_URL, [
            'Applicationno' => '',
            'Company' => $registrarClientId,
            'SelectionType' => 'PN',
            'PanNo' => strtoupper($pan),
            'txtcsdl' => '',
            'CaptchaAnswer' => $captchaAnswer,
            'CaptchaToken' => $captchaToken,
            'ResultToken' => '',
            'ddlType' => '0',
            'lang' => 'en',
            'txtClId' => '',
            'txtDPID' => '',
        ]);

        if ($response->failed()) {
            throw new AllotmentCheckException("Bigshare: lookup failed (HTTP {$response->status()}).");
        }

        return $response;
    }

    private function client()
    {
        return Http::withHeaders([
            'accept' => 'application/json, text/javascript, */*; q=0.01',
            'content-type' => 'application/json; charset=UTF-8',
            'origin' => self::ORIGIN,
            'referer' => self::ORIGIN.'/ipo_status.aspx',
            'x-requested-with' => 'XMLHttpRequest',
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
        ])->timeout(20);
    }

    private function parse(array $data): ?AllotmentCheckResult
    {
        $records = $data['Records'] ?? [];

        if ((int) ($data['MatchCount'] ?? 0) <= 0 || empty($records)) {
            return null;
        }

        $row = $records[0];

        $applied = trim((string) ($row['APPLIED'] ?? ''));
        // ALLOTED holds a share count when allotted, but is overloaded with a
        // status string ("NON-ALLOTTE[D]", truncated by their column width)
        // when it isn't — so anything non-numeric here means zero allotted.
        $alloted = trim((string) ($row['ALLOTED'] ?? ''));

        return new AllotmentCheckResult(
            sharesApplied: $applied !== '' && ctype_digit($applied) ? (int) $applied : null,
            sharesAllotted: ctype_digit($alloted) ? (int) $alloted : 0,
            refundAmount: null,
            refundReference: null,
        );
    }
}
