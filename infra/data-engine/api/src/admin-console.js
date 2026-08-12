import { query } from './db.js';
import { clearAdminSessionCookie, createAdminSessionCookie } from './auth.js';

const ITEM_TYPE_OPTIONS = [
  ['article', 'Article'],
  ['social', 'Social'],
  ['video', 'Video']
];

const STATUS_OPTIONS = [
  ['raw', 'Raw'],
  ['reviewed', 'Reviewed'],
  ['published', 'Published'],
  ['rejected', 'Rejected']
];

const CATEGORY_OPTIONS = [
  ['', 'Uncategorized'],
  ['notes', 'Notes'],
  ['nigeria', 'Nigeria'],
  ['world', 'World'],
  ['football', 'Football'],
  ['sports', 'Sports'],
  ['politics', 'Politics'],
  ['business', 'Business'],
  ['technology', 'Technology'],
  ['entertainment', 'Entertainment'],
  ['health', 'Health'],
  ['science', 'Science'],
  ['opinion', 'Opinion'],
  ['crime', 'Crime']
];

function esc(value = '') {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

function attr(value = '') {
  return esc(value).replace(/\n/g, '&#10;');
}

function limit(value, fallback = 25, max = 100) {
  const parsed = Number.parseInt(value, 10);
  return Number.isFinite(parsed) && parsed > 0 ? Math.min(parsed, max) : fallback;
}

function offset(page, perPage) {
  const parsed = Number.parseInt(page, 10);
  return (Number.isFinite(parsed) && parsed > 1 ? parsed - 1 : 0) * perPage;
}

function redirect(reply, path) {
  return reply.redirect(path, 303);
}

function layout(title, body, request, notice = '') {
  const active = (path) => request.url.startsWith(path) ? ' class="active"' : '';
  return `<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>${esc(title)} · Rifnote Warehouse</title>
  <style>
    :root{--red:#ed1c24;--ink:#111827;--muted:#667085;--line:#e7ecf3;--bg:#f6f8fb;--card:#fff;}
    *{box-sizing:border-box} body{margin:0;background:var(--bg);color:var(--ink);font-family:Inter,Roboto,Arial,sans-serif}
    a{color:inherit} .shell{display:grid;grid-template-columns:260px 1fr;min-height:100vh}
    aside{background:#fff;border-right:1px solid var(--line);padding:24px;position:sticky;top:0;height:100vh}
    .brand{display:flex;gap:12px;align-items:center;font-weight:900;font-size:22px;margin-bottom:28px}
    .mark{width:42px;height:42px;border-radius:14px;background:var(--red);color:#fff;display:grid;place-items:center;font-weight:900}
    nav a{display:block;padding:13px 14px;border-radius:14px;text-decoration:none;color:#475467;font-weight:800;margin-bottom:6px}
    nav a.active,nav a:hover{background:#111827;color:#fff}
    main{padding:28px;max-width:1500px;width:100%}
    .top{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:20px}
    h1{font-size:34px;line-height:1.05;margin:0} h2{font-size:22px;margin:0 0 14px}
    .muted{color:var(--muted)} .card{background:var(--card);border:1px solid var(--line);border-radius:22px;padding:20px;box-shadow:0 12px 30px rgba(15,23,42,.04)}
    .grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}.stat b{font-size:30px;display:block;margin-bottom:6px}
    .toolbar{display:grid;grid-template-columns:2fr repeat(4,1fr) auto;gap:10px;margin:14px 0}
    label span{display:block;font-weight:900;margin-bottom:7px;color:#344054}
    input,select,textarea{width:100%;border:1px solid var(--line);border-radius:12px;padding:12px 13px;font:inherit;background:#fff;color:var(--ink)}
    textarea{min-height:120px}.btn,button{border:0;border-radius:999px;background:#111827;color:#fff;padding:11px 16px;font-weight:900;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:8px}
    .btn.red,button.red{background:var(--red)}.btn.ghost,button.ghost{background:#fff;color:#111827;border:1px solid var(--line)}
    table{width:100%;border-collapse:collapse} th{text-align:left;color:#667085;font-size:12px;text-transform:uppercase;letter-spacing:.06em}
    th,td{padding:14px;border-bottom:1px solid var(--line);vertical-align:top} td.actions{white-space:nowrap}
    .pill{display:inline-flex;border:1px solid var(--line);border-radius:999px;padding:5px 10px;font-weight:800;color:#667085;background:#f8fafc}
    .pill.raw{color:#b54708;background:#fffaeb;border-color:#fedf89}.pill.reviewed{color:#175cd3;background:#eff8ff;border-color:#b2ddff}.pill.published{color:#027a48;background:#ecfdf3;border-color:#abefc6}.pill.rejected{color:#b42318;background:#fef3f2;border-color:#fecdca}
    .inline-actions{display:flex;flex-wrap:wrap;gap:6px;margin-top:10px}.inline-actions form{display:inline}.mini{padding:7px 10px;font-size:12px}
    .ok{color:#039855}.bad{color:#b42318}.notice{padding:12px 14px;border-radius:14px;background:#ecfdf3;border:1px solid #abefc6;margin-bottom:14px;font-weight:800}
    .form-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.full{grid-column:1/-1}.danger{border-top:1px solid var(--line);margin-top:18px;padding-top:18px}
    .login{min-height:100vh;display:grid;place-items:center;padding:24px}.login .card{max-width:460px;width:100%}
    @media(max-width:900px){.shell{display:block}aside{position:static;height:auto}.grid,.toolbar,.form-grid{grid-template-columns:1fr}main{padding:16px}table{display:block;overflow-x:auto}}
  </style>
</head>
<body>
  <div class="shell">
    <aside>
      <div class="brand"><span class="mark">R</span><span>Warehouse</span></div>
      <nav>
        <a${active('/admin/dashboard')} href="/admin/dashboard">Dashboard</a>
        <a${active('/admin/items')} href="/admin/items">Items CRUD</a>
        <a${active('/admin/feeds')} href="/admin/feeds">RSS Feeds</a>
        <a${active('/admin/settings')} href="/admin/settings">RSS Settings</a>
        <a${active('/admin/logs')} href="/admin/logs">Ingest Logs</a>
        <a href="/admin/logout">Logout</a>
      </nav>
    </aside>
    <main>
      <div class="top"><div><h1>${esc(title)}</h1><p class="muted">PostgreSQL control center for RSS, social and video warehouse data.</p></div></div>
      ${notice ? `<div class="notice">${esc(notice)}</div>` : ''}
      ${body}
    </main>
  </div>
</body>
</html>`;
}

function loginPage(error = '') {
  return `<!doctype html><html><head><meta name="viewport" content="width=device-width,initial-scale=1"><title>Rifnote Warehouse Login</title>
  <style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#f6f8fb;font-family:Inter,Roboto,Arial,sans-serif;color:#111827}.card{background:#fff;border:1px solid #e7ecf3;border-radius:24px;padding:28px;max-width:440px;width:calc(100% - 32px);box-shadow:0 20px 50px rgba(15,23,42,.08)}h1{margin:0 0 8px;font-size:32px}.mark{width:48px;height:48px;border-radius:15px;background:#ed1c24;color:#fff;display:grid;place-items:center;font-weight:900;margin-bottom:16px}input,button{width:100%;border-radius:999px;padding:14px 16px;font:inherit}input{border:1px solid #d8dee8;margin:18px 0}button{border:0;background:#ed1c24;color:#fff;font-weight:900}.bad{color:#b42318;font-weight:800}</style></head>
  <body><form class="card" method="post" action="/admin/login"><div class="mark">R</div><h1>Warehouse Login</h1><p>Use the Data API token to access RSS, social and video CRUD.</p>${error ? `<p class="bad">${esc(error)}</p>` : ''}<input type="password" name="token" placeholder="Data API token" autofocus><button>Enter warehouse</button></form></body></html>`;
}

async function stats() {
  const result = await query(`
    SELECT
      (SELECT COUNT(*) FROM sources) AS sources,
      (SELECT COUNT(*) FROM feed_channels) AS feeds,
      (SELECT COUNT(*) FROM external_items) AS items,
      (SELECT COUNT(*) FROM external_items WHERE created_at > NOW() - INTERVAL '24 hours') AS items_24h,
      (SELECT COUNT(*) FROM ingest_runs WHERE started_at > NOW() - INTERVAL '24 hours') AS runs_24h,
      (SELECT COUNT(*) FROM external_items WHERE editorial_status = 'raw') AS raw_items
  `);
  return result.rows[0] || {};
}

function postBody(request) {
  return request.body && typeof request.body === 'object' ? request.body : {};
}

export async function registerAdminConsole(app) {
  app.get('/admin', async (request, reply) => redirect(reply, '/admin/dashboard'));

  app.get('/admin/login', async (_request, reply) => {
    reply.type('text/html').send(loginPage());
  });

  app.post('/admin/login', async (request, reply) => {
    const token = String(postBody(request).token || '');
    if (!process.env.RIFNOTE_DATA_API_TOKEN || token !== process.env.RIFNOTE_DATA_API_TOKEN) {
      reply.type('text/html').send(loginPage('Invalid Data API token.'));
      return;
    }
    reply.header('Set-Cookie', createAdminSessionCookie());
    redirect(reply, '/admin/dashboard');
  });

  app.get('/admin/logout', async (_request, reply) => {
    reply.header('Set-Cookie', clearAdminSessionCookie());
    redirect(reply, '/admin/login');
  });

  app.get('/admin/dashboard', async (request, reply) => {
    const data = await stats();
    const runs = await query(`
      SELECT ingest_runs.*, feed_channels.feed_url, sources.source_name
      FROM ingest_runs
      LEFT JOIN feed_channels ON feed_channels.id = ingest_runs.channel_id
      LEFT JOIN sources ON sources.id = feed_channels.source_id
      ORDER BY ingest_runs.started_at DESC
      LIMIT 8
    `);
    const body = `<section class="grid">
      ${[['Items', data.items], ['New today', data.items_24h], ['Feeds', data.feeds], ['Raw queue', data.raw_items]].map(([label, value]) => `<div class="card stat"><b>${esc(value || 0)}</b><span class="muted">${esc(label)}</span></div>`).join('')}
    </section>
    <section class="card" style="margin-top:16px"><h2>Latest ingest runs</h2>${runsTable(runs.rows)}</section>`;
    reply.type('text/html').send(layout('Dashboard', body, request));
  });

  app.get('/admin/items', async (request, reply) => {
    const q = String(request.query.q || '').trim();
    const type = String(request.query.type || '').trim();
    const status = String(request.query.status || '').trim();
    const category = String(request.query.category || '').trim();
    const perPage = limit(request.query.limit, 25);
    const page = Math.max(1, Number.parseInt(request.query.page || '1', 10) || 1);
    const params = [];
    const where = [];
    if (q) {
      params.push(`%${q}%`);
      where.push(`(external_items.title ILIKE $${params.length} OR external_items.description ILIKE $${params.length} OR external_items.canonical_url ILIKE $${params.length} OR sources.source_name ILIKE $${params.length})`);
    }
    if (type) {
      params.push(type);
      where.push(`external_items.item_type = $${params.length}`);
    }
    if (status) {
      params.push(status);
      where.push(`external_items.editorial_status = $${params.length}`);
    }
    if (category) {
      params.push(category);
      where.push(`external_items.category_slug = $${params.length}`);
    }
    const clause = where.length ? `WHERE ${where.join(' AND ')}` : '';
    const count = await query(`SELECT COUNT(*)::int AS total FROM external_items LEFT JOIN sources ON sources.id = external_items.source_id ${clause}`, params);
    params.push(perPage, offset(page, perPage));
    const items = await query(`
      SELECT external_items.*, sources.source_name, sources.logo_url
      FROM external_items
      LEFT JOIN sources ON sources.id = external_items.source_id
      ${clause}
      ORDER BY external_items.published_at DESC NULLS LAST, external_items.created_at DESC
      LIMIT $${params.length - 1} OFFSET $${params.length}
    `, params);
    const body = `${itemsToolbar({ q, type, status, category, perPage })}
      <section class="card">${bulkPublishForm(count.rows[0]?.total || 0, status)}${itemsTable(items.rows)}${pager('/admin/items', request.query, count.rows[0]?.total || 0, perPage, page)}</section>`;
    reply.type('text/html').send(layout('Items CRUD', body, request));
  });

  app.post('/admin/items/publish-raw', async (_request, reply) => {
    await query("UPDATE external_items SET editorial_status = 'published', updated_at = NOW() WHERE editorial_status = 'raw'");
    redirect(reply, '/admin/items?status=published&bulk_published=1');
  });

  app.post('/admin/items/:id/status', async (request, reply) => {
    const body = postBody(request);
    const status = STATUS_OPTIONS.some(([value]) => value === body.editorial_status) ? body.editorial_status : 'published';
    await query('UPDATE external_items SET editorial_status = $1, updated_at = NOW() WHERE id = $2', [status, request.params.id]);
    redirect(reply, request.headers.referer && String(request.headers.referer).includes('/admin/items') ? request.headers.referer : '/admin/items');
  });

  app.get('/admin/items/:id/edit', async (request, reply) => {
    const item = await query(`SELECT external_items.*, sources.source_name, sources.source_url, sources.logo_url FROM external_items LEFT JOIN sources ON sources.id = external_items.source_id WHERE external_items.id = $1`, [request.params.id]);
    if (!item.rows[0]) {
      reply.code(404).type('text/html').send(layout('Item not found', '<section class="card">No item found.</section>', request));
      return;
    }
    reply.type('text/html').send(layout('Edit Item', itemForm(item.rows[0]), request));
  });

  app.post('/admin/items/:id', async (request, reply) => {
    const body = postBody(request);
    await query(`
      UPDATE external_items SET
        title = $1, description = $2, content_text = $3, canonical_url = $4, item_type = $5,
        category_slug = $6, editorial_status = $7, image_url = $8, video_url = $9, social_url = $10,
        author_name = $11, updated_at = NOW()
      WHERE id = $12
    `, [
      body.title || '',
      body.description || null,
      body.content_text || null,
      body.canonical_url || '',
      body.item_type || 'article',
      body.category_slug || null,
      body.editorial_status || 'published',
      body.image_url || null,
      body.video_url || null,
      body.social_url || null,
      body.author_name || null,
      request.params.id
    ]);
    redirect(reply, `/admin/items/${request.params.id}/edit?saved=1`);
  });

  app.post('/admin/items/:id/delete', async (request, reply) => {
    await query('DELETE FROM external_items WHERE id = $1', [request.params.id]);
    redirect(reply, '/admin/items?deleted=1');
  });

  app.get('/admin/feeds', async (request, reply) => {
    const settings = await getRssSettings();
    const feeds = await query(`
      SELECT feed_channels.*, sources.source_name, sources.source_url, sources.source_type, sources.logo_url
      FROM feed_channels
      LEFT JOIN sources ON sources.id = feed_channels.source_id
      ORDER BY feed_channels.is_active DESC, feed_channels.next_check_at ASC NULLS FIRST, sources.source_name ASC
    `);
    reply.type('text/html').send(layout('RSS Feeds', rssSettingsSummary(settings) + feedCreateForm() + `<section class="card" style="margin-top:16px">${feedsTable(feeds.rows)}</section>`, request));
  });

  app.post('/admin/feeds', async (request, reply) => {
    const body = postBody(request);
    const logoUrl = sourceLogo(body.logo_url, body.source_url || body.feed_url);
    const source = await query(`
      INSERT INTO sources (source_key, source_name, source_url, source_type, logo_url, updated_at)
      VALUES ($1, $2, $3, 'rss', $4, NOW())
      ON CONFLICT (source_key) DO UPDATE SET source_name = EXCLUDED.source_name, source_url = EXCLUDED.source_url, logo_url = EXCLUDED.logo_url, updated_at = NOW()
      RETURNING id
    `, [slug(body.source_name || body.feed_url), body.source_name || body.feed_url, body.source_url || null, logoUrl]);
    await query(`
      INSERT INTO feed_channels (source_id, feed_url, category_slug, poll_interval_seconds, next_check_at, is_active, updated_at)
      VALUES ($1, $2, $3, $4, NOW(), $5, NOW())
      ON CONFLICT (feed_url) DO UPDATE SET source_id = EXCLUDED.source_id, category_slug = EXCLUDED.category_slug, poll_interval_seconds = EXCLUDED.poll_interval_seconds, is_active = EXCLUDED.is_active, updated_at = NOW()
    `, [source.rows[0].id, body.feed_url, body.category_slug || null, Number.parseInt(body.poll_interval_seconds || '300', 10), !!body.is_active]);
    redirect(reply, '/admin/feeds?saved=1');
  });

  app.get('/admin/feeds/:id/edit', async (request, reply) => {
    const feed = await query(`SELECT feed_channels.*, sources.source_name, sources.source_url, sources.logo_url FROM feed_channels LEFT JOIN sources ON sources.id = feed_channels.source_id WHERE feed_channels.id = $1`, [request.params.id]);
    if (!feed.rows[0]) {
      reply.code(404).type('text/html').send(layout('Feed not found', '<section class="card">No feed found.</section>', request));
      return;
    }
    reply.type('text/html').send(layout('Edit Feed', feedEditForm(feed.rows[0]), request));
  });

  app.post('/admin/feeds/:id', async (request, reply) => {
    const body = postBody(request);
    const logoUrl = sourceLogo(body.logo_url, body.source_url || body.feed_url);
    await query(`
      UPDATE feed_channels SET feed_url = $1, category_slug = $2, poll_interval_seconds = $3, is_active = $4,
        next_check_at = CASE WHEN $4 THEN COALESCE(next_check_at, NOW()) ELSE next_check_at END,
        updated_at = NOW()
      WHERE id = $5
    `, [body.feed_url, body.category_slug || null, Number.parseInt(body.poll_interval_seconds || '300', 10), !!body.is_active, request.params.id]);
    await query(`
      UPDATE sources SET source_name = $1, source_url = $2, logo_url = $3, updated_at = NOW()
      WHERE id = (SELECT source_id FROM feed_channels WHERE id = $4)
    `, [body.source_name || body.feed_url, body.source_url || null, logoUrl, request.params.id]);
    redirect(reply, `/admin/feeds/${request.params.id}/edit?saved=1`);
  });

  app.post('/admin/feeds/:id/delete', async (request, reply) => {
    await query('DELETE FROM feed_channels WHERE id = $1', [request.params.id]);
    redirect(reply, '/admin/feeds?deleted=1');
  });

  app.get('/admin/settings', async (request, reply) => {
    reply.type('text/html').send(layout('RSS Settings', rssSettingsForm(await getRssSettings()), request));
  });

  app.post('/admin/settings/rss-worker', async (request, reply) => {
    const settings = sanitizeRssSettings(postBody(request));
    await query(
      `
        INSERT INTO app_settings (setting_key, setting_value, updated_at)
        VALUES ('rss_worker', $1::jsonb, NOW())
        ON CONFLICT (setting_key) DO UPDATE SET setting_value = EXCLUDED.setting_value, updated_at = NOW()
      `,
      [JSON.stringify(settings)]
    );
    redirect(reply, '/admin/settings?saved=1');
  });

  app.get('/admin/logs', async (request, reply) => {
    const runs = await query(`
      SELECT ingest_runs.*, feed_channels.feed_url, sources.source_name
      FROM ingest_runs
      LEFT JOIN feed_channels ON feed_channels.id = ingest_runs.channel_id
      LEFT JOIN sources ON sources.id = feed_channels.source_id
      ORDER BY ingest_runs.started_at DESC
      LIMIT 100
    `);
    reply.type('text/html').send(layout('Ingest Logs', `<section class="card">${runsTable(runs.rows)}</section>`, request));
  });
}

function slug(value) {
  return String(value || 'source').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || 'source';
}

function sourceLogo(logoUrl, sourceUrl) {
  if (logoUrl && /^https?:\/\//i.test(String(logoUrl))) {
    return String(logoUrl);
  }
  try {
    const host = new URL(String(sourceUrl || '')).hostname.replace(/^www\./, '');
    return host ? `https://www.google.com/s2/favicons?domain=${encodeURIComponent(host)}&sz=96` : null;
  } catch {
    return null;
  }
}

function boundedInt(value, fallback, min, max) {
  const parsed = Number.parseInt(value, 10);
  if (!Number.isFinite(parsed)) {
    return fallback;
  }
  return Math.max(min, Math.min(max, parsed));
}

function boolInput(value) {
  return ['1', 'true', 'yes', 'on', 'active'].includes(String(value || '').toLowerCase());
}

function sanitizeRssSettings(value = {}) {
  return {
    enabled: boolInput(value.enabled),
    tick_seconds: boundedInt(value.tick_seconds, 60, 10, 3600),
    batch_size: boundedInt(value.batch_size, 10, 1, 100),
    items_per_feed: boundedInt(value.items_per_feed, 10, 1, 30),
    fetch_timeout_seconds: boundedInt(value.fetch_timeout_seconds, 12, 3, 60),
    cleanup_after_days: boundedInt(value.cleanup_after_days, 30, 1, 365)
  };
}

async function getRssSettings() {
  const result = await query("SELECT setting_value FROM app_settings WHERE setting_key = 'rss_worker' LIMIT 1");
  return sanitizeRssSettings({
    enabled: true,
    tick_seconds: 60,
    batch_size: 10,
    items_per_feed: 10,
    fetch_timeout_seconds: 12,
    cleanup_after_days: 30,
    ...(result.rows[0]?.setting_value || {})
  });
}

function rssSettingsSummary(settings) {
  return `<section class="card" style="margin-bottom:16px">
    <h2>Worker controls</h2>
    <p class="muted">The VPS data engine owns RSS polling. Feed rows keep their own poll interval; these controls decide how often the worker wakes, how many feeds it checks per pass, and how aggressively old RSS items are cleaned.</p>
    <p>
      <span class="pill ${settings.enabled ? 'published' : 'rejected'}">${settings.enabled ? 'Worker enabled' : 'Worker paused'}</span>
      <span class="pill">Wake every ${esc(settings.tick_seconds)}s</span>
      <span class="pill">${esc(settings.batch_size)} feeds per pass</span>
      <span class="pill">${esc(settings.items_per_feed)} items/feed</span>
      <a class="btn ghost mini" href="/admin/settings">Edit settings</a>
    </p>
  </section>`;
}

function rssSettingsForm(settings) {
  return `<form class="card" method="post" action="/admin/settings/rss-worker">
    <h2>RSS worker settings</h2>
    <p class="muted">These values are saved in PostgreSQL and picked up by the data-engine worker on its next cycle. Docker env values remain fallbacks only.</p>
    <div class="form-grid">
      <label><span>Worker status</span>${select('enabled', [['1', 'Enabled'], ['0', 'Paused']], settings.enabled ? '1' : '0')}</label>
      ${field('tick_seconds', 'Worker wake interval seconds', settings.tick_seconds)}
      ${field('batch_size', 'Feeds checked per pass', settings.batch_size)}
      ${field('items_per_feed', 'Max items per feed run', settings.items_per_feed)}
      ${field('fetch_timeout_seconds', 'Feed fetch timeout seconds', settings.fetch_timeout_seconds)}
      ${field('cleanup_after_days', 'Auto-delete RSS items after days', settings.cleanup_after_days)}
    </div>
    <p><button class="red">Save RSS settings</button> <a class="btn ghost" href="/admin/feeds">Back to feeds</a></p>
  </form>`;
}

function itemsToolbar(values) {
  return `<form class="toolbar" method="get" action="/admin/items">
    <input name="q" value="${attr(values.q)}" placeholder="Search title, source, URL">
    ${select('type', [['', 'All types'], ...ITEM_TYPE_OPTIONS], values.type)}
    ${select('status', [['', 'All statuses'], ...STATUS_OPTIONS], values.status)}
    ${select('category', [['', 'All categories'], ...CATEGORY_OPTIONS.filter(([value]) => value)], values.category)}
    ${select('limit', [['25', '25'], ['50', '50'], ['100', '100']], String(values.perPage))}
    <button>Filter</button>
  </form>`;
}

function select(name, choices, selected = '') {
  return `<select name="${attr(name)}">${choices.map(([value, label]) => `<option value="${attr(value)}"${String(value) === String(selected) ? ' selected' : ''}>${esc(label)}</option>`).join('')}</select>`;
}

function selectField(name, label, choices, selected = '') {
  return `<label><span>${esc(label)}</span>${select(name, choices, selected)}</label>`;
}

function bulkPublishForm(total, status) {
  if ('raw' !== status) {
    return '';
  }

  return `<form method="post" action="/admin/items/publish-raw" onsubmit="return confirm('Publish every raw warehouse item?')" style="margin-bottom:14px">
    <button class="red">Publish all raw items</button>
    <span class="muted" style="margin-left:10px">${esc(total)} raw item(s) in this filtered view.</span>
  </form>`;
}

function itemsTable(rows) {
  if (!rows.length) {
    return '<p class="muted">No warehouse items found.</p>';
  }
  return `<table><thead><tr><th>Story</th><th>Source</th><th>Type</th><th>Status</th><th>Published</th><th></th></tr></thead><tbody>
    ${rows.map((row) => `<tr>
      <td><b>${esc(row.title)}</b><br><span class="muted">${esc(row.canonical_url)}</span></td>
      <td>${esc(row.source_name || 'Unknown')}</td>
      <td><span class="pill">${esc(row.item_type)}</span></td>
      <td><span class="pill ${esc(row.editorial_status)}">${esc(row.editorial_status)}</span></td>
      <td>${esc(row.published_at || row.created_at || '')}</td>
      <td class="actions">
        <a class="btn ghost mini" href="/admin/items/${row.id}/edit">Edit</a>
        <div class="inline-actions">
          ${row.editorial_status !== 'published' ? quickStatus(row.id, 'published', 'Publish') : ''}
          ${row.editorial_status !== 'reviewed' ? quickStatus(row.id, 'reviewed', 'Review') : ''}
          ${row.editorial_status !== 'rejected' ? quickStatus(row.id, 'rejected', 'Reject') : ''}
        </div>
      </td>
    </tr>`).join('')}
  </tbody></table>`;
}

function itemForm(row) {
  return `<form class="card" method="post" action="/admin/items/${row.id}">
    <div class="form-grid">
      ${field('title', 'Title', row.title)}
      ${field('canonical_url', 'Canonical URL', row.canonical_url)}
      ${selectField('item_type', 'Type', ITEM_TYPE_OPTIONS, row.item_type || 'article')}
      ${selectField('editorial_status', 'Status', STATUS_OPTIONS, row.editorial_status || 'published')}
      ${selectField('category_slug', 'Category', CATEGORY_OPTIONS, row.category_slug || '')}
      ${field('author_name', 'Author', row.author_name)}
      ${field('image_url', 'Image URL', row.image_url)}
      ${field('video_url', 'Video URL', row.video_url)}
      ${field('social_url', 'Social URL', row.social_url)}
      <label class="full">Description<textarea name="description">${esc(row.description || '')}</textarea></label>
      <label class="full">Content<textarea name="content_text">${esc(row.content_text || '')}</textarea></label>
    </div>
    <p><button class="red">Save item</button> <a class="btn ghost" href="/admin/items">Back</a></p>
  </form>
  <form class="card danger" method="post" action="/admin/items/${row.id}/delete" onsubmit="return confirm('Delete this warehouse item?')">
    <h2>Delete item</h2><p class="muted">This removes the imported warehouse record from PostgreSQL.</p><button class="red">Delete permanently</button>
  </form>`;
}

function field(name, label, value = '') {
  return `<label><span>${esc(label)}</span><input name="${attr(name)}" value="${attr(value || '')}"></label>`;
}

function quickStatus(id, status, label) {
  return `<form method="post" action="/admin/items/${id}/status"><input type="hidden" name="editorial_status" value="${attr(status)}"><button class="ghost mini">${esc(label)}</button></form>`;
}

function feedCreateForm() {
  return `<form class="card" method="post" action="/admin/feeds"><h2>Add RSS feed</h2><div class="form-grid">
    ${field('source_name', 'Source name')}
    ${field('source_url', 'Source URL')}
    ${field('feed_url', 'Feed URL')}
    ${selectField('category_slug', 'Category', CATEGORY_OPTIONS)}
    ${field('poll_interval_seconds', 'Poll interval seconds', '300')}
    ${field('logo_url', 'Logo URL')}
    <label><input type="checkbox" name="is_active" value="1" checked style="width:auto"> Active</label>
  </div><p><button class="red">Save feed</button></p></form>`;
}

function feedsTable(rows) {
  if (!rows.length) {
    return '<p class="muted">No feeds saved yet.</p>';
  }
  return `<table><thead><tr><th>Feed</th><th>Category</th><th>Interval</th><th>Next check</th><th>Status</th><th></th></tr></thead><tbody>${rows.map((row) => `<tr>
    <td><b>${esc(row.source_name || row.feed_url)}</b><br><span class="muted">${esc(row.feed_url)}</span></td>
    <td>${esc(row.category_slug || '')}</td>
    <td>${esc(row.poll_interval_seconds)}s</td>
    <td>${esc(row.next_check_at || '')}</td>
    <td><span class="${row.is_active ? 'ok' : 'bad'}">${row.is_active ? 'Active' : 'Paused'}</span><br><span class="muted">${esc(row.last_status || '')}</span></td>
    <td><a class="btn ghost" href="/admin/feeds/${row.id}/edit">Edit</a></td>
  </tr>`).join('')}</tbody></table>`;
}

function feedEditForm(row) {
  return `<form class="card" method="post" action="/admin/feeds/${row.id}"><div class="form-grid">
    ${field('source_name', 'Source name', row.source_name)}
    ${field('source_url', 'Source URL', row.source_url)}
    ${field('feed_url', 'Feed URL', row.feed_url)}
    ${selectField('category_slug', 'Category', CATEGORY_OPTIONS, row.category_slug || '')}
    ${field('poll_interval_seconds', 'Poll interval seconds', row.poll_interval_seconds)}
    ${field('logo_url', 'Logo URL', row.logo_url)}
    <label><input type="checkbox" name="is_active" value="1"${row.is_active ? ' checked' : ''} style="width:auto"> Active</label>
  </div><p><button class="red">Save feed</button> <a class="btn ghost" href="/admin/feeds">Back</a></p></form>
  <form class="card danger" method="post" action="/admin/feeds/${row.id}/delete" onsubmit="return confirm('Delete this feed?')"><h2>Delete feed</h2><button class="red">Delete feed</button></form>`;
}

function runsTable(rows) {
  if (!rows.length) {
    return '<p class="muted">No ingest runs logged yet.</p>';
  }
  return `<table><thead><tr><th>Run</th><th>Source</th><th>Checked</th><th>Created</th><th>Duplicates</th><th>Error</th></tr></thead><tbody>${rows.map((row) => `<tr>
    <td><span class="pill">${esc(row.status)}</span><br><span class="muted">${esc(row.started_at || '')}</span></td>
    <td><b>${esc(row.source_name || 'Unknown')}</b><br><span class="muted">${esc(row.expected_url || row.feed_url || '')}</span></td>
    <td>${esc(row.pulled_items || 0)}</td>
    <td>${esc(row.inserted_items || 0)}</td>
    <td>${esc(row.duplicate_items || 0)}</td>
    <td class="bad">${esc(row.error_message || '')}</td>
  </tr>`).join('')}</tbody></table>`;
}

function pager(path, query, total, perPage, page) {
  const pages = Math.ceil(total / perPage);
  if (pages < 2) return '';
  const clean = { ...query };
  return `<p>${page > 1 ? `<a class="btn ghost" href="${path}?${new URLSearchParams({ ...clean, page: page - 1 }).toString()}">Previous</a>` : ''} <span class="pill">Page ${page} of ${pages}</span> ${page < pages ? `<a class="btn ghost" href="${path}?${new URLSearchParams({ ...clean, page: page + 1 }).toString()}">Next</a>` : ''}</p>`;
}
