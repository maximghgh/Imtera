#!/usr/bin/env node

import crypto from 'node:crypto';
import process from 'node:process';
import { chromium } from 'playwright';

const DEFAULTS = {
  maxReviews: 5000,
  maxScrollSteps: 900,
  waitMs: 700,
  idleRounds: 28,
  timeoutMs: 180000,
};

const REVIEW_FETCH_PATH = '/maps/api/business/fetchReviews';

const args = parseArgs(process.argv.slice(2));
const sourceUrl = args.url || args.u;

if (!sourceUrl) {
  printAndExitError('VALIDATION_ERROR', 'Missing required argument --url');
}

const maxReviews = toPositiveInt(args['max-reviews'], DEFAULTS.maxReviews);
const maxScrollSteps = toPositiveInt(args['max-scroll-steps'], DEFAULTS.maxScrollSteps);
const waitMs = toPositiveInt(args['wait-ms'], DEFAULTS.waitMs);
const idleRoundsLimit = toPositiveInt(args['idle-rounds'], DEFAULTS.idleRounds);
const timeoutMs = toPositiveInt(args['timeout-ms'], DEFAULTS.timeoutMs);

const reviewsById = new Map();
const loadedPages = new Set();
const diagnostics = {
  fetchCalls: 0,
  domCollected: 0,
  networkCollected: 0,
  emptyFetchPages: 0,
};

let totalPages = null;
let totalReviewsCount = null;
let companyName = null;
let companyRating = null;
let blocked = false;

let browser;

try {
  browser = await chromium.launch({
    headless: true,
    args: [
      '--disable-blink-features=AutomationControlled',
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
    ],
  });

  const context = await browser.newContext({
    locale: 'ru-RU',
    timezoneId: 'Europe/Moscow',
    userAgent: 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/137.0.0.0 Safari/537.36',
  });
  const page = await context.newPage();
  page.setDefaultTimeout(timeoutMs);

  page.on('response', async (response) => {
    const url = response.url();

    if (!url.includes(REVIEW_FETCH_PATH)) {
      return;
    }

    diagnostics.fetchCalls += 1;

    let payload;
    try {
      payload = JSON.parse(await response.text());
    } catch {
      return;
    }

    const params = payload?.data?.params || {};
    const pageNumber = Number(params.page);

    if (Number.isFinite(pageNumber) && pageNumber > 0) {
      loadedPages.add(pageNumber);
    }

    const totalPagesFromPayload = Number(params.totalPages);
    const countFromPayload = Number(params.count);

    if (Number.isFinite(totalPagesFromPayload) && totalPagesFromPayload > 0) {
      totalPages = totalPagesFromPayload;
    }

    if (Number.isFinite(countFromPayload) && countFromPayload >= 0) {
      totalReviewsCount = countFromPayload;
    }

    const reviews = Array.isArray(payload?.data?.reviews) ? payload.data.reviews : [];

    if (reviews.length === 0) {
      diagnostics.emptyFetchPages += 1;
      return;
    }

    for (const item of reviews) {
      const parsed = parseNetworkReview(item);
      if (!parsed) {
        continue;
      }

      upsertReview(reviewsById, parsed, maxReviews);
      diagnostics.networkCollected += 1;
    }
  });

  await page.goto(sourceUrl, {
    waitUntil: 'domcontentloaded',
    timeout: timeoutMs,
  });
  await page.waitForTimeout(6500);

  const pageTitle = await page.title();
  blocked = isBlockedText(pageTitle);

  if (!blocked) {
    blocked = await page.evaluate(() => {
      const text = (document.body?.innerText || '').toLowerCase();
      return (
        text.includes('вы не робот') ||
        text.includes('подтвердите, что запросы отправляли вы') ||
        text.includes('showcaptcha') ||
        text.includes('smartcaptcha')
      );
    });
  }

  if (blocked) {
    printAndExitError('YANDEX_BLOCKED', 'Yandex returned anti-bot challenge page');
  }

  const firstDomBatch = await collectDomReviews(page, maxReviews);
  diagnostics.domCollected += firstDomBatch.length;
  for (const item of firstDomBatch) {
    upsertReview(reviewsById, item, maxReviews);
  }

  let idleRounds = 0;

  for (let step = 0; step < maxScrollSteps && reviewsById.size < maxReviews; step += 1) {
    const sizeBefore = reviewsById.size;
    await scrollReviewsContainer(page);
    await page.waitForTimeout(waitMs);

    if (step % 10 === 0) {
      const batch = await collectDomReviews(page, maxReviews);
      diagnostics.domCollected += batch.length;
      for (const item of batch) {
        upsertReview(reviewsById, item, maxReviews);
      }
    }

    if (reviewsById.size > sizeBefore) {
      idleRounds = 0;
    } else {
      idleRounds += 1;
    }

    const networkPagesComplete =
      totalPages !== null &&
      loadedPages.size >= Math.max(0, totalPages - 1);

    if (networkPagesComplete && idleRounds >= 6) {
      break;
    }

    if (idleRounds >= idleRoundsLimit) {
      break;
    }
  }

  const finalDomBatch = await collectDomReviews(page, maxReviews);
  diagnostics.domCollected += finalDomBatch.length;
  for (const item of finalDomBatch) {
    upsertReview(reviewsById, item, maxReviews);
  }

  const html = await page.content();
  const aggregate = extractAggregateFromHtml(html);

  companyName = normalizeCompanyName(pageTitle, html);
  companyRating = aggregate.rating;

  if (totalReviewsCount === null && aggregate.reviewsCount !== null) {
    totalReviewsCount = aggregate.reviewsCount;
  }

  if (totalReviewsCount === null) {
    totalReviewsCount = reviewsById.size;
  }

  const reviews = Array.from(reviewsById.values());

  if (totalReviewsCount > 0 && reviews.length === 0) {
    printAndExitError('YANDEX_REVIEWS_NOT_EXTRACTED', 'No reviews extracted from Yandex page');
  }

  process.stdout.write(
    JSON.stringify({
      success: true,
      source: 'playwright',
      company_name: companyName,
      rating: companyRating,
      reviews_count: totalReviewsCount,
      reviews,
      stats: {
        loaded_fetch_pages: Array.from(loadedPages.values()).sort((a, b) => a - b),
        total_pages: totalPages,
        collected_reviews: reviews.length,
        diagnostics,
      },
    }),
  );
} catch (error) {
  printAndExitError('SCRAPER_RUNTIME_ERROR', error instanceof Error ? error.message : 'Unknown runtime error');
} finally {
  if (browser) {
    await browser.close().catch(() => undefined);
  }
}

