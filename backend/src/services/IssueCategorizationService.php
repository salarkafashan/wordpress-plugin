<?php

declare(strict_types=1);

namespace App\services;

final class IssueCategorizationService
{
    private array $rules = [
        'Form not working' => ['form', 'contact form', 'submit', 'sending', 'not sending'],
        'Performance issue' => ['speed', 'slow', 'performance', 'lag', 'timeout', 'load time'],
        'Image replacement' => ['image', 'photo', 'picture', 'banner', 'logo'],
        'Website bug' => ['error', 'bug', 'broken', '500', '404', 'exception'],
        'Content change' => ['content', 'text', 'copy', 'headline', 'paragraph'],
        'Other' => [],
    ];

    public function suggest(?string $description): array
    {
        $text = strtolower(trim((string) $description));
        if ($text === '') {
            return ['suggested_issue_type' => 'Other', 'confidence' => 0];
        }

        $bestType = 'Other';
        $bestScore = 0;
        foreach ($this->rules as $type => $keywords) {
            $score = 0;
            foreach ($keywords as $keyword) {
                if (strpos($text, $keyword) !== false) {
                    $score++;
                }
            }
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestType = $type;
            }
        }

        return [
            'suggested_issue_type' => $bestType,
            'confidence' => $bestScore,
        ];
    }
}
