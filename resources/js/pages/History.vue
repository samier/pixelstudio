<script setup lang="ts">
import FlashMessage from '@/components/FlashMessage.vue';
import GenerationCard from '@/components/GenerationCard.vue';
import { Button } from '@/components/ui/button';
import { useLiveReload } from '@/composables/useLiveReload';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem, Generation, ResourcePage } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    generations: ResourcePage<Generation>;
    filters: { status: string | null };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'History', href: '/generations' }];

const statuses = [
    { value: null, label: 'All' },
    { value: 'queued', label: 'Queued' },
    { value: 'processing', label: 'Processing' },
    { value: 'succeeded', label: 'Succeeded' },
    { value: 'failed', label: 'Failed' },
];

const hasInFlight = computed(() => props.generations.data.some((g) => g.status === 'queued' || g.status === 'processing'));
useLiveReload(hasInFlight, ['generations']);
</script>

<template>
    <Head title="History" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-6">
            <FlashMessage />

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold">Your generations</h1>
                    <p class="text-sm text-muted-foreground">{{ generations.meta.total }} total · statuses update live</p>
                </div>
                <nav class="inline-flex rounded-lg border p-1" aria-label="Filter by status">
                    <Link
                        v-for="s in statuses"
                        :key="s.label"
                        :href="route('generations.index', s.value ? { status: s.value } : {})"
                        preserve-scroll
                        class="rounded-md px-3 py-1 text-sm transition-colors"
                        :class="filters.status === s.value ? 'bg-primary text-primary-foreground' : 'hover:bg-accent'"
                    >
                        {{ s.label }}
                    </Link>
                </nav>
            </div>

            <div v-if="!generations.data.length" class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">
                No generations here yet.
                <Link :href="route('dashboard')" class="font-medium text-foreground underline">Generate one</Link>
            </div>

            <div v-else class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                <GenerationCard v-for="generation in generations.data" :key="generation.id" :generation="generation" />
            </div>

            <div v-if="generations.meta.last_page > 1" class="flex items-center justify-between">
                <Button variant="outline" size="sm" :disabled="!generations.links.prev" as-child>
                    <Link v-if="generations.links.prev" :href="generations.links.prev" preserve-scroll>← Newer</Link>
                    <span v-else>← Newer</span>
                </Button>
                <span class="text-sm text-muted-foreground">Page {{ generations.meta.current_page }} of {{ generations.meta.last_page }}</span>
                <Button variant="outline" size="sm" :disabled="!generations.links.next" as-child>
                    <Link v-if="generations.links.next" :href="generations.links.next" preserve-scroll>Older →</Link>
                    <span v-else>Older →</span>
                </Button>
            </div>
        </div>
    </AppLayout>
</template>
