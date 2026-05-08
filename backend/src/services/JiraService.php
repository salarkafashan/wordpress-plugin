<?php

declare(strict_types=1);

namespace App\services;

use App\config\Config;
use App\helpers\Logger;
use RuntimeException;

final class JiraService
{
    private ClientJiraMappingService $mappingService;

    public function __construct()
    {
        $this->mappingService = new ClientJiraMappingService();
    }

    public function createIssue(array $request, array $issues, array $attachments): string
    {
        $url = rtrim((string) Config::getJiraValue('JIRA_BASE_URL', ''), '/') . '/rest/api/3/issue';
        $projectKey = $this->resolveProjectKeyForRequest($request);
        $configuredIssueType = trim((string) Config::getJiraValue('JIRA_ISSUE_TYPE', 'support_ticket'));
        $issueTypeCandidates = array_values(array_unique(array_filter([
            $configuredIssueType,
            'Task',
            'Story',
            'Bug',
        ])));

        $metadata = json_decode((string) ($request['metadata_json'] ?? '{}'), true);
        $metadata = is_array($metadata) ? $metadata : [];
        
        $title = trim((string) ($metadata['title'] ?? ''));
        $websiteDomain = trim((string) ($request['website_domain'] ?? ''));
        $publicId = trim((string) ($request['public_id'] ?? 'Unknown'));

        $summaryParts = [];
        if ($websiteDomain !== '') {
            $summaryParts[] = $websiteDomain;
        }
        if ($title !== '') {
            $summaryParts[] = $title;
        }
        $summaryParts[] = $publicId;

        $summary = implode(' - ', $summaryParts);

        $descriptionAdf = $this->buildDescriptionAdf($request, $issues, $attachments);
        $calculatedPriority = $this->calculatePriority($issues);

        // Get custom fields for Client Name and Team if defined in config
        $teamFieldId = trim((string) Config::getJiraValue('JIRA_CF_TEAM', ''));
        $clientFieldId = trim((string) Config::getJiraValue('JIRA_CF_CLIENT_NAME', ''));

        $lastError = '';
        foreach ($issueTypeCandidates as $issueType) {
            $payload = [
                'fields' => [
                    'project' => ['key' => $projectKey],
                    'summary' => $summary,
                    'description' => $descriptionAdf,
                    'issuetype' => ['name' => $issueType],
                    'priority' => ['name' => $calculatedPriority],
                ],
            ];

            // Add Team custom field if mapped
            if ($teamFieldId !== '') {
                $payload['fields'][$teamFieldId] = 'PM-Dev-Design-Content';
            }

            // Add Client Name custom field if mapped
            if ($clientFieldId !== '') {
                $clientName = !empty($request['client_name']) ? $request['client_name'] : ($request['submitted_email'] ?? 'Unknown');
                $payload['fields'][$clientFieldId] = $clientName;
            }

            try {
                $response = $this->postJson($url, $payload);
                if (!isset($response['key'])) {
                    throw new RuntimeException('Jira issue key missing in response.');
                }
                return (string) $response['key'];
            } catch (RuntimeException $exception) {
                $lastError = $exception->getMessage();
                Logger::error('Jira create issue attempt failed', [
                    'request_id' => (int) ($request['id'] ?? 0),
                    'public_id' => (string) ($request['public_id'] ?? ''),
                    'project_key' => $projectKey,
                    'issue_type_attempted' => $issueType,
                    'configured_issue_type' => $configuredIssueType,
                    'error' => $lastError,
                ]);

                if (!$this->isInvalidIssueTypeError($lastError)) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException(
            'Jira issue creation failed after trying issue types [' . implode(', ', $issueTypeCandidates) . ']. Last error: ' . $lastError
        );
    }

    /**
     * Search Jira projects for admin-side mapping UI.
     * Returns normalized rows that can be shown directly in a typeahead list.
     */
    public function searchProjects(string $query = '', int $maxResults = 50, array $debugContext = []): array
    {
        $baseUrl = rtrim((string) Config::getJiraValue('JIRA_BASE_URL', ''), '/');
        if ($baseUrl === '') {
            throw new RuntimeException('JIRA_BASE_URL is not configured.');
        }
        $resolvedAuth = [
            'user' => (string) Config::getJiraValue('JIRA_API_USER', ''),
            'token' => (string) Config::getJiraValue('JIRA_API_TOKEN', ''),
            'source' => 'resolved',
        ];
        $envAuth = [
            'user' => (string) Config::getEnvValue('JIRA_API_USER', ''),
            'token' => (string) Config::getEnvValue('JIRA_API_TOKEN', ''),
            'source' => 'env',
        ];

        $params = [
            'maxResults' => max(1, min(200, $maxResults)),
        ];
        if (trim($query) !== '') {
            $params['query'] = trim($query);
        }
        $url = $baseUrl . '/rest/api/3/project/search?' . http_build_query($params);
        $response = $this->getJson($url, [], $resolvedAuth);

        $values = $response['values'] ?? [];

        if (!is_array($values)) {
            return [];
        }

        $projects = $this->normalizeProjects($values);
        if ($projects !== []) {
            return $projects;
        }

        $canRetryWithEnv = $this->isAuthUsable($envAuth)
            && ($envAuth['user'] !== $resolvedAuth['user'] || $envAuth['token'] !== $resolvedAuth['token']);
        if ($canRetryWithEnv) {
            $response = $this->getJson($url, [], $envAuth);
            $retryValues = $response['values'] ?? [];
            if (is_array($retryValues)) {
                $retryProjects = $this->normalizeProjects($retryValues);
                if ($retryProjects !== []) {
                    return $retryProjects;
                }
            }
        }

        // Fallback for Jira environments where the search endpoint returns zero results for this account.
        $fallbackUrl = $baseUrl . '/rest/api/3/project';
        $fallbackResponse = $this->getJson($fallbackUrl, [], $resolvedAuth);
        $fallbackValues = is_array($fallbackResponse) ? $fallbackResponse : [];

        return $this->normalizeProjects($fallbackValues);
    }

    /**
     * @param array<int, mixed> $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeProjects(array $rows): array
    {
        $projects = [];
        foreach ($rows as $project) {
            if (!is_array($project)) {
                continue;
            }
            if ($this->isArchivedProject($project)) {
                continue;
            }
            $projectId = (string) ($project['id'] ?? '');
            $projectKey = (string) ($project['key'] ?? '');
            if ($projectId === '' || $projectKey === '') {
                continue;
            }
            $projectCategory = '';
            if (isset($project['projectCategory']) && is_array($project['projectCategory'])) {
                $projectCategory = (string) ($project['projectCategory']['name'] ?? '');
            }
            $projects[] = [
                'jira_project_id' => $projectId,
                'jira_project_key' => $projectKey,
                'jira_project_name' => (string) ($project['name'] ?? ''),
                // Jira Cloud project APIs don't expose a strict "space" field. Category is more useful than type for admin mapping.
                'jira_space_name' => $projectCategory !== '' ? $projectCategory : (string) ($project['projectTypeKey'] ?? ''),
                'jira_board_id' => null,
            ];
        }
        return $projects;
    }

    private function isArchivedProject(array $project): bool
    {
        if (isset($project['archived']) && (bool) $project['archived']) {
            return true;
        }

        $name = strtoupper(trim((string) ($project['name'] ?? '')));
        if (strpos($name, '[CLOSED]') === 0 || strpos($name, '[ARCHIVED]') === 0) {
            return true;
        }

        $categoryName = '';
        if (isset($project['projectCategory']) && is_array($project['projectCategory'])) {
            $categoryName = strtolower(trim((string) ($project['projectCategory']['name'] ?? '')));
        }
        if ($categoryName === 'archived' || $categoryName === 'closed') {
            return true;
        }

        return false;
    }

    private function resolveProjectKeyForRequest(array $request): string
    {
        $routingMode = strtolower(trim((string) Config::getJiraValue('JIRA_ROUTING_MODE', 'support_space')));
        if ($routingMode !== 'client_mapped') {
            $supportProjectKey = trim((string) Config::getJiraValue(
                'JIRA_SUPPORT_SPACE_ID',
                (string) Config::getJiraValue('JIRA_SUPPORT_PROJECT_KEY', (string) Config::getJiraValue('JIRA_PROJECT_KEY', 'KSR'))
            ));
            if ($supportProjectKey === '') {
                throw new RuntimeException('Jira support project key is not configured.');
            }
            return $supportProjectKey;
        }

        $whmcsClientId = (int) ($request['client_whmcs_id'] ?? 0);
        $mapping = $this->mappingService->getMappingByWhmcsClientId($whmcsClientId);

        if (!$mapping || trim((string) ($mapping['jira_project_key'] ?? '')) === '') {
            Logger::error('Jira project mapping lookup failed', [
                'request_id' => (int) ($request['id'] ?? 0),
                'public_id' => (string) ($request['public_id'] ?? ''),
                'client_whmcs_id' => $whmcsClientId,
                'website_domain' => (string) ($request['website_domain'] ?? ''),
            ]);
            throw new RuntimeException('Jira mapping missing for WHMCS client ID: ' . $whmcsClientId);
        }

        return trim((string) $mapping['jira_project_key']);
    }

    private function calculatePriority(array $issues): string
    {
        if ($issues === []) {
            return 'Medium';
        }

        $weights = [
            'critical' => 4,
            'high' => 3,
            'medium' => 2,
            'low' => 1,
            'minor' => 1,
            'minor issue' => 1,
        ];

        $total = 0;
        foreach ($issues as $issue) {
            // Check 'urgency_level' from database
            $urgency = strtolower(trim((string) ($issue['urgency_level'] ?? 'medium')));
            $total += $weights[$urgency] ?? 2;
        }

        $average = $total / count($issues);

        if ($average >= 3) {
            return 'High';
        }
        if ($average > 1.5) { // e.g. 5 issues, 3 high(3), 2 medium(2) -> avg 2.6 -> High (avg >= 2.5 for High, let's adjust logic)
            // But user said: 3 high, 2 medium = High. Wait: (3*3 + 2*2)/5 = 13/5 = 2.6.
            // If average >= 2.5, it's High.
            if ($average >= 2.5) {
                return 'High';
            }
            return 'Medium';
        }
        return 'Low';
    }

    private function buildDescriptionAdf(array $request, array $issues, array $attachments): array
    {
        $attachmentsByIssue = [];
        foreach ($attachments as $attachment) {
            $issueId = (int) ($attachment['issue_id'] ?? 0);
            if ($issueId <= 0) {
                continue;
            }
            if (!isset($attachmentsByIssue[$issueId])) {
                $attachmentsByIssue[$issueId] = [];
            }
            $attachmentsByIssue[$issueId][] = $attachment;
        }

        $content = [];

        $metadata = json_decode((string) ($request['metadata_json'] ?? '{}'), true);
        $metadata = is_array($metadata) ? $metadata : [];

        $content[] = $this->createAdfParagraph([
            ['type' => 'text', 'text' => 'Website: ', 'marks' => [['type' => 'strong']]],
            ['type' => 'text', 'text' => $request['website_domain'] ?? 'Unknown'],
            ['type' => 'hardBreak'],
            ['type' => 'text', 'text' => 'Request ID: ', 'marks' => [['type' => 'strong']]],
            ['type' => 'text', 'text' => $request['public_id'] ?? ''],
            ['type' => 'hardBreak'],
            ['type' => 'text', 'text' => 'Title: ', 'marks' => [['type' => 'strong']]],
            ['type' => 'text', 'text' => $metadata['title'] ?? ''],
            ['type' => 'hardBreak'],
            ['type' => 'text', 'text' => 'Submitted at: ', 'marks' => [['type' => 'strong']]],
            ['type' => 'text', 'text' => $request['created_at'] ?? '']
        ]);

        $content[] = $this->createAdfHeading(3, 'Issues:');

        $baseUrl = rtrim((string) \App\config\Config::get('APP_BASE_URL', ''), '/');

        // Mappings for urgency to ensure it outputs Low, Medium, or High
        $urgencyMap = [
            'critical' => 'High',
            'high' => 'High',
            'medium' => 'Medium',
            'low' => 'Low',
            'minor' => 'Low',
            'minor issue' => 'Low'
        ];

        foreach ($issues as $idx => $issue) {
            $issueId = (int) ($issue['id'] ?? 0);
            
            // Add a little more space between issues (an empty paragraph) if not the first
            if ($idx > 0) {
                $content[] = $this->createAdfParagraph([]);
            }

            $content[] = $this->createAdfHeading(4, 'Issue ' . ($idx + 1) . ':');

            $issueType = $issue['issue_type'] ?? '';
            $isContentChange = stripos($issueType, 'content change') !== false || stripos($issueType, 'content') !== false;
            $isImageReplacement = stripos($issueType, 'image replacement') !== false || stripos($issueType, 'image') !== false;

            $rawUrgency = strtolower(trim((string) ($issue['urgency_level'] ?? 'medium')));
            $mappedUrgency = $urgencyMap[$rawUrgency] ?? 'Medium';

            // Grouping texts in a single paragraph using hardBreak to decrease line height
            $issueTextBlock = [];
            
            // 1. URL
            $issueTextBlock[] = ['type' => 'text', 'text' => 'URL: ', 'marks' => [['type' => 'strong']]];
            $issueTextBlock[] = ['type' => 'text', 'text' => $issue['page_url'] ?? ''];
            $issueTextBlock[] = ['type' => 'hardBreak'];
            
            // 2. Title (Issue Type)
            $issueTextBlock[] = ['type' => 'text', 'text' => 'Title: ', 'marks' => [['type' => 'strong']]];
            $issueTextBlock[] = ['type' => 'text', 'text' => $issueType];
            $issueTextBlock[] = ['type' => 'hardBreak'];
            
            // 3. Urgency
            $issueTextBlock[] = ['type' => 'text', 'text' => 'Urgency: ', 'marks' => [['type' => 'strong']]];
            $issueTextBlock[] = ['type' => 'text', 'text' => $mappedUrgency];
            $issueTextBlock[] = ['type' => 'hardBreak'];
            
            // 4. Description
            $issueTextBlock[] = ['type' => 'text', 'text' => 'Description: ', 'marks' => [['type' => 'strong']]];
            $issueTextBlock[] = ['type' => 'text', 'text' => $issue['description'] ?? ''];

            // "Suggested Type" is removed completely.
            
            // Current Content and New Content logic
            if (!$isContentChange && !$isImageReplacement) {
                if (!empty($issue['current_content']) || !empty($issue['new_content'])) {
                    $issueTextBlock[] = ['type' => 'hardBreak'];
                    $issueTextBlock[] = ['type' => 'text', 'text' => 'Current Content: ', 'marks' => [['type' => 'strong']]];
                    $issueTextBlock[] = ['type' => 'text', 'text' => $issue['current_content'] ?? ''];
                    $issueTextBlock[] = ['type' => 'hardBreak'];
                    $issueTextBlock[] = ['type' => 'text', 'text' => 'New Content: ', 'marks' => [['type' => 'strong']]];
                    $issueTextBlock[] = ['type' => 'text', 'text' => $issue['new_content'] ?? ''];
                }
            }
            
            // 5. Screenshot or Attachment
            $issueAttachments = $attachmentsByIssue[$issueId] ?? [];
            if ($issueAttachments !== []) {
                $issueTextBlock[] = ['type' => 'hardBreak'];
                foreach ($issueAttachments as $attachment) {
                    $label = !empty($attachment['is_screenshot']) ? 'Screenshot' : 'Attachment';
                    
                    $storedName = (string) ($attachment['stored_name'] ?? '');
                    $originalName = (string) ($attachment['original_name'] ?? 'file');

                    $issueTextBlock[] = ['type' => 'text', 'text' => '- ' . $label . ': '];
                    $issueTextBlock[] = ['type' => 'text', 'text' => $storedName . ' (' . $originalName . ')'];
                    $issueTextBlock[] = ['type' => 'hardBreak'];
                }

                // Remove the last hardBreak
                array_pop($issueTextBlock);
            }

            $content[] = $this->createAdfParagraph($issueTextBlock);
        }

        return [
            'type' => 'doc',
            'version' => 1,
            'content' => $content
        ];
    }

    private function createAdfParagraph(array $contentNodes): array
    {
        return [
            'type' => 'paragraph',
            'content' => $contentNodes
        ];
    }

    private function createAdfHeading(int $level, string $text): array
    {
        return [
            'type' => 'heading',
            'attrs' => ['level' => $level],
            'content' => [
                ['type' => 'text', 'text' => $text]
            ]
        ];
    }

    private function postJson(string $url, array $payload): array
    {
        $token = (string) Config::getJiraValue('JIRA_API_TOKEN', '');
        $user = (string) Config::getJiraValue('JIRA_API_USER', '');
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Basic ' . base64_encode($user . ':' . $token),
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_TIMEOUT => 20,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '') {
            throw new RuntimeException('Jira request failed: ' . $error);
        }
        $decoded = json_decode($body, true);
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Jira request failed with HTTP ' . $status . ': ' . (is_array($decoded) ? json_encode($decoded) : $body));
        }
        return is_array($decoded) ? $decoded : [];
    }

    private function getJson(string $url, array $context = [], ?array $auth = null): array
    {
        $token = (string) ($auth['token'] ?? Config::getJiraValue('JIRA_API_TOKEN', ''));
        $user = (string) ($auth['user'] ?? Config::getJiraValue('JIRA_API_USER', ''));
        if ($token === '' || $user === '') {
            throw new RuntimeException('JIRA_API_USER or JIRA_API_TOKEN is not configured.');
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Basic ' . base64_encode($user . ':' . $token),
            ],
            CURLOPT_TIMEOUT => 20,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $error !== '') {
            Logger::error('Jira GET request transport failure', [
                'url' => $url,
                'error' => $error
            ]);
            throw new RuntimeException('Jira request failed: ' . $error);
        }
        $decoded = json_decode($body, true);
        if ($status < 200 || $status >= 300) {
            Logger::error('Jira GET request failed', [
                'url' => $url,
                'status' => $status,
                'response_excerpt' => mb_substr(is_array($decoded) ? json_encode($decoded) : (string) $body, 0, 500)
            ]);
            throw new RuntimeException('Jira request failed with HTTP ' . $status . ': ' . (is_array($decoded) ? json_encode($decoded) : $body));
        }
        return is_array($decoded) ? $decoded : [];
    }

    private function isAuthUsable(array $auth): bool
    {
        return trim((string) ($auth['user'] ?? '')) !== '' && trim((string) ($auth['token'] ?? '')) !== '';
    }

    private function isInvalidIssueTypeError(string $error): bool
    {
        $normalized = strtolower($error);
        return strpos($normalized, 'issuetype') !== false
            && (
                strpos($normalized, 'specify a valid issue type') !== false
                || strpos($normalized, 'is required') !== false
            );
    }
}
