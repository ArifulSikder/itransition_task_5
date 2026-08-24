<?php

namespace App\Movie;

use Faker\Factory;

final class MovieGenerator
{
    public const PAGE_SIZE = 10;
    public const REVIEW_POOL = 10;

    public function __construct(
        private readonly LocaleCatalog $locales,
        private readonly TrailerComposer $trailers,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function page(string $locale, int $seed, float $likes, float $reviews, int $page, int $pageSize = self::PAGE_SIZE): array
    {
        $page = max(1, $page);
        $pageSize = max(1, $pageSize);
        $start = ($page - 1) * $pageSize + 1;
        $movies = [];

        for ($index = $start; $index < $start + $pageSize; ++$index) {
            $movies[] = $this->movie($locale, $seed, $likes, $reviews, $index, $page);
        }

        return $movies;
    }

    /**
     * @return array<string, mixed>
     */
    public function movie(string $locale, int $seed, float $likes, float $reviews, int $index, int $page): array
    {
        $dataset = $this->locales->dataset($locale);

        // Seed + page + index. Likes/reviews use other sub-seeds so they cannot change the title.
        $recordSeed = SeededRandom::mix(SeededRandom::mix($seed, $page), $index);
        $core = new SeededRandom($recordSeed);

        $faker = Factory::create($this->locales->fakerLocale($locale));
        $faker->seed((int) ($recordSeed & 0x7FFFFFFF));

        $title = $this->title($core, $faker, $dataset);
        $actorCount = $core->nextInt(2, 4);
        $actors = [];
        for ($i = 0; $i < $actorCount; ++$i) {
            $actors[] = $this->personName($core, $faker, $dataset);
        }

        $year = $core->nextInt(1954, 2026);
        $genre = $core->pick($dataset['genres'] ?? ['Drama']);

        $reviewPool = [];
        for ($i = 0; $i < self::REVIEW_POOL; ++$i) {
            $reviewPool[] = [
                'author' => $this->personName($core, $faker, $dataset),
                'company' => $this->company($core, $faker, $dataset),
                'text' => $this->review($core, $dataset, $title, $genre, $actors[0] ?? ''),
                'stars' => $core->nextInt(2, 5),
            ];
        }

        $likesRng = new SeededRandom(SeededRandom::mix($recordSeed, 0x4C494B45));
        $reviewsRng = new SeededRandom(SeededRandom::mix($recordSeed, 0x52455643));
        $likeCount = $likesRng->probabilisticCount($likes);
        $reviewCount = $reviewsRng->probabilisticCount($reviews);

        $trailerRng = new SeededRandom(SeededRandom::mix($recordSeed, 0x54524C52));
        $trailer = $this->trailers->compose($trailerRng, $dataset, $title, $actors);

        return [
            'index' => $index,
            'title' => $title,
            'year' => $year,
            'genre' => $genre,
            'actors' => $actors,
            'director' => $this->personName($core, $faker, $dataset),
            'synopsis' => $this->synopsis($core, $dataset, $title, $genre, $actors[0] ?? ''),
            'duration' => $core->nextInt(92, 168),
            'rating' => $core->pick($dataset['ratings'] ?? ['G', 'PG', '13+', '16+', '18+']),
            'top10' => $core->bool(0.18),
            'likes' => $likeCount,
            'reviews' => array_slice($reviewPool, 0, $reviewCount),
            'trailer' => $trailer,
        ];
    }

    /**
     * @param array<string, mixed> $dataset
     */
    private function title(SeededRandom $rng, \Faker\Generator $faker, array $dataset): string
    {
        $title = $dataset['title'] ?? [];
        $patterns = $title['patterns'] ?? ['{adj} {noun}'];
        $pattern = $rng->pick($patterns);

        $replacements = [
            '{adj}' => $rng->pick($title['adjectives'] ?? ['Silent']),
            '{noun}' => $rng->pick($title['nouns'] ?? ['Harbor']),
            '{noun2}' => $rng->pick($title['nouns2'] ?? $title['nouns'] ?? ['Shadow']),
            '{suffix}' => $rng->pick($title['suffixes'] ?? ['Rising']),
            '{name}' => $this->familyName($rng, $faker, $dataset),
        ];

        $rendered = strtr($pattern, $replacements);
        $rendered = preg_replace('/\s+/', ' ', trim($rendered)) ?? $rendered;

        return $rendered;
    }

    /**
     * @param array<string, mixed> $dataset
     */
    private function review(SeededRandom $rng, array $dataset, string $title, string $genre, string $actor): string
    {
        $opener = $rng->pick($dataset['reviewOpeners'] ?? ['A striking picture.']);
        $body = $rng->pick($dataset['reviewBodies'] ?? ['The story lingers.']);
        $closer = $rng->pick($dataset['reviewClosers'] ?? ['Worth watching.']);

        $text = $opener.' '.$body.' '.$closer;
        $text = strtr($text, [
            '{title}' => $title,
            '{genre}' => mb_strtolower($genre),
            '{actor}' => $actor,
        ]);

        return preg_replace('/\s+/', ' ', trim($text)) ?? $text;
    }

    /**
     * @param array<string, mixed> $dataset
     */
    private function synopsis(SeededRandom $rng, array $dataset, string $title, string $genre, string $actor): string
    {
        $templates = $dataset['synopses'] ?? [];
        if ($templates === []) {
            return $this->review($rng, $dataset, $title, $genre, $actor);
        }

        $text = $rng->pick($templates);

        return strtr($text, [
            '{title}' => $title,
            '{genre}' => mb_strtolower($genre),
            '{actor}' => $actor,
        ]);
    }

    /**
     * @param array<string, mixed> $dataset
     */
    private function company(SeededRandom $rng, \Faker\Generator $faker, array $dataset): string
    {
        $companies = $dataset['companies'] ?? [];
        if ($companies !== []) {
            return $rng->pick($companies);
        }

        return $faker->company();
    }

    /**
     * @param array<string, mixed> $dataset
     */
    private function personName(SeededRandom $rng, \Faker\Generator $faker, array $dataset): string
    {
        $first = $dataset['firstNames'] ?? [];
        $last = $dataset['lastNames'] ?? [];
        if ($first !== [] && $last !== []) {
            return $rng->pick($first).' '.$rng->pick($last);
        }

        return $faker->name();
    }

    /**
     * @param array<string, mixed> $dataset
     */
    private function familyName(SeededRandom $rng, \Faker\Generator $faker, array $dataset): string
    {
        $last = $dataset['lastNames'] ?? [];
        if ($last !== []) {
            return $rng->pick($last);
        }

        return $faker->lastName();
    }
}
