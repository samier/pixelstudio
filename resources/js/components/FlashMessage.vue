<script setup lang="ts">
import { flashMessage, showServerFlash } from '@/lib/flash';
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { CheckCircle2, X } from 'lucide-vue-next';
import { onBeforeUnmount, ref, watch } from 'vue';

const HIDE_AFTER_MS = 5000;

const page = usePage<SharedData>();
const visible = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

const dismiss = () => {
    visible.value = false;
    clearTimeout(timer);
};

// Show each message once (from the server or from showFlash), then hide it after a few seconds or when closed.
watch(
    () => flashMessage.value?.key,
    (key) => {
        clearTimeout(timer);
        visible.value = key !== undefined;
        if (visible.value) timer = setTimeout(dismiss, HIDE_AFTER_MS);
    },
);

// A message flashed by the server. Watched by id: the live-status reloads keep the old flash in the props,
// and that must not bring a closed message back.
watch(
    () => page.props.flash?.id,
    (id) => {
        if (id && page.props.flash.success) showServerFlash(id, page.props.flash.success);
    },
    { immediate: true },
);

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div
        v-if="visible && flashMessage"
        role="status"
        class="flex items-center gap-2 rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-950/30 dark:text-emerald-200"
    >
        <CheckCircle2 class="size-4 shrink-0" />
        <span class="flex-1">{{ flashMessage.text }}</span>
        <button
            type="button"
            class="-m-1 rounded p-1 opacity-70 transition hover:bg-emerald-100 hover:opacity-100 dark:hover:bg-emerald-900/40"
            aria-label="Dismiss message"
            @click="dismiss"
        >
            <X class="size-4" />
        </button>
    </div>
</template>
