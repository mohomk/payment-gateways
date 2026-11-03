<?php

namespace Moho\PaymentGateways\Casys;

/**
 * cPay checksum: a header listing the signed field names and their lengths,
 * then HMAC-SHA256 (current API) or MD5 (old API) over header + values.
 */
final class Checksum
{
    /**
     * @param  array<string, string>  $params  signed fields in order, empty values already removed
     * @return array{header: string, checksum: string}
     */
    public static function make(array $params, string $key, bool $oldApi = false): array
    {
        $lengths = '';
        foreach ($params as $value) {
            $lengths .= str_pad((string) mb_strlen($value, 'UTF-8'), 3, '0', STR_PAD_LEFT);
        }

        $header = str_pad((string) count($params), 2, '0', STR_PAD_LEFT) . implode(',', array_keys($params)) . ',' . $lengths;
        $input = $header . implode('', $params);

        return [
            'header' => $header,
            'checksum' => $oldApi ? md5($input . $key) : hash_hmac('sha256', $input, $key),
        ];
    }

    /**
     * Checks cPay's ReturnCheckSum, recomputed over the fields named in ReturnCheckSumHeader.
     *
     * @param  array<string, mixed>  $callback
     */
    public static function verifyReturn(array $callback, string $key, bool $oldApi = false): bool
    {
        $header = $callback['ReturnCheckSumHeader'] ?? null;
        $checksum = $callback['ReturnCheckSum'] ?? null;
        if (!is_string($header) || !is_string($checksum) || $header === '' || $checksum === '') {
            return false;
        }

        // "NN" count, then names, then a final comma-separated block of lengths.
        $names = explode(',', substr($header, 2));
        array_pop($names);

        $params = [];
        foreach ($names as $name) {
            $value = (string) ($callback[$name] ?? '');
            if ($value !== '') {
                $params[$name] = $value;
            }
        }

        return hash_equals(strtolower(self::make($params, $key, $oldApi)['checksum']), strtolower($checksum));
    }
}
