import { ref } from 'vue';

/**
 * The success message currently shown by <FlashMessage>. Every call to showFlash() gets a new key, so the
 * same text shown twice in a row (e.g. two generations queued) still appears again and restarts its timer.
 */
export const flashMessage = ref<{ text: string; key: number } | null>(null);

export const GENERATION_QUEUED = "Generation queued. You'll see it update below.";

let counter = 0;
let lastServerId: string | null = null;

export function showFlash(text: string): void {
    flashMessage.value = { text, key: ++counter };
}

/** Show a message flashed by the server, once per id (going back in history must not show it again). */
export function showServerFlash(id: string, text: string): void {
    if (id === lastServerId) return;
    lastServerId = id;
    showFlash(text);
}
