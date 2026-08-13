<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AuthLayout from '@/Layouts/AuthLayout.vue';
const form = useForm({ email: '' });
const submit = () => form.post('/forgot-password');
</script>
<template><Head title="Reset password" /><AuthLayout title="Reset your password" subtitle="We will send a secure reset link to your email."><p v-if="$page.props.flash?.status" class="notice notice-info mt-6" role="status">{{ $page.props.flash.status }}</p><form class="mt-7 space-y-5" @submit.prevent="submit"><div><label class="field-label" for="email">Email</label><input id="email" v-model="form.email" class="field" type="email" autocomplete="email" required><p v-if="form.errors.email" class="field-error" role="alert">{{ form.errors.email }}</p></div><button class="btn btn-primary w-full" :disabled="form.processing" type="submit">Email reset link</button></form><p class="mt-6 text-sm"><Link href="/login">Back to sign in</Link></p></AuthLayout></template>
