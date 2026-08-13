<script setup>
defineProps({ title: String, subtitle: String });
</script>

<template>
    <main class="grid min-h-screen bg-page lg:grid-cols-2">
        <section class="hidden bg-brand-700 p-12 text-white lg:flex lg:flex-col lg:justify-between">
            <div class="flex items-center gap-3 text-xl font-bold"><span class="grid size-10 place-items-center rounded-xl bg-white text-brand-700">F</span> Felio</div>
            <div class="max-w-md"><p class="text-sm font-semibold tracking-[.16em] text-brand-100 uppercase">Invest with clarity</p><h1 class="mt-4 text-4xl font-semibold leading-tight">Your portfolio, in one calm place.</h1><p class="mt-5 text-lg leading-8 text-brand-100">Import your positions, follow exact valuations, and make considered decisions.</p></div>
            <p class="text-sm text-brand-100">Private portfolio intelligence.</p>
        </section>
        <section class="flex items-center justify-center px-4 py-10 sm:px-6">
            <div class="w-full max-w-md"><div class="mb-8 flex items-center gap-3 text-xl font-bold text-ink-950 lg:hidden"><span class="grid size-10 place-items-center rounded-xl bg-brand-600 text-white">F</span> Felio</div><div class="card"><p class="eyebrow">Welcome to Felio</p><h1 class="mt-2 text-2xl font-semibold tracking-tight text-ink-950">{{ title }}</h1><p v-if="subtitle" class="mt-2 muted">{{ subtitle }}</p><slot /></div></div>
        </section>
    </main>
</template>
