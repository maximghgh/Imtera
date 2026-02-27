<template>
    <div class="main">
        <div class="block">
            <h2 class="block__title">Авторизация</h2>
            <form @submit.prevent="fetchUser" class="form__auth">
                <div class="field">
                    <label for="" class="field__label">Логин</label>
                    <input v-model="form.login" type="text" class="field__input" placeholder="Логин">
                </div>
                <div class="field field--mb">
                    <label for="" class="field__label">Пароль</label>
                    <input v-model="form.password" type="password" class="field__input" placeholder="Пароль">
                </div>
                <button class="btn" :disabled="loading">
                    {{ loading ? 'Входим...' : 'Авторизоваться' }}
                </button>
                <p v-if="error" style="color: red; margin-top: 10px;">
                    {{ error }}
                </p>
            </form>
        </div>
    </div>
</template>

<script setup>
    import axios from 'axios';
    import { ref, onMounted } from 'vue';

    const loading = ref(false);
    const error = ref(null);

    const form = ref({
        login: '',
        password: '',
    });

    const fetchUser = async () => {
        loading.value = true;
        error.value = null;

        try {
            const response = await axios.post('/api/auth/login-or-register', {
                login: form.value.login,
                password: form.value.password,
            }, {
                headers: {
                    Accept: 'application/json',
                },
            });

            localStorage.setItem('token', response.data.token);
            axios.defaults.headers.common.Authorization = `Bearer ${response.data.token}`;
            window.location.href = '/dashboard';
        } catch (e) {
            error.value = e?.response?.data?.message ?? 'Неверный логин или пароль.';
        } finally {
            loading.value = false;
        }
    };

    onMounted(async () => {
        const token = localStorage.getItem('token');
        if (!token) {
            return;
        }

        axios.defaults.headers.common.Authorization = `Bearer ${token}`;

        try {
            await axios.get('/api/auth/me', {
                headers: {
                    Accept: 'application/json',
                },
            });
            window.location.href = '/dashboard';
        } catch (_) {
            localStorage.removeItem('token');
            delete axios.defaults.headers.common.Authorization;
        }
    });
</script>
