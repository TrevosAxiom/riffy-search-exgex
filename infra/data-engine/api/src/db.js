import pg from 'pg';

const { Pool } = pg;

export const pool = new Pool({
  host: process.env.POSTGRES_HOST || 'postgres',
  port: Number(process.env.POSTGRES_PORT || 5432),
  database: process.env.POSTGRES_DB || 'rifnote_data',
  user: process.env.POSTGRES_USER || 'rifnote',
  password: process.env.POSTGRES_PASSWORD,
  max: Number(process.env.POSTGRES_POOL_SIZE || 10),
  idleTimeoutMillis: 30000,
  connectionTimeoutMillis: 5000
});

export async function query(text, params = []) {
  return pool.query(text, params);
}

export async function ensureSchema() {
  await query(`
    ALTER TABLE sources ADD COLUMN IF NOT EXISTS logo_url TEXT;
    ALTER TABLE feed_channels ADD COLUMN IF NOT EXISTS poll_interval_seconds INTEGER NOT NULL DEFAULT 300;
    ALTER TABLE feed_channels ADD COLUMN IF NOT EXISTS last_checked_at TIMESTAMPTZ;
    ALTER TABLE feed_channels ADD COLUMN IF NOT EXISTS next_check_at TIMESTAMPTZ;
    ALTER TABLE feed_channels ADD COLUMN IF NOT EXISTS last_status TEXT;
    ALTER TABLE feed_channels ADD COLUMN IF NOT EXISTS last_error TEXT;
    ALTER TABLE feed_channels ADD COLUMN IF NOT EXISTS is_active BOOLEAN NOT NULL DEFAULT TRUE;
  `);

  await query(`
    CREATE TABLE IF NOT EXISTS app_settings (
      setting_key TEXT PRIMARY KEY,
      setting_value JSONB NOT NULL DEFAULT '{}'::JSONB,
      created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
      updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
    )
  `);

  await query(`
    UPDATE sources
    SET logo_url = 'https://www.google.com/s2/favicons?domain=' ||
      regexp_replace(regexp_replace(source_url, '^https?://(www\\.)?', ''), '/.*$', '') ||
      '&sz=96',
      updated_at = NOW()
    WHERE (logo_url IS NULL OR logo_url = '')
      AND source_url ~ '^https?://'
  `);

  await query(`
    UPDATE sources
    SET logo_url = 'https://www.google.com/s2/favicons?domain=' ||
      regexp_replace(regexp_replace(feed_channels.feed_url, '^https?://(www\\.)?', ''), '/.*$', '') ||
      '&sz=96',
      updated_at = NOW()
    FROM feed_channels
    WHERE feed_channels.source_id = sources.id
      AND (sources.logo_url IS NULL OR sources.logo_url = '')
      AND feed_channels.feed_url ~ '^https?://'
  `);
}
