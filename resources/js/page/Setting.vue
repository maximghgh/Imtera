<script setup>
import axios from 'axios';
import { onMounted, ref } from 'vue';

const sourceUrl = ref('');
const sourceInfo = ref(null);
const saving = ref(false);
const syncing = ref(false);
const error = ref('');
const success = ref('');

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

const loadSource = async () => {
    const response = await axios.get('/api/auth/yandex/source', {
        headers: {
            Accept: 'application/json',
        },
    });

    sourceInfo.value = response.data?.source ?? null;
    sourceUrl.value = sourceInfo.value?.source_url ?? '';
};

const saveSource = async () => {
    saving.value = true;
    error.value = '';
    success.value = '';

    try {
        const response = await axios.post('/api/auth/yandex/source', {
            source_url: sourceUrl.value,
        }, {
            headers: {
                Accept: 'application/json',
            },
        });

        sourceInfo.value = response.data?.source ?? null;
        success.value = `Ссылка сохранена. Загружено отзывов: ${response.data?.reviews_synced ?? 0}`;
    } catch (e) {
        if (e?.response?.data?.source) {
            sourceInfo.value = e.response.data.source;
        }
        error.value = buildErrorText('Не удалось сохранить ссылку.', e);
    } finally {
        saving.value = false;
    }
};

const syncReviews = async () => {
    syncing.value = true;
    error.value = '';
    success.value = '';

    try {
        const inputUrl = sourceUrl.value.trim();
        const savedUrl = (sourceInfo.value?.source_url ?? '').trim();

        if (inputUrl && inputUrl !== savedUrl) {
            const saveResponse = await axios.post('/api/auth/yandex/source', {
                source_url: inputUrl,
            }, {
                headers: {
                    Accept: 'application/json',
                },
            });

            sourceInfo.value = saveResponse.data?.source ?? null;
            success.value = `Ссылка обновлена. Загружено отзывов: ${saveResponse.data?.reviews_synced ?? 0}`;
            return;
        }

        const response = await axios.post('/api/auth/yandex/source/sync', {}, {
            headers: {
                Accept: 'application/json',
            },
        });

        sourceInfo.value = response.data?.source ?? null;
        success.value = `Отзывы обновлены. Загружено: ${response.data?.reviews_synced ?? 0}`;
    } catch (e) {
        error.value = buildErrorText('Не удалось обновить отзывы.', e);
    } finally {
        syncing.value = false;
    }
};

onMounted(async () => {
    const ok = await ensureAuth();

    if (!ok) {
        return;
    }

    try {
        await loadSource();
    } catch (_) {
        error.value = 'Не удалось загрузить настройки источника.';
    }
});
</script>

<template>
    <div class="main main--dashboard">
        <div class="logout">
            <button class="btn btn--logout" @click="logout">
                <img src="../../img/logout.png" alt="Кнопка выйти">
            </button>
        </div>
        <div class="block__info">
            <h3 class="info__title">Подключить Яндекс</h3>
            <p class="desc">Укажите ссылку на Яндекс, пример <a class="link">https://yandex.ru/maps/org/samoye_populyarnoye_kafe/1010501395/reviews/</a></p>
            <form class="form__setting" @submit.prevent="saveSource">
                <input
                    v-model="sourceUrl"
                    type="text"
                    class="form__input"
                    placeholder="https://yandex.ru/maps/org/samoye_populyarnoye_kafe/1010501395/reviews/"
                >
                <div class="form__actions">
                    <button class="btn__setting" :disabled="saving || syncing">
                        {{ saving ? 'Сохранение...' : 'Сохранить' }}
                    </button>
                    <button type="button" class="btn__setting btn__setting--ghost" :disabled="saving || syncing" @click="syncReviews">
                        {{ syncing ? 'Обновление...' : 'Обновить отзывы' }}
                    </button>
                </div>
                <p v-if="success" class="status status--success">{{ success }}</p>
                <p v-if="error" class="status status--error">{{ error }}</p>
            </form>

            <div v-if="sourceInfo" class="source-info">
                <p class="source-info__item">Компания: {{ sourceInfo.company_name || 'Не определена' }}</p>
                <p class="source-info__item">
                    Рейтинг: {{ sourceInfo.company_rating ?? '—' }} / Отзывов: {{ sourceInfo.company_reviews_count ?? 0 }}
                </p>
            </div>
        </div>
    </div>
</template>
