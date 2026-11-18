<script setup lang="ts">
import { Button } from '@/components/ui/button';
import type { SharedData } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Coins, Receipt, Wand2 } from 'lucide-vue-next';

const page = usePage<SharedData>();

const features = [
    { icon: Wand2, title: 'Describe it', body: 'Write a prompt, pick a size and quality, and optionally add reference images.' },
    { icon: Coins, title: 'See the price first', body: 'A live estimate with a guaranteed maximum, before you spend anything.' },
    { icon: Receipt, title: 'Pay the exact cost', body: 'Charged to a billionth of a dollar once the image is done. Failures are free.' },
];
</script>

<template>
    <Head title="Welcome" />
    <div class="flex min-h-screen flex-col bg-background text-foreground">
        <header class="mx-auto flex w-full max-w-5xl items-center justify-between p-6">
            <span class="font-semibold">Pixel Studio</span>
            <nav class="flex gap-2">
                <Button v-if="page.props.auth.user" as-child><Link :href="route('dashboard')">Open studio</Link></Button>
                <template v-else>
                    <Button variant="ghost" as-child><Link :href="route('login')">Log in</Link></Button>
                    <Button as-child><Link :href="route('register')">Sign up</Link></Button>
                </template>
            </nav>
        </header>

        <main class="mx-auto flex w-full max-w-5xl flex-1 flex-col justify-center gap-12 p-6">
            <div class="max-w-2xl">
                <h1 class="text-4xl font-semibold tracking-tight md:text-5xl">Generate images. Know exactly what they cost.</h1>
                <p class="mt-4 text-lg text-muted-foreground">
                    Powered by Pixelforge. New accounts get $1.00 of free credit to try it out.
                </p>
                <Button size="lg" class="mt-6" as-child>
                    <Link :href="page.props.auth.user ? route('dashboard') : route('register')">Start generating</Link>
                </Button>
            </div>
            <div class="grid gap-4 md:grid-cols-3">
                <div v-for="f in features" :key="f.title" class="rounded-xl border bg-card p-5">
                    <component :is="f.icon" class="mb-3 size-5" />
                    <h2 class="font-medium">{{ f.title }}</h2>
                    <p class="mt-1 text-sm text-muted-foreground">{{ f.body }}</p>
                </div>
            </div>
        </main>
    </div>
</template>
