<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useInfiniteScroll } from '@vueuse/core';
import TrailerPlayer from './components/TrailerPlayer.vue';

const props = defineProps({
    config: { type: Object, required: true },
});

// Toolbar values — changing any of these reloads movies from the server.
const locale = ref(props.config.defaultLocale);
const seed = ref(props.config.defaultSeed);
const likes = ref(props.config.defaultLikes);
const reviews = ref(props.config.defaultReviews);
const view = ref('table');

const tablePage = ref(1);
const tableMovies = ref([]);
const tableError = ref('');
const expanded = ref(new Set());

const galleryPage = ref(1);
const galleryMovies = ref([]);
const galleryOpen = ref(new Set());
const galleryBusy = ref(false);

let reloadTimer = 0;

const pagerPages = computed(() => {
    const start = Math.max(1, tablePage.value - 1);
    return [start, start + 1, start + 2];
});

function query() {
    return {
        locale: locale.value,
        seed: seed.value || '0',
        likes: likes.value,
        reviews: reviews.value,
    };
}

async function fetchMovies(page) {
    const params = new URLSearchParams({
        ...query(),
        page: String(page),
        pageSize: String(props.config.pageSize),
    });
    const response = await fetch(`${props.config.moviesUrl}?${params}`);
    if (!response.ok) {
        throw new Error('Unable to load movies');
    }
    return response.json();
}

async function loadTable() {
    try {
        tableError.value = '';
        const data = await fetchMovies(tablePage.value);
        tableMovies.value = data.movies;
    } catch (error) {
        tableMovies.value = [];
        tableError.value = error.message || 'Unable to load movies';
    }
}

async function loadGalleryPages(count) {
    if (galleryBusy.value) {
        return;
    }
    galleryBusy.value = true;
    try {
        for (let i = 0; i < count; i += 1) {
            const data = await fetchMovies(galleryPage.value);
            galleryMovies.value.push(...data.movies);
            galleryPage.value += 1;
        }
    } finally {
        galleryBusy.value = false;
    }
}

function resetLists(keepExpanded = false) {
    tablePage.value = 1;
    galleryPage.value = 1;
    galleryMovies.value = [];
    galleryOpen.value = new Set();
    if (!keepExpanded) {
        expanded.value = new Set();
    }
    window.scrollTo(0, 0);
}

function reload(keepExpanded = false) {
    resetLists(keepExpanded);
    if (view.value === 'table') {
        loadTable();
    } else {
        loadGalleryPages(3);
    }
}

function later(fn, ms) {
    clearTimeout(reloadTimer);
    reloadTimer = setTimeout(fn, ms);
}

async function randomSeed() {
    try {
        const data = await (await fetch(props.config.seedUrl)).json();
        seed.value = data.seed;
    } catch {
        seed.value = Math.floor(Math.random() * (props.config.maxSeed + 1));
    }
    reload(false);
}

function showTable() {
    view.value = 'table';
    if (tableMovies.value.length === 0) {
        loadTable();
    }
}

function showGallery() {
    view.value = 'gallery';
    if (galleryMovies.value.length === 0) {
        loadGalleryPages(3);
    }
}

function goToPage(page) {
    if (!page || page === tablePage.value) {
        return;
    }
    tablePage.value = page;
    expanded.value = new Set();
    loadTable();
}

function toggleRow(index) {
    const next = new Set(expanded.value);
    if (next.has(index)) {
        next.delete(index);
        window.dispatchEvent(new Event('trailer:stop-all'));
    } else {
        next.add(index);
    }
    expanded.value = next;
}

function toggleCard(event, index) {
    if (event.target.closest('.play-btn, canvas, .card-expand')) {
        return;
    }
    const next = new Set(galleryOpen.value);
    if (next.has(index)) {
        next.delete(index);
    } else {
        next.add(index);
    }
    galleryOpen.value = next;
}

useInfiniteScroll(
    window,
    () => {
        if (view.value === 'gallery') {
            loadGalleryPages(1);
        }
    },
    { distance: 400 },
);

onMounted(() => reload(false));
onUnmounted(() => clearTimeout(reloadTimer));
</script>

