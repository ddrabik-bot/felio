<script setup>
import { useForm } from '@inertiajs/vue3';

const form = useForm({ accountReference: '' });
const submit = () => form.post('/portfolio/onboarding');
</script>

<template>
    <main aria-labelledby="portfolio-onboarding-heading">
        <h1 id="portfolio-onboarding-heading">Set up your XTB portfolio</h1>
        <p>Create an active XTB portfolio before importing a workbook or viewing valuations.</p>
        <form @submit.prevent="submit">
            <label>
                XTB account reference
                <input v-model="form.accountReference" autocomplete="off" required>
            </label>
            <p v-if="form.errors.accountReference" role="alert">{{ form.errors.accountReference }}</p>
            <button :disabled="form.processing" type="submit">Create active XTB portfolio</button>
        </form>
    </main>
</template>
