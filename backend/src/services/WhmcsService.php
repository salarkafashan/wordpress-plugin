<?php

declare(strict_types=1);

namespace App\services;

use App\config\Config;
use App\helpers\Logger;
use App\helpers\Validator;
use App\models\ClientCacheModel;
use RuntimeException;
use Throwable;

final class WhmcsService
{
    private const LOOKUP_SERVICE_BY_URL = '/internal-api/v1/lookup/service-by-url';
    private const LOOKUP_USER_BY_EMAIL = '/internal-api/v1/lookup/user-by-email';

    private ClientCacheModel $cacheModel;
    private bool $lastLookupNotFound = false;

    public function __construct()
    {
        $this->cacheModel = new ClientCacheModel();
    }

    public function findClientByEmail(string $email): ?array
    {
        $email = strtolower(trim($email));
        if (!Validator::email($email)) {
            Logger::error('WHMCS email lookup skipped: invalid email format', ['email' => $email]);
            return null;
        }


        $response = $this->callLookup(self::LOOKUP_USER_BY_EMAIL, ['email' => $email]);
        if (empty($response['success']) || !isset($response['data']) || !is_array($response['data'])) {
            Logger::error('WHMCS email lookup returned no usable data', ['email' => $email]);
            return null;
        }

        $data = $response['data'];
        $domains = [];
        $services = [];

        foreach (($data['services'] ?? []) as $service) {
            if (!is_array($service)) {
                continue;
            }
            $normalizedDomain = Validator::normalizeDomainInput((string) ($service['domain'] ?? ''));
            if ($normalizedDomain === '') {
                continue;
            }
            $domains[] = $normalizedDomain;
            $services[] = [
                'service_id' => (int) ($service['service_id'] ?? 0),
                'domain' => $normalizedDomain,
                'status' => (string) ($service['status'] ?? ''),
                'package_id' => (int) ($service['package_id'] ?? 0),
            ];
        }

        $client = [
            'whmcs_client_id' => (int) ($data['user_id'] ?? 0),
            'name' => trim(((string) ($data['first_name'] ?? '')) . ' ' . ((string) ($data['last_name'] ?? ''))),
            'email' => strtolower((string) ($data['email'] ?? $email)),
            'domains' => array_values(array_unique($domains)),
            'services' => $services,
            'status' => (string) ($data['status'] ?? ''),
            'company' => (string) ($data['company'] ?? ''),
        ];

        $this->cacheClient($client);
        return $client;
    }

    public function findClientByDomain(string $domain): ?array
    {
        $this->lastLookupNotFound = false;
        $normalizedDomain = Validator::normalizeDomainInput($domain);
        if ($normalizedDomain === '') {
            Logger::error('WHMCS domain lookup skipped: empty normalized domain', ['domain_input' => $domain]);
            return null;
        }


        $response = $this->callLookup(self::LOOKUP_SERVICE_BY_URL, ['url' => $normalizedDomain]);
        if (empty($response['success']) || !isset($response['data']) || !is_array($response['data'])) {
            if (!empty($response['_not_found'])) {
                $this->lastLookupNotFound = true;
                return null;
            }
            Logger::error('WHMCS domain lookup returned no usable data', ['normalized_domain' => $normalizedDomain]);
            return null;
        }

        $data = $response['data'];
        $clientData = is_array($data['client'] ?? null) ? $data['client'] : [];
        $serviceDomain = Validator::normalizeDomainInput((string) ($data['domain'] ?? $normalizedDomain));
        $contactEmails = $this->extractContactEmails($data['contacts'] ?? []);
        $ownerEmail = strtolower((string) ($clientData['email'] ?? ''));
        $authorizedEmails = array_values(array_unique(array_filter(array_merge(
            $ownerEmail !== '' ? [$ownerEmail] : [],
            $contactEmails
        ))));

        $client = [
            'whmcs_client_id' => (int) ($data['user_id'] ?? 0),
            'name' => trim(((string) ($clientData['first_name'] ?? '')) . ' ' . ((string) ($clientData['last_name'] ?? ''))),
            'email' => $ownerEmail,
            'owner_email' => $ownerEmail,
            'contact_emails' => $contactEmails,
            'authorized_emails' => $authorizedEmails,
            'domains' => $serviceDomain !== '' ? [$serviceDomain] : [$normalizedDomain],
            'services' => [
                [
                    'service_id' => (int) ($data['service_id'] ?? 0),
                    'domain' => $serviceDomain !== '' ? $serviceDomain : $normalizedDomain,
                    'status' => (string) ($data['status'] ?? ''),
                    'package_id' => (int) ($data['package_id'] ?? 0),
                ]
            ],
            'status' => (string) ($data['status'] ?? ''),
            'company' => (string) ($clientData['company'] ?? ''),
        ];

        $this->cacheClient($client);
        return $client;
    }

