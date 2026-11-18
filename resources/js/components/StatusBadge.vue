<script setup lang="ts">
import type { GenerationStatus } from '@/types';
import { CheckCircle2, Clock, Loader2, XCircle } from 'lucide-vue-next';
import { computed } from 'vue';

const props = defineProps<{ status: GenerationStatus }>();

const config = computed(
    () =>
        ({
            queued: { label: 'Queued', icon: Clock, spin: false, cls: 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300' },
            processing: { label: 'Processing', icon: Loader2, spin: true, cls: 'bg-blue-100 text-blue-800 dark:bg-blue-500/15 dark:text-blue-300' },
            succeeded: {
                label: 'Succeeded',
                icon: CheckCircle2,
                spin: false,
                cls: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300',
            },
            failed: { label: 'Failed', icon: XCircle, spin: false, cls: 'bg-red-100 text-red-800 dark:bg-red-500/15 dark:text-red-300' },
        })[props.status],
);
</script>

<template>
    <span :class="['inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium', config.cls]">
        <component :is="config.icon" :class="['size-3.5', config.spin && 'animate-spin']" />
        {{ config.label }}
    </span>
</template>
