function context() {
  return window.RIFNOTE_SEARCH ?? null;
}

function headers() {
  const nonce = context()?.nonce;
  return nonce ? { 'X-WP-Nonce': nonce } : {};
}

function restBaseUrl() {
  const restUrl = context()?.restUrl;

  if (!restUrl) {
    return '';
  }

  const configured = new URL(restUrl, window.location.href);

  if (configured.hostname === window.location.hostname && configured.protocol !== window.location.protocol) {
    configured.protocol = window.location.protocol;
    configured.port = window.location.port;
  }

  return configured.toString();
}

export function getAnonKey() {
  const storageKey = 'rifnote_anon_key';
  const existing = window.localStorage?.getItem(storageKey);

  if (existing) {
    return existing;
  }

  const generated = `rfs_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 12)}`;
  window.localStorage?.setItem(storageKey, generated);

  return generated;
}

function getSessionKey() {
  const storageKey = 'rifnote_session_key';
  const startedKey = 'rifnote_session_started_at';
  const existing = window.sessionStorage?.getItem(storageKey);

  if (existing) {
    return existing;
  }

  const generated = `rfsess_${Date.now().toString(36)}_${Math.random().toString(36).slice(2, 12)}`;
  window.sessionStorage?.setItem(storageKey, generated);
  window.sessionStorage?.setItem(startedKey, String(Date.now()));

  return generated;
}

function visitorStats() {
  const countKey = 'rifnote_visit_count';
  const lastSeenKey = 'rifnote_last_seen_at';
  const countedKey = 'rifnote_session_counted';
  const current = Number(window.localStorage?.getItem(countKey) || 0);
  const counted = window.sessionStorage?.getItem(countedKey);
  const next = counted ? Math.max(1, current) : current + 1;
  if (!counted) {
    window.sessionStorage?.setItem(countedKey, '1');
  }
  window.localStorage?.setItem(countKey, String(Math.max(1, next)));
  window.localStorage?.setItem(lastSeenKey, String(Date.now()));

  return {
    visit_count: Math.max(1, next),
    is_returning: next > 1,
  };
}

function deviceType() {
  const width = window.innerWidth || 1280;
  if (width <= 760) return 'mobile';
  if (width <= 1100) return 'tablet';
  return 'desktop';
}

function pageType() {
  const mode = document.querySelector('.rifnote-search-root')?.dataset?.rifnoteMode || 'app';
  if (mode && mode !== 'app') return mode;
  if (window.location.pathname.includes('football')) return 'football';
  if (window.location.pathname.includes('story')) return 'story-cluster';
  if (window.location.search.includes('q=')) return 'search-results';
  return 'home';
}

export function audienceContext(extra = {}) {
  return {
    visitor_id: getAnonKey(),
    session_id: getSessionKey(),
    ...visitorStats(),
    device_type: deviceType(),
    page_type: pageType(),
    path: window.location.pathname,
    url: window.location.href,
    referrer: document.referrer || '',
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || '',
    ...extra,
  };
}

export async function searchRifnote({ query = '', category = 'All News', dateRange = 'all', sort = 'relevance', page = 1, perPage = 10 } = {}) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) {
    return { query, results: [], pagination: { page, per_page: perPage, total: 0, total_pages: 1 }, no_result_insights: null, football_results: null };
  }

  const url = new URL('rifnote/v1/search', baseUrl);
  const audience = audienceContext();
  if (query) url.searchParams.set('q', query);
  if (category && category !== 'All News') url.searchParams.set('category', category);
  url.searchParams.set('date_range', dateRange);
  url.searchParams.set('sort', sort);
  url.searchParams.set('page', page);
  url.searchParams.set('per_page', perPage);
  url.searchParams.set('visitor_id', audience.visitor_id);
  url.searchParams.set('session_id', audience.session_id);
  url.searchParams.set('device_type', audience.device_type);

  const response = await fetch(url, { headers: headers() });
  if (!response.ok) throw new Error(`Rifnote search failed: ${response.status}`);
  return response.json();
}

