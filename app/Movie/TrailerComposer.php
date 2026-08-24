<?php

namespace App\Movie;

/**
 * Builds a reproducible trailer recipe from a seed.
 * The browser composites title cards, clips, color grading, zoom, speed, and transitions.
 */
final class TrailerComposer
{
    /** @var list<array{id: string, file: string}> */
    private array $clips = [];

    /** @var list<string> */
    private array $transitions = [];

    /** @var list<string> */
    private array $titleStyles = [];

    public function __construct(private readonly string $clipsFile)
    {
        $this->load();
    }

    /**
     * @param array<string, mixed> $dataset
     * @param list<string>         $actors
     *
     * @return array<string, mixed>
     */
    public function compose(SeededRandom $rng, array $dataset, string $title, array $actors): array
    {
        $clipCount = $rng->nextInt(2, 3);
        $chosen = $rng->pickN($this->clips, $clipCount);

        $clips = [];
        foreach ($chosen as $clip) {
            $clips[] = [
                'id' => $clip['id'],
                'src' => '/media/clips/'.$clip['file'],
                'hue' => $rng->nextInt(0, 70) - 35,
                'saturate' => round(0.8 + $rng->nextFloat() * 0.55, 2),
                'contrast' => round(1.02 + $rng->nextFloat() * 0.32, 2),
                'brightness' => round(0.88 + $rng->nextFloat() * 0.28, 2),
                'sepia' => round($rng->nextFloat() * 0.16, 2),
                'zoom' => round(1.1 + $rng->nextFloat() * 0.28, 2),
                'speed' => round(0.85 + $rng->nextFloat() * 0.35, 2),
                'start' => round($rng->nextFloat() * 1.8, 2),
                'panX' => round(($rng->nextFloat() - 0.5) * 0.12, 3),
                'panY' => round(($rng->nextFloat() - 0.5) * 0.12, 3),
            ];
        }

        // Title + 2–3 clips + end card must all fit inside 5–10 seconds.
        $titleDur = round(1.35 + $rng->nextFloat() * 0.4, 2);
        $endDur = round(1.15 + $rng->nextFloat() * 0.35, 2);
        $target = round(6.8 + $rng->nextFloat() * 2.4, 2);
        $clipDur = round(($target - $titleDur - $endDur) / $clipCount, 2);
        $clipDur = max(1.7, min(3.0, $clipDur));
        $duration = round($titleDur + ($clipCount * $clipDur) + $endDur, 2);
        if ($duration > 10) {
            $clipDur = round((10 - $titleDur - $endDur) / $clipCount, 2);
            $duration = 10.0;
        } elseif ($duration < 5) {
            $clipDur = round((5 - $titleDur - $endDur) / $clipCount, 2);
            $duration = 5.0;
        }
        $xfade = min(0.35, $clipDur * 0.22);

        $taglines = $dataset['taglines'] ?? ['COMING SOON'];
        $palette = $this->palette($rng);

        return [
            'duration' => $duration,
            'titleDuration' => $titleDur,
            'clipDuration' => $clipDur,
            'endDuration' => $endDur,
            'crossfade' => $xfade,
            'titleStyle' => $rng->pick($this->titleStyles),
            'endStyle' => $rng->pick($this->titleStyles),
            'tagline' => $rng->pick($taglines),
            'endCard' => $actors[0] ?? $title,
            'comingSoon' => (string) ($dataset['comingSoon'] ?? 'COMING SOON'),
            'title' => $title,
            'palette' => $palette,
            'clips' => $clips,
            'transitions' => $rng->pickN($this->transitions, max(1, count($clips))),
            'grain' => round(0.08 + $rng->nextFloat() * 0.18, 2),
            'letterbox' => $rng->bool(0.7),
            'music' => [
                'root' => $rng->nextInt(40, 58),
                'mode' => $rng->pick(['minor', 'dorian', 'phrygian']),
                'bpm' => $rng->nextInt(68, 112),
                'volume' => round(0.24 + $rng->nextFloat() * 0.12, 2),
            ],
        ];
    }

    /**
     * @return list<string>
     */
    private function palette(SeededRandom $rng): array
    {
        $hues = [
            ['#1a0b0b', '#c9a227', '#f4e7c5'],
            ['#0b1220', '#7ec8e3', '#e8f4ff'],
            ['#120c18', '#c45c7a', '#f3d5de'],
            ['#0c1610', '#8fbf6a', '#e4f0d4'],
            ['#1a1208', '#e07a3d', '#f6d9b8'],
            ['#0e0e18', '#9b8cff', '#ddd6ff'],
            ['#14080c', '#e23d4f', '#ffd0d4'],
            ['#081418', '#2ec4b6', '#c8fff6'],
        ];

        return $rng->pick($hues);
    }

    private function load(): void
    {
        $decoded = json_decode((string) file_get_contents($this->clipsFile), true, 512, JSON_THROW_ON_ERROR);
        $this->clips = array_values($decoded['clips'] ?? []);
        $this->transitions = array_values($decoded['transitions'] ?? ['fade']);
        $this->titleStyles = array_values($decoded['titleStyles'] ?? ['rise']);

        if ($this->clips === []) {
            throw new \RuntimeException('Trailer clip catalog is empty.');
        }
    }
}
