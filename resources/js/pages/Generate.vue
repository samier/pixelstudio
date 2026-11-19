<script setup lang="ts">
import FlashMessage from '@/components/FlashMessage.vue';
import GenerationCard from '@/components/GenerationCard.vue';
import InputError from '@/components/InputError.vue';
import Money from '@/components/Money.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useLiveReload } from '@/composables/useLiveReload';
import AppLayout from '@/layouts/AppLayout.vue';
import { estimate as estimateCost, type TokenCounts } from '@/lib/estimate';
import { GENERATION_QUEUED, showFlash } from '@/lib/flash';
import type { BreadcrumbItem, Generation, Pricing, SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { AlertTriangle, ImagePlus, Loader2, Wand2, X } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{
    pricing: Pricing | null;
    markupPercent: number;
    limits: { sizes: string[]; qualities: string[]; maxPromptLength: number; maxReferenceImages: number };
    recent: { data: Generation[] };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Generate', href: '/dashboard' }];
const page = usePage<SharedData>();

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: [] as string[],
});

const sizeLabels: Record<string, string> = { '1024x1024': 'Square', '1536x1024': 'Landscape', '1024x1536': 'Portrait' };
const testKeywords = ['[succeed]', '[fail]', '[slow]', '[duplicate]', '[overloaded]', '[blocked]'];

const references = computed(() => form.reference_images.filter((url) => url.trim() !== ''));
const promptLength = computed(() => [...form.prompt].length);

const estimate = computed(() =>
    props.pricing && form.prompt.trim() !== ''
        ? estimateCost(props.pricing, form.prompt.trim(), form.size, form.quality, references.value.length, props.markupPercent)
        : null,
);

const balance = computed(() => page.props.auth.balanceNanos ?? 0);
const cannotAfford = computed(() => estimate.value !== null && estimate.value.max.price > balance.value);

const tokenRows: { key: keyof TokenCounts; label: string; note: string }[] = [
    { key: 'input_text_tokens', label: 'Prompt text', note: '≈ 1 per 4 characters, ±15%' },
    { key: 'input_image_tokens', label: 'Reference images', note: '765 each' },
    { key: 'output_tokens', label: 'Image output', note: 'exact for size & quality' },
    { key: 'reasoning_tokens', label: 'Reasoning', note: 'varies, known after' },
];

const tokenRange = (key: keyof TokenCounts) => {
    if (!estimate.value) return '—';
    const { min, max } = estimate.value;
    const lo = min.tokens[key].toLocaleString();
    const hi = max.tokens[key].toLocaleString();
    return lo === hi ? lo : `${lo}–${hi}`;
};

const addReference = () => form.reference_images.length < props.limits.maxReferenceImages && form.reference_images.push('');
const removeReference = (i: number) => form.reference_images.splice(i, 1);
const addKeyword = (keyword: string) => (form.prompt = `${form.prompt.trimEnd()} ${keyword}`.trimStart());

// Shown here as well as flashed by the server: a live-status reload running at the same moment can use up
// the server's flash before this page reads it.
const submit = () => form.post(route('generations.store'), { preserveScroll: true, onSuccess: () => showFlash(GENERATION_QUEUED) });

const hasInFlight = computed(() => props.recent.data.some((g) => g.status === 'queued' || g.status === 'processing'));
useLiveReload(hasInFlight, ['recent']);
</script>