export async function getHomeLeadStory() {
  const baseUrl = restBaseUrl();

  if (!baseUrl) {
    return { configured: false, story: null };
  }

  const response = await fetch(new URL('rifnote/v1/home-lead', baseUrl), { headers: headers() });
  if (!response.ok) throw new Error(`Rifnote home lead failed: ${response.status}`);
  return response.json();
}

export async function getHomeNotes({ pill = 'Notes' } = {}) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) {
    return { configured: false, stories: [] };
  }

  const url = new URL('rifnote/v1/home-notes', baseUrl);
  if (pill) url.searchParams.set('pill', pill);
  const response = await fetch(url, { headers: headers() });
  if (!response.ok) throw new Error(`Rifnote home notes failed: ${response.status}`);
  return response.json();
}

export async function trashStory(postId) {
  const baseUrl = restBaseUrl();
  const id = Number(postId || 0);

  if (!baseUrl || !id) {
    throw new Error('Rifnote trash endpoint is not available.');
  }

  const response = await fetch(new URL(`rifnote/v1/admin/post/${id}/trash`, baseUrl), {
    method: 'POST',
    headers: headers(),
  });

  if (!response.ok) throw new Error(`Rifnote trash failed: ${response.status}`);
  return response.json();
}

export async function getRifnoteAiAnswer({ query = '', category = 'All News', dateRange = 'all', sort = 'relevance' } = {}) {
  if (!query.trim()) {
    return { available: false, reason: 'missing_query' };
  }

  const baseUrl = restBaseUrl();

  if (!baseUrl) {
    return { available: false, reason: 'not_configured', message: 'Rifnote REST is not available yet.' };
  }

  const url = new URL('rifnote/v1/ai-answer', baseUrl);
  url.searchParams.set('q', query);
  if (category && category !== 'All News') url.searchParams.set('category', category);
  url.searchParams.set('date_range', dateRange);
  url.searchParams.set('sort', sort);

  const response = await fetch(url, { headers: headers() });
  if (!response.ok) throw new Error(`Rifnote AI answer failed: ${response.status}`);
  return response.json();
}

export async function getTrendingTopics({ limit = 10 } = {}) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) {
    return { topics: [] };
  }

  const url = new URL('rifnote/v1/trending', baseUrl);
  url.searchParams.set('limit', limit);

  const response = await fetch(url, { headers: headers() });
  if (!response.ok) throw new Error(`Rifnote trending failed: ${response.status}`);
  return response.json();
}

export async function getSuggestions({ query = '', limit = 8 } = {}) {
  const baseUrl = restBaseUrl();

  if (!baseUrl || query.trim().length < 2) {
    return { suggestions: [] };
  }

  const url = new URL('rifnote/v1/suggest', baseUrl);
  url.searchParams.set('q', query);
  url.searchParams.set('limit', limit);

  const response = await fetch(url, { headers: headers() });
  if (!response.ok) throw new Error(`Rifnote suggestions failed: ${response.status}`);
  return response.json();
}

export async function getSocialEmbed(url = '') {
  const baseUrl = restBaseUrl();

  if (!baseUrl || !url) {
    return { url, embed_html: '' };
  }

  const endpoint = new URL('rifnote/v1/social/oembed', baseUrl);
  endpoint.searchParams.set('url', url);

  const response = await fetch(endpoint, { headers: headers() });
  if (!response.ok) throw new Error(`Rifnote social embed failed: ${response.status}`);
  return response.json();
}

export async function getStoryCluster(clusterId) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) {
    return { headline: '', summary: '', stories: [], timeline: [] };
  }

  const response = await fetch(new URL(`rifnote/v1/story/${encodeURIComponent(clusterId)}`, baseUrl), { headers: headers() });
  if (!response.ok) throw new Error(`Rifnote story cluster failed: ${response.status}`);
  return response.json();
}

export async function getSourceProfile(domain) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) {
    return { domain, source_name: domain, trust: { source_authority_score: 0, approved: false, verified: false, recent_story_count: 0 }, stories: [] };
  }

  const response = await fetch(new URL(`rifnote/v1/source/${encodeURIComponent(domain)}`, baseUrl), { headers: headers() });
  if (!response.ok) throw new Error(`Rifnote source profile failed: ${response.status}`);
  return response.json();
}

