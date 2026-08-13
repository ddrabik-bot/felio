<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const open = ref(false);
const page = usePage();
const currentPath = computed(() => page.url.split('?')[0]);
const valuationDate = computed(() => page.props.valuationDate ?? new Date().toISOString().slice(0, 10));
const valuationHref = computed(() => `/portfolio/valuation?date=${encodeURIComponent(valuationDate.value)}`);
const links = computed(() => [
    { label: 'Dashboard / Valuation', href: valuationHref.value },
    { label: 'Import XTB', href: '/portfolio/imports/xtb' },
]);

const isCurrent = (href) => {
    const base = href.split('?')[0];

    return currentPath.value === base || currentPath.value.startsWith(`${base}/`);
};

const logout = () => router.post('/logout', {}, { preserveScroll: true });
</script>

<template>
    <div class="app-shell">
        <header class="border-b border-slate-200/80 bg-white/90 backdrop-blur">
            <div class="mx-auto flex min-h-18 max-w-7xl items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
                <Link :href="valuationHref" class="flex items-center gap-2.5 font-bold text-ink-950 no-underline" aria-label="Felio dashboard">
                    <span class="grid size-9 place-items-center rounded-xl bg-brand-600 text-lg text-white shadow-sm">F</span>
                    <span>Felio</span>
                </Link>
                <button class="btn btn-secondary sm:hidden" type="button" :aria-expanded="open" aria-controls="application-navigation" @click="open = !open">Menu</button>
                <nav id="application-navigation" :class="[open ? 'flex' : 'hidden', 'absolute inset-x-4 top-16 z-10 flex-col gap-2 rounded-card border border-slate-200 bg-white p-3 shadow-card sm:static sm:flex sm:flex-row sm:items-center sm:border-0 sm:bg-transparent sm:p-0 sm:shadow-none']" aria-label="Application">
                    <Link v-for="link in links" :key="link.href" :href="link.href" :aria-current="isCurrent(link.href) ? 'page' : undefined" :class="[isCurrent(link.href) ? 'bg-brand-50 text-brand-700' : 'text-slate-600 hover:bg-slate-50', 'rounded-control px-3 py-2 text-sm font-medium no-underline']" @click="open = false">{{ link.label }}</Link>
                    <button class="btn btn-secondary" type="button" @click="logout">Log out</button>
                </nav>
            </div>
        </header>
        <slot />
    </div>
</template>
