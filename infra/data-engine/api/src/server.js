import Fastify from 'fastify';
import cors from '@fastify/cors';
import helmet from '@fastify/helmet';
import { requireApiToken } from './auth.js';
import { pool } from './db.js';
import { registerRoutes } from './routes.js';

const app = Fastify({
  logger: true,
  trustProxy: false
});

await app.register(helmet, {
  contentSecurityPolicy: false
});

await app.register(cors, {
  origin: false
});

app.get('/health', async () => ({ ok: true, service: 'rifnote-data-api' }));

app.addHook('preHandler', async (request, reply) => {
  if (request.url === '/health') {
    return;
  }

  if (!requireApiToken(request, reply)) {
    return reply;
  }
});

await registerRoutes(app);

const shutdown = async () => {
  app.log.info('shutting down');
  await app.close();
  await pool.end();
  process.exit(0);
};

process.on('SIGINT', shutdown);
process.on('SIGTERM', shutdown);

const port = Number(process.env.PORT || 3010);
await app.listen({ host: '0.0.0.0', port });
