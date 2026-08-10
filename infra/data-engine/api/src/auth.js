export function requireApiToken(request, reply) {
  const configured = process.env.RIFNOTE_DATA_API_TOKEN;
  if (!configured || configured.length < 24) {
    reply.code(503).send({ error: 'api_token_not_configured' });
    return false;
  }

  const header = request.headers.authorization || '';
  const token = header.startsWith('Bearer ') ? header.slice(7).trim() : '';

  if (token !== configured) {
    reply.code(401).send({ error: 'unauthorized' });
    return false;
  }

  return true;
}
