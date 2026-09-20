<?php

namespace Tests\Feature\Services;

use App\Services\TrackingQrCode;
use Tests\TestCase;

class TrackingQrCodeTest extends TestCase
{
    private function decodedImage(string $dataUri): string
    {
        $this->assertStringStartsWith('data:image/png;base64,', $dataUri);

        return base64_decode(substr($dataUri, strlen('data:image/png;base64,')), true);
    }

    public function test_it_makes_a_real_square_png_ready_for_an_img_tag(): void
    {
        $png = $this->decodedImage(app(TrackingQrCode::class)->dataUri('https://careflow.test/t/abcdefghjkmnpq'));

        $this->assertStringStartsWith("\x89PNG", $png);
        [$width, $height] = getimagesizefromstring($png);
        $this->assertSame($width, $height);
        $this->assertGreaterThanOrEqual(300, $width);
    }

    public function test_the_same_link_gives_the_same_code_and_a_different_link_a_different_one(): void
    {
        $qr = app(TrackingQrCode::class);

        $this->assertSame($qr->dataUri('https://careflow.test/t/aaaaaaaaaaaaaa'), $qr->dataUri('https://careflow.test/t/aaaaaaaaaaaaaa'));
        $this->assertNotSame($qr->dataUri('https://careflow.test/t/aaaaaaaaaaaaaa'), $qr->dataUri('https://careflow.test/t/bbbbbbbbbbbbbb'));
    }

    public function test_a_long_tunnelled_link_still_fits_in_a_code(): void
    {
        $url = 'https://a1b2-102-215-77-14-extra-long-subdomain.ngrok-free.app/t/abcdefghjkmnpq';

        $this->assertNotEmpty($this->decodedImage(app(TrackingQrCode::class)->dataUri($url)));
    }
}
