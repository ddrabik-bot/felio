<script setup>
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({ activePortfolio: Object, portfolios: Array, batches: Array });
const preview = ref(null);
const error = ref(null);
const file = ref(null);
const loading = ref(false);
const mappings = ref({});
const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '';
const mappingSymbols = computed(() => [...new Set((preview.value?.rows ?? []).filter((row) => row.status === 'pending' && row.sourceSymbol).map((row) => row.sourceSymbol))]);

function selectedMappings() {
    return Object.fromEntries(
        Object.entries(mappings.value)
            .filter(([sourceSymbol, canonicalInstrument]) => sourceSymbol && canonicalInstrument.trim())
            .map(([sourceSymbol, canonicalInstrument]) => [sourceSymbol, canonicalInstrument.trim()]),
    );
}

function requestHeaders(json = false) {
    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-TOKEN': csrfToken,
        ...(json ? { 'Content-Type': 'application/json' } : {}),
    };
}

async function responseBody(response) {
    const body = await response.json();
    if (!response.ok) throw body;

    return body;
}

function choosePortfolio(event) {
    router.post('/portfolio/active', { portfolioId: Number(event.target.value) }, { onSuccess: () => router.reload() });
}

function upload() {
    if (!file.value) return;
    loading.value = true;
    error.value = null;
    const data = new FormData();
    data.append('workbook', file.value);
    fetch('/portfolio/imports/xtb', { method: 'POST', body: data, headers: requestHeaders() })
        .then(responseBody)
        .then((body) => {
            preview.value = body;
            mappings.value = {};
        })
        .catch((exception) => error.value = exception.errors?.workbook?.[0] || exception.message || 'Upload failed.')
        .finally(() => loading.value = false);
}

function confirm() {
    if (!preview.value) return;
    loading.value = true;
    fetch(`/portfolio/imports/xtb/${preview.value.importId}/confirm`, {
        method: 'POST',
        headers: requestHeaders(true),
        body: JSON.stringify({ mappings: selectedMappings() }),
    })
        .then(responseBody)
        .then(() => {
            preview.value = null;
            router.reload();
        })
        .catch((exception) => error.value = exception.errors?.workbook?.[0] || exception.message || 'Import confirmation failed.')
        .finally(() => loading.value = false);
}

function batchAction(id, action) {
    fetch(`/portfolio/import-batches/${id}${action === 'delete' ? '' : '/reprocess'}`, {
        method: action === 'delete' ? 'DELETE' : 'POST',
        headers: requestHeaders(true),
        body: action === 'delete' ? null : JSON.stringify({ mappings: mappings.value }),
    })
        .then(responseBody)
        .then(() => router.reload())
        .catch((exception) => error.value = exception.message || 'Batch action failed.');
}
</script>

<template>
    <main aria-labelledby="xtb-import-heading">
        <nav aria-label="Application">
            <a href="/portfolio/imports/xtb">Imports</a>
            <a href="/portfolio/valuation?date=2026-01-02">Valuation</a>
            <form action="/logout" method="post">
                <input type="hidden" name="_token" :value="csrfToken">
                <button type="submit">Log out</button>
            </form>
            <label>Active portfolio <select :value="activePortfolio.id" @change="choosePortfolio"><option v-for="portfolio in portfolios" :key="portfolio.id" :value="portfolio.id">{{ portfolio.broker }} — {{ portfolio.accountReference }}</option></select></label>
        </nav>
        <h1 id="xtb-import-heading">XTB import</h1>
        <input type="file" accept=".xlsx" @change="file = $event.target.files[0]">
        <button :disabled="loading || !file" @click="upload">Preview import</button>
        <p v-if="error" role="alert">{{ error }}</p>

        <section v-if="preview" aria-label="Import preview">
            <h2>Parse-only preview</h2>
            <p>Valid: {{ preview.summary.valid }}, pending: {{ preview.summary.pending }}, rejected: {{ preview.summary.rejected }}</p>
            <table>
                <caption>Parsed import rows</caption>
                <thead><tr><th>Status</th><th>Source row</th><th>Symbol</th><th>Operation</th><th>Quantity</th><th>Canonical instrument / diagnostic</th></tr></thead>
                <tbody><tr v-for="row in preview.rows" :key="`${row.sourceRowReference}-${row.status}`"><td>{{ row.status }}</td><td>{{ row.sourceRowReference }}</td><td>{{ row.sourceSymbol }}</td><td>{{ row.operation }}</td><td>{{ row.quantity }}</td><td>{{ row.canonicalInstrument || row.diagnostic }}</td></tr></tbody>
            </table>
            <fieldset v-if="mappingSymbols.length">
                <legend>Resolve pending instruments</legend>
                <label v-for="symbol in mappingSymbols" :key="symbol">{{ symbol }} <input v-model="mappings[symbol]" :aria-label="`Canonical instrument for ${symbol}`" placeholder="e.g. PZU.PL"></label>
            </fieldset>
            <button :disabled="loading" @click="confirm">Confirm import</button>
        </section>

        <section>
            <h2>Import batches</h2>
            <ul><li v-for="batch in batches" :key="batch.id">#{{ batch.id }} — {{ batch.status }} <button @click="batchAction(batch.id, 'reprocess')">Reprocess</button> <button @click="batchAction(batch.id, 'delete')">Destroy</button></li></ul>
        </section>
    </main>
</template>