export async function getDailyBriefing({ limit = 8 } = {}) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) {
    return { title: 'Rifnote Daily Briefing', intro: '', trending_topics: [], stories: [] };
  }

  const url = new URL('rifnote/v1/daily-briefing', baseUrl);
  url.searchParams.set('limit', limit);
  const response = await fetch(url, { headers: headers() });
  if (!response.ok) throw new Error(`Rifnote briefing failed: ${response.status}`);
  return response.json();
}

export async function getFootballLive({ force = false } = {}) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) {
    return { provider: 'not-configured', configured: false, updated_at: new Date().toISOString(), poll_after: 30, fixtures: [] };
  }

  const url = new URL('rifnote/v1/football/live', baseUrl);
  if (force) url.searchParams.set('force', '1');

  const response = await fetch(url, { headers: headers() });
  if (!response.ok) throw new Error(`Rifnote football live failed: ${response.status}`);
  return response.json();
}

export async function getFootballFixtures({ date = '', league = '', season = '', force = false } = {}) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) {
    return { provider: 'not-configured', configured: false, updated_at: new Date().toISOString(), poll_after: 300, fixtures: [] };
  }

  const url = new URL('rifnote/v1/football/fixtures', baseUrl);
  if (date) url.searchParams.set('date', date);
  if (league) url.searchParams.set('league', league);
  if (season) url.searchParams.set('season', season);
  if (force) url.searchParams.set('force', '1');

  const response = await fetch(url, { headers: headers() });
  if (!response.ok) throw new Error(`Rifnote football fixtures failed: ${response.status}`);
  return response.json();
}

export async function getFootballStandings({ league = '', season = '', force = false } = {}) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) {
    return { provider: 'not-configured', configured: false, updated_at: new Date().toISOString(), groups: [] };
  }

  const url = new URL('rifnote/v1/football/standings', baseUrl);
  if (league) url.searchParams.set('league', league);
  if (season) url.searchParams.set('season', season);
  if (force) url.searchParams.set('force', '1');

  const response = await fetch(url, { headers: headers() });
  if (!response.ok) throw new Error(`Rifnote football standings failed: ${response.status}`);
  return response.json();
}

export async function getFootballCompetition({ league = '', season = '', force = false } = {}) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) {
    return { provider: 'not-configured', configured: false, updated_at: new Date().toISOString(), league: null, standings: { groups: [] }, top_scorers: { players: [] }, competitions: [] };
  }

  const url = new URL('rifnote/v1/football/competition', baseUrl);
  if (league) url.searchParams.set('league', league);
  if (season) url.searchParams.set('season', season);
  if (force) url.searchParams.set('force', '1');

  const response = await fetch(url, { headers: headers() });
  if (!response.ok) throw new Error(`Rifnote football competition failed: ${response.status}`);
  return response.json();
}

export async function getFootballWatchlist({ date = '', force = false } = {}) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) {
    return { provider: 'not-configured', configured: false, updated_at: new Date().toISOString(), poll_after: 300, competitions: [], fixtures: [] };
  }

  const url = new URL('rifnote/v1/football/watchlist', baseUrl);
  if (date) url.searchParams.set('date', date);
  if (force) url.searchParams.set('force', '1');

  const response = await fetch(url, { headers: headers() });
  if (!response.ok) throw new Error(`Rifnote football watchlist failed: ${response.status}`);
  return response.json();
}

export async function getFootballUpcoming({ next = 30, force = false } = {}) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) {
    return { provider: 'not-configured', configured: false, updated_at: new Date().toISOString(), poll_after: 300, competitions: [], fixtures: [] };
  }

  const url = new URL('rifnote/v1/football/upcoming', baseUrl);
  url.searchParams.set('next', next);
  if (force) url.searchParams.set('force', '1');

  const response = await fetch(url, { headers: headers() });
  if (!response.ok) throw new Error(`Rifnote football upcoming failed: ${response.status}`);
  return response.json();
}

