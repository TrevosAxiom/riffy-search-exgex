import { query } from './db.js';

const MAX_LIMIT = 50;

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
}