function parseArgs(list) {
  const result = {};

  for (let i = 0; i < list.length; i += 1) {
    const token = list[i];

    if (!token.startsWith('--')) {
      continue;
    }

    const key = token.slice(2);
    const next = list[i + 1];

    if (next && !next.startsWith('--')) {
      result[key] = next;
      i += 1;
      continue;
    }

    result[key] = 'true';
  }

  return result;
}

function toPositiveInt(value, fallback) {
  const parsed = Number(value);
  if (!Number.isFinite(parsed) || parsed <= 0) {
    return fallback;
  }
  return Math.floor(parsed);
}

function toRating(value) {
  const parsed = Number(value);
  if (!Number.isFinite(parsed)) {
    return null;
  }
  const bounded = Math.max(0, Math.min(5, parsed));
  return Number(bounded.toFixed(2));
}

function normalizeText(value) {
  if (typeof value !== 'string') {
    return null;
  }

  const normalized = value
    .replace(/\s+/g, ' ')
    .trim();

  return normalized.length > 0 ? normalized : null;
}

function parseNetworkReview(item) {
  if (!item || typeof item !== 'object') {
    return null;
  }

  const text = normalizeText(item.text || item.description || item.reviewBody);
  const authorName = normalizeText(item.author?.name || item.authorName || item.userName);
  const rating = toRating(item.rating ?? item.ratingValue);
  const publishedAt = normalizeText(item.updatedTime || item.createdTime || item.datePublished);
  const reviewId = normalizeText(item.reviewId || item.id);

  if (!text && !authorName && rating === null) {
    return null;
  }

  const externalId = reviewId || buildHash([authorName, text, publishedAt, String(rating)]);

  return {
    external_id: externalId,
    author_name: authorName,
    rating,
    body: text,
    published_at: publishedAt,
    raw_payload: item,
  };
}

async function collectDomReviews(page, maxReviews) {
  const domReviews = await page.evaluate(() => {
    const cards = Array.from(document.querySelectorAll('[itemprop="review"]'));

    return cards.map((card) => {
      const textNode =
        card.querySelector('[itemprop="reviewBody"]') ||
        card.querySelector('.business-review-view__body-text') ||
        card.querySelector('.business-review-view__comment');
      const authorNode =
        card.querySelector('[itemprop="author"] [itemprop="name"]') ||
        card.querySelector('[itemprop="author"]') ||
        card.querySelector('.business-review-view__author-name');
      const dateNode =
        card.querySelector('[itemprop="datePublished"]') ||
        card.querySelector('meta[itemprop="datePublished"]') ||
        card.querySelector('.business-review-view__date');
      const ratingNode = card.querySelector('[aria-label*="Оценка"]');
      const linkNode = card.querySelector('a[href*="/maps/user/"]');

      const ratingLabel = ratingNode?.getAttribute('aria-label') || '';
      const ratingMatch = ratingLabel.match(/([0-5](?:[.,][0-9])?)/);
      const rating = ratingMatch ? Number(ratingMatch[1].replace(',', '.')) : null;
      const authorName = authorNode?.textContent || null;
      const text = textNode?.textContent || null;
      const dateValue = dateNode?.getAttribute('content') || dateNode?.textContent || null;
      const profileLink = linkNode?.getAttribute('href') || null;

      return {
        author_name: authorName,
        body: text,
        published_at: dateValue,
        rating,
        profile_link: profileLink,
      };
    });
  });

  const normalized = [];

  for (const item of domReviews) {
    const authorName = normalizeText(item.author_name);
    const body = normalizeText(item.body);
    const publishedAt = normalizeText(item.published_at);
    const rating = toRating(item.rating);

    if (!body && !authorName && rating === null) {
      continue;
    }

    const externalId = buildHash([
      normalizeText(item.profile_link),
      authorName,
      body,
      publishedAt,
      String(rating),
    ]);

    normalized.push({
      external_id: externalId,
      author_name: authorName,
      rating,
      body,
      published_at: publishedAt,
      raw_payload: {
        source: 'dom',
      },
    });

    if (normalized.length >= maxReviews) {
      break;
    }
  }

  return normalized;
}

