<script setup>
/**
 * Plays one trailer recipe from the server:
 * title card over footage → color-graded clips → end card, plus a generated melody.
 * Click play/pause. Closing the row unmounts this component and stops playback.
 */
import { onMounted, onUnmounted, ref, watch } from 'vue';

const MODES = {
    minor: [0, 3, 7, 10, 12],
    dorian: [0, 2, 3, 7, 9, 12],
    phrygian: [0, 1, 3, 7, 8, 12],
};

const props = defineProps({
    recipe: { type: Object, default: () => ({}) },
});

const canvasEl = ref(null);
const rootEl = ref(null);
const playing = ref(false);

let ctx = null;
let destroyed = false;
let session = 0;
let videos = [];
let raf = 0;
let audio = null;
let loadLock = Promise.resolve();

function midiToFreq(midi) {
    return 440 * 2 ** ((midi - 69) / 12);
}

function ease(t) {
    const p = Math.max(0, Math.min(1, t));
    return 1 - (1 - p) ** 3;
}

function currentRecipe() {
    return props.recipe || {};
}

function clipAt(index) {
    const clips = currentRecipe().clips || [];
    return clips[Math.max(0, Math.min(clips.length - 1, index))] || null;
}

function videoAt(index) {
    return videos[Math.max(0, Math.min(videos.length - 1, index))] || null;
}

function hasFrame(video) {
    return Boolean(video && video.readyState >= 2 && video.videoWidth > 0);
}

function drawPoster() {
    const recipe = currentRecipe();
    const clip = clipAt(0);
    const video = videoAt(0);
    paintTitleCard(recipe, 0.9, recipe.titleStyle || 'rise', recipe.tagline || '', recipe.title || '', video, clip, 0.55);
    drawLetterbox(recipe);
    drawGrain(recipe.grain || 0.12);
}

function stopAudio() {
    if (!audio) {
        return;
    }
    audio.nodes.forEach((node) => {
        try {
            node.stop();
        } catch {
            // already stopped
        }
    });
    audio.ctx.close().catch(() => {});
    audio = null;
}

function stop() {
    session += 1;
    playing.value = false;
    cancelAnimationFrame(raf);
    videos.forEach((video) => video.pause());
    stopAudio();
    if (!destroyed && ctx) {
        drawPoster();
    }
}

function onStopAll() {
    if (playing.value) {
        stop();
    }
}

async function toggle() {
    if (destroyed) {
        return;
    }
    if (playing.value) {
        stop();
        return;
    }
    window.dispatchEvent(new Event('trailer:stop-all'));
    await play();
}

async function play() {
    const recipe = currentRecipe();
    if (destroyed || !recipe.clips?.length) {
        return;
    }

    const current = ++session;
    playing.value = true;
    startAudio(recipe);

    await ensureVideos(recipe);
    if (destroyed || current !== session) {
        stopAudio();
        return;
    }
    await startClipPlayback(recipe);
    if (destroyed || current !== session || !playing.value) {
        return;
    }

    const startedAt = performance.now();
    const tick = () => {
        if (!playing.value || destroyed || current !== session) {
            return;
        }
        const elapsed = (performance.now() - startedAt) / 1000;
        if (elapsed >= recipe.duration) {
            stop();
            return;
        }
        drawFrame(elapsed, recipe);
        raf = requestAnimationFrame(tick);
    };
    raf = requestAnimationFrame(tick);
}

async function ensureVideos(recipe, limit = recipe.clips.length) {
    const run = loadLock.then(() => loadVideos(recipe, limit));
    loadLock = run.catch(() => {});
    return run;
}

async function loadVideos(recipe, limit) {
    const needed = (recipe.clips || []).slice(0, limit);
    if (!rootEl.value || needed.length === 0) {
        return;
    }

    const mismatch = videos.some((video, i) => video.dataset.src !== needed[i]?.src);
    if (mismatch) {
        videos.forEach((video) => video.remove());
        videos = [];
    }

    for (let i = videos.length; i < needed.length; i += 1) {
        videos.push(createVideo(needed[i]));
    }

    await Promise.all(videos.slice(0, needed.length).map((video, index) => primeVideo(video, needed[index])));
}

