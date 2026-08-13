<script setup>
import { useForm } from '@inertiajs/vue3';

const form = useForm({});
const submit = () => form.post('/portfolio/onboarding');
</script>

<template>
    <main aria-labelledby="portfolio-onboarding-heading">
        <h1 id="portfolio-onboarding-heading">Set up your XTB portfolio</h1>
        <p>Create an active XTB portfolio before importing a workbook or viewing valuations. The selected portfolio receives imported transactions regardless of the account reference in the workbook.</p>
        <form @submit.prevent="submit">
            <button :disabled="form.processing" type="submit">Create active XTB portfolio</button>
        </form>
    </main>
</template>
