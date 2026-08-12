import { query } from './db.js';
import { ingestFeed } from './routes.js';

const DEFAULT_TICK_SECONDS = 60;
const DEFAULT_BATCH_SIZE = 10;
const DEFAULT_ITEMS_PER_FEED = 10;
const DEFAULT_FETCH_TIMEOUT_SECONDS = 12;
const DEFAULT_CLEANUP_DAYS = 30;

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

function boolValue(value, fallback = true) {
  if (value === undefined || value === null || value === '') {
    return fallback;
  }
  if (typeof value === 'boolean') {
    return value;
  }
  return ['1', 'true', 'yes', 'on', 'active'].includes(String(value).toLowerCase());
}

function boundedInt(value, fallback, min, max) {
  const parsed = Number.parseInt(value, 10);
  if (!Number.isFinite(parsed)) {
    return fallback;
  }
  return Math.max(min, Math.min(max, parsed));
}

async function workerSettings() {
  const envDefaults = {
    enabled: true,
    tick_seconds: intEnv('RIFNOTE_RSS_WORKER_TICK_SECONDS', DEFAULT_TICK_SECONDS, 10, 3600),
    batch_size: intEnv('RIFNOTE_RSS_WORKER_BATCH_SIZE', DEFAULT_BATCH_SIZE, 1, 100),
    items_per_feed: intEnv('RIFNOTE_RSS_ITEMS_PER_FEED', DEFAULT_ITEMS_PER_FEED, 1, 30),
    fetch_timeout_seconds: intEnv('RIFNOTE_RSS_FETCH_TIMEOUT_SECONDS', DEFAULT_FETCH_TIMEOUT_SECONDS, 3, 60),
    cleanup_after_days: intEnv('RIFNOTE_RSS_CLEANUP_AFTER_DAYS', DEFAULT_CLEANUP_DAYS, 1, 365)
  };

  const result = await query("SELECT setting_value FROM app_settings WHERE setting_key = 'rss_worker' LIMIT 1");
  const saved = result.rows[0]?.setting_value || {};

  return {
    enabled: boolValue(saved.enabled, envDefaults.enabled),
    tick_seconds: boundedInt(saved.tick_seconds, envDefaults.tick_seconds, 10, 3600),
    batch_size: boundedInt(saved.batch_size, envDefaults.batch_size, 1, 100),
    items_per_feed: boundedInt(saved.items_per_feed, envDefaults.items_per_feed, 1, 30),
    fetch_timeout_seconds: boundedInt(saved.fetch_timeout_seconds, envDefaults.fetch_timeout_seconds, 3, 60),
    cleanup_after_days: boundedInt(saved.cleanup_after_days, envDefaults.cleanup_after_days, 1, 365)
  };
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

function normalizeFeed(row, settings) {
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
    limit: settings.items_per_feed || DEFAULT_ITEMS_PER_FEED,
    timeout: settings.fetch_timeout_seconds || DEFAULT_FETCH_TIMEOUT_SECONDS
  };
}

async function cleanupOldItems(days) {
  if (!days) {
    return 0;
  }

  const result = await query(
    `
      DELETE FROM external_items
      USING sources
      WHERE external_items.source_id = sources.id
        AND sources.source_type = 'rss'
        AND external_items.created_at < NOW() - $1 * INTERVAL '1 day'
    `,
    [days]
  );

  return result.rowCount || 0;
}

async function tick(app, settings) {
  const feeds = await dueFeeds(settings.batch_size);
  if (!feeds.length) {
    const deleted = await cleanupOldItems(settings.cleanup_after_days);
    return { checked: 0, inserted: 0, duplicates: 0, errors: 0, deleted };
  }

  const summary = { checked: 0, inserted: 0, duplicates: 0, errors: 0, deleted: 0 };
  for (const feed of feeds) {
    const claimed = await claimFeed(feed);
    if (!claimed) {
      continue;
    }

    summary.checked++;
    const result = await ingestFeed(normalizeFeed(feed, settings));
    summary.inserted += Number(result.inserted || 0);
    summary.duplicates += Number(result.duplicates || 0);
    if (!result.ok) {
      summary.errors++;
      app.log.warn({ feed_url: feed.feed_url, error: result.error }, 'rss worker feed failed');
    }
  }

  summary.deleted = await cleanupOldItems(settings.cleanup_after_days);
  return summary;
}

export function startRssWorker(app) {
  if (!workerEnabled()) {
    app.log.info('rss worker disabled');
    return { stop() {} };
  }

  let running = false;
  let stopped = false;
  let timer = null;
  let lastSettings = {
    tick_seconds: intEnv('RIFNOTE_RSS_WORKER_TICK_SECONDS', DEFAULT_TICK_SECONDS, 10, 3600),
    batch_size: intEnv('RIFNOTE_RSS_WORKER_BATCH_SIZE', DEFAULT_BATCH_SIZE, 1, 100)
  };

  const run = async () => {
    if (stopped || running) {
      return;
    }

    running = true;
    try {
      const settings = await workerSettings();
      lastSettings = settings;
      if (!settings.enabled) {
        app.log.debug('rss worker paused by warehouse settings');
      } else {
        const summary = await tick(app, settings);
        if (summary.checked || summary.deleted) {
        app.log.info(summary, 'rss worker tick complete');
        }
      }
    } catch (error) {
      app.log.error({ error: error.message }, 'rss worker tick failed');
    } finally {
      running = false;
      if (!stopped) {
        timer = setTimeout(run, Math.max(10, Number(lastSettings.tick_seconds || DEFAULT_TICK_SECONDS)) * 1000);
      }
    }
  };

  timer = setTimeout(run, 2500);
  app.log.info(lastSettings, 'rss worker started');

  return {
    stop() {
      stopped = true;
      if (timer) {
        clearTimeout(timer);
      }
    }
  };
}
