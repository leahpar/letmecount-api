<?php

namespace App\Service;

/**
 * Déduit un nom lisible pour un passkey à partir du User-Agent envoyé lors de
 * l'enregistrement, avec repli sur le type de transport WebAuthn quand le
 * User-Agent n'apporte rien (ex: clé de sécurité, appareil non reconnu).
 */
class DeviceNameResolver
{
    /**
     * @param array<string> $transports
     */
    public function resolve(?string $userAgent, array $transports): string
    {
        $device = $this->guessDevice($userAgent);
        $browser = $this->guessBrowser($userAgent);

        if ($device && $browser) {
            return "$browser sur $device";
        }

        if ($device) {
            return $device;
        }

        if (in_array('hybrid', $transports, true)) {
            return 'Téléphone (via QR code)';
        }

        if (array_intersect(['usb', 'nfc', 'ble'], $transports)) {
            return 'Clé de sécurité';
        }

        return 'Appareil';
    }

    private function guessDevice(?string $userAgent): ?string
    {
        if (!$userAgent) {
            return null;
        }

        return match (true) {
            str_contains($userAgent, 'iPhone') => 'iPhone',
            str_contains($userAgent, 'iPad') => 'iPad',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'Macintosh') => 'Mac',
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Linux') => 'Linux',
            default => null,
        };
    }

    private function guessBrowser(?string $userAgent): ?string
    {
        if (!$userAgent) {
            return null;
        }

        return match (true) {
            str_contains($userAgent, 'Edg/') => 'Edge',
            str_contains($userAgent, 'OPR/') => 'Opera',
            str_contains($userAgent, 'CriOS/') => 'Chrome',
            str_contains($userAgent, 'FxiOS/') => 'Firefox',
            str_contains($userAgent, 'Chrome/') => 'Chrome',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Safari/') => 'Safari',
            default => null,
        };
    }
}
