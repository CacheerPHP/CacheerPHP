<?php

declare(strict_types=1);

if ($argc < 3) {
    fwrite(STDERR, "Usage: php benchmarks/compare.php <baseline.json> <candidate.json> [max-regression]\n");
    exit(2);
}

$baseline = decodeBenchmark($argv[1]);
$candidate = decodeBenchmark($argv[2]);
$maximumRegression = isset($argv[3]) ? (float) $argv[3] : 0.25;

if ($maximumRegression < 0.0 || $maximumRegression >= 1.0) {
    fwrite(STDERR, "The maximum regression must be between 0.0 and 1.0.\n");
    exit(2);
}

$regressions = [];
$metrics = ['write_ops_per_second', 'read_ops_per_second', 'delete_ops_per_second'];

foreach ($baseline['results'] ?? [] as $driver => $payloads) {
    foreach ($payloads as $payload => $measurements) {
        $candidateMeasurements = $candidate['results'][$driver][$payload] ?? null;
        if (!is_array($measurements) || !is_array($candidateMeasurements)) {
            continue;
        }

        foreach ($metrics as $metric) {
            $expected = $measurements[$metric] ?? null;
            $actual = $candidateMeasurements[$metric] ?? null;
            if (!is_numeric($expected) || !is_numeric($actual) || (float) $expected <= 0.0) {
                continue;
            }

            $ratio = (float) $actual / (float) $expected;
            if ($ratio < (1.0 - $maximumRegression)) {
                $regressions[] = [
                    'driver'     => $driver,
                    'payload'    => $payload,
                    'metric'     => $metric,
                    'baseline'   => (float) $expected,
                    'candidate'  => (float) $actual,
                    'regression' => round(1.0 - $ratio, 4),
                ];
            }
        }
    }
}

echo json_encode([
    'format'             => 'cacheer-benchmark-comparison-v1',
    'maximum_regression' => $maximumRegression,
    'regressions'        => $regressions,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($regressions === [] ? 0 : 1);

/**
 * @return array<string,mixed>
 */
function decodeBenchmark(string $path): array
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException('Unable to read benchmark file: ' . $path);
    }

    $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || !isset($decoded['results'])) {
        throw new RuntimeException('Invalid benchmark document: ' . $path);
    }

    return $decoded;
}
