<?php

namespace App\Services\AllotmentCheck;

/**
 * Reads the digits out of a registrar's login-free "prove you're not a bot"
 * captcha image, so a checker that sits behind one (Bigshare) can complete its
 * lookup unattended, the same way MUFG's captcha-free flow always could.
 */
interface CaptchaSolver
{
    /** @param string $imageContents raw image bytes (already base64-decoded). Returns null when nothing could be read. */
    public function solve(string $imageContents): ?string;
}
