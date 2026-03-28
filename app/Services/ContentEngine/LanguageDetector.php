<?php

namespace App\Services\ContentEngine;

class LanguageDetector
{
    private const SWAHILI_MARKERS = [
        'na', 'ya', 'wa', 'ni', 'kwa', 'katika', 'hii', 'huo', 'yake',
        'wake', 'kwamba', 'lakini', 'pia', 'sana', 'kupitia', 'baada',
        'kabla', 'mpaka', 'tangu', 'kama', 'ili', 'ingawa', 'kwani',
        'habari', 'asante', 'karibu', 'ndio', 'hapana', 'ndiyo',
        'leo', 'kesho', 'jana', 'sasa', 'bado', 'tayari', 'pamoja',
        'watu', 'mtu', 'nyumba', 'kazi', 'shule', 'dada', 'kaka',
        'mama', 'baba', 'mtoto', 'watoto', 'rafiki', 'marafiki',
    ];

    public static function detect(?string $text): ?string
    {
        if (empty($text)) {
            return null;
        }

        $words = preg_split('/\s+/', mb_strtolower(strip_tags($text)));
        $totalWords = count($words);

        if ($totalWords < 3) {
            return null;
        }

        $swahiliCount = 0;
        foreach ($words as $word) {
            $cleaned = preg_replace('/[^a-z]/', '', $word);
            if (in_array($cleaned, self::SWAHILI_MARKERS, true)) {
                $swahiliCount++;
            }
        }

        $ratio = $swahiliCount / $totalWords;
        return $ratio > 0.15 ? 'sw' : 'en';
    }
}