export async function getFootballFinished({ limit = 30, force = false } = {}) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) {
    return { provider: 'not-configured', configured: false, updated_at: new Date().toISOString(), poll_after: 300, competitions: [], fixtures: [] };
  }

  const url = new URL('rifnote/v1/football/finished', baseUrl);
  url.searchParams.set('limit', limit);
  if (force) url.searchParams.set('force', '1');

  const response = await fetch(url, { headers: headers() });
  if (!response.ok) throw new Error(`Rifnote football finished failed: ${response.status}`);
  return response.json();
}

export async function getFootballFixtureDetails(fixtureId, { force = false } = {}) {
  const baseUrl = restBaseUrl();

  if (!baseUrl || !fixtureId) {
    return { provider: 'not-configured', configured: false, fixture: null, details: { goalscorers: [], statistics: [], timeline: [], h2h: [], squads: [] } };
  }

  const url = new URL(`rifnote/v1/football/fixture/${encodeURIComponent(fixtureId)}`, baseUrl);
  if (force) {
    url.searchParams.set('force', '1');
    url.searchParams.set('_live', Date.now().toString());
  }

  const response = await fetch(url, { headers: headers(), cache: force ? 'no-store' : 'default' });
  if (!response.ok) throw new Error(`Rifnote football fixture details failed: ${response.status}`);
  return response.json();
}

export async function getFootballTeams({ league = '', season = '', limit = 100 } = {}) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) {
    return { source: 'database', configured: false, teams: [], competitions: [] };
  }

  const url = new URL('rifnote/v1/football/teams', baseUrl);
  if (league) url.searchParams.set('league', league);
  if (season) url.searchParams.set('season', season);
  url.searchParams.set('limit', limit);

  const response = await fetch(url, { headers: headers() });
  if (!response.ok) throw new Error(`Rifnote football teams failed: ${response.status}`);
  return response.json();
}

export async function getFootballTeamProfile(teamId, { limit = 12 } = {}) {
  const baseUrl = restBaseUrl();

  if (!baseUrl || !teamId) {
    return { source: 'database', configured: false, team: null, fixtures: [], stats: {}, players: [], latest_news: [] };
  }

  const url = new URL(`rifnote/v1/football/team/${encodeURIComponent(teamId)}`, baseUrl);
  url.searchParams.set('limit', limit);

  const response = await fetch(url, { headers: headers() });
  if (!response.ok) throw new Error(`Rifnote football team failed: ${response.status}`);
  return response.json();
}

export async function getFootballPlayers({ team = '', limit = 120 } = {}) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) {
    return { source: 'database', configured: false, players: [], teams: [] };
  }

  const url = new URL('rifnote/v1/football/players', baseUrl);
  if (team) url.searchParams.set('team', team);
  url.searchParams.set('limit', limit);

  const response = await fetch(url, { headers: headers() });
  if (!response.ok) throw new Error(`Rifnote football players failed: ${response.status}`);
  return response.json();
}

export async function getFootballPlayerProfile({ playerId = '', playerName = '', limit = 14 } = {}) {
  const baseUrl = restBaseUrl();

  if (!baseUrl || (!playerId && !playerName)) {
    return { source: 'database', configured: false, player: null, stats: {}, fixtures: [], events: [], latest_news: [] };
  }

  const url = new URL('rifnote/v1/football/player', baseUrl);
  if (playerId) url.searchParams.set('player_id', playerId);
  if (playerName) url.searchParams.set('player_name', playerName);
  url.searchParams.set('limit', limit);

  const response = await fetch(url, { headers: headers() });
  if (!response.ok) throw new Error(`Rifnote football player failed: ${response.status}`);
  return response.json();
}

export async function getFootballTransfers({ limit = 24 } = {}) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) {
    return { source: 'database', configured: false, stories: [], sources: 0, topics: [] };
  }

  const url = new URL('rifnote/v1/football/transfers', baseUrl);
  url.searchParams.set('limit', limit);

  const response = await fetch(url, { headers: headers() });
  if (!response.ok) throw new Error(`Rifnote football transfers failed: ${response.status}`);
  return response.json();
}

