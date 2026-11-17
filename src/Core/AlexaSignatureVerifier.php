<?php

declare(strict_types=1);

namespace Familienkalender\Core;

/** Verifies Amazon's signed Alexa request envelope before application code runs. */
final class AlexaSignatureVerifier implements AlexaRequestVerifier
{
    private const CERT_HOST = 's3.amazonaws.com';
    private const CERT_PATH = '/echo.api/echo-api-cert.pem';

    /** @param callable(string):string|false|null $certificateFetcher Test seam; production uses HTTPS with cURL. */
    public function __construct(private readonly mixed $certificateFetcher = null) {}

    /** @param array<string,string> $headers */
    public function verify(string $rawBody, array $headers, ?int $now = null): bool
    {
        $signature = $headers['signature'] ?? $headers['Signature'] ?? '';
        $url = $headers['signaturecertchainurl'] ?? $headers['SignatureCertChainUrl'] ?? '';
        if ($signature === '' || !$this->validCertificateUrl($url)) return false;
        $payload = json_decode($rawBody, true);
        $timestamp = is_array($payload) ? ($payload['request']['timestamp'] ?? null) : null;
        if (!is_string($timestamp)) return false;
        try { if (abs(($now ?? time()) - (new \DateTimeImmutable($timestamp))->getTimestamp()) > 150) return false; }
        catch (\Throwable) { return false; }
        $pem = $this->fetchCertificate($url);
        if (!is_string($pem) || $pem === '') return false;
        $current = $now ?? time();
        preg_match_all('/-----BEGIN CERTIFICATE-----.*?-----END CERTIFICATE-----/s', $pem, $matches);
        $chain = array_map(static fn(string $item) => @openssl_x509_read($item), $matches[0] ?? []);
        if ($chain === [] || in_array(false, $chain, true)) return false;
        foreach ($chain as $index => $certificate) {
            $parsed = openssl_x509_parse($certificate);
            if ($current < (int) ($parsed['validFrom_time_t'] ?? 0) || $current > (int) ($parsed['validTo_time_t'] ?? 0)) return false;
            if ($index === 0) {
                $san = (string) ($parsed['extensions']['subjectAltName'] ?? '');
                if (preg_match('/(?:^|,\s*)DNS:echo-api\.amazon\.com(?:,|$)/i', $san) !== 1) return false;
            }
            if (isset($chain[$index + 1])) {
                $issuer = openssl_x509_parse($chain[$index + 1]);
                if (($parsed['issuer'] ?? null) !== ($issuer['subject'] ?? null)) return false;
                $issuerKey = @openssl_pkey_get_public($chain[$index + 1]);
                if ($issuerKey === false || @openssl_x509_verify($certificate, $issuerKey) !== 1) return false;
            }
        }
        // The final certificate is the trust anchor and must be present in
        // the machine's trusted CA store; a self-supplied root is not enough.
        $root = $chain[count($chain) - 1];
        if (@openssl_x509_checkpurpose($root, X509_PURPOSE_ANY) !== true) return false;
        $key = @openssl_pkey_get_public($chain[0]);
        $bytes = base64_decode($signature, true);
        return $key !== false && $bytes !== false && @openssl_verify($rawBody, $bytes, $key, OPENSSL_ALGO_SHA1) === 1;
    }

    private function validCertificateUrl(string $url): bool
    {
        $p = parse_url($url);
        return is_array($p) && strtolower((string) ($p['scheme'] ?? '')) === 'https'
            && strtolower((string) ($p['host'] ?? '')) === self::CERT_HOST && ($p['path'] ?? '') === self::CERT_PATH
            && !isset($p['user']) && !isset($p['pass']) && !isset($p['port'])
            && !isset($p['fragment']) && empty($p['query']);
    }

    private function fetchCertificate(string $url): string|false
    {
        if ($this->certificateFetcher !== null) return ($this->certificateFetcher)($url);
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_TIMEOUT => 5, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);
        return $status === 200 && is_string($body) && strlen($body) <= 65536 ? $body : false;
    }
}
