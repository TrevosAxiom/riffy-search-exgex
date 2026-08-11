import crypto from 'node:crypto';

const COOKIE_NAME = 'rifnote_data_admin';

function configuredToken() {
  return process.env.RIFNOTE_DATA_API_TOKEN || '';
}

function cookieMap(header = '') {
  return String(header || '').split(';').reduce((cookies, part) => {
    const [rawKey, ...rest] = part.trim().split('=');
    if (rawKey) {
      cookies[rawKey] = decodeURIComponent(rest.join('=') || '');
    }
    return cookies;
  }, {});
}

function signature(value) {
  return crypto.createHmac('sha256', configuredToken()).update(value).digest('hex');
}

export function adminCookieName() {
  return COOKIE_NAME;
}

export function createAdminSessionCookie() {
  const issued = String(Date.now());
  const value = `${issued}.${signature(issued)}`;
  return `${COOKIE_NAME}=${encodeURIComponent(value)}; HttpOnly; Secure; SameSite=Lax; Path=/admin; Max-Age=43200`;
}

export function clearAdminSessionCookie() {
  return `${COOKIE_NAME}=; HttpOnly; Secure; SameSite=Lax; Path=/admin; Max-Age=0`;
}

export function hasAdminSession(request) {
  const configured = configuredToken();
  if (!configured || configured.length < 24) {
    return false;
  }

  const value = cookieMap(request.headers.cookie || '')[COOKIE_NAME] || '';
  const [issued, sig] = value.split('.');
  const ageMs = Date.now() - Number(issued || 0);

  return !!issued && !!sig && ageMs >= 0 && ageMs <= 12 * 60 * 60 * 1000 && sig === signature(issued);
}

export function isAdminPath(url = '') {
  return String(url).startsWith('/admin');
}

export function isPublicAdminPath(url = '') {
  return String(url).startsWith('/admin/login');
}

export function requireApiToken(request, reply) {
  const configured = configuredToken();
  if (!configured || configured.length < 24) {
    reply.code(503).send({ error: 'api_token_not_configured' });
    return false;
  }

  const header = request.headers.authorization || '';
  const token = header.startsWith('Bearer ') ? header.slice(7).trim() : '';

  if (token !== configured && request.headers['x-rifnote-token'] !== configured && request.headers['x-api-key'] !== configured) {
    reply.code(401).send({ error: 'unauthorized' });
    return false;
  }

  return true;
}