    /**
     * Normalize contact structures returned by WHMCS and extract valid emails.
     *
     * @param mixed $contactsRaw
     * @return array<int, string>
     */
    private function extractContactEmails($contactsRaw): array
    {
        $emails = [];

        if (is_array($contactsRaw)) {
            foreach ($contactsRaw as $entry) {
                if (is_array($entry) && isset($entry['email'])) {
                    $email = strtolower(trim((string) $entry['email']));
                    if (Validator::email($email)) {
                        $emails[] = $email;
                    }
                    continue;
                }
                if (is_string($entry)) {
                    $email = strtolower(trim($entry));
                    if (Validator::email($email)) {
                        $emails[] = $email;
                    }
                }
            }
        }

        return array_values(array_unique($emails));
    }

    public function safeFindClientByEmail(string $email): ?array
    {
        try {
            return $this->findClientByEmail($email);
        } catch (Throwable $exception) {
            Logger::error('WHMCS API lookup by email failed', ['error' => $exception->getMessage()]);
            return null;
        }
    }

    public function safeFindClientByDomain(string $domain): ?array
    {
        try {
            return $this->findClientByDomain($domain);
        } catch (Throwable $exception) {
            $this->lastLookupNotFound = false;
            Logger::error('WHMCS API lookup by domain failed', ['error' => $exception->getMessage()]);
            return null;
        }
    }

    public function wasLastLookupNotFound(): bool
    {
        return $this->lastLookupNotFound;
    }

    private function cacheClient(array $client): void
    {
        if (($client['whmcs_client_id'] ?? 0) <= 0 || empty($client['email'])) {
            return;
        }

        $cacheId = $this->cacheModel->upsertClient([
            'whmcs_client_id' => (int) $client['whmcs_client_id'],
            'email' => (string) $client['email'],
            'first_name' => $this->extractFirstName((string) ($client['name'] ?? '')),
            'last_name' => $this->extractLastName((string) ($client['name'] ?? '')),
        ]);
        $this->cacheModel->replaceDomains($cacheId, $client['domains'] ?? []);
    }

