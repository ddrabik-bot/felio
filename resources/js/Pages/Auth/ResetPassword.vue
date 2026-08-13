<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';
const props = defineProps({ email: String, token: String });
const form = useForm({ email: props.email, token: props.token, password: '', password_confirmation: '' });
const submit = () => form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') });
</script>
<template><Head title="Choose a new password" /><AuthLayout title="Choose a new password" subtitle="Use a strong, unique password for your Felio account."><form class="mt-7 space-y-5" @submit.prevent="submit"><div><label class="field-label" for="email">Email</label><input id="email" v-model="form.email" class="field" type="email" autocomplete="email" required><p v-if="form.errors.email" class="field-error" role="alert">{{ form.errors.email }}</p></div><div><label class="field-label" for="password">New password</label><input id="password" v-model="form.password" class="field" type="password" autocomplete="new-password" required><p v-if="form.errors.password" class="field-error" role="alert">{{ form.errors.password }}</p></div><div><label class="field-label" for="password_confirmation">Confirm new password</label><input id="password_confirmation" v-model="form.password_confirmation" class="field" type="password" autocomplete="new-password" required></div><button class="btn btn-primary w-full" :disabled="form.processing" type="submit">Reset password</button></form></AuthLayout></template>
