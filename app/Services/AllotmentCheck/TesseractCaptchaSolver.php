<?php

namespace App\Services\AllotmentCheck;

use Illuminate\Support\Facades\Process;

/**
 * Solves a numeric captcha image with the Tesseract OCR binary. Bigshare's
 * captchas are 6 solid-coloured digits, tight against the image edges, with
 * a couple of thin diagonal lines drawn through them as noise.
 *
 * Two things turned out to matter, found by testing against real captures
 * (see BigshareChecker's test coverage — none of this is guessable from the
 * image alone): tesseract needs white padding around the digits (it reads
 * badly when they touch the edge), and `--psm 13` ("raw line", skipping
 * tesseract's own layout/script-detection heuristics) reads every sample
 * correctly where the default `--psm 7` line mode missed roughly half —
 * those heuristics apparently misfire on the mix of colours and thin noise
 * lines. No thresholding/binarizing needed once those two are in place;
 * keeping the original colours reads better than forcing black-on-white.
 *
 * Requires the `tesseract` binary on PATH (or TESSERACT_BINARY in .env) and
 * the GD extension. Neither is guaranteed to be present, so a missing one
 * surfaces as an AllotmentCheckException rather than a silent wrong answer.
 */
class TesseractCaptchaSolver implements CaptchaSolver
{
    public function __construct(private readonly string $binary = 'tesseract') {}

    public function solve(string $imageContents): ?string
    {
        if (! extension_loaded('gd')) {
            throw new AllotmentCheckException('Bigshare: the GD extension is required to read captcha images.');
        }

        $image = @imagecreatefromstring($imageContents);

        if (! $image) {
            throw new AllotmentCheckException('Bigshare: could not decode the captcha image.');
        }

        $path = tempnam(sys_get_temp_dir(), 'bigshare_captcha_').'.png';

        try {
            imagepng($this->preprocess($image), $path);
            imagedestroy($image);

            $result = Process::timeout(10)->run([
                $this->binary, $path, 'stdout',
                '--psm', '13', // raw line — bypasses layout heuristics that misread this captcha's colours/noise
                '-c', 'tessedit_char_whitelist=0123456789',
            ]);

            if ($result->failed()) {
                throw new AllotmentCheckException(
                    "Bigshare: tesseract OCR failed — is it installed? ({$result->errorOutput()})"
                );
            }

            $digits = preg_replace('/\D+/', '', $result->output());

            return $digits !== '' ? $digits : null;
        } finally {
            @unlink($path);
        }
    }

    /** Upscale and surround with a white margin — tesseract reads badly when characters touch the image edge. */
    private function preprocess(\GdImage $image): \GdImage
    {
        $scale = 3;
        $pad = 20;
        $width = imagesx($image);
        $height = imagesy($image);

        $scaled = imagecreatetruecolor($width * $scale, $height * $scale);
        imagecopyresampled($scaled, $image, 0, 0, 0, 0, $width * $scale, $height * $scale, $width, $height);

        $padded = imagecreatetruecolor(imagesx($scaled) + $pad * 2, imagesy($scaled) + $pad * 2);
        imagefill($padded, 0, 0, imagecolorallocate($padded, 255, 255, 255));
        imagecopy($padded, $scaled, $pad, $pad, 0, 0, imagesx($scaled), imagesy($scaled));
        imagedestroy($scaled);

        return $padded;
    }
}
