<?php

namespace App\Tests\Service;

use App\Service\DeviceNameResolver;
use PHPUnit\Framework\TestCase;

class DeviceNameResolverTest extends TestCase
{
    private DeviceNameResolver $resolver;

    protected function setUp(): void
    {
        $this->resolver = new DeviceNameResolver();
    }

    public function testChromeOnMac(): void
    {
        $userAgent = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

        $this->assertSame('Chrome sur Mac', $this->resolver->resolve($userAgent, ['internal']));
    }

    public function testSafariOnIphone(): void
    {
        $userAgent = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

        $this->assertSame('Safari sur iPhone', $this->resolver->resolve($userAgent, ['internal']));
    }

    public function testChromeOnAndroid(): void
    {
        $userAgent = 'Mozilla/5.0 (Linux; Android 14; SM-A105F) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/125.0.0.0 Mobile Safari/537.36';

        $this->assertSame('Chrome sur Android', $this->resolver->resolve($userAgent, ['internal']));
    }

    public function testFirefoxOnWindows(): void
    {
        $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:126.0) Gecko/20100101 Firefox/126.0';

        $this->assertSame('Firefox sur Windows', $this->resolver->resolve($userAgent, ['internal']));
    }

    public function testHybridTransportWithoutUserAgentFallsBackToPhone(): void
    {
        $this->assertSame('Téléphone (via QR code)', $this->resolver->resolve(null, ['hybrid']));
    }

    public function testUsbTransportWithoutUserAgentFallsBackToSecurityKey(): void
    {
        $this->assertSame('Clé de sécurité', $this->resolver->resolve(null, ['usb', 'nfc']));
    }

    public function testUnknownEverythingFallsBackToGenericName(): void
    {
        $this->assertSame('Appareil', $this->resolver->resolve(null, []));
        $this->assertSame('Appareil', $this->resolver->resolve('curl/8.0', []));
    }
}
