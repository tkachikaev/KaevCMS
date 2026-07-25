<?php

namespace Tests\Unit;

use App\Services\GameAccounts\MobiusPasswordEncoder;
use PHPUnit\Framework\TestCase;

class MobiusPasswordEncoderTest extends TestCase
{
    public function test_it_encodes_password_as_base64_sha1_for_mobius(): void
    {
        $this->assertSame(
            'fEqNCco3Yq9h5ZUglD3CZJT4lBs=',
            (new MobiusPasswordEncoder)->encode('123456'),
        );
    }
}
