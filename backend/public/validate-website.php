<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use App\helpers\Http;
use App\helpers\Logger;
use App\helpers\Validator;
use App\config\Config;
use App\services\WhmcsService;

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if (!$error) {
        return;
    }
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR];
    if (!in_array($error['type'], $fatalTypes, true)) {
        return;
    }
    Logger::error('Website validation fatal error', $error);
    if (!headers_sent()) {
        Http::json(['success' => false, 'message' => 'Fatal error during website validation.'], 500);
    }
});
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    Logger::error('Website validation rejected: invalid method', [
        'method' => $_SERVER['REQUEST_METHOD'] ?? 'UNKNOWN',
    ]);
    Http::json(['success' => false, 'message' => 'Method not allowed'], 405);
    exit;
}

$payload = Http::body();
$websiteUrl = trim((string) ($payload['website_url'] ?? ''));
$domain = Validator::normalizeDomainInput($websiteUrl);
$traceId = 'wv-' . bin2hex(random_bytes(6));

if (!Validator::isValidDomainInput($websiteUrl)) {
    Logger::error('Website validation failed: invalid website input', [
        'trace_id' => $traceId,
        'website_url' => $websiteUrl,
    ]);
    Http::json(['success' => false, 'message' => 'Please enter a valid website URL or domain.'], 422);
    exit;
}

try {
    $env = strtolower((string) Config::get('APP_ENV', 'production'));
    $bypassWhmcs = in_array($env, ['local', 'development', 'dev', 'testing'], true)
        && Config::getBool('WHMCS_BYPASS_LOCAL', false);
    if ($bypassWhmcs) {
        Http::json(['success' => true, 'message' => 'Website validated (local bypass).', 'domain' => $domain, 'bypassed' => true]);
        exit;
    }

    $whmcsService = new WhmcsService();
    $client = $whmcsService->safeFindClientByDomain($domain);
    if (!$client) {
        if (!$whmcsService->wasLastLookupNotFound()) {
            Logger::error('Website validation failed: WHMCS lookup returned no client', [
                'trace_id' => $traceId,
                'domain' => $domain,
            ]);
        }
        Http::json(['success' => false, 'message' => "We couldn't find your website. Please contact our support team."], 404);
        exit;
    }
    Http::json([
        'success' => true,
        'message' => 'Website validated.',
        'domain' => $domain,
        'client' => [
            'name' => $client['name'] ?? '',
            'email' => $client['email'] ?? '',
            'domains' => $client['domains'] ?? [$domain],
        ],
    ]);
} catch (\Throwable $exception) {
    Logger::error('Website validation endpoint failed', [
        'trace_id' => $traceId,
        'error' => $exception->getMessage(),
        'domain' => $domain,
    ]);
    Http::json(['success' => false, 'message' => 'Something went wrong while validating your website.'], 500);
}
