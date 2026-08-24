<?php

namespace App\Http\Controllers;

use App\Movie\LocaleCatalog;
use App\Movie\MovieGenerator;
use App\Movie\SeededRandom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShowcaseController extends Controller
{
    public function __construct(
        private readonly LocaleCatalog $locales,
        private readonly MovieGenerator $generator,
    ) {
    }

    public function index(): View
    {
        return view('showcase', [
            'config' => [
                'moviesUrl' => '/api/movies',
                'seedUrl' => '/api/seed',
                'locales' => array_values($this->locales->choices()),
                'defaultLocale' => $this->locales->defaultCode(),
                'defaultSeed' => 271828182845,
                'maxSeed' => SeededRandom::MASK,
                'defaultLikes' => 5.0,
                'defaultReviews' => 3.5,
                'pageSize' => MovieGenerator::PAGE_SIZE,
            ],
        ]);
    }

    public function movies(Request $request): JsonResponse
    {
        $locale = (string) $request->query('locale', $this->locales->defaultCode());
        if (! $this->locales->has($locale)) {
            $locale = $this->locales->defaultCode();
        }

        $seed = max(0, min(SeededRandom::MASK, $this->intParam($request, 'seed', 271828182845)));
        $likes = max(0.0, min(10.0, $this->floatParam($request, 'likes', 5.0)));
        $reviews = max(0.0, min(10.0, $this->floatParam($request, 'reviews', 3.5)));
        $page = max(1, $this->intParam($request, 'page', 1));
        $pageSize = max(1, min(24, $this->intParam($request, 'pageSize', MovieGenerator::PAGE_SIZE)));

        return response()->json([
            'page' => $page,
            'pageSize' => $pageSize,
            'locale' => $locale,
            'seed' => $seed,
            'likes' => $likes,
            'reviews' => $reviews,
            'movies' => $this->generator->page($locale, $seed, $likes, $reviews, $page, $pageSize),
        ]);
    }

    public function seed(): JsonResponse
    {
        return response()->json([
            'seed' => random_int(0, SeededRandom::MASK),
        ]);
    }

    private function intParam(Request $request, string $name, int $default): int
    {
        $value = $request->query($name, (string) $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    private function floatParam(Request $request, string $name, float $default): float
    {
        $value = $request->query($name, (string) $default);

        return is_numeric($value) ? (float) $value : $default;
    }
}