export async function getLiveWeather({ force = false, latitude = null, longitude = null, label = '' } = {}) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) {
    return { provider: 'not-configured', source_label: 'Setup', configured: false, updated_at: new Date().toISOString(), poll_after: 900, items: [] };
  }

  const url = new URL('rifnote/v1/live/weather', baseUrl);
  if (force) url.searchParams.set('force', '1');
  if (latitude !== null && longitude !== null) {
    url.searchParams.set('latitude', latitude);
    url.searchParams.set('longitude', longitude);
    if (label) url.searchParams.set('label', label);
  }

  const response = await fetch(url, { headers: headers() });
  if (!response.ok) throw new Error(`Rifnote weather failed: ${response.status}`);
  return response.json();
}

export async function getWorldWeather({ force = false } = {}) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) {
    return { provider: 'not-configured', source_label: 'Setup', configured: false, updated_at: new Date().toISOString(), poll_after: 900, items: [] };
  }

  const url = new URL('rifnote/v1/live/weather/world', baseUrl);
  if (force) url.searchParams.set('force', '1');

  const response = await fetch(url, { headers: headers() });
  if (!response.ok) throw new Error(`Rifnote world weather failed: ${response.status}`);
  return response.json();
}

export async function getLiveMarkets({ force = false } = {}) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) {
    return { provider: 'not-configured', source_label: 'Setup', configured: false, updated_at: new Date().toISOString(), poll_after: 900, items: [] };
  }

  const url = new URL('rifnote/v1/live/markets', baseUrl);
  if (force) url.searchParams.set('force', '1');

  const response = await fetch(url, { headers: headers() });
  if (!response.ok) throw new Error(`Rifnote markets failed: ${response.status}`);
  return response.json();
}

export async function getFeedDiagnostics(publisherId) {
  const baseUrl = restBaseUrl();

  if (!baseUrl || !publisherId) {
    return null;
  }

  const response = await fetch(new URL(`rifnote/v1/feed-diagnostics/${publisherId}`, baseUrl), { headers: headers() });
  if (!response.ok) throw new Error(`Rifnote feed diagnostics failed: ${response.status}`);
  return response.json();
}

export async function subscribeNoResult(payload) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) {
    return { success: false, message: 'Rifnote REST is not available yet.' };
  }

  const url = new URL('rifnote/v1/no-result/subscribe', baseUrl);
  const response = await fetch(url, {
    method: 'POST',
    headers: { ...headers(), 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  const data = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new Error(data?.message || `Rifnote no-result subscription failed: ${response.status}`);
  }

  return data;
}

export async function submitPublisherStory(payload) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) {
    return { success: false, status: 'unavailable', message: 'Rifnote REST is not available yet.' };
  }

  const url = new URL('rifnote/v1/submit-post', baseUrl);
  const response = await fetch(url, {
    method: 'POST',
    headers: { ...headers(), 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  const data = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new Error(data?.message || `Rifnote submission failed: ${response.status}`);
  }

  return data;
}

export async function submitPublisherSignup(payload) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) {
    return { success: false, message: 'Rifnote REST is not available yet.' };
  }

  const response = await fetch(new URL('rifnote/v1/publisher/signup', baseUrl), {
    method: 'POST',
    headers: { ...headers(), 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  const data = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new Error(data?.message || `Rifnote publisher signup failed: ${response.status}`);
  }

  return data;
}

export async function submitLegalRequest(payload) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) {
    return { success: false, status: 'unavailable', message: 'Rifnote REST is not available yet.' };
  }

  const url = new URL('rifnote/v1/legal-request', baseUrl);
  const response = await fetch(url, {
    method: 'POST',
    headers: { ...headers(), 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  const data = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new Error(data?.message || `Rifnote legal request failed: ${response.status}`);
  }

  return data;
}

export async function submitBetaFeedback(payload) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) {
    return { success: false, message: 'Rifnote REST is not available yet.' };
  }

  const url = new URL('rifnote/v1/beta-feedback', baseUrl);
  const response = await fetch(url, {
    method: 'POST',
    headers: { ...headers(), 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  const data = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new Error(data?.message || `Rifnote beta feedback failed: ${response.status}`);
  }

  return data;
}

export async function submitSponsorRequest(payload) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) {
    return { success: false, checkout_url: '', message: 'Rifnote REST is not available yet.' };
  }

  const url = new URL('rifnote/v1/sponsor/request', baseUrl);
  const response = await fetch(url, {
    method: 'POST',
    headers: { ...headers(), 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  const data = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new Error(data?.message || `Rifnote sponsor request failed: ${response.status}`);
  }

  return data;
}

export async function submitAdvertiserSignup(payload) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) {
    return { success: false, message: 'Rifnote REST is not available yet.' };
  }

  const response = await fetch(new URL('rifnote/v1/advertiser/signup', baseUrl), {
    method: 'POST',
    headers: { ...headers(), 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  const data = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new Error(data?.message || `Rifnote advertiser signup failed: ${response.status}`);
  }

  return data;
}

export async function getAdInventory() {
  const baseUrl = restBaseUrl();

  if (!baseUrl) {
    return { currency: 'NGN', placements: [], objectives: [] };
  }

  const url = new URL('rifnote/v1/ads/inventory', baseUrl);
  const response = await fetch(url, { headers: headers() });
  const data = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new Error(data?.message || `Rifnote ad inventory failed: ${response.status}`);
  }

  return data;
}

