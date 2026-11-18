<script setup lang="ts">
import { exactUsd, formatUsd } from '@/lib/money';

/** A rounded amount whose exact nine-decimal value shows on hover. */
withDefaults(defineProps<{ nanos: number; decimals?: number }>(), { decimals: 4 });
</script>

<template>
    <span class="tabular-nums" :title="exactUsd(nanos)">{{ formatUsd(nanos, decimals) }}</span>
</template>
