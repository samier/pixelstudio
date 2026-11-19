<script setup lang="ts">
import Money from '@/components/Money.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { GENERATION_QUEUED, showFlash } from '@/lib/flash';
import type { Generation } from '@/types';
import { useForm } from '@inertiajs/vue3';
import { AlertTriangle, ImageOff, RotateCcw, Sparkles } from 'lucide-vue-next';
import { computed, ref } from 'vue';

const props = defineProps<{ generation: Generation }>();

const g = computed(() => props.generation);
const inFlight = computed(() => g.value.status === 'queued' || g.value.status === 'processing');
const imageBroken = ref(false);

const aspect = computed(() => {
    const [w, h] = g.value.size.split('x').map(Number);
    return `${w} / ${h}`;
});

const retry = useForm({});
const submitRetry = () =>
    retry.post(route('generations.retry', { generation: g.value.id }), { preserveScroll: true, onSuccess: () => showFlash(GENERATION_QUEUED) });

const createdAt = computed(() =>
    new Date(g.value.created_at).toLocaleString(undefined, { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' }),
);

/** Difference between what we charged and the up-front expected figure. */
const delta = computed(() => (g.value.charged_nanos === null ? null : g.value.charged_nanos - g.value.estimated_nanos));
</script>

<template>
    <article class="flex flex-col overflow-hidden rounded-xl border bg-card text-card-foreground shadow-sm">
        <div class="relative w-full overflow-hidden bg-muted" :style="{ aspectRatio: aspect }">
            <!-- In flight -->
            <div v-if="inFlight" class="absolute inset-0 flex flex-col items-center justify-center gap-2 p-4 text-center">
                <div class="absolute inset-0 animate-pulse bg-gradient-to-br from-muted via-muted-foreground/10 to-muted" />
                <Sparkles class="relative size-6 text-muted-foreground" :class="g.status === 'processing' && 'animate-pulse'" />
                <p class="relative text-sm text-muted-foreground">{{ g.status_detail ?? (g.status === 'queued' ? 'Queued' : 'Generating') }}…</p>
            </div>

            <!-- Succeeded -->
            <template v-else-if="g.status === 'succeeded'">
                <a v-if="g.image_url && !imageBroken" :href="g.image_url" target="_blank" rel="noopener" class="block h-full">
                    <img :src="g.image_url" :alt="g.prompt" loading="lazy" class="h-full w-full object-cover" @error="imageBroken = true" />
                </a>
                <div v-else class="absolute inset-0 flex flex-col items-center justify-center gap-2 p-4 text-center text-muted-foreground">
                    <ImageOff class="size-6" />
                    <p class="text-sm">Image link expired</p>
                    <p class="text-xs">Pixelforge image links last 24 hours.</p>
                </div>
            </template>

            <!-- Failed -->
            <div
                v-else
                class="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-red-50 p-4 text-center text-red-800 dark:bg-red-950/40 dark:text-red-300"
            >
                <AlertTriangle class="size-6" />
                <p class="text-sm">{{ g.error_message ?? 'The generation failed. You were not charged.' }}</p>
                <Button size="sm" variant="outline" class="mt-1" :disabled="retry.processing" @click="submitRetry"> <RotateCcw /> Try again </Button>
            </div>
        </div>

        <div class="flex flex-1 flex-col gap-3 p-4">
            <div class="flex items-center justify-between gap-2">
                <StatusBadge :status="g.status" />
                <time class="text-xs text-muted-foreground" :datetime="g.created_at">{{ createdAt }}</time>
            </div>

            <p class="line-clamp-2 text-sm" :title="g.prompt">{{ g.prompt }}</p>

            <div class="flex flex-wrap gap-1.5 text-xs text-muted-foreground">
                <span class="rounded bg-muted px-1.5 py-0.5">{{ g.size }}</span>
                <span class="rounded bg-muted px-1.5 py-0.5 capitalize">{{ g.quality }}</span>
                <span v-if="g.reference_images.length" class="rounded bg-muted px-1.5 py-0.5">
                    {{ g.reference_images.length }} reference{{ g.reference_images.length > 1 ? 's' : '' }}
                </span>
            </div>

            <dl class="mt-auto grid grid-cols-2 gap-x-3 gap-y-1 border-t pt-3 text-xs">
                <dt class="text-muted-foreground">Estimated</dt>
                <dd class="text-right">
                    ≈ <Money :nanos="g.estimated_nanos" />
                    <span class="text-muted-foreground"> (<Money :nanos="g.estimated_min_nanos" />–<Money :nanos="g.estimated_max_nanos" />) </span>
                </dd>

                <dt class="text-muted-foreground">Actual</dt>
                <dd class="text-right font-medium">
                    <template v-if="g.status === 'succeeded' && g.charged_nanos !== null">
                        <Money :nanos="g.charged_nanos" />
                        <span v-if="delta !== null && delta !== 0" class="ml-1 font-normal text-muted-foreground">
                            ({{ delta > 0 ? '+' : '−' }}<Money :nanos="Math.abs(delta)" />)
                        </span>
                    </template>
                    <span v-else-if="g.status === 'failed'" class="text-emerald-700 dark:text-emerald-400">Free (not charged)</span>
                    <span v-else class="font-normal text-muted-foreground"> <Money :nanos="g.hold_nanos" /> reserved </span>
                </dd>
            </dl>
        </div>
    </article>
</template>
