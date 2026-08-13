<script setup>
import { useForm } from '@inertiajs/vue3';
import AppLayout from '@/Components/AppLayout.vue';
const form = useForm({ accountReference: '' });
const submit = () => form.post('/portfolio/onboarding');
</script>
<template><AppLayout><main class="app-content" aria-labelledby="portfolio-onboarding-heading"><section class="mx-auto max-w-2xl"><p class="eyebrow">Portfolio setup</p><div class="card mt-3"><h1 id="portfolio-onboarding-heading" class="text-2xl font-semibold tracking-tight text-ink-950">Set up your XTB portfolio</h1><p class="mt-3 muted">Create an active XTB portfolio before importing a workbook or viewing valuations.</p><form class="mt-7 space-y-5" @submit.prevent="submit"><div><label class="field-label" for="account-reference">XTB account reference <span class="font-normal text-slate-500">(optional)</span></label><input id="account-reference" v-model="form.accountReference" class="field" autocomplete="off" placeholder="Optional"><p v-if="form.errors.accountReference" class="field-error" role="alert">{{ form.errors.accountReference }}</p></div><button class="btn btn-primary" :disabled="form.processing" type="submit">Create active XTB portfolio</button></form></div></section></main></AppLayout></template>