export async function uploadMedia(file) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) {
    throw new Error('Rifnote REST is not available yet.');
  }

  if (!file) {
    throw new Error('Choose a media file first.');
  }

  const formData = new FormData();
  formData.append('file', file);

  const response = await fetch(new URL('rifnote/v1/media/upload', baseUrl), {
    method: 'POST',
    headers: headers(),
    body: formData,
  });
  const data = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new Error(data?.message || `Rifnote media upload failed: ${response.status}`);
  }

  return data;
}

export async function getAdvertiserDashboard() {
  const baseUrl = restBaseUrl();

  if (!baseUrl) {
    return { authenticated: false, campaigns: [], summary: {}, message: 'Rifnote REST is not available yet.' };
  }

  const url = new URL('rifnote/v1/advertiser/dashboard', baseUrl);
  const response = await fetch(url, { headers: headers() });
  const data = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new Error(data?.message || `Rifnote advertiser dashboard failed: ${response.status}`);
  }

  return data;
}

export async function submitAdvertiserPaymentProof(payload) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) {
    return { success: false, message: 'Rifnote REST is not available yet.' };
  }

  const response = await fetch(new URL('rifnote/v1/advertiser/payment-proof', baseUrl), {
    method: 'POST',
    headers: { ...headers(), 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  const data = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new Error(data?.message || `Rifnote payment proof failed: ${response.status}`);
  }

  return data;
}

export async function updateAdvertiserProfile(payload) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) {
    return { success: false, message: 'Rifnote REST is not available yet.' };
  }

  const response = await fetch(new URL('rifnote/v1/advertiser/profile', baseUrl), {
    method: 'POST',
    headers: { ...headers(), 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  const data = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new Error(data?.message || `Rifnote advertiser profile failed: ${response.status}`);
  }

  return data;
}

export async function trackSponsoredClick(id) {
  const baseUrl = restBaseUrl();

  if (!baseUrl || !id) {
    return { success: true };
  }

  const url = new URL('rifnote/v1/sponsored/click', baseUrl);
  const response = await fetch(url, {
    method: 'POST',
    headers: { ...headers(), 'Content-Type': 'application/json' },
    body: JSON.stringify({ id }),
  });
  const data = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new Error(data?.message || `Rifnote sponsored click failed: ${response.status}`);
  }

  return data;
}

export async function savePreference(payload) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) return { success: true };

  const response = await fetch(new URL('rifnote/v1/preferences', baseUrl), {
    method: 'POST',
    headers: { ...headers(), 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(data?.message || `Rifnote preference failed: ${response.status}`);
  return data;
}

export async function getForYou({ anonKey = '', limit = 12 } = {}) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) return { preferences: [], results: [] };

  const url = new URL('rifnote/v1/for-you', baseUrl);
  if (anonKey) url.searchParams.set('anon_key', anonKey);
  url.searchParams.set('limit', limit);
  const response = await fetch(url, { headers: headers() });
  if (!response.ok) throw new Error(`Rifnote For You failed: ${response.status}`);
  return response.json();
}

