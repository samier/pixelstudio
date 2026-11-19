<script setup lang="ts">
import FlashMessage from '@/components/FlashMessage.vue';
import Money from '@/components/Money.vue';
import TruncatedText from '@/components/TruncatedText.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { NANOS_PER_USD } from '@/lib/money';
import type { BreadcrumbItem, Page, SharedData } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { CreditCard, Loader2 } from 'lucide-vue-next';
import { computed } from 'vue';

interface Transaction {
    id: number;
    type: 'grant' | 'purchase' | 'hold' | 'hold_release' | 'charge';
    amount_nanos: number;
    description: string;
    prompt: string | null;
    created_at: string;
}

const props = defineProps<{
    options: number[];
    customMin: number;
    customMax: number;
    transactions: Page<Transaction>;
    reservedNanos: number;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Credits', href: '/credits' }];
const page = usePage<SharedData>();

const form = useForm({ amount: 0 });
const buy = (amount: number) => {
    form.amount = amount;
    form.post(route('credits.store'), { preserveScroll: true });
};

// Custom amount: sent as the typed string so the server parses it exactly (no float rounding).
const custom = useForm({ amount: '' });
const customValid = computed(() => {
    const value = custom.amount.trim();
    if (!/^\d{1,9}(\.\d{1,2})?$/.test(value)) return false;
    const n = Number(value);
    return n >= props.customMin && n <= props.customMax;
});
const buyCustom = () => {
    if (!customValid.value) return;
    custom.amount = custom.amount.trim();
    custom.post(route('credits.store'), { preserveScroll: true, onSuccess: () => custom.reset() });
};
const busy = computed(() => form.processing || custom.processing);

const typeLabels: Record<Transaction['type'], string> = {
    grant: 'Welcome credit',
    purchase: 'Purchase',
    hold: 'Reserved',
    hold_release: 'Reservation released',
    charge: 'Charge',
};

const when = (iso: string) => new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
</script>

<template>
    <Head title="Credits" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
            <FlashMessage />

            <section class="grid gap-4 md:grid-cols-[1fr_1.4fr]">
                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <p class="text-sm text-muted-foreground">Available balance</p>
                    <p class="text-4xl font-semibold tracking-tight"><Money :nanos="page.props.auth.balanceNanos ?? 0" /></p>
                    <p v-if="reservedNanos > 0" class="mt-1 text-sm text-muted-foreground">
                        + <Money :nanos="reservedNanos" /> reserved for generations in progress
                    </p>
                    <p class="mt-3 text-xs text-muted-foreground">
                        1 credit = 1 US dollar. Amounts are tracked to a billionth of a dollar; hover any amount to see it exactly.
                    </p>
                </div>

                <div class="rounded-xl border bg-card p-5 shadow-sm">
                    <h2 class="font-medium">Buy credits</h2>
                    <p class="mb-4 text-sm text-muted-foreground">This is a demo: no payment is taken, credits are added instantly.</p>
                    <div class="grid grid-cols-3 gap-2">
                        <Button v-for="amount in options" :key="amount" variant="outline" size="lg" :disabled="busy" @click="buy(amount)">
                            <Loader2 v-if="form.processing && form.amount === amount" class="animate-spin" />
                            <CreditCard v-else />
                            ${{ amount }}
                        </Button>
                    </div>
                    <p v-if="form.errors.amount" class="mt-2 text-sm text-red-600">{{ form.errors.amount }}</p>

                    <form class="mt-4 border-t pt-4" @submit.prevent="buyCustom">
                        <Label for="custom-amount">Or enter an amount</Label>
                        <div class="mt-1.5 flex gap-2">
                            <div class="relative flex-1">
                                <span class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-sm text-muted-foreground">$</span>
                                <Input
                                    id="custom-amount"
                                    v-model="custom.amount"
                                    type="text"
                                    inputmode="decimal"
                                    autocomplete="off"
                                    placeholder="12.50"
                                    class="pl-7"
                                    :aria-invalid="!!custom.errors.amount"
                                />
                            </div>
                            <Button type="submit" :disabled="busy || !customValid">
                                <Loader2 v-if="custom.processing" class="animate-spin" />
                                <CreditCard v-else />
                                Add
                            </Button>
                        </div>
                        <p v-if="custom.errors.amount" class="mt-2 text-sm text-red-600">{{ custom.errors.amount }}</p>
                        <p v-else class="mt-2 text-xs text-muted-foreground">Between ${{ customMin }} and ${{ customMax }}, up to two decimals.</p>
                    </form>
                </div>
            </section>

            <section class="rounded-xl border bg-card shadow-sm">
                <h2 class="border-b px-5 py-3 font-medium">Activity</h2>
                <p v-if="!transactions.data.length" class="p-5 text-sm text-muted-foreground">No activity yet.</p>
                <div v-else class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="text-left text-xs text-muted-foreground">
                            <tr class="border-b">
                                <th class="px-5 py-2 font-normal">When</th>
                                <th class="px-5 py-2 font-normal">What</th>
                                <th class="px-5 py-2 text-right font-normal">Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="t in transactions.data" :key="t.id" class="border-b last:border-0">
                                <td class="whitespace-nowrap px-5 py-2 text-muted-foreground">{{ when(t.created_at) }}</td>
                                <td class="px-5 py-2">
                                    <span class="font-medium">{{ typeLabels[t.type] }}</span>
                                    <span v-if="t.prompt" class="ml-2 text-muted-foreground"
                                        >“<TruncatedText :text="t.prompt" :max-words="50" />”</span
                                    >
                                </td>
                                <td
                                    class="whitespace-nowrap px-5 py-2 text-right"
                                    :class="t.amount_nanos > 0 ? 'text-emerald-700 dark:text-emerald-400' : ''"
                                >
                                    {{ t.amount_nanos > 0 ? '+' : ''
                                    }}<Money :nanos="t.amount_nanos" :decimals="Math.abs(t.amount_nanos) >= NANOS_PER_USD ? 2 : 4" />
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-if="transactions.last_page > 1" class="flex justify-between border-t px-5 py-3 text-sm">
                    <Link v-if="transactions.prev_page_url" :href="transactions.prev_page_url" preserve-scroll class="hover:underline">← Newer</Link>
                    <span v-else />
                    <span class="text-muted-foreground">Page {{ transactions.current_page }} of {{ transactions.last_page }}</span>
                    <Link v-if="transactions.next_page_url" :href="transactions.next_page_url" preserve-scroll class="hover:underline">Older →</Link>
                    <span v-else />
                </div>
            </section>
        </div>
    </AppLayout>
</template>
