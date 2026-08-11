import { query } from './db.js';
import { ingestFeed } from './routes.js';

const DEFAULT_TICK_SECONDS = 60;
const DEFAULT_BATCH_SIZE = 10;

function intEnv(name, fallback, min, max) {
  const value = Number.parseInt(process.env[name] || '', 10);
  if (!Number.isFinite(value)) {
    return fallback;
  }
  return Math.max(min, Math.min(max, value));
}

function workerEnabled() {
  return !['0', 'false', 'off', 'no'].includes(String(process.env.RIFNOTE_RSS_WORKER_ENABLED || 'true').toLowerCase());
}

async function dueFeeds(limit) {
  const result = await query(
    `
      SELECT feed_channels.*, sources.source_key, sources.source_name, sources.source_url, sources.source_type,
             sources.logo_url, sources.trust_score, sources.is_active AS source_is_active
      FROM feed_channels
      LEFT JOIN sources ON sources.id = feed_channels.source_id
      WHERE feed_channels.is_active = TRUE
        AND COALESCE(sources.is_active, TRUE) = TRUE
        AND (feed_channels.next_check_at IS NULL OR feed_channels.next_check_at <= NOW())
      ORDER BY feed_channels.next_check_at ASC NULLS FIRST, feed_channels.id ASC
      LIMIT $1
    `,
    [limit]
  );

  return result.rows;
}

async function claimFeed(feed) {
  const result = await query(
    `
      UPDATE feed_channels
      SET next_check_at = NOW() + GREATEST(poll_interval_seconds, 60) * INTERVAL '1 second',
          updated_at = NOW()
      WHERE id = $1
        AND is_active = TRUE
        AND (next_check_at IS NULL OR next_check_at <= NOW())
      RETURNING *
    `,
    [feed.id]
  );

  return !!result.rowCount;
}

function normalizeFeed(row) {
  return {
    id: row.id,
    feed_url: row.feed_url,
    category_slug: row.category_slug || 'news',
    poll_interval_seconds: row.poll_interval_seconds || 300,
    name: row.source_name,
    publisher_name: row.source_name,
    source_url: row.source_url,
    website_url: row.source_url,
    source_type: row.source_type || 'rss',
    logo_url: row.logo_url,
    language_code: row.language_code || 'en',
    country_code: row.country_code || null,
    limit: process.env.RIFNOTE_RSS_ITEMS_PER_FEED || 10,
    timeout: process.env.RIFNOTE_RSS_FETCH_TIMEOUT_SECONDS || 12
  };
}

async function tick(app, batchSize) {
  const feeds = await dueFeeds(batchSize);
  if (!feeds.length) {
    return { checked: 0, inserted: 0, duplicates: 0, errors: 0 };
  }

  const summary = { checked: 0, inserted: 0, duplicates: 0, errors: 0 };
  for (const feed of feeds) {
    const claimed = await claimFeed(feed);
    if (!claimed) {
      continue;
    }

    summary.checked++;
    const result = await ingestFeed(normalizeFeed(feed));
    summary.inserted += Number(result.inserted || 0);
    summary.duplicates += Number(result.duplicates || 0);
    if (!result.ok) {
      summary.errors++;
      app.log.warn({ feed_url: feed.feed_url, error: result.error }, 'rss worker feed failed');
    }
  }

  return summary;
}

export function startRssWorker(app) {
  if (!workerEnabled()) {
    app.log.info('rss worker disabled');
    return { stop() {} };
  }

  const tickSeconds = intEnv('RIFNOTE_RSS_WORKER_TICK_SECONDS', DEFAULT_TICK_SECONDS, 10, 3600);
  const batchSize = intEnv('RIFNOTE_RSS_WORKER_BATCH_SIZE', DEFAULT_BATCH_SIZE, 1, 100);
  let running = false;
  let stopped = false;
  let timer = null;

  const run = async () => {
    if (stopped || running) {
      return;
    }

    running = true;
    try {
      const summary = await tick(app, batchSize);
      if (summary.checked) {
        app.log.info(summary, 'rss worker tick complete');
      }
    } catch (error) {
      app.log.error({ error: error.message }, 'rss worker tick failed');
    } finally {
      running = false;
      if (!stopped) {
        timer = setTimeout(run, tickSeconds * 1000);
      }
    }
  };

  timer = setTimeout(run, 2500);
  app.log.info({ tick_seconds: tickSeconds, batch_size: batchSize }, 'rss worker started');

  return {
    stop() {
      stopped = true;
      if (timer) {
        clearTimeout(timer);
      }
    }
  };
}
