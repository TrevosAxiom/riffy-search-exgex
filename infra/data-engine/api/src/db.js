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