    private function extractFirstName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        return (string) ($parts[0] ?? '');
    }

    private function extractLastName(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        if (count($parts) <= 1) {
            return '';
        }
        array_shift($parts);
        return implode(' ', $parts);
    }

    private function callLookup(string $path, array $payload): ?array
    {
        $baseUrl = rtrim((string) Config::getWhmcsValue('WHMCS_API_BASE_URL', 'https://kgr360.com'), '/');
        $apiKey = trim((string) Config::getWhmcsValue('WHMCS_API_KEY', 'site-a'));
        $secret = trim((string) Config::getWhmcsValue('WHMCS_API_TOKEN', ''));

        if ($secret === '' || $apiKey === '') {
            throw new RuntimeException('WHMCS API credentials are not configured.');
        }

        $rawJsonBody = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $payloadToSign = $apiKey . '.' . $timestamp . '.' . $nonce . '.' . $rawJsonBody;
        $signature = hash_hmac('sha256', $payloadToSign, $secret);
        $url = $baseUrl . $path;


        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'X-API-KEY: ' . $apiKey,
            'X-TIMESTAMP: ' . $timestamp,
            'X-NONCE: ' . $nonce,
            'X-SIGNATURE: ' . $signature,
            'Content-Length: ' . strlen($rawJsonBody),
        ];

        $result = $this->sendJsonRequest($url, $headers, $rawJsonBody);
        return $result;
    }

    private function sendJsonRequest(string $url, array $headers, string $rawJsonBody): ?array
    {
        $timeout = max(3, (int) Config::get('WHMCS_API_TIMEOUT', 12));
        $connectTimeout = max(2, (int) Config::get('WHMCS_API_CONNECT_TIMEOUT', 5));

        if (function_exists('curl_init')) {

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $rawJsonBody,
            ]);

            $responseBody = curl_exec($ch);
            if ($responseBody === false) {
                $error = curl_error($ch);
                curl_close($ch);
                Logger::error('WHMCS API request failed (curl transport)', ['url' => $url, 'error' => $error]);
                throw new RuntimeException('WHMCS API request failed: ' . $error);
            }

            $statusCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);


            $decoded = json_decode((string) $responseBody, true);
            if (!is_array($decoded)) {
                Logger::error('WHMCS API invalid JSON response (curl)', ['url' => $url, 'status_code' => $statusCode]);
                throw new RuntimeException('WHMCS API returned invalid JSON. HTTP ' . $statusCode);
            }

            if ($statusCode >= 400) {
                if ($this->isNotFoundResponse($statusCode, $decoded)) {
                    return ['success' => false, '_not_found' => true, 'message' => $decoded['message'] ?? null];
                }

                Logger::info('WHMCS API response (curl)', [
                    'url' => $url,
                    'status_code' => $statusCode,
                    'response' => $decoded,
                ]);
                Logger::error('WHMCS API error status (curl)', [
                    'url' => $url,
                    'status_code' => $statusCode,
                    'response_message' => $decoded['message'] ?? null,
                ]);
                return null;
            }

            Logger::info('WHMCS API response (curl)', [
                'url' => $url,
                'status_code' => $statusCode,
                'response' => $decoded,
            ]);

            return $decoded;
        }


        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $rawJsonBody,
                'ignore_errors' => true,
                'timeout' => $timeout,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $context);
        if ($responseBody === false) {
            Logger::error('WHMCS API request failed (stream transport)', ['url' => $url]);
            throw new RuntimeException('WHMCS API request failed using stream context.');
        }

        $statusCode = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', (string) $http_response_header[0], $matches)) {
            $statusCode = (int) $matches[1];
        }


        $decoded = json_decode((string) $responseBody, true);
        if (!is_array($decoded)) {
            Logger::error('WHMCS API invalid JSON response (stream)', ['url' => $url, 'status_code' => $statusCode]);
            throw new RuntimeException('WHMCS API returned invalid JSON. HTTP ' . $statusCode);
        }

        if ($statusCode >= 400) {
            if ($this->isNotFoundResponse($statusCode, $decoded)) {
                return ['success' => false, '_not_found' => true, 'message' => $decoded['message'] ?? null];
            }

            Logger::info('WHMCS API response (stream)', [
                'url' => $url,
                'status_code' => $statusCode,
                'response' => $decoded,
            ]);
            Logger::error('WHMCS API error status (stream)', [
                'url' => $url,
                'status_code' => $statusCode,
                'response_message' => $decoded['message'] ?? null,
            ]);
            return null;
        }

        Logger::info('WHMCS API response (stream)', [
            'url' => $url,
            'status_code' => $statusCode,
            'response' => $decoded,
        ]);

        return $decoded;
    }

    private function isNotFoundResponse(int $statusCode, array $decoded): bool
    {
        if ($statusCode !== 404) {
            return false;
        }

        $message = strtolower(trim((string) ($decoded['message'] ?? '')));
        return $message === '' || strpos($message, 'not found') !== false;
    }
}
