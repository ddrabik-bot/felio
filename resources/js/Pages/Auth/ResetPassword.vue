<script setup>
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({ email: String, token: String });
const form = useForm({ email: props.email, token: props.token, password: '', password_confirmation: '' });
const submit = () => form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') });
</script>

<template>
    <Head title="Choose a new password" />
    <main>
        <h1>Choose a new password</h1>
        <form @submit.prevent="submit">
            <label>Email <input v-model="form.email" type="email" autocomplete="email" required></label>
            <p v-if="form.errors.email" role="alert">{{ form.errors.email }}</p>
            <label>New password <input v-model="form.password" type="password" autocomplete="new-password" required></label>
            <p v-if="form.errors.password" role="alert">{{ form.errors.password }}</p>
            <label>Confirm new password <input v-model="form.password_confirmation" type="password" autocomplete="new-password" required></label>
            <button :disabled="form.processing" type="submit">Reset password</button>
        </form>
    </main>
</template>
