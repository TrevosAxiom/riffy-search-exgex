import Fastify from 'fastify';
import cors from '@fastify/cors';
import formbody from '@fastify/formbody';
import helmet from '@fastify/helmet';
import { hasAdminSession, isAdminPath, isPublicAdminPath, requireApiToken } from './auth.js';
import { pool } from './db.js';
import { registerAdminConsole } from './admin-console.js';
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

await app.register(formbody);

app.get('/health', async () => ({ ok: true, service: 'rifnote-data-api' }));

app.addHook('preHandler', async (request, reply) => {
  if (request.url === '/health') {
    return;
  }

  if (isPublicAdminPath(request.url)) {
    return;
  }

  if (isAdminPath(request.url)) {
    if (hasAdminSession(request)) {
      return;
    }

    return reply.redirect('/admin/login', 303);
  }

  if (!requireApiToken(request, reply)) {
    return reply;
  }
});

await registerAdminConsole(app);
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
