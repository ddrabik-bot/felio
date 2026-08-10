<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({ email: '', password: '', remember: false });

const submit = () => form.post('/login', { onFinish: () => form.reset('password') });
</script>

<template>
    <Head title="Sign in" />
    <main>
        <h1>Sign in</h1>
        <form @submit.prevent="submit">
            <label>Email <input v-model="form.email" type="email" autocomplete="email" required></label>
            <p v-if="form.errors.email" role="alert">{{ form.errors.email }}</p>
            <label>Password <input v-model="form.password" type="password" autocomplete="current-password" required></label>
            <p v-if="form.errors.password" role="alert">{{ form.errors.password }}</p>
            <label><input v-model="form.remember" type="checkbox"> Remember me</label>
            <button :disabled="form.processing" type="submit">Sign in</button>
        </form>
        <p><Link href="/forgot-password">Forgot password?</Link></p>
        <p><Link href="/register">Create an account</Link></p>
    </main>
</template>
