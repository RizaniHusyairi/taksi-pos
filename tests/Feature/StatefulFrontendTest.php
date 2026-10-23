<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Panel admin memanggil API dengan cookie sesi. Browser yang tidak mengirim
 * Referer harus tetap dikenali lewat Sec-Fetch-Site; request lintas situs
 * tidak boleh ikut dianggap stateful.
 */
class StatefulFrontendTest extends TestCase
{
    private function req(array $headers): Request
    {
        $r = Request::create('http://127.0.0.1:8000/api/admin/users', 'GET');
        foreach ($headers as $k => $v) {
            $r->headers->set($k, $v);
        }

        return $r;
    }

    public function test_referer_terdaftar_tetap_dikenali(): void
    {
        $this->assertTrue(EnsureFrontendRequestsAreStateful::fromFrontend(
            $this->req(['Referer' => 'http://127.0.0.1:8000/admin'])
        ));
    }

    public function test_tanpa_referer_dikenali_lewat_sec_fetch_site(): void
    {
        $this->assertTrue(EnsureFrontendRequestsAreStateful::fromFrontend(
            $this->req(['Sec-Fetch-Site' => 'same-origin'])
        ));
    }

    public function test_lintas_situs_tidak_dikenali(): void
    {
        $this->assertFalse(EnsureFrontendRequestsAreStateful::fromFrontend(
            $this->req(['Sec-Fetch-Site' => 'cross-site'])
        ));
        $this->assertFalse(EnsureFrontendRequestsAreStateful::fromFrontend($this->req([])));
    }
}
