<?php
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json');

require_once 'config.php';

$res = $conn->query("SELECT id, title, content FROM disaster_guidelines ORDER BY id ASC");
$guidelines = [];

if ($res) {
    while ($row = $res->fetch_assoc()) {
        $rawLines = preg_split('/\r\n|\r|\n/', trim($row['content']));
        $steps = [];
        $stepCounter = 1;

        foreach ($rawLines as $line) {
            $line = trim($line);
            if (empty($line) || str_starts_with($line, 'EMERGENCY HOTLINES:')) continue;

            $cleanLine = preg_replace('/^\d+[\.\)]\s*/', '', $line);
            $eng = $cleanLine;
            $fil = '';

            if (preg_match('/^(.*?)\s*\((.*?)\)$/', $cleanLine, $matches)) {
                $eng = trim($matches[1]);
                $fil = trim($matches[2]);
            }

            $steps[] = [
                'step' => $stepCounter++,
                'english' => $eng,
                'filipino' => $fil
            ];
        }

        $fullTitle = $row['title'];
        $phase = $fullTitle;
        $subtitle = '';

        if (str_contains($fullTitle, ' - ')) {
            $parts = explode(' - ', $fullTitle, 2);
            $phase = trim($parts[1]);
        }

        $upperPhase = strtoupper($phase);
        if (str_contains($upperPhase, 'BEFORE')) {
            $subtitle = 'Monitor the news for weather updates.';
        } elseif (str_contains($upperPhase, 'DURING')) {
            $subtitle = 'Stay alert and stay tuned.';
        } elseif (str_contains($upperPhase, 'AFTER')) {
            $subtitle = 'Remain alert and be cautious.';
        }

        $guidelines[] = [
            'id' => (int)$row['id'],
            'full_title' => $fullTitle,
            'phase' => $phase,
            'subtitle' => $subtitle,
            'steps_count' => count($steps),
            'steps' => $steps
        ];
    }
}

echo json_encode(['success' => true, 'data' => $guidelines]);
$conn->close();