function createVideo(clip) {
    const video = document.createElement('video');
    video.muted = true;
    video.defaultMuted = true;
    video.playsInline = true;
    video.setAttribute('playsinline', '');
    video.preload = 'auto';
    video.loop = true;
    video.dataset.src = clip.src;
    video.src = clip.src;
    video.className = 'clip-el';
    video.setAttribute('aria-hidden', 'true');
    rootEl.value.appendChild(video);
    video.load();
    return video;
}

function primeVideo(video, clip) {
    return new Promise((resolve) => {
        let settled = false;
        const done = () => {
            if (settled) {
                return;
            }
            settled = true;
            resolve();
        };
        const timer = setTimeout(done, 4000);

        const applyStart = () => {
            try {
                const duration = Number.isFinite(video.duration) ? video.duration : 0;
                video.playbackRate = clip.speed || 1;
                if (duration > 0.4) {
                    const start = Math.min(clip.start || 0, Math.max(0, duration - 1.2));
                    if (Math.abs(video.currentTime - start) > 0.05) {
                        video.addEventListener('seeked', () => {
                            clearTimeout(timer);
                            done();
                        }, { once: true });
                        video.currentTime = start;
                        return;
                    }
                }
            } catch {
                // Some browsers reject currentTime before metadata.
            }
            clearTimeout(timer);
            done();
        };

        if (video.readyState >= 1) {
            applyStart();
            return;
        }
        video.addEventListener('loadedmetadata', applyStart, { once: true });
        video.addEventListener('error', () => {
            clearTimeout(timer);
            done();
        }, { once: true });
    });
}

async function startClipPlayback(recipe) {
    const clips = recipe.clips || [];
    await Promise.all(videos.map(async (video, index) => {
        try {
            video.playbackRate = clips[index]?.speed || 1;
            video.muted = true;
            await video.play();
        } catch {
            // Autoplay can still fail if the user gesture was lost.
        }
    }));
}

function activeClipIndex(elapsed, recipe) {
    const clips = recipe.clips || [];
    const titleEnd = recipe.titleDuration;
    const endStart = recipe.duration - recipe.endDuration;
    if (elapsed < titleEnd || clips.length === 0) {
        return 0;
    }
    if (elapsed >= endStart) {
        return clips.length - 1;
    }
    const local = elapsed - titleEnd;
    return Math.min(clips.length - 1, Math.floor(local / recipe.clipDuration));
}

function drawFrame(elapsed, recipe) {
    const titleEnd = recipe.titleDuration;
    const clipDur = recipe.clipDuration;
    const endStart = recipe.duration - recipe.endDuration;
    const clips = recipe.clips || [];
    const index = activeClipIndex(elapsed, recipe);
    const clip = clips[index];
    const video = videos[index];

    if (elapsed < titleEnd) {
        const zoomT = elapsed / Math.max(0.01, titleEnd);
        paintTitleCard(recipe, elapsed / titleEnd, recipe.titleStyle, recipe.tagline, recipe.title, video, clip, zoomT);
    } else if (elapsed >= endStart) {
        const zoomT = (elapsed - endStart) / Math.max(0.01, recipe.endDuration);
        paintTitleCard(recipe, zoomT, recipe.endStyle, recipe.comingSoon, recipe.endCard, video, clip, 0.7 + zoomT * 0.3);
    } else {
        const local = elapsed - titleEnd;
        const inner = local - index * clipDur;
        drawClip(clip, video, inner, clipDur);
        maybeTransition(recipe, clips, index, inner, clipDur);
    }

    drawLetterbox(recipe);
    drawGrain(recipe.grain || 0.12);
}

