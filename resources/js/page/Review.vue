<script setup>
import axios from 'axios';
import { onMounted, ref, watch } from 'vue';

const loading = ref(true);
const syncing = ref(false);
const error = ref('');
const sort = ref('newest');
const company = ref(null);
const reviews = ref([]);
const meta = ref({
    current_page: 1,
    last_page: 1,
    per_page: 5,
    total: 0,
});

const buildErrorText = (fallback, e) => {
    const message = e?.response?.data?.message ?? fallback;
    const code = e?.response?.data?.error;

    if (!code || typeof code !== 'string') {
        return message;
    }

    return `${message} [${code}]`;
};

const goToLogin = () => {
    window.location.href = '/login';
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

const ensureAuth = async () => {
    const token = localStorage.getItem('token');

    if (!token) {
        goToLogin();
        return false;
    }

    axios.defaults.headers.common.Authorization = `Bearer ${token}`;

    try {
        await axios.get('/api/auth/me', {
            headers: {
                Accept: 'application/json',
            },
        });
        return true;
    } catch (_) {
        localStorage.removeItem('token');
        delete axios.defaults.headers.common.Authorization;
        goToLogin();
        return false;
    }
};

const loadReviews = async (page = 1) => {
    loading.value = true;
    error.value = '';

    try {
        const response = await axios.get('/api/auth/yandex/reviews', {
            params: {
                sort: sort.value,
                page,
                per_page: 5,
            },
            headers: {
                Accept: 'application/json',
            },
        });

        company.value = response.data?.company ?? null;
        reviews.value = response.data?.reviews ?? [];
        meta.value = response.data?.meta ?? meta.value;
    } catch (e) {
        error.value = buildErrorText('Не удалось загрузить отзывы.', e);
    } finally {
        loading.value = false;
    }
};

const syncReviews = async () => {
    syncing.value = true;
    error.value = '';

    try {
        await axios.post('/api/auth/yandex/source/sync', {}, {
            headers: {
                Accept: 'application/json',
            },
        });

        await loadReviews(1);
    } catch (e) {
        error.value = buildErrorText('Не удалось обновить отзывы.', e);
    } finally {
        syncing.value = false;
    }
};

const goPage = (page) => {
    if (page < 1 || page > meta.value.last_page || page === meta.value.current_page) {
        return;
    }

    loadReviews(page);
};

const formatDate = (dateValue) => {
    if (!dateValue) {
        return 'Дата не указана';
    }

    const parsed = new Date(dateValue);

    if (Number.isNaN(parsed.getTime())) {
        return dateValue;
    }

    return new Intl.DateTimeFormat('ru-RU', {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    }).format(parsed);
};

const STAR_COUNT = 5;

const toNumberRating = (value) => {
    const parsed = Number(value);

    if (Number.isNaN(parsed)) {
        return null;
    }

    return Math.min(STAR_COUNT, Math.max(0, parsed));
};

const buildStars = (value) => {
    const rating = toNumberRating(value);
    const filledStars = rating === null ? 0 : Math.round(rating);

    return Array.from({ length: STAR_COUNT }, (_, index) => index < filledStars);
};

const formatRating = (value) => {
    const rating = toNumberRating(value);

    return rating === null ? '—' : rating.toFixed(1);
};

const getPhoneLabel = (review) => {
    const phone = review?.phone ?? review?.phone_number ?? '';

    if (typeof phone !== 'string') {
        return '';
    }

    return phone.trim();
};

watch(sort, () => {
    loadReviews(1);
});

onMounted(async () => {
    const ok = await ensureAuth();

    if (!ok) {
        return;
    }

    await loadReviews(1);
});
</script>

<template>
    <div class="main main--dashboard">
        <div class="logout">
            <button class="btn btn--logout" @click="logout">
                <img src="../../img/logout.png" alt="Кнопка выйти">
            </button>
        </div>

        <div class="reviews-page">
            <div class="reviews-toolbar">
                <div class="yandex-badge">
                    <img src="../../img/yandex-maps-logo.png" alt="Иконка Яндекс карты" class="yandex-badge__icon">
                    <span class="yandex-badge__title">Яндекс Карты</span>
                </div>
                <div class="reviews-toolbar__actions">
                    <select v-model="sort" class="form__input form__input--select">
                        <option value="newest">Сначала новые</option>
                        <option value="oldest">Сначала старые</option>
                    </select>
                </div> 
            </div>

            <p v-if="error" class="status status--error">{{ error }}</p>

            <div v-if="loading" class="reviews-loading">Загрузка...</div>

            <div v-else-if="!company" class="reviews-empty">
                Ссылка на Яндекс не настроена. Перейдите в раздел «Настройки» и сохраните ссылку.
            </div>

            <div v-else class="reviews-layout">
                <div class="reviews-list">
                        <article v-for="review in reviews" :key="review.id" class="review-card">
                            <div class="review-card__inner">
                                <div class="review-card__top">
                                    <div class="review-card__meta">
                                        <span class="review-card__date">{{ formatDate(review.published_at) }}</span>
                                        <span class="review-card__branch">
                                            <span>{{ company.company_name || 'Яндекс Карты' }}</span>
                                            <img src="../../img/yandex-maps-logo.png" alt="Иконка Яндекс карты" class="yandex-badge__icon">
                                        </span>
                                    </div>
                                    <div class="review-card__stars">
                                        <span
                                            v-for="(filled, index) in buildStars(review.rating)"
                                            :key="`${review.id}-star-${index}`"
                                            class="review-card__star"
                                            :class="{ 'review-card__star--active': filled }"
                                        >★</span>
                                    </div>
                                </div>

                                <p class="review-card__author-row">
                                    <span class="review-card__author">{{ review.author_name || 'Аноним' }}</span>
                                    <span v-if="getPhoneLabel(review)" class="review-card__phone">{{ getPhoneLabel(review) }}</span>
                                </p>

                                <p class="review-card__text">{{ review.body || 'Текст отзыва отсутствует.' }}</p>
                            </div>
                        </article>
                    <div class="pagination">
                        <button type="button" class="btn__setting btn__setting--ghost" @click="goPage(meta.current_page - 1)">
                            Назад
                        </button>
                        <span class="pagination__label">{{ meta.current_page }} / {{ meta.last_page }}</span>
                        <button type="button" class="btn__setting btn__setting--ghost" @click="goPage(meta.current_page + 1)">
                            Вперед
                        </button>
                    </div>
                </div>

                <aside class="reviews-summary">
                    <div class="reviews-summary__block">
                        <p class="reviews-summary__rating">{{ formatRating(company.company_rating) }}</p>
                        <div class="reviews-summary__stars">
                            <span
                                v-for="(filled, index) in buildStars(company.company_rating)"
                                :key="`company-star-${index}`"
                                class="reviews-summary__star"
                                :class="{ 'reviews-summary__star--active': filled }"
                            >★</span>
                        </div>
                    </div>
                    <p class="reviews-summary__count">Всего отзывов: {{ company.company_reviews_count ?? 0 }}</p>
                </aside>
            </div>
        </div>
    </div>
</template>
