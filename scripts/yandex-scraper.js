#!/usr/bin/env node
/**
 * Yandex Maps scraper using Puppeteer (real Chrome browser).
 *
 * Why Puppeteer instead of Guzzle/cURL:
 *   Yandex SmartCaptcha uses TLS fingerprinting — curl/Guzzle have a fundamentally
 *   different TLS Client Hello than Chrome, which flags them as bots regardless of
 *   User-Agent headers. A real Chromium instance passes all fingerprint checks.
 *
 * Data extraction strategy:
 *   - Org name / rating / counts: parsed from server-rendered OG meta tags
 *   - Reviews: extracted from Schema.org microdata in the DOM (.business-review-view),
 *     loaded incrementally by scrolling the sidebar (.scroll__container) until no
 *     new reviews appear or the MAX_REVIEWS cap is reached.
 *
 * Usage: node yandex-scraper.js <orgId>
 * Output: JSON to stdout — { org: {...}, reviews: [...] }
 * Errors: JSON to stderr — { error: "message" }, exits with code 1
 */

import puppeteer from 'puppeteer';

const orgId = process.argv[2];
if (!orgId || !/^\d+$/.test(orgId)) {
    process.stderr.write(JSON.stringify({ error: 'orgId argument (numeric) required' }) + '\n');
    process.exit(1);
}

const MAX_REVIEWS    = 700;
const SCROLL_WAIT_MS = 2500; // wait after each scroll for new cards to render