<template>
    <div class="app container-fluid py-3">
        <div class="toolbar row g-3 align-items-end mb-3" role="toolbar" aria-label="Generation parameters">
            <div class="col-auto">
                <label class="form-label small mb-1">Language</label>
                <select class="form-select" v-model="locale" @change="later(() => reload(false), 80)">
                    <option v-for="item in config.locales" :key="item.code" :value="item.code">
                        {{ item.label }}
                    </option>
                </select>
            </div>

            <div class="col-auto">
                <label class="form-label small mb-1">Seed</label>
                <div class="input-group">
                    <input
                        class="form-control seed-input"
                        type="number"
                        min="0"
                        :max="config.maxSeed"
                        v-model="seed"
                        @input="later(() => reload(false), 280)"
                    >
                    <button type="button" class="btn btn-outline-secondary" title="Random seed" @click="randomSeed">
                        <i class="bi bi-shuffle"></i>
                    </button>
                </div>
            </div>

            <div class="col likes-col">
                <label class="form-label small mb-1 d-flex justify-content-between">
                    <span>Likes</span>
                    <strong>{{ Number(likes).toFixed(1) }}</strong>
                </label>
                <input
                    class="form-range likes-slider"
                    type="range"
                    min="0"
                    max="10"
                    step="0.1"
                    v-model.number="likes"
                    @input="later(() => reload(true), 80)"
                >
            </div>

            <div class="col-auto">
                <label class="form-label small mb-1">Reviews</label>
                <input
                    class="form-control reviews-input"
                    type="number"
                    min="0"
                    max="10"
                    step="0.1"
                    v-model.number="reviews"
                    @input="later(() => reload(true), 80)"
                >
            </div>

            <div class="col-auto ms-auto view-toggle" role="group" aria-label="Display mode">
                <button type="button" class="btn btn-link p-1" :class="{ 'is-active': view === 'gallery' }" title="Gallery" @click="showGallery">
                    <i class="bi bi-grid-3x3-gap-fill"></i>
                </button>
                <button type="button" class="btn btn-link p-1" :class="{ 'is-active': view === 'table' }" title="Table" @click="showTable">
                    <i class="bi bi-list"></i>
                </button>
            </div>
        </div>

        <section v-if="view === 'table'" class="stage">
            <div class="table-wrap">
                <table class="table movies align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="col-idx">#</th>
                            <th>Genre</th>
                            <th>Title</th>
                            <th>Cast</th>
                            <th>Year</th>
                            <th class="col-likes">Likes</th>
                            <th class="col-expand"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-if="tableError">
                            <td colspan="7" class="empty-state">{{ tableError }}</td>
                        </tr>
                        <tr v-else-if="!tableMovies.length">
                            <td colspan="7" class="empty-state">No movies generated for this seed.</td>
                        </tr>
                        <template v-for="movie in tableMovies" :key="movie.index">
                            <tr class="movie-row" :class="{ 'is-open': expanded.has(movie.index) }" @click="toggleRow(movie.index)">
                                <td class="col-idx">{{ movie.index }}</td>
                                <td>{{ movie.genre }}</td>
                                <td>{{ movie.title }}</td>
                                <td>{{ (movie.actors || []).join(', ') }}</td>
                                <td>{{ movie.year }}</td>
                                <td class="col-likes">
                                    <span class="like-count"><i class="bi bi-hand-thumbs-up-fill"></i> {{ movie.likes }}</span>
                                </td>
                                <td class="col-expand text-end">
                                    <i class="bi chevron" :class="expanded.has(movie.index) ? 'bi-chevron-up' : 'bi-chevron-down'"></i>
                                </td>
                            </tr>
                            <tr v-if="expanded.has(movie.index)" class="detail-row">
                                <td colspan="7">
                                    <div class="detail">
                                        <div class="poster-col">
                                            <TrailerPlayer :recipe="movie.trailer" />
                                            <button type="button" class="like-btn" tabindex="-1">
                                                <i class="bi bi-hand-thumbs-up-fill"></i> {{ movie.likes }}
                                            </button>
                                        </div>
                                        <div class="detail-copy">
                                            <div class="detail-title">
                                                <h2>{{ movie.title }}</h2>
                                                <span class="meta">{{ movie.year }}</span>
                                                <span class="meta">{{ movie.genre }}</span>
                                            </div>
                                            <div class="badges">
                                                <span v-if="movie.top10" class="badge-soft">Top 10</span>
                                                <span v-if="movie.duration" class="badge-soft">{{ movie.duration }} min</span>
                                                <span v-if="movie.rating" class="badge-soft">{{ movie.rating }}</span>
                                            </div>
                                            <p><strong>Cast:</strong> {{ (movie.actors || []).join(', ') }}</p>
                                            <p><strong>Director:</strong> {{ movie.director || '' }}</p>
                                            <p>{{ movie.synopsis || '' }}</p>
                                            <aside class="reviews">
                                                <h3>Reviews</h3>
                                                <p v-if="!movie.reviews.length" class="empty-reviews">No reviews for this picture.</p>
                                                <div v-for="(review, i) in movie.reviews" :key="i" class="review">
                                                    <p>“{{ review.text }}”</p>
                                                    <div class="byline">{{ review.author }}<template v-if="review.company">, {{ review.company }}</template></div>
                                                </div>
                                            </aside>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <nav class="pager mt-3" aria-label="Table pagination">
                <button type="button" class="page-link-btn" :disabled="tablePage <= 1" @click="goToPage(tablePage - 1)">&laquo;</button>
                <div class="page-numbers">
                    <button
                        v-for="number in pagerPages"
                        :key="number"
                        type="button"
                        class="page-link-btn"
                        :class="{ 'is-active': number === tablePage }"
                        @click="goToPage(number)"
                    >
                        {{ number }}
                    </button>
                </div>
                <button type="button" class="page-link-btn" @click="goToPage(tablePage + 1)">&raquo;</button>
            </nav>
        </section>

        <section v-else class="stage">
            <div class="gallery">
                <article
                    v-for="movie in galleryMovies"
                    :key="movie.index"
                    class="card"
                    :class="{ 'is-open': galleryOpen.has(movie.index) }"
                    @click="toggleCard($event, movie.index)"
                >
                    <TrailerPlayer :recipe="movie.trailer" />
                    <div class="card-info">
                        <p class="card-meta">#{{ movie.index }} · {{ movie.year }} · {{ movie.genre }} · ♥ {{ movie.likes }}</p>
                        <h2>{{ movie.title }}</h2>
                        <p class="card-actors">{{ (movie.actors || []).join(', ') }}</p>
                    </div>
                    <div class="card-expand">
                        <aside class="reviews">
                            <h3>Reviews</h3>
                            <p v-if="!movie.reviews.length" class="empty-reviews">No reviews for this picture.</p>
                            <div v-for="(review, i) in movie.reviews" :key="i" class="review">
                                <p>“{{ review.text }}”</p>
                                <div class="byline">{{ review.author }}<template v-if="review.company">, {{ review.company }}</template></div>
                            </div>
                        </aside>
                    </div>
                </article>
            </div>
            <p v-show="galleryBusy" class="loading">Loading more films…</p>
        </section>
    </div>
</template>
