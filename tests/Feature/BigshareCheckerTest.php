<?php

namespace Tests\Feature;

use App\Services\AllotmentCheck\AllotmentCheckException;
use App\Services\AllotmentCheck\BigshareChecker;
use App\Services\AllotmentCheck\CaptchaSolver;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BigshareCheckerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    /** Stub the captcha endpoint; a 1x1 PNG is enough since the solver itself is faked. */
    private function fakeCaptcha(): void
    {
        $pixel = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');

        Http::fake([
            'ipo.bigshareonline.com/Captcha.ashx*' => Http::response([
                'image' => 'data:image/png;base64,'.base64_encode($pixel),
                'token' => 'captcha-token',
            ]),
        ]);
    }

    private function fakeSolver(?string $answer): void
    {
        $this->app->instance(CaptchaSolver::class, new class($answer) implements CaptchaSolver
        {
            public function __construct(private readonly ?string $answer) {}

            public function solve(string $imageContents): ?string
            {
                return $this->answer;
            }
        });
    }

    private function checker(): BigshareChecker
    {
        return $this->app->make(BigshareChecker::class);
    }

    /** FetchIpodetails is an ASP.NET page method — the real payload always sits under a "d" key. */
    private function lookupResponse(array $data)
    {
        return Http::response(['d' => $data]);
    }

    public function test_a_full_allotment_is_reported(): void
    {
        $this->fakeCaptcha();
        $this->fakeSolver('123456');
        Http::fake(['ipo.bigshareonline.com/Data.aspx/FetchIpodetails' => $this->lookupResponse([
            'Status' => 'OK',
            'Message' => '',
            'MatchCount' => 1,
            'Records' => [[
                'APPLICATION_NO' => '920688397',
                'DPID' => '1203320231842011',
                'Name' => 'OM KALPESHBHAI VAGHANI',
                'APPLIED' => '182',
                'ALLOTED' => '182',
            ]],
        ])]);

        $result = $this->checker()->check('9047', 'CEAPV6462B');

        $this->assertSame(182, $result->sharesApplied);
        $this->assertSame(182, $result->sharesAllotted);
        $this->assertTrue($result->isFullyAllotted());
    }

    /** ALLOTED comes back as a truncated status string, not a count, when nothing was allotted. */
    public function test_a_non_allotment_is_reported_as_zero_shares(): void
    {
        $this->fakeCaptcha();
        $this->fakeSolver('123456');
        Http::fake(['ipo.bigshareonline.com/Data.aspx/FetchIpodetails' => $this->lookupResponse([
            'Status' => 'OK',
            'Message' => '',
            'MatchCount' => 1,
            'Records' => [[
                'APPLICATION_NO' => '920688397',
                'APPLIED' => '182',
                'ALLOTED' => 'NON-ALLOTTE',
            ]],
        ])]);

        $result = $this->checker()->check('9047', 'CEAPV6462B');

        $this->assertSame(182, $result->sharesApplied);
        $this->assertSame(0, $result->sharesAllotted);
        $this->assertFalse($result->isAllotted());
    }

    public function test_no_match_returns_null(): void
    {
        $this->fakeCaptcha();
        $this->fakeSolver('123456');
        Http::fake(['ipo.bigshareonline.com/Data.aspx/FetchIpodetails' => $this->lookupResponse([
            'Status' => 'OK',
            'Message' => '',
            'MatchCount' => 0,
            'Records' => [],
        ])]);

        $this->assertNull($this->checker()->check('9047', 'CEAPV6462B'));
    }

    public function test_a_wrong_captcha_guess_is_retried_with_a_fresh_one(): void
    {
        $this->fakeCaptcha();
        $this->fakeSolver('111111');

        $calls = 0;
        Http::fake(['ipo.bigshareonline.com/Data.aspx/FetchIpodetails' => function () use (&$calls) {
            $calls++;

            return $calls === 1
                ? $this->lookupResponse(['Status' => 'FAIL', 'Message' => 'Invalid Captcha', 'MatchCount' => 0, 'Records' => []])
                : $this->lookupResponse(['Status' => 'OK', 'Message' => '', 'MatchCount' => 0, 'Records' => []]);
        }]);

        $this->assertNull($this->checker()->check('9047', 'CEAPV6462B'));
        $this->assertSame(2, $calls);
    }

    public function test_giving_up_after_repeated_wrong_captchas_throws(): void
    {
        $this->fakeCaptcha();
        $this->fakeSolver('111111');
        Http::fake(['ipo.bigshareonline.com/Data.aspx/FetchIpodetails' => $this->lookupResponse([
            'Status' => 'FAIL', 'Message' => 'Invalid Captcha', 'MatchCount' => 0, 'Records' => [],
        ])]);

        $this->expectException(AllotmentCheckException::class);
        $this->expectExceptionMessage('Invalid Captcha');

        $this->checker()->check('9047', 'CEAPV6462B');
    }

    public function test_a_non_captcha_error_is_reported_immediately(): void
    {
        $this->fakeCaptcha();
        $this->fakeSolver('123456');

        $calls = 0;
        Http::fake(['ipo.bigshareonline.com/Data.aspx/FetchIpodetails' => function () use (&$calls) {
            $calls++;

            return $this->lookupResponse(['Status' => 'FAIL', 'Message' => 'Company not found', 'MatchCount' => 0, 'Records' => []]);
        }]);

        $this->expectException(AllotmentCheckException::class);
        $this->expectExceptionMessage('Company not found');

        try {
            $this->checker()->check('9047', 'CEAPV6462B');
        } finally {
            $this->assertSame(1, $calls);
        }
    }

    public function test_ocr_returning_nothing_retries_with_a_fresh_captcha(): void
    {
        $this->fakeCaptcha();
        $this->fakeSolver(null);

        try {
            $this->checker()->check('9047', 'CEAPV6462B');
            $this->fail('Expected an AllotmentCheckException.');
        } catch (AllotmentCheckException $e) {
            $this->assertStringContainsString('could not solve the captcha', $e->getMessage());
        }

        Http::assertSentCount(4); // captcha fetched fresh each of the 4 attempts, lookup never reached
    }

    public function test_a_captcha_fetch_failure_is_reported(): void
    {
        Http::fake(['ipo.bigshareonline.com/Captcha.ashx*' => Http::response('down', 503)]);
        $this->fakeSolver('123456');

        $this->expectException(AllotmentCheckException::class);
        $this->expectExceptionMessage('HTTP 503');

        $this->checker()->check('9047', 'CEAPV6462B');
    }
}
