<script setup lang="ts">
import Money from '@/components/Money.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { exactUsd } from '@/lib/money';
import type { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/vue3';
import { AlertTriangle, CheckCircle2, XCircle } from 'lucide-vue-next';
import { computed, reactive } from 'vue';

interface Row {
    generations: number;
    charged: number;
    cost: number;
    profit: number;
    reported_cost: number | null;
    reported_generations: number | null;
    matches: boolean | null;
}

const props = defineProps<{
    range: { start: string; end: string };
    days: (Row & { date: string })[];
    users: (Row & { name: string; email: string; balance: number })[];
    total: Row;
    reportError: string | null;
    failedCount: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Operator report', href: '/admin/report' }];

const filters = reactive({ ...props.range });
const apply = () => router.get(route('admin.report'), filters, { preserveScroll: true });

const activeDays = computed(() => props.days.filter((d) => d.generations > 0 || (d.reported_generations ?? 0) > 0));
const mismatches = computed(() => props.days.filter((d) => d.matches === false).length);
const margin = computed(() => (props.total.charged ? ((props.total.profit / props.total.charged) * 100).toFixed(1) : '0.0'));
</script>

<template>
    <Head title="Operator report" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-6xl flex-col gap-6 p-4 md:p-6">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div>
                    <h1 class="text-xl font-semibold">Operator report</h1>
                    <p class="text-sm text-muted-foreground">
                        Succeeded generations, grouped by the UTC day they completed (the day Pixelforge bills them).
                    </p>
                </div>
                <form class="flex items-end gap-2" @submit.prevent="apply">
                    <div class="grid gap-1">
                        <Label for="start" class="text-xs">From</Label>
                        <Input id="start" v-model="filters.start" type="date" class="h-8" />
                    </div>
                    <div class="grid gap-1">
                        <Label for="end" class="text-xs">To</Label>
                        <Input id="end" v-model="filters.end" type="date" class="h-8" />
                    </div>
                    <Button size="sm" type="submit">Apply</Button>
                </form>
            </div>

            <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-sm text-muted-foreground">Credit spend (charged to users)</p>
                    <p class="text-2xl font-semibold"><Money :nanos="total.charged" /></p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-sm text-muted-foreground">Provider cost (our records)</p>
                    <p class="text-2xl font-semibold"><Money :nanos="total.cost" /></p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-sm text-muted-foreground">Profit</p>
                    <p class="text-2xl font-semibold"><Money :nanos="total.profit" /></p>
                    <p class="text-xs text-muted-foreground">{{ margin }}% of spend · {{ total.generations }} images · {{ failedCount }} failed (free)</p>
                </div>
                <div class="rounded-xl border bg-card p-4 shadow-sm">
                    <p class="text-sm text-muted-foreground">Pixelforge reports (GET /v1/usage)</p>
                    <template v-if="total.reported_cost !== null">
                        <p class="text-2xl font-semibold"><Money :nanos="total.reported_cost" /></p>
                        <p v-if="mismatches === 0 && total.matches" class="flex items-center gap-1 text-xs text-emerald-700 dark:text-emerald-400">
                            <CheckCircle2 class="size-3.5" /> Matches our records exactly
                        </p>
                        <p v-else class="flex items-center gap-1 text-xs text-red-700 dark:text-red-400">
                            <XCircle class="size-3.5" /> {{ mismatches }} day(s) differ — see below
                        </p>
                    </template>
                    <p v-else class="flex items-center gap-1 text-sm text-amber-700"><AlertTriangle class="size-4" /> Unavailable</p>
                </div>
            </div>

            <p v-if="reportError" class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-950/30 dark:text-amber-200">
                {{ reportError }}
            </p>

            <section class="rounded-xl border bg-card shadow-sm">
                <h2 class="border-b px-5 py-3 font-medium">By day <span class="text-sm font-normal text-muted-foreground">(days with activity)</span></h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-right text-xs text-muted-foreground">
                            <tr class="border-b">
                                <th class="px-4 py-2 text-left font-normal">Date (UTC)</th>
                                <th class="px-4 py-2 font-normal">Images</th>
                                <th class="px-4 py-2 font-normal">Credit spend</th>
                                <th class="px-4 py-2 font-normal">Provider cost</th>
                                <th class="px-4 py-2 font-normal">Profit</th>
                                <th class="px-4 py-2 font-normal">Pixelforge says</th>
                                <th class="px-4 py-2 text-center font-normal">Match</th>
                            </tr>
                        </thead>
                        <tbody class="text-right tabular-nums">
                            <tr v-if="!activeDays.length">
                                <td colspan="7" class="px-4 py-6 text-center text-muted-foreground">No activity in this range.</td>
                            </tr>
                            <tr v-for="d in activeDays" :key="d.date" class="border-b last:border-0" :class="d.matches === false && 'bg-red-50 dark:bg-red-950/30'">
                                <td class="px-4 py-2 text-left">{{ d.date }}</td>
                                <td class="px-4 py-2">{{ d.generations }}</td>
                                <td class="px-4 py-2" :title="exactUsd(d.charged)">{{ exactUsd(d.charged) }}</td>
                                <td class="px-4 py-2">{{ exactUsd(d.cost) }}</td>
                                <td class="px-4 py-2">{{ exactUsd(d.profit) }}</td>
                                <td class="px-4 py-2">
                                    <template v-if="d.reported_cost !== null">{{ exactUsd(d.reported_cost) }} · {{ d.reported_generations }}</template>
                                    <template v-else>—</template>
                                </td>
                                <td class="px-4 py-2 text-center">
                                    <CheckCircle2 v-if="d.matches" class="inline size-4 text-emerald-600" />
                                    <XCircle v-else-if="d.matches === false" class="inline size-4 text-red-600" />
                                    <span v-else>—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="rounded-xl border bg-card shadow-sm">
                <h2 class="border-b px-5 py-3 font-medium">By user</h2>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-right text-xs text-muted-foreground">
                            <tr class="border-b">
                                <th class="px-4 py-2 text-left font-normal">User</th>
                                <th class="px-4 py-2 font-normal">Images</th>
                                <th class="px-4 py-2 font-normal">Credit spend</th>
                                <th class="px-4 py-2 font-normal">Provider cost</th>
                                <th class="px-4 py-2 font-normal">Profit</th>
                                <th class="px-4 py-2 font-normal">Balance now</th>
                            </tr>
                        </thead>
                        <tbody class="text-right tabular-nums">
                            <tr v-if="!users.length">
                                <td colspan="6" class="px-4 py-6 text-center text-muted-foreground">No activity in this range.</td>
                            </tr>
                            <tr v-for="u in users" :key="u.email" class="border-b last:border-0">
                                <td class="px-4 py-2 text-left">
                                    {{ u.name }}
                                    <div class="text-xs text-muted-foreground">{{ u.email }}</div>
                                </td>
                                <td class="px-4 py-2">{{ u.generations }}</td>
                                <td class="px-4 py-2">{{ exactUsd(u.charged) }}</td>
                                <td class="px-4 py-2">{{ exactUsd(u.cost) }}</td>
                                <td class="px-4 py-2">{{ exactUsd(u.profit) }}</td>
                                <td class="px-4 py-2"><Money :nanos="u.balance" /></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