async function run() {
    const browser = await puppeteer.launch({
        headless: true,
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
            '--disable-gpu',
            '--disable-blink-features=AutomationControlled',
        ],
    });

    try {
        const page = await browser.newPage();
        await page.setViewport({ width: 1280, height: 900 });

        // Mask automation signals that Yandex SmartCaptcha checks
        await page.evaluateOnNewDocument(() => {
            Object.defineProperty(navigator, 'webdriver', { get: () => undefined });
            window.chrome = { runtime: {} };
        });

        await page.setUserAgent(
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 ' +
            '(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36'
        );
        await page.setExtraHTTPHeaders({ 'Accept-Language': 'ru-RU,ru;q=0.9,en;q=0.7' });

        // Navigate — yandex.ru redirects to yandex.com; both render the same content
        await page.goto(`https://yandex.ru/maps/org/${orgId}/reviews/`, {
            waitUntil: 'networkidle2',
            timeout: 30000,
        });

        // Extra wait for React hydration
        await new Promise(r => setTimeout(r, 2000));

        // ── Detect captcha ────────────────────────────────────────────────────────
        const isCaptcha = await page.evaluate(() =>
            document.body.innerText.includes('SmartCaptcha') ||
            document.URL.includes('showcaptcha') ||
            !!document.querySelector('[class*="captcha"]')
        );
        if (isCaptcha) {
            throw new Error('captcha: Яндекс запросил капчу — запрос воспринят как бот. Попробуйте позже.');
        }

        // ── Org data from server-rendered OG meta tags ────────────────────────────
        const orgInfo = await page.evaluate(() => {
            const og   = (p) => document.querySelector(`meta[property="${p}"]`)?.content ?? '';
            const desc = og('og:description');
            const title = og('og:title');

            const name = document.querySelector('h1')?.innerText?.trim()
                      || title.replace(/^Reviews of /, '').split(',')[0].split('—')[0].trim()
                      || null;

            // "Rated X.X based on YYYYY ratings and ZZZZ reviews"
            const countMatch   = desc.match(/based on ([\d\s,]+) ratings? and ([\d\s,]+) reviews?/i);
            const ratingsCount = countMatch ? parseInt(countMatch[1].replace(/[\s,]/g, ''), 10) : 0;
            const reviewsCount = countMatch ? parseInt(countMatch[2].replace(/[\s,]/g, ''), 10) : 0;

            const ratingMatch = desc.match(/Rated\s+([\d.]+)/i);
            const rating      = parseFloat(ratingMatch?.[1] ?? '') || null;

            // Address: after org type+name in OG title, before "—"
            const address = title.replace(/^Reviews of [^,]+,\s*/, '').split('—')[0].trim() || null;

            return { name, rating, ratingsCount, reviewsCount, address };
        });

        if (!orgInfo.name) {
            throw new Error('parse: Не удалось распознать данные организации. Возможно, разметка страницы изменилась.');
        }

        // ── Reviews via DOM scroll-and-extract ───────────────────────────────────
        // Yandex renders reviews with Schema.org microdata inside .business-review-view.
        // New batches appear as the .scroll__container sidebar is scrolled down.

        const seen = new Map(); // key → review object — deduplicates across scroll batches

        const extractCurrentReviews = () => page.evaluate(() => {
            const cards = [...document.querySelectorAll('.business-review-view')];
            return cards.map(card => {
                const author = card.querySelector('[itemprop="name"]')?.innerText?.trim() || 'Аноним';
                const rating = parseFloat(
                    card.querySelector('[itemprop="ratingValue"]')?.getAttribute('content') ?? '0'
                ) || 0;
                const dateRaw = card.querySelector('[itemprop="datePublished"]')?.getAttribute('content') || null;
                const text    = card.querySelector('[itemprop="reviewBody"]')?.innerText?.trim() || null;

                // Unique key: user profile URL hash + ISO date (user can only post one review per org)
                const userHref = card.querySelector('a[href*="/maps/user/"]')?.href
                              || card.querySelector('a[href*="/user/"]')?.href || '';
                const userId   = userHref.match(/\/(?:maps\/)?user\/([^/?#]+)/)?.[1] || '';
                const id       = userId
                    ? userId + '_' + (dateRaw || '').replace(/\W/g, '')
                    : author.replace(/\s/g, '') + '_' + (dateRaw || '').replace(/\W/g, '');

                // Only return cards that look like real reviews (have a date)
                if (!dateRaw) return null;
                return { id, author, rating, date: dateRaw.slice(0, 10), text: text || null };
            }).filter(Boolean);
        });

        // Click all "read more" buttons inside review cards to expand truncated text
        const expandReviewTexts = () => page.evaluate(() => {
            const selectors = [
                '.business-review-view__more',
                '.business-review-view__expand',
                '.business-review-view__show-more',
                '[class*="review"][class*="more"]',
                '[class*="review"][class*="expand"]',
            ];
            let clicked = 0;
            selectors.forEach(sel => {
                document.querySelectorAll(sel).forEach(btn => {
                    if (btn.offsetParent !== null) { btn.click(); clicked++; }
                });
            });
            return clicked;
        });

        let prevCount = 0;
        let stableIter = 0;

        while (seen.size < MAX_REVIEWS && stableIter < 3) {
            await expandReviewTexts();
            await new Promise(r => setTimeout(r, 400));

            const batch = await extractCurrentReviews();
            batch.forEach(r => seen.set(r.id, r));

            if (seen.size === prevCount) {
                stableIter++;
            } else {
                stableIter = 0;
            }
            prevCount = seen.size;

            if (seen.size >= MAX_REVIEWS) break;

            // Scroll to the bottom of the sidebar to trigger lazy-loading of next batch
            await page.evaluate(() => {
                const container = document.querySelector('.scroll__container');
                if (container) {
                    container.scrollTop = container.scrollHeight;
                } else {
                    window.scrollTo(0, document.body.scrollHeight);
                }
            });
            await new Promise(r => setTimeout(r, SCROLL_WAIT_MS));
        }

        process.stdout.write(JSON.stringify({
            org: {
                name:          orgInfo.name,
                address:       orgInfo.address,
                rating:        orgInfo.rating,
                ratings_count: orgInfo.ratingsCount,
                reviews_count: orgInfo.reviewsCount,
            },
            reviews: [...seen.values()],
        }) + '\n');

    } finally {
        await browser.close();
    }
}

run().catch((e) => {
    process.stderr.write(JSON.stringify({ error: e.message }) + '\n');
    process.exit(1);
});
