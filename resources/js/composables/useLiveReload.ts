import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, watch, type Ref } from 'vue';

/**
 * While `active` is true, re-fetch the given Inertia props every few seconds, so generation statuses,
 * costs and the balance update live without websockets.
 */
export function useLiveReload(active: Ref<boolean>, only: string[], intervalMs = 3000) {
    let timer: ReturnType<typeof setTimeout> | null = null;

    const schedule = () => {
        timer = setTimeout(() => {
            timer = null;
            router.reload({
                only: [...only, 'auth'],
                onFinish: () => {
                    if (active.value && !timer) schedule();
                },
            });
        }, intervalMs);
    };

    const stop = () => {
        if (timer) clearTimeout(timer);
        timer = null;
    };

    watch(active, (on) => (on ? !timer && schedule() : stop()), { immediate: true });
    onBeforeUnmount(stop);
}