async function scrollReviewsContainer(page) {
  await page.evaluate(() => {
    const container = document.querySelector('div.scroll__container');
    if (!container) {
      window.scrollBy(0, window.innerHeight);
      return;
    }
    container.scrollTop = container.scrollHeight;
  });
}

function upsertReview(store, review, maxReviews) {
  if (!review || typeof review !== 'object') {
    return;
  }

  const externalId = normalizeText(review.external_id) || buildHash([
    review.author_name,
    review.body,
    review.published_at,
    String(review.rating),
  ]);

  const existing = store.get(externalId);

  const merged = {
    external_id: externalId,
    author_name: pickLongerText(existing?.author_name, review.author_name),
    rating: review.rating ?? existing?.rating ?? null,
    body: pickLongerText(existing?.body, review.body),
    published_at: review.published_at || existing?.published_at || null,
    raw_payload: review.raw_payload || existing?.raw_payload || {},
  };

  store.set(externalId, merged);

  if (store.size > maxReviews) {
    const first = store.keys().next().value;
    if (first) {
      store.delete(first);
    }
  }
}

function pickLongerText(first, second) {
  const a = normalizeText(first);
  const b = normalizeText(second);
  if (!a) {
    return b;
  }
  if (!b) {
    return a;
  }
  return b.length > a.length ? b : a;
}

function buildHash(parts) {
  const normalized = parts
    .map((part) => normalizeText(String(part ?? '')) || '')
    .join('|');
  return crypto.createHash('sha1').update(normalized).digest('hex');
}

function normalizeCompanyName(title, html) {
  const normalizedTitle = normalizeText(title);
  if (normalizedTitle) {
    return normalizedTitle.replace(/\s*[-|—]\s*Яндекс.*$/iu, '').trim();
  }

  const titleMatch = html.match(/<title[^>]*>(.*?)<\/title>/is);
  if (!titleMatch) {
    return null;
  }

  return normalizeText(
    titleMatch[1]
      .replace(/<[^>]+>/g, '')
      .replace(/\s*[-|—]\s*Яндекс.*$/iu, ''),
  );
}

function extractAggregateFromHtml(html) {
  let rating = null;
  let reviewsCount = null;

  const first = html.match(/"(?:reviewCount|reviewsCount|reviews_count|review_count)"\s*:\s*([0-9]{1,7}).{0,250}?"(?:ratingValue|averageRating|rating)"\s*:\s*"?([0-5](?:[.,][0-9])?)"?/isu);
  const second = html.match(/"(?:ratingValue|averageRating|rating)"\s*:\s*"?([0-5](?:[.,][0-9])?)"?[^\n]{0,250}"(?:reviewCount|reviewsCount|reviews_count|review_count)"\s*:\s*([0-9]{1,7})/isu);

  if (first) {
    reviewsCount = Number(first[1]);
    rating = toRating(Number(first[2].replace(',', '.')));
  } else if (second) {
    rating = toRating(Number(second[1].replace(',', '.')));
    reviewsCount = Number(second[2]);
  }

  if (reviewsCount === null) {
    const all = [...html.matchAll(/([0-9]{1,7})\s*отзыв(?:ов|а)?/giu)];
    const values = all
      .map((m) => Number(m[1]))
      .filter((v) => Number.isFinite(v) && v > 0);
    if (values.length > 0) {
      reviewsCount = Math.max(...values);
    }
  }

  return {
    rating,
    reviewsCount,
  };
}

function isBlockedText(value) {
  const text = (value || '').toLowerCase();
  return (
    text.includes('вы не робот') ||
    text.includes('are you a robot') ||
    text.includes('showcaptcha') ||
    text.includes('smartcaptcha')
  );
}

function printAndExitError(code, message) {
  process.stdout.write(
    JSON.stringify({
      success: false,
      code,
      message,
    }),
  );
  process.exit(1);
}