function applyGrade(clip) {
    ctx.filter = [
        `hue-rotate(${clip?.hue || 0}deg)`,
        `saturate(${clip?.saturate || 1})`,
        `contrast(${clip?.contrast || 1})`,
        `brightness(${clip?.brightness || 1})`,
        `sepia(${clip?.sepia || 0})`,
    ].join(' ');
}

function drawClip(clip, video, inner, clipDur) {
    const { width, height } = canvasEl.value;
    ctx.fillStyle = '#000';
    ctx.fillRect(0, 0, width, height);

    if (!hasFrame(video)) {
        fillBackdrop(currentRecipe());
        return;
    }

    if (video.paused && playing.value) {
        video.play().catch(() => {});
    }

    const zoomT = inner / Math.max(0.01, clipDur);
    const zoom = 1 + ((clip.zoom || 1.12) - 1) * zoomT;
    drawCover(video, clip, zoom);
}

function drawCover(video, clip, zoom) {
    const { width, height } = canvasEl.value;
    const vw = video.videoWidth || width;
    const vh = video.videoHeight || height;
    const dw = width * zoom;
    const dh = height * zoom;
    const scale = Math.max(dw / vw, dh / vh);
    const rw = vw * scale;
    const rh = vh * scale;
    const dx = (width - rw) / 2 + width * (clip?.panX || 0);
    const dy = (height - rh) / 2 + height * (clip?.panY || 0);
    applyGrade(clip);
    try {
        ctx.drawImage(video, dx, dy, rw, rh);
    } catch {
        fillBackdrop(currentRecipe());
    }
    ctx.filter = 'none';
}

function maybeTransition(recipe, clips, index, inner, clipDur) {
    const fade = recipe.crossfade || 0.28;
    if (inner < fade && index > 0) {
        const amount = 1 - inner / fade;
        ctx.save();
        ctx.globalAlpha = amount;
        applyTransition(recipe.transitions[index - 1] || 'fade', amount);
        drawClip(clips[index - 1], videos[index - 1], clipDur - 0.01, clipDur);
        ctx.restore();
    }
}

function applyTransition(type, amount) {
    const { width, height } = canvasEl.value;
    if (type === 'wipe-left') {
        ctx.beginPath();
        ctx.rect(0, 0, width * amount, height);
        ctx.clip();
    } else if (type === 'wipe-up') {
        ctx.beginPath();
        ctx.rect(0, height * (1 - amount), width, height * amount);
        ctx.clip();
    } else if (type === 'zoom') {
        const scale = 1 + amount * 0.2;
        ctx.translate(width / 2, height / 2);
        ctx.scale(scale, scale);
        ctx.translate(-width / 2, -height / 2);
    } else if (type === 'flash') {
        ctx.globalAlpha = Math.min(ctx.globalAlpha, amount * 0.85);
    }
}

