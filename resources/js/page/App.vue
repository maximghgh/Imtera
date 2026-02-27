<script setup>
import MainComponent from './../components/MainComponent.vue';
import NavComponent from './../components/NavComponent.vue';
import Dachboard from './Dachboard.vue';
import Profile from './Profile.vue';
import Review from './Review.vue';
import Setting from './Setting.vue';

const isDashboardPage = window.location.pathname === '/dashboard';
const isProfilePage = window.location.pathname === '/profile';
const isReviewPage = window.location.pathname === '/review';
const isSettingPage = window.location.pathname === '/setting';
</script>

<template>
    <div class="app">
        <NavComponent />
        <Dachboard v-if="isDashboardPage" />
        <Profile v-else-if="isProfilePage" />
        <Review v-else-if="isReviewPage" />
        <Setting v-else-if="isSettingPage" />
        <MainComponent v-else />
    </div>
</template>
