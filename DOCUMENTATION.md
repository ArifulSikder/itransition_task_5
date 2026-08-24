# Movie Showcase (Laravel + Vue 3)

Single-page app for Itransition Task 5. There is no movie database. The server builds each page of fake movies from a **seed** so the same seed always gives the same catalog.

## What the app does

Toolbar (one row):

- **Language** — English (USA) and Bengali (Bangladesh). Titles, names, genres, and reviews match the language.
- **Seed** — 48-bit number. Same seed = same movies. Shuffle creates a new seed.
- **Likes** — average likes per movie (0–10, fractions allowed). `3.7` means 3 likes, plus a 70% chance of a 4th.
- **Reviews** — average reviews per movie (same rule).

Views:

- **Table** — numbered pages. Click a row to open the trailer, synopsis, director, and reviews.
- **Gallery** — infinite scroll. Click a card to open reviews.

Changing language or seed rebuilds titles, actors, trailers, and review text. Changing likes or reviews only updates those counts.

No login. Data is generated in memory and is never saved.

## How a request works

```
Browser (Vue)
    GET /api/movies?locale=&seed=&likes=&reviews=&page=
        → ShowcaseController
        → MovieGenerator (Faker + locale JSON)
        → TrailerComposer (clip list + random effects)
        → JSON (movies + trailer recipe)
        → TrailerPlayer draws title cards and video on a canvas
```

The seed for each movie is `user seed + page + index`. Likes and reviews use their own sub-seeds so they do not change titles.

## Project layout

```
app/Http/Controllers/ShowcaseController.php   page + JSON APIs
app/Movie/SeededRandom.php                    48-bit deterministic RNG
app/Movie/LocaleCatalog.php                   loads resources/locales/*.json
app/Movie/MovieGenerator.php                  one movie / one page
app/Movie/TrailerComposer.php                 trailer recipe (clips, color, zoom)
resources/js/App.vue                          toolbar, table, gallery
resources/js/components/TrailerPlayer.vue     canvas player
resources/locales/                            language word lists (not hardcoded in PHP)
resources/trailers/clips.json                 clip catalog
public/media/clips/                           short video files
```

Add a language by dropping a new `xx_YY.json` into `resources/locales/`. No PHP change.

## Run it

```bash
composer install
npm install
cp .env.example .env && php artisan key:generate
npm run build
php artisan serve
```

Open http://127.0.0.1:8000

While editing Vue files:

```bash
php artisan serve
npm run dev
```

## API

| Route | Role |
|---|---|
| `GET /` | Showcase page |
| `GET /api/movies` | One page of movies |
| `GET /api/seed` | New random 48-bit seed |

```
/api/movies?locale=en_US&seed=271828182845&likes=5&reviews=3.5&page=1
```
