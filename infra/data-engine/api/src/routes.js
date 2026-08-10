import { XMLParser } from 'fast-xml-parser';
import { pool, query } from './db.js';

const MAX_LIMIT = 50;
const DEFAULT_FEED_LIMIT = 10;
const MAX_FEED_LIMIT = 30;

const parser = new XMLParser({
  ignoreAttributes: false,
  attributeNamePrefix: '@_',
  textNodeName: '#text',
  cdataPropName: '#cdata',
  trimValues: true
});

function limitValue(value, fallback = 20) {
  const parsed = Number.parseInt(value, 10);
  if (!Number.isFinite(parsed) || parsed < 1) {
    return fallback;
  }
  return Math.min(parsed, MAX_LIMIT);
}

function serializeItem(row) {
  return {
    id: Number(row.id),
    type: row.item_type,
    title: row.title,
    description: row.description,
    url: row.canonical_url,
    source: {
      id: row.source_id ? Number(row.source_id) : null,
      name: row.source_name || null,
      url: row.source_url || null,
      logo: row.logo_url || null
    },
    media: {
      image: row.image_url || null,
      video: row.video_url || null,
      social: row.social_url || null
    },
    category: row.category_slug || null,
    language: row.language_code || null,
    country: row.country_code || null,
    published_at: row.published_at,
    discovered_at: row.discovered_at,
    wp_post_id: row.wp_post_id ? Number(row.wp_post_id) : null
  };
}

function first(value) {
  return Array.isArray(value) ? value[0] : value;
}

