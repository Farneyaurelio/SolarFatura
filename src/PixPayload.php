<?php

declare(strict_types=1);

namespace SolarFatura;

/** Builds a static BR Code (Pix) payload with a fixed amount. */
final class PixPayload
{
    public static function build(string $key, float $amount, string $merchantName, string $merchantCity): ?string
    {
        $key = self::normalizeKey($key);
        if ($key === null || $key === '' || $amount <= 0) {
            return null;
        }

        $merchantName = self::text($merchantName, 25, 'SOLARFATURA');
        $merchantCity = self::text($merchantCity, 15, 'BRASIL');
        $account = self::field('00', 'BR.GOV.BCB.PIX') . self::field('01', $key);
        $amountText = number_format($amount, 2, '.', '');

        $payload = self::field('00', '01')
            . self::field('26', $account)
            . self::field('52', '0000')
            . self::field('53', '986')
            . self::field('54', $amountText)
            . self::field('58', 'BR')
            . self::field('59', $merchantName)
            . self::field('60', $merchantCity)
            . self::field('62', self::field('05', '***'))
            . '6304';

        return $payload . strtoupper(str_pad(dechex(self::crc16($payload)), 4, '0', STR_PAD_LEFT));
    }

    /** Returns the key in the exact BR Code format, or null when unsupported. */
    public static function normalizeKey(string $key): ?string
    {
        $key = trim($key);
        if ($key === '') {
            return '';
        }
        if (filter_var($key, FILTER_VALIDATE_EMAIL)) {
            return $key;
        }
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $key)) {
            return strtolower($key);
        }

        $digits = preg_replace('/\D/', '', $key) ?? '';
        if (str_starts_with($key, '+') && preg_match('/^55\d{10,11}$/', $digits)) {
            return '+' . $digits;
        }
        if (preg_match('/^55\d{10,11}$/', $digits)) {
            return '+' . $digits;
        }
        if (preg_match('/^\d{10}$/', $digits) || (preg_match('/^\d{11}$/', $digits) && !self::validCpf($digits))) {
            return '+55' . $digits;
        }
        if (self::validCpf($digits) || self::validCnpj($digits)) {
            return $digits;
        }
        if (preg_match('/^[A-Za-z0-9]{14}$/', $key) && preg_match('/[A-Za-z]/', $key)) {
            return strtoupper($key);
        }
        return null;
    }

    private static function validCpf(string $value): bool
    {
        if (!preg_match('/^\d{11}$/', $value) || preg_match('/^(\d)\1{10}$/', $value)) {
            return false;
        }
        for ($position = 9; $position <= 10; $position++) {
            $sum = 0;
            for ($index = 0; $index < $position; $index++) {
                $sum += (int) $value[$index] * (($position + 1) - $index);
            }
            $digit = ($sum * 10) % 11;
            if ($digit === 10) { $digit = 0; }
            if ($digit !== (int) $value[$position]) { return false; }
        }
        return true;
    }

    private static function validCnpj(string $value): bool
    {
        if (!preg_match('/^\d{14}$/', $value) || preg_match('/^(\d)\1{13}$/', $value)) {
            return false;
        }
        $weights = [[5,4,3,2,9,8,7,6,5,4,3,2], [6,5,4,3,2,9,8,7,6,5,4,3,2]];
        foreach ($weights as $check => $weight) {
            $sum = 0;
            foreach ($weight as $index => $factor) { $sum += (int) $value[$index] * $factor; }
            $digit = $sum % 11 < 2 ? 0 : 11 - ($sum % 11);
            if ($digit !== (int) $value[12 + $check]) { return false; }
        }
        return true;
    }

    private static function field(string $id, string $value): string
    {
        return $id . str_pad((string) strlen($value), 2, '0', STR_PAD_LEFT) . $value;
    }

    private static function text(string $value, int $limit, string $fallback): string
    {
        $ascii = strtoupper(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', trim($value)) ?: '');
        $ascii = preg_replace('/[^A-Z0-9 ]/', '', $ascii) ?? '';
        $ascii = trim(preg_replace('/\s+/', ' ', $ascii) ?? '');
        return substr($ascii !== '' ? $ascii : $fallback, 0, $limit);
    }

    private static function crc16(string $value): int
    {
        $crc = 0xFFFF;
        for ($i = 0, $length = strlen($value); $i < $length; $i++) {
            $crc ^= ord($value[$i]) << 8;
            for ($bit = 0; $bit < 8; $bit++) {
                $crc = ($crc & 0x8000) ? (($crc << 1) ^ 0x1021) : ($crc << 1);
                $crc &= 0xFFFF;
            }
        }
        return $crc;
    }
}
