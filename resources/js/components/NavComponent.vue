<script setup>
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';

const user = ref(null);

const accountName = computed(() => user.value?.login ?? 'Название аккаунта');
const isAuthenticated = computed(() => Boolean(user.value));
const isActive = (href) => window.location.pathname === href;

const loadUser = async () => {
    const token = localStorage.getItem('token');

    if (!token) {
        user.value = null;
        return;
    }

    axios.defaults.headers.common.Authorization = `Bearer ${token}`;

    try {
        const response = await axios.get('/api/auth/me', {
            headers: {
                Accept: 'application/json',
            },
        });

        user.value = response.data?.data ?? response.data;
    } catch (_) {
        localStorage.removeItem('token');
        delete axios.defaults.headers.common.Authorization;
        user.value = null;
    }
};

onMounted(() => {
    loadUser();
});
</script>

<template>
    <div>
        <nav class="menu">
            <a href="/dashboard" class="logo-link">
                <img src="../../img/лого.svg" alt="Логотип">
            </a>

            <span class="name__account">
                <a href="/profile" class="name__account">
                    {{ accountName }}
                </a>
            </span>

            <ul v-if="isAuthenticated" class="list">
                <li class="list__item list__item--xl">
                    <a href="#" class="list__link--xl">
                        <img src="../../img/icon__setting.svg" alt="Иконка настройки" class="icon">
                        Отзывы
                    </a>
                </li>
                <li class="list__item">
                    <a href="/review" class="list__link" :class="{ 'list__link--active': isActive('/review') }">
                        Отзывы
                    </a>
                </li>
                <li class="list__item">
                    <a href="/setting" class="list__link" :class="{ 'list__link--active': isActive('/setting') }">
                        Настройки
                    </a>
                </li>
            </ul>
        </nav>
    </div>
</template>