function paintTitleCard(recipe, progress, style, kicker, title, video, clip, zoomT) {
    const { width, height } = canvasEl.value;
    if (hasFrame(video) && clip) {
        if (video.paused && playing.value) {
            video.play().catch(() => {});
        }
        drawCover(video, clip, 1 + ((clip.zoom || 1.12) - 1) * Math.min(1, zoomT || 0.4));
        ctx.fillStyle = 'rgba(0, 0, 0, 0.48)';
        ctx.fillRect(0, 0, width, height);
    } else {
        fillBackdrop(recipe);
    }

    const eased = ease(progress);
    const palette = recipe.palette || ['#1a0b0b', '#c9a227', '#f4e7c5'];
    const context = ctx;

    context.save();
    context.textAlign = 'center';

    let alpha = Math.min(1, eased * 1.4);
    let y = height * 0.56;
    let tracking = 10;
    let scale = 1;
    const kickerY = height * 0.38;

    if (style === 'rise') {
        y = height * 0.62 - 40 * eased;
        alpha = eased;
    } else if (style === 'tracking') {
        tracking = 28 - 16 * eased;
        alpha = eased;
    } else if (style === 'scale') {
        scale = 0.72 + 0.28 * eased;
        alpha = eased;
    } else if (style === 'slide') {
        context.translate(-80 * (1 - eased), 0);
        alpha = eased;
    } else if (style === 'flicker') {
        alpha = 0.55 + Math.abs(Math.sin(progress * 40)) * 0.45;
    } else if (style === 'glitch') {
        alpha = 0.9;
    } else if (style === 'typewriter') {
        alpha = 1;
    }

    context.globalAlpha = alpha;
    context.font = '700 22px "Source Sans 3", sans-serif';
    context.letterSpacing = '0.45em';
    context.fillStyle = palette[1] || '#c9a227';
    context.fillText((kicker || '').toUpperCase(), width / 2, kickerY);

    context.strokeStyle = palette[1] || '#c9a227';
    context.lineWidth = 1;
    context.beginPath();
    context.moveTo(width * 0.38, height * 0.44);
    context.lineTo(width * 0.62, height * 0.44);
    context.stroke();

    const display = displayTitle(title || '', style, progress);
    context.translate(width / 2, y);
    context.scale(scale, scale);
    context.font = '88px "Bebas Neue", Impact, sans-serif';
    context.letterSpacing = `${tracking}px`;
    context.fillStyle = palette[2] || '#f4e7c5';

    if (style === 'glitch') {
        context.fillStyle = '#ff4d6d';
        context.fillText(display, -4, 0);
        context.fillStyle = '#4deaff';
        context.fillText(display, 4, 2);
        context.fillStyle = palette[2] || '#f4e7c5';
    }

    wrapTitle(context, display, 0, 0, width * 0.82, 82);
    context.restore();
}

function displayTitle(title, style, progress) {
    const full = title.toUpperCase();
    if (style !== 'typewriter') {
        return full;
    }
    const count = Math.max(1, Math.floor(full.length * Math.min(1, progress * 1.2)));
    return full.slice(0, count);
}

function wrapTitle(context, text, x, y, maxWidth, lineHeight) {
    const words = text.split(' ');
    const lines = [];
    let current = '';
    words.forEach((word) => {
        const next = current ? `${current} ${word}` : word;
        if (context.measureText(next).width > maxWidth && current) {
            lines.push(current);
            current = word;
        } else {
            current = next;
        }
    });
    if (current) {
        lines.push(current);
    }
    const startY = y - ((lines.length - 1) * lineHeight) / 2;
    lines.forEach((line, i) => context.fillText(line, x, startY + i * lineHeight));
}

function fillBackdrop(recipe) {
    const { width, height } = canvasEl.value;
    const palette = recipe.palette || ['#1a0b0b', '#c9a227', '#f4e7c5'];
    const gradient = ctx.createLinearGradient(0, 0, width, height);
    gradient.addColorStop(0, palette[0]);
    gradient.addColorStop(1, '#000');
    ctx.fillStyle = gradient;
    ctx.fillRect(0, 0, width, height);
}

function drawLetterbox(recipe) {
    if (!recipe.letterbox) {
        return;
    }
    const { width, height } = canvasEl.value;
    const bar = height * 0.1;
    ctx.fillStyle = '#000';
    ctx.fillRect(0, 0, width, bar);
    ctx.fillRect(0, height - bar, width, bar);
}

function drawGrain(amount) {
    const { width, height } = canvasEl.value;
    ctx.save();
    ctx.globalAlpha = Math.min(0.22, amount);
    ctx.fillStyle = '#ffffff';
    for (let i = 0; i < 90; i += 1) {
        ctx.fillRect(Math.random() * width, Math.random() * height, 1.4, 1.4);
    }
    ctx.restore();
}

