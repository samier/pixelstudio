<script setup lang="ts">
import { computed, ref } from 'vue';

const props = withDefaults(defineProps<{ text: string; maxWords?: number }>(), { maxWords: 50 });

const expanded = ref(false);
const words = computed(() => props.text.trim().split(/\s+/));
const isLong = computed(() => words.value.length > props.maxWords);
const shown = computed(() => (isLong.value && !expanded.value ? words.value.slice(0, props.maxWords).join(' ') + '…' : props.text));
</script>

<template>
    <span>
        {{ shown }}
        <button
            v-if="isLong"
            type="button"
            class="ml-1 whitespace-nowrap font-medium text-foreground underline-offset-4 hover:underline"
            :aria-expanded="expanded"
            @click="expanded = !expanded"
        >
            {{ expanded ? 'See less' : 'See more' }}
        </button>
    </span>
</template>
