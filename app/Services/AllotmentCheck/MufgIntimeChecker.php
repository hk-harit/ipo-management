<?php

namespace App\Services\AllotmentCheck;

use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * MUFG Intime India (formerly Link Intime) — one of SEBI's registered IPO
 * registrars. Their public "IPO Allotment Status" page (no login, no captcha)
 * is a two-step AJAX flow: mint a session token, then look up a PAN with it.
 * This replicates exactly those two calls, same as the page's own JS.
 *
 * @see https://in.mpms.mufg.com/Initial_Offer/public-issues.html
 */
class MufgIntimeChecker implements AllotmentChecker
{
    private const BASE_URL = 'https://in.mpms.mufg.com/Initial_Offer/IPO.aspx';

    public function check(string $registrarClientId, string $pan): ?AllotmentCheckResult
    {
        // The token is bound to the session cookie it was minted under, so both
        // calls have to share the same cookie jar.
        $cookies = new CookieJar;

        // A body of "{}" (what Http::post() sends with no data) makes this endpoint
        // 500 — it wants a genuinely empty body, same as curl's `content-length: 0`.
        // (withBody() would work too, but it appends a second content-type header
        // on top of the one already set below, and that duplicate 400s instead.)
        $tokenResponse = $this->client($cookies)
            ->send('POST', self::BASE_URL.'/generateToken', ['body' => '']);

        if ($tokenResponse->failed()) {
            throw new AllotmentCheckException("MUFG Intime: couldn't get a session token (HTTP {$tokenResponse->status()}).");
        }

        $token = $tokenResponse->json('d');

        if (blank($token)) {
            throw new AllotmentCheckException('MUFG Intime: token response had no token in it.');
        }

        $searchResponse = $this->client($cookies)->post(self::BASE_URL.'/SearchOnPan', [
            'clientid' => $registrarClientId,
            'PAN' => strtoupper($pan),
            'IFSC' => '',
            'CHKVAL' => '1',
            'token' => $token,
        ]);

        if ($searchResponse->failed()) {
            throw new AllotmentCheckException("MUFG Intime: lookup failed (HTTP {$searchResponse->status()}).");
        }

        return $this->parse($searchResponse->json('d'));
    }

    private function client(CookieJar $cookies): PendingRequest
    {
        return Http::withOptions(['cookies' => $cookies])
            ->withHeaders([
                'accept' => 'application/json, text/javascript, */*; q=0.01',
                'content-type' => 'application/json; charset=UTF-8',
                'origin' => 'https://in.mpms.mufg.com',
                'referer' => 'https://in.mpms.mufg.com/Initial_Offer/public-issues.html',
                'x-requested-with' => 'XMLHttpRequest',
                'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36',
            ])
            ->timeout(15);
    }

    private function parse(?string $xml): ?AllotmentCheckResult
    {
        if (blank($xml) || ! str_contains($xml, '<Table>')) {
            return null;
        }

        $row = @simplexml_load_string($xml)?->Table;

        if (! $row) {
            return null;
        }

        return new AllotmentCheckResult(
            sharesApplied: isset($row->SHARES) && (string) $row->SHARES !== '' ? (int) (string) $row->SHARES : null,
            sharesAllotted: isset($row->ALLOT) && (string) $row->ALLOT !== '' ? (int) (string) $row->ALLOT : 0,
            refundAmount: isset($row->RFNDAMT) && (string) $row->RFNDAMT !== '' ? (float) (string) $row->RFNDAMT : null,
            refundReference: isset($row->RFNDNO) && (string) $row->RFNDNO !== '' ? (string) $row->RFNDNO : null,
            companyName: isset($row->companyname) ? (string) $row->companyname : null,
        );
    }
}