function startAudio(recipe) {
    stopAudio();
    const music = recipe.music || {};
    const AudioCtx = window.AudioContext || window.webkitAudioContext;
    if (!AudioCtx) {
        return;
    }
    const audioCtx = new AudioCtx();
    if (audioCtx.state === 'suspended') {
        audioCtx.resume().catch(() => {});
    }
    const master = audioCtx.createGain();
    master.gain.value = music.volume || 0.28;
    master.connect(audioCtx.destination);

    const drone = audioCtx.createOscillator();
    drone.type = 'sawtooth';
    drone.frequency.value = midiToFreq(music.root || 46);
    const droneGain = audioCtx.createGain();
    droneGain.gain.value = 0.2;
    const filter = audioCtx.createBiquadFilter();
    filter.type = 'lowpass';
    filter.frequency.value = 480;
    drone.connect(filter);
    filter.connect(droneGain);
    droneGain.connect(master);
    drone.start();

    const noise = audioCtx.createBufferSource();
    noise.buffer = noiseBuffer(audioCtx);
    noise.loop = true;
    const noiseGain = audioCtx.createGain();
    noiseGain.gain.value = 0.035;
    noise.connect(noiseGain);
    noiseGain.connect(master);
    noise.start();

    const scale = MODES[music.mode] || MODES.minor;
    const step = 60 / (music.bpm || 86);
    const osc = audioCtx.createOscillator();
    osc.type = 'triangle';
    const oscGain = audioCtx.createGain();
    oscGain.gain.value = 0;
    osc.connect(oscGain);
    oscGain.connect(master);
    osc.start();

    const length = recipe.duration || 8;
    let t = audioCtx.currentTime + 0.12;
    const endAt = audioCtx.currentTime + length;
    let i = 0;
    while (t < endAt - 0.15) {
        const interval = scale[i % scale.length];
        const lift = i % 8 === 0 ? 12 : 0;
        osc.frequency.setValueAtTime(midiToFreq((music.root || 46) + interval + lift), t);
        oscGain.gain.setValueAtTime(0.0001, t);
        oscGain.gain.linearRampToValueAtTime(0.15, t + 0.03);
        oscGain.gain.exponentialRampToValueAtTime(0.001, t + step * 0.82);
        t += step;
        i += 1;
    }

    audio = { ctx: audioCtx, nodes: [drone, noise, osc] };
}

function noiseBuffer(audioCtx) {
    const buffer = audioCtx.createBuffer(1, audioCtx.sampleRate * 2, audioCtx.sampleRate);
    const data = buffer.getChannelData(0);
    for (let i = 0; i < data.length; i += 1) {
        data[i] = Math.random() * 2 - 1;
    }
    return buffer;
}

async function preloadPoster() {
    const recipe = currentRecipe();
    if (!recipe.clips?.length || destroyed) {
        return;
    }
    // Gallery mounts many players; only the expanded table poster preloads a still.
    if (rootEl.value?.closest('.gallery')) {
        return;
    }
    await ensureVideos(recipe, 1);
    if (!destroyed && ctx && !playing.value) {
        drawPoster();
    }
}

onMounted(() => {
    ctx = canvasEl.value.getContext('2d');
    window.addEventListener('trailer:stop-all', onStopAll);
    drawPoster();
    preloadPoster();
});

watch(() => props.recipe, () => {
    stop();
    drawPoster();
    preloadPoster();
});

onUnmounted(() => {
    destroyed = true;
    window.removeEventListener('trailer:stop-all', onStopAll);
    stop();
    videos.forEach((video) => video.remove());
    videos = [];
});
</script>

<template>
    <div ref="rootEl" class="trailer">
        <canvas ref="canvasEl" width="960" height="540" @click.stop.prevent="toggle"></canvas>
        <button
            type="button"
            class="play-btn"
            :aria-label="playing ? 'Stop trailer' : 'Play trailer'"
            @click.stop.prevent="toggle"
        >
            {{ playing ? '❚❚' : '▶' }}
        </button>
    </div>
</template>
