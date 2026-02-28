<script setup>
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';

const user = ref(null);

const accountName = computed(() => user.value?.login ?? '');
const isAuthenticated = computed(() => Boolean(user.value));
const logoHref = computed(() => (isAuthenticated.value ? '/review' : '/'));
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
            <a :href="logoHref" class="menu__logo-link">
                <img src="../../img/лого.png" alt="Логотип">
            </a>

            <span v-if="isAuthenticated" class="menu__account-name">
                <a href="/profile" class="menu__account-name">
                    {{ accountName }}
                </a>
            </span>

            <ul v-if="isAuthenticated" class="menu__list">
                <li class="menu__item menu__item--primary">
                    <a href="/review" class="menu__link menu__link--primary" :class="{ 'menu__link--active': isActive('/review') }">
                        <img src="../../img/icon__setting.svg" alt="Иконка настройки" class="menu__icon">
                        Отзывы
                    </a>
                </li>
                <li class="menu__item">
                    <a href="/review" class="menu__link" :class="{ 'menu__link--active': isActive('/review') }">
                        Отзывы
                    </a>
                </li>
                <li class="menu__item">
                    <a href="/setting" class="menu__link" :class="{ 'menu__link--active': isActive('/setting') }">
                        Настройки
                    </a>
                </li>
            </ul>
        </nav>
    </div>
</template>