export async function registerDevice(payload = {}) {
  const baseUrl = restBaseUrl();
  const anonKey = payload.anon_key || getAnonKey();

  if (!baseUrl) return { success: true, device_id: anonKey };

  const response = await fetch(new URL('rifnote/v1/device', baseUrl), {
    method: 'POST',
    headers: { ...headers(), 'Content-Type': 'application/json' },
    body: JSON.stringify({
      anon_key: anonKey,
      device_id: payload.device_id || anonKey,
      platform: payload.platform || (window.matchMedia?.('(display-mode: standalone)').matches ? 'pwa' : 'web'),
      permission_status: payload.permission_status || (typeof Notification !== 'undefined' ? Notification.permission : 'unsupported'),
      app_version: context()?.version || '',
      user_agent: navigator.userAgent,
      ...payload,
    }),
  });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(data?.message || `Rifnote device registration failed: ${response.status}`);
  return data;
}

export async function getNotifications({ anonKey = '', limit = 20 } = {}) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) return { notifications: [] };

  const url = new URL('rifnote/v1/notifications', baseUrl);
  if (anonKey) url.searchParams.set('anon_key', anonKey);
  url.searchParams.set('limit', limit);

  const response = await fetch(url, { headers: headers() });
  if (!response.ok) throw new Error(`Rifnote notifications failed: ${response.status}`);
  return response.json();
}

export async function updateNotification(payload) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) return { success: true };

  const response = await fetch(new URL('rifnote/v1/notifications', baseUrl), {
    method: 'POST',
    headers: { ...headers(), 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(data?.message || `Rifnote notification update failed: ${response.status}`);
  return data;
}

export async function saveAlert(payload) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) return { success: false, message: 'Rifnote REST is not available yet.' };

  const response = await fetch(new URL('rifnote/v1/alerts', baseUrl), {
    method: 'POST',
    headers: { ...headers(), 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(data?.message || `Rifnote alert failed: ${response.status}`);
  return data;
}

export async function subscribeNewsletter(payload) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) return { success: false, message: 'Rifnote REST is not available yet.' };

  const response = await fetch(new URL('rifnote/v1/newsletter', baseUrl), {
    method: 'POST',
    headers: { ...headers(), 'Content-Type': 'application/json' },
    body: JSON.stringify(payload),
  });
  const data = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(data?.message || `Rifnote newsletter failed: ${response.status}`);
  return data;
}

export async function getWidget(widget = 'trending') {
  const baseUrl = restBaseUrl();

  if (!baseUrl) return { topics: [] };

  const response = await fetch(new URL(`rifnote/v1/widget/${widget}`, baseUrl), { headers: headers() });
  if (!response.ok) throw new Error(`Rifnote widget failed: ${response.status}`);
  return response.json();
}

export async function getPublisherStats({ publisherId = '' } = {}) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) {
    return {
      success: false,
      profile: null,
      stats: { submitted_posts: 0, pending_posts: 0, approved_posts: 0, rejected_posts: 0, indexed_posts: 0, clicks_sent: 0, analytics_ready: false },
      submissions: [],
      indexed_posts: [],
    };
  }

  const url = new URL('rifnote/v1/publisher/stats', baseUrl);
  if (publisherId) url.searchParams.set('publisher_id', publisherId);

  const response = await fetch(url, { headers: headers() });
  const data = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new Error(data?.message || `Rifnote publisher stats failed: ${response.status}`);
  }

  return data;
}

export function trackAnalyticsEvent(payload = {}) {
  const baseUrl = restBaseUrl();

  if (!baseUrl) {
    return;
  }

  const url = new URL('rifnote/v1/analytics/event', baseUrl);
  const metadata = { ...(payload.metadata || {}), ...audienceContext(payload.metadata || {}) };
  const body = JSON.stringify({
    ...payload,
    visitor_id: metadata.visitor_id,
    session_id: metadata.session_id,
    metadata,
  });

  if (navigator.sendBeacon) {
    const blob = new Blob([body], { type: 'application/json' });
    navigator.sendBeacon(url.toString(), blob);
    return;
  }

  fetch(url, {
    method: 'POST',
    headers: { ...headers(), 'Content-Type': 'application/json' },
    body,
    keepalive: true,
  }).catch(() => {});
}