<template>
    <Head title="Generate" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-6xl flex-col gap-8 p-4 md:p-6">
            <FlashMessage />

            <div
                v-if="!pricing"
                class="flex items-start gap-3 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-950/30 dark:text-amber-200"
            >
                <AlertTriangle class="mt-0.5 size-4 shrink-0" />
                <p>We can't load Pixelforge's prices right now, so we can't show you what a generation would cost. Please refresh in a moment.</p>
            </div>

            <form class="grid gap-6 lg:grid-cols-[1fr_340px]" @submit.prevent="submit">
                <!-- Left: what to generate -->
                <div class="flex flex-col gap-6 rounded-xl border bg-card p-5 shadow-sm">
                    <div class="grid gap-2">
                        <div class="flex items-end justify-between">
                            <Label for="prompt" class="text-base">Prompt</Label>
                            <span class="text-xs tabular-nums text-muted-foreground" :class="promptLength > limits.maxPromptLength && 'text-red-600'">
                                {{ promptLength.toLocaleString() }} / {{ limits.maxPromptLength.toLocaleString() }}
                            </span>
                        </div>
                        <textarea
                            id="prompt"
                            v-model="form.prompt"
                            rows="5"
                            :maxlength="limits.maxPromptLength"
                            placeholder="A lighthouse on a cliff at dawn, oil painting"
                            class="w-full resize-y rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                            @keydown.meta.enter="submit"
                            @keydown.ctrl.enter="submit"
                        />
                        <InputError :message="form.errors.prompt" />
                        <div class="flex flex-wrap items-center gap-1.5 text-xs text-muted-foreground">
                            <span>Test keywords:</span>
                            <button
                                v-for="keyword in testKeywords"
                                :key="keyword"
                                type="button"
                                class="rounded border px-1.5 py-0.5 font-mono hover:bg-accent hover:text-accent-foreground"
                                @click="addKeyword(keyword)"
                            >
                                {{ keyword }}
                            </button>
                        </div>
                    </div>

                    <div class="grid gap-2">
                        <Label class="text-base">Size</Label>
                        <div class="grid grid-cols-3 gap-2">
                            <button
                                v-for="size in limits.sizes"
                                :key="size"
                                type="button"
                                :aria-pressed="form.size === size"
                                class="flex flex-col items-center gap-2 rounded-lg border p-3 text-sm transition-colors hover:bg-accent"
                                :class="form.size === size ? 'border-primary bg-accent ring-1 ring-primary' : ''"
                                @click="form.size = size"
                            >
                                <span
                                    class="block rounded-sm border-2 border-current opacity-60"
                                    :style="{ width: size === '1024x1536' ? '20px' : '30px', height: size === '1536x1024' ? '20px' : '30px' }"
                                />
                                <span class="font-medium">{{ sizeLabels[size] ?? size }}</span>
                                <span class="text-xs text-muted-foreground">{{ size.replace('x', ' × ') }}</span>
                            </button>
                        </div>
                        <InputError :message="form.errors.size" />
                    </div>

                    <div class="grid gap-2">
                        <Label class="text-base">Quality</Label>
                        <div class="inline-flex w-full rounded-lg border p-1">
                            <button
                                v-for="quality in limits.qualities"
                                :key="quality"
                                type="button"
                                :aria-pressed="form.quality === quality"
                                class="flex-1 rounded-md px-3 py-1.5 text-sm capitalize transition-colors"
                                :class="form.quality === quality ? 'bg-primary text-primary-foreground shadow' : 'hover:bg-accent'"
                                @click="form.quality = quality"
                            >
                                {{ quality }}
                            </button>
                        </div>
                        <InputError :message="form.errors.quality" />
                    </div>

                    <div class="grid gap-2">
                        <div class="flex items-center justify-between">
                            <Label class="text-base">Reference images <span class="font-normal text-muted-foreground">(optional)</span></Label>
                            <span class="text-xs text-muted-foreground">{{ form.reference_images.length }} / {{ limits.maxReferenceImages }}</span>
                        </div>
                        <p class="text-xs text-muted-foreground">Image URLs to guide the style. Each one adds 765 input tokens.</p>
                        <div v-for="(_, i) in form.reference_images" :key="i" class="flex flex-col gap-1">
                            <div class="flex gap-2">
                                <Input v-model="form.reference_images[i]" type="url" placeholder="https://example.com/style.png" />
                                <Button type="button" variant="ghost" size="icon" aria-label="Remove reference image" @click="removeReference(i)">
                                    <X />
                                </Button>
                            </div>
                            <InputError :message="(form.errors as Record<string, string>)[`reference_images.${i}`]" />
                        </div>
                        <InputError :message="form.errors.reference_images" />
                        <Button
                            v-if="form.reference_images.length < limits.maxReferenceImages"
                            type="button"
                            variant="outline"
                            size="sm"
                            class="w-fit"
                            @click="addReference"
                        >
                            <ImagePlus /> Add reference image
                        </Button>
                    </div>
                </div>

                <!-- Right: what it costs -->
                <aside class="flex h-fit flex-col gap-4 rounded-xl border bg-card p-5 shadow-sm lg:sticky lg:top-4">
                    <div>
                        <p class="text-sm text-muted-foreground">Estimated cost</p>
                        <p class="text-3xl font-semibold tracking-tight">
                            <template v-if="estimate">≈ <Money :nanos="estimate.expected.price" /></template>
                            <span v-else class="text-muted-foreground">—</span>
                        </p>
                        <p v-if="estimate" class="text-sm text-muted-foreground">
                            between <Money :nanos="estimate.min.price" /> and <Money :nanos="estimate.max.price" />
                        </p>
                        <p v-else class="text-sm text-muted-foreground">Write a prompt to see the price.</p>
                    </div>

                    <table class="w-full text-xs">
                        <thead class="text-muted-foreground">
                            <tr class="border-b">
                                <th class="py-1.5 text-left font-normal">Tokens</th>
                                <th class="py-1.5 text-right font-normal">Count</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in tokenRows" :key="row.key" class="border-b last:border-0">
                                <td class="py-1.5">
                                    {{ row.label }}
                                    <div class="text-muted-foreground">{{ row.note }}</div>
                                </td>
                                <td class="py-1.5 text-right tabular-nums">{{ tokenRange(row.key) }}</td>
                            </tr>
                        </tbody>
                    </table>

                    <p class="text-xs leading-relaxed text-muted-foreground">
                        Pixelforge's price plus {{ markupPercent }}%. Reasoning and exact text tokens are only known once the image is done, so we
                        reserve the maximum while it runs and then charge the exact cost. <strong>Failed generations are free.</strong>
                    </p>

                    <div class="flex items-center justify-between rounded-lg bg-muted px-3 py-2 text-sm">
                        <span class="text-muted-foreground">Your balance</span>
                        <Link :href="route('credits.index')" class="font-medium hover:underline"><Money :nanos="balance" /></Link>
                    </div>

                    <div
                        v-if="cannotAfford || page.props.errors.credits"
                        class="rounded-lg border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:border-red-500/30 dark:bg-red-950/30 dark:text-red-300"
                    >
                        <template v-if="cannotAfford && estimate">
                            This can cost up to <Money :nanos="estimate.max.price" />, more than your balance.
                        </template>
                        <template v-else>{{ page.props.errors.credits }}</template>
                        <Link :href="route('credits.index')" class="mt-1 block font-medium underline">Buy credits</Link>
                    </div>

                    <Button type="submit" size="lg" class="w-full" :disabled="!estimate || cannotAfford || form.processing">
                        <Loader2 v-if="form.processing" class="animate-spin" />
                        <Wand2 v-else />
                        Generate
                        <span v-if="estimate" class="font-normal opacity-80">· max <Money :nanos="estimate.max.price" /></span>
                    </Button>
                </aside>
            </form>

            <section class="flex flex-col gap-4">
                <div class="flex items-center justify-between">
                    <h2 class="text-lg font-semibold">Recent generations</h2>
                    <Link :href="route('generations.index')" class="text-sm text-muted-foreground hover:underline">View all →</Link>
                </div>
                <p v-if="!recent.data.length" class="rounded-xl border border-dashed p-8 text-center text-sm text-muted-foreground">
                    Nothing yet. Your images appear here as they're generated.
                </p>
                <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    <GenerationCard v-for="generation in recent.data" :key="generation.id" :generation="generation" />
                </div>
            </section>
        </div>
    </AppLayout>
</template>
