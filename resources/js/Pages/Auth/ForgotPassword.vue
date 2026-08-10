<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({ email: '' });
const submit = () => form.post('/forgot-password');
</script>

<template>
    <Head title="Reset password" />
    <main>
        <h1>Reset password</h1>
        <p v-if="$page.props.flash?.status" role="status">{{ $page.props.flash.status }}</p>
        <form @submit.prevent="submit">
            <label>Email <input v-model="form.email" type="email" autocomplete="email" required></label>
            <p v-if="form.errors.email" role="alert">{{ form.errors.email }}</p>
            <button :disabled="form.processing" type="submit">Email reset link</button>
        </form>
        <p><Link href="/login">Back to sign in</Link></p>
    </main>
</template>