function cleanText(value) {
  if (value === null || value === undefined) {
    return '';
  }

  if (typeof value === 'object') {
    value = value['#cdata'] || value['#text'] || '';
  }

  return String(value)
    .replace(/<[^>]+>/g, ' ')
    .replace(/&nbsp;/gi, ' ')
    .replace(/&#8216;|&#x2018;/gi, "'")
    .replace(/&#8217;|&#x2019;/gi, "'")
    .replace(/&#8220;|&#x201c;/gi, '"')
    .replace(/&#8221;|&#x201d;/gi, '"')
    .replace(/&amp;/gi, '&')
    .replace(/&quot;/gi, '"')
    .replace(/&#039;/gi, "'")
    .replace(/\s+/g, ' ')
    .trim();
}

function slug(value, fallback = 'news') {
  const clean = cleanText(value).toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
  return clean || fallback;
}

function sourceKey(feed) {
  const raw = feed.source_url || feed.website_url || feed.url || feed.feed_url || feed.name || 'source';
  try {
    const host = new URL(raw).hostname.replace(/^www\./, '');
    return slug(host, 'source');
  } catch {
    return slug(raw, 'source');
  }
}

function sourceHome(feedUrl) {
  try {
    const url = new URL(feedUrl);
    return `${url.protocol}//${url.hostname}`;
  } catch {
    return '';
  }
}

function itemLink(entry) {
  const link = first(entry.link);

  if (typeof link === 'string') {
    return link;
  }

  if (link && typeof link === 'object') {
    if (link['@_href']) {
      return link['@_href'];
    }
    if (link['#text']) {
      return link['#text'];
    }
  }

  const guid = entry.guid;
  if (typeof guid === 'string' && /^https?:\/\//i.test(guid)) {
    return guid;
  }
  if (guid && typeof guid === 'object' && /^https?:\/\//i.test(guid['#text'] || '')) {
    return guid['#text'];
  }

  return '';
}

function itemImage(entry) {
  const enclosure = first(entry.enclosure);
  if (enclosure && enclosure['@_url'] && (!enclosure['@_type'] || String(enclosure['@_type']).startsWith('image/'))) {
    return enclosure['@_url'];
  }

  const mediaContent = first(entry['media:content']);
  if (mediaContent && mediaContent['@_url']) {
    return mediaContent['@_url'];
  }

  const mediaThumb = first(entry['media:thumbnail']);
  if (mediaThumb && mediaThumb['@_url']) {
    return mediaThumb['@_url'];
  }

  return '';
}

function parseFeed(xml) {
  const parsed = parser.parse(String(xml || ''));
  const rssItems = parsed?.rss?.channel?.item ? (Array.isArray(parsed.rss.channel.item) ? parsed.rss.channel.item : [parsed.rss.channel.item]) : [];
  const atomItems = parsed?.feed?.entry ? (Array.isArray(parsed.feed.entry) ? parsed.feed.entry : [parsed.feed.entry]) : [];
  const rows = rssItems.length ? rssItems : atomItems;

  return rows.map((entry) => {
    const title = cleanText(entry.title);
    const link = itemLink(entry);
    const description = cleanText(entry.description || entry.summary || entry['content:encoded'] || entry.content);
    const published = cleanText(entry.pubDate || entry.published || entry.updated || entry.date);
    const externalId = cleanText(entry.guid || entry.id || link);

    return {
      title,
      link,
      description,
      content_text: description,
      published_at: published ? new Date(published) : null,
      image_url: itemImage(entry),
      external_id: externalId,
      raw_payload: entry
    };
  }).filter((item) => item.title && item.link);
}

async function upsertSource(client, feed) {
  const feedUrl = feed.feed_url || feed.url || '';
  const home = feed.source_url || feed.website_url || sourceHome(feedUrl);
  const result = await client.query(
    `
      INSERT INTO sources (source_key, source_name, source_url, source_type, logo_url, updated_at)
      VALUES ($1, $2, $3, $4, $5, NOW())
      ON CONFLICT (source_key) DO UPDATE SET
        source_name = EXCLUDED.source_name,
        source_url = COALESCE(EXCLUDED.source_url, sources.source_url),
        source_type = EXCLUDED.source_type,
        logo_url = COALESCE(EXCLUDED.logo_url, sources.logo_url),
        updated_at = NOW()
      RETURNING id, source_name
    `,
    [
      sourceKey(feed),
      cleanText(feed.name || feed.publisher_name || sourceHome(feedUrl) || 'RSS source'),
      home || null,
      cleanText(feed.source_type || 'rss') || 'rss',
      feed.logo_url || null
    ]
  );

  return result.rows[0];
}

async function upsertChannel(client, sourceId, feed) {
  const feedUrl = feed.feed_url || feed.url || '';
  const interval = Math.max(60, Number.parseInt(feed.poll_interval_seconds || feed.interval_seconds || 300, 10) || 300);
  const result = await client.query(
    `
      INSERT INTO feed_channels (source_id, feed_url, category_slug, poll_interval_seconds, next_check_at, updated_at)
      VALUES ($1, $2, $3, $4, NOW(), NOW())
      ON CONFLICT (feed_url) DO UPDATE SET
        source_id = EXCLUDED.source_id,
        category_slug = EXCLUDED.category_slug,
        poll_interval_seconds = EXCLUDED.poll_interval_seconds,
        updated_at = NOW()
      RETURNING id
    `,
    [sourceId, feedUrl, slug(feed.category || feed.categories || 'news'), interval]
  );

  return result.rows[0];
}

async function insertExternalItem(client, sourceId, channelId, feed, item) {
  const result = await client.query(
    `
      INSERT INTO external_items (
        source_id, channel_id, external_id, canonical_url, item_type, title, description, content_text,
        image_url, language_code, country_code, category_slug, published_at, raw_payload, editorial_status, updated_at
      )
      VALUES ($1, $2, $3, $4, 'article', $5, $6, $7, $8, $9, $10, $11, $12, $13::jsonb, 'raw', NOW())
      ON CONFLICT (canonical_url) DO UPDATE SET
        source_id = EXCLUDED.source_id,
        channel_id = EXCLUDED.channel_id,
        title = EXCLUDED.title,
        description = COALESCE(EXCLUDED.description, external_items.description),
        content_text = COALESCE(EXCLUDED.content_text, external_items.content_text),
        image_url = COALESCE(EXCLUDED.image_url, external_items.image_url),
        category_slug = EXCLUDED.category_slug,
        published_at = COALESCE(EXCLUDED.published_at, external_items.published_at),
        raw_payload = EXCLUDED.raw_payload,
        updated_at = NOW()
      RETURNING id, (xmax = 0) AS inserted
    `,
    [
      sourceId,
      channelId,
      item.external_id || item.link,
      item.link,
      cleanText(item.title),
      cleanText(item.description),
      cleanText(item.content_text),
      item.image_url || null,
      feed.language_code || 'en',
      feed.country_code || null,
      slug(feed.category || feed.categories || 'news'),
      item.published_at && !Number.isNaN(item.published_at.getTime()) ? item.published_at.toISOString() : null,
      JSON.stringify(item.raw_payload || {})
    ]
  );

  return result.rows[0];
}

async function ingestFeed(feed) {
  const feedUrl = feed.feed_url || feed.url || '';
  if (!/^https?:\/\//i.test(feedUrl)) {
    return { ok: false, feed_url: feedUrl, error: 'invalid_feed_url' };
  }

  const expected = Math.max(1, Math.min(MAX_FEED_LIMIT, Number.parseInt(feed.items_per_feed || feed.limit || DEFAULT_FEED_LIMIT, 10) || DEFAULT_FEED_LIMIT));
  const client = await pool.connect();
  let source;
  let channel;
  let runId = null;

  try {
    source = await upsertSource(client, feed);
    channel = await upsertChannel(client, source.id, feed);
    const run = await client.query(
      `
        INSERT INTO ingest_runs (channel_id, run_type, status, expected_url, expected_items)
        VALUES ($1, 'rss', 'started', $2, $3)
        RETURNING id
      `,
      [channel.id, feedUrl, expected]
    );
    runId = run.rows[0].id;
  } finally {
    client.release();
  }

  let pulled = 0;
  let inserted = 0;
  let duplicates = 0;

  try {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), Math.max(3, Number.parseInt(feed.timeout || 10, 10)) * 1000);
    const response = await fetch(feedUrl, {
      headers: { 'User-Agent': 'RifnoteBot/1.0; +https://rifnote.com' },
      signal: controller.signal
    });
    clearTimeout(timeout);

    if (!response.ok) {
      throw new Error(`Feed returned HTTP ${response.status}.`);
    }

    const xml = await response.text();
    const items = parseFeed(xml).slice(0, expected);
    pulled = items.length;

    const writeClient = await pool.connect();
    try {
      for (const item of items) {
        const saved = await insertExternalItem(writeClient, source.id, channel.id, feed, item);
        if (saved.inserted) {
          inserted++;
        } else {
          duplicates++;
        }
      }

      await writeClient.query(
        `
          UPDATE ingest_runs
          SET status = 'ok', pulled_items = $1, inserted_items = $2, duplicate_items = $3, finished_at = NOW()
          WHERE id = $4
        `,
        [pulled, inserted, duplicates, runId]
      );
      await writeClient.query(
        `
          UPDATE feed_channels
          SET last_checked_at = NOW(), next_check_at = NOW() + poll_interval_seconds * INTERVAL '1 second',
              last_status = 'ok', last_error = NULL, updated_at = NOW()
          WHERE id = $1
        `,
        [channel.id]
      );
    } finally {
      writeClient.release();
    }

    return { ok: true, source: source.source_name, feed_url: feedUrl, pulled, inserted, duplicates };
  } catch (error) {
    await query(
      `
        UPDATE ingest_runs
        SET status = 'error', pulled_items = $1, inserted_items = $2, duplicate_items = $3, error_message = $4, finished_at = NOW()
        WHERE id = $5
      `,
      [pulled, inserted, duplicates, error.message, runId]
    );
    if (channel?.id) {
      await query(
        'UPDATE feed_channels SET last_checked_at = NOW(), last_status = $1, last_error = $2, updated_at = NOW() WHERE id = $3',
        ['error', error.message, channel.id]
      );
    }

    return { ok: false, source: source?.source_name || null, feed_url: feedUrl, pulled, inserted, duplicates, error: error.message };
  }
}

export async function registerRoutes(app) {
  app.get('/v1/health', async () => {
    const result = await query('SELECT NOW() AS now');
    return {
      ok: true,
      service: 'rifnote-data-api',
      database_time: result.rows[0].now
    };
  });

  app.get('/v1/stories/search', async (request) => {
    const q = String(request.query.q || '').trim();
    const limit = limitValue(request.query.limit);
    const type = String(request.query.type || '').trim();

    const params = [];
    const where = [];

    if (q) {
      params.push(`%${q}%`);
      where.push(`(
        external_items.title ILIKE $${params.length}
        OR external_items.description ILIKE $${params.length}
        OR external_items.content_text ILIKE $${params.length}
      )`);
    }

    if (type) {
      params.push(type);
      where.push(`external_items.item_type = $${params.length}`);
    }

    if (request.query.category) {
      params.push(String(request.query.category).trim());
      where.push(`external_items.category_slug = $${params.length}`);
    }

    params.push(limit);

    const sql = `
      SELECT external_items.*, sources.source_name, sources.source_url, sources.logo_url
      FROM external_items
      LEFT JOIN sources ON sources.id = external_items.source_id
      ${where.length ? `WHERE ${where.join(' AND ')}` : ''}
      ORDER BY external_items.published_at DESC NULLS LAST, external_items.discovered_at DESC
      LIMIT $${params.length}
    `;

    const result = await query(sql, params);
    return { items: result.rows.map(serializeItem) };
  });

  app.get('/v1/stories/category/:slug', async (request) => {
    const limit = limitValue(request.query.limit);
    const result = await query(
      `
        SELECT external_items.*, sources.source_name, sources.source_url, sources.logo_url
        FROM external_items
        LEFT JOIN sources ON sources.id = external_items.source_id
        WHERE external_items.category_slug = $1
        ORDER BY external_items.published_at DESC NULLS LAST, external_items.discovered_at DESC
        LIMIT $2
      `,
      [request.params.slug, limit]
    );

    return { items: result.rows.map(serializeItem) };
  });

  app.get('/v1/trending', async (request) => {
    const limit = limitValue(request.query.limit, 15);
    const category = String(request.query.category || '').trim();
    const params = [limit];
    const where = ['expires_at > NOW()'];

    if (category) {
      params.unshift(category);
      where.push('category_slug = $1');
    }

    const limitPlaceholder = `$${params.length}`;
    const result = await query(
      `
        SELECT term, category_slug, score, source_mix, calculated_at, expires_at
        FROM trending_terms
        WHERE ${where.join(' AND ')}
        ORDER BY score DESC, calculated_at DESC
        LIMIT ${limitPlaceholder}
      `,
      params
    );

    return { terms: result.rows };
  });

  app.get('/v1/sources', async (request) => {
    const limit = limitValue(request.query.limit, 50);
    const result = await query(
      `
        SELECT id, source_key, source_name, source_url, source_type, logo_url, trust_score, is_active
        FROM sources
        ORDER BY source_name ASC
        LIMIT $1
      `,
      [limit]
    );

    return { sources: result.rows };
  });

  app.get('/v1/admin/stats', async () => {
    const [counts, runs, items] = await Promise.all([
      query(`
        SELECT
          (SELECT COUNT(*) FROM sources) AS sources,
          (SELECT COUNT(*) FROM feed_channels) AS feed_channels,
          (SELECT COUNT(*) FROM external_items) AS external_items,
          (SELECT COUNT(*) FROM ingest_runs) AS ingest_runs,
          (SELECT COUNT(*) FROM external_items WHERE created_at > NOW() - INTERVAL '24 hours') AS items_24h
      `),
      query(`
        SELECT ingest_runs.*, feed_channels.feed_url, sources.source_name
        FROM ingest_runs
        LEFT JOIN feed_channels ON feed_channels.id = ingest_runs.channel_id
        LEFT JOIN sources ON sources.id = feed_channels.source_id
        ORDER BY ingest_runs.started_at DESC
        LIMIT 12
      `),
      query(`
        SELECT external_items.id, external_items.title, external_items.category_slug, external_items.created_at, sources.source_name
        FROM external_items
        LEFT JOIN sources ON sources.id = external_items.source_id
        ORDER BY external_items.created_at DESC
        LIMIT 8
      `)
    ]);

    return {
      counts: counts.rows[0],
      recent_runs: runs.rows,
      recent_items: items.rows
    };
  });

  app.post('/v1/items/batch', async (request) => {
    const body = request.body || {};
    const items = Array.isArray(body.items) ? body.items : [];
    const feed = body.feed || {};

    if (!items.length) {
      return { ok: false, error: 'empty_items', inserted: 0, duplicates: 0 };
    }

    const client = await pool.connect();
    let inserted = 0;
    let duplicates = 0;
    try {
      const source = await upsertSource(client, feed);
      const channel = await upsertChannel(client, source.id, feed);
      for (const item of items) {
        const saved = await insertExternalItem(client, source.id, channel.id, feed, {
          ...item,
          link: item.link || item.url || item.canonical_url,
          published_at: item.published_at ? new Date(item.published_at) : null,
          raw_payload: item
        });
        if (saved.inserted) {
          inserted++;
        } else {
          duplicates++;
        }
      }
    } finally {
      client.release();
    }

    return { ok: true, inserted, duplicates };
  });

  app.post('/v1/ingest/rss', async (request) => {
    const feed = request.body?.feed || request.body || {};
    const result = await ingestFeed(feed);
    return { ok: !!result.ok, result };
  });

  app.post('/v1/ingest/rss/batch', async (request) => {
    const feeds = Array.isArray(request.body?.feeds) ? request.body.feeds : [];

    if (!feeds.length) {
      return { ok: false, error: 'empty_feeds', results: [] };
    }

    const results = [];
    for (const feed of feeds.slice(0, 100)) {
      results.push(await ingestFeed(feed));
    }

    return {
      ok: results.some((result) => result.ok),
      checked: results.length,
      inserted: results.reduce((total, result) => total + Number(result.inserted || 0), 0),
      duplicates: results.reduce((total, result) => total + Number(result.duplicates || 0), 0),
      errors: results.filter((result) => !result.ok).length,
      results
    };
  });
}
