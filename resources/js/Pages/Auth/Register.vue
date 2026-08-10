<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({ name: '', email: '', password: '', password_confirmation: '' });

const submit = () => form.post('/register', { onFinish: () => form.reset('password', 'password_confirmation') });
</script>

<template>
    <Head title="Create account" />
    <main>
        <h1>Create account</h1>
        <form @submit.prevent="submit">
            <label>Name <input v-model="form.name" autocomplete="name" required></label>
            <p v-if="form.errors.name" role="alert">{{ form.errors.name }}</p>
            <label>Email <input v-model="form.email" type="email" autocomplete="email" required></label>
            <p v-if="form.errors.email" role="alert">{{ form.errors.email }}</p>
            <label>Password <input v-model="form.password" type="password" autocomplete="new-password" required></label>
            <p v-if="form.errors.password" role="alert">{{ form.errors.password }}</p>
            <label>Confirm password <input v-model="form.password_confirmation" type="password" autocomplete="new-password" required></label>
            <button :disabled="form.processing" type="submit">Create account</button>
        </form>
        <p><Link href="/login">Already have an account?</Link></p>
    </main>
</template>
