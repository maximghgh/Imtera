<script setup>
import axios from 'axios';
import { onMounted, ref } from 'vue';

const user = ref(null);
const loading = ref(true);
const error = ref(null);

const goToLogin = () => {
    window.location.href = '/';
};

const goToReview = () => {
    window.location.href = '/review';
};

const loadUser = async () => {
    const token = localStorage.getItem('token');

    if (!token) {
        goToLogin();
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
        goToReview();
    } catch (_) {
        localStorage.removeItem('token');
        delete axios.defaults.headers.common.Authorization;
        goToLogin();
    } finally {
        loading.value = false;
    }
};

const logout = async () => {
    try {
        await axios.post('/api/auth/logout', {}, {
            headers: {
                Accept: 'application/json',
            },
        });
    } finally {
        localStorage.removeItem('token');
        delete axios.defaults.headers.common.Authorization;
        goToLogin();
    }
};

onMounted(() => {
    loadUser();
});
</script>

<template>
    <div class="main--dashboard">
        <div class="logout">
            <button class="btn btn--logout" @click="logout">
                <img src="../../img/logout.png" alt="Кнопка выйти">
            </button>
        </div>
    </div>
</template>
