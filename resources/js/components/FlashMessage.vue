<script setup lang="ts">
import type { SharedData } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { CheckCircle2 } from 'lucide-vue-next';

const page = usePage<SharedData>();
</script>

<template>
    <div
        v-if="page.props.flash.success"
        role="status"
        class="flex items-center gap-2 rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-900 dark:border-emerald-500/30 dark:bg-emerald-950/30 dark:text-emerald-200"
    >
        <CheckCircle2 class="size-4 shrink-0" />
        {{ page.props.flash.success }}
    </div>
</template>
