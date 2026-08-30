import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { createRoot } from 'react-dom/client';
import { ArrowLeft, ArrowRight, Bookmark, CalendarDays, Clock3, Cloud, CloudRain, CloudSun, DollarSign, ExternalLink, Flame, Globe2, Goal, Home, Landmark, Map as MapIcon, Menu, Newspaper, Pencil, Play, Radio, RotateCcw, Search, Shield, Sun, Trash2, TrendingUp, Trophy, UserRound, Volume2, VolumeX } from 'lucide-react';
import { getAdInventory, getAdvertiserDashboard, getAnonKey, getDailyBriefing, getFeedDiagnostics, getFootballCompetition, getFootballFinished, getFootballFixtureDetails, getFootballFixtures, getFootballLive, getFootballPlayerProfile, getFootballPlayers, getFootballTeamProfile, getFootballTeams, getFootballTransfers, getFootballUpcoming, getForYou, getHomeLeadStory, getHomeNotes, getLiveMarkets, getLiveWeather, getNotifications, getPublisherStats, getRifnoteAiAnswer, getSocialEmbed, getSourceProfile, getStoryCluster, getSuggestions, getTrendingTopics, getWidget, getWorldWeather, registerDevice, saveAlert, savePreference, searchRifnote, subscribeNewsletter, submitAdvertiserPaymentProof, submitAdvertiserSignup, submitBetaFeedback, submitLegalRequest, submitPublisherSignup, submitPublisherStory, submitSponsorRequest, subscribeNoResult, trackAnalyticsEvent, trackSponsoredClick, trashStory, updateAdvertiserProfile, updateNotification, uploadMedia } from './api.js';
import { rifnoteCategories, searchTabs } from './data/rifnote.js';
import './styles/index.css';

const defaultHomePills = [
  { label: 'Notes', category: 'Notes', is_notes: true },
  { label: 'Nigeria', category: 'Nigeria' },
  { label: 'World', category: 'World' },
  { label: 'Football', category: 'Football' },
  { label: 'Politics', category: 'Politics' },
  { label: 'Business', category: 'Business' },
  { label: 'Tech', category: 'Tech' },
];
const showStoryExcerpts = window.RIFNOTE_SEARCH?.showExcerpts !== false;
const showAiCards = window.RIFNOTE_SEARCH?.showAiCards !== false;
const pwaStorageKeys = {
  savedPrefix: 'rifnote_saved_story_',
  preferences: 'rifnote_pwa_preferences',
  onboarding: 'rifnote_pwa_onboarding_done',
  lastCatchup: 'rifnote_pwa_last_catchup',
};

function isStandalonePwa() {
  return Boolean(window.matchMedia?.('(display-mode: standalone)').matches || window.navigator.standalone === true);
}

function safeJsonParse(value, fallback) {
  try {
    return value ? JSON.parse(value) : fallback;
  } catch (_) {
    return fallback;
  }
}

function readSavedStories() {
  const stories = [];

  try {
    for (let index = 0; index < window.localStorage.length; index += 1) {
      const key = window.localStorage.key(index);
      if (key?.startsWith(pwaStorageKeys.savedPrefix)) {
        const story = safeJsonParse(window.localStorage.getItem(key), null);
        if (story?.id || story?.headline) {
          stories.push(story);
        }
      }
    }
  } catch (_) {}

  return stories.sort((first, second) => storyTimeValue(second) - storyTimeValue(first)).slice(0, 30);
}

function readPwaPreferences() {
  return safeJsonParse(window.localStorage?.getItem(pwaStorageKeys.preferences), {
    teams: [],
    topics: [],
    cities: [],
    sources: [],
    quietHours: false,
    privateMode: false,
    compactMode: false,
  });
}

function writePwaPreferences(next) {
  try {
    window.localStorage?.setItem(pwaStorageKeys.preferences, JSON.stringify(next));
  } catch (_) {}
}

function normalizeHomePills(rawPills) {
  const pills = Array.isArray(rawPills) ? rawPills : [];
  const clean = pills
    .map((pill) => {
      const label = String(pill?.label || '').trim();
      const category = String(pill?.category || label).trim();
      const isNotes = Boolean(pill?.is_notes) || label.toLowerCase() === 'notes' || category.toLowerCase() === 'notes';

      if (!label) {
        return null;
      }

      return {
        label,
        category: category || label,
        is_notes: isNotes,
      };
    })
    .filter(Boolean);

  const notesPill = clean.find((pill) => pill.is_notes) || defaultHomePills[0];
  const rest = clean.filter((pill) => !pill.is_notes);
  const keyed = new Map();

  [notesPill, ...rest].forEach((pill) => {
    const key = `${pill.label.toLowerCase()}|${pill.category.toLowerCase()}`;
    if (!keyed.has(key)) {
      keyed.set(key, pill);
    }
  });

  return Array.from(keyed.values()).slice(0, 10);
}

function runtimeSiteCategories() {
  const categories = Array.isArray(window.RIFNOTE_SEARCH?.siteCategories) ? window.RIFNOTE_SEARCH.siteCategories : [];
  return categories
    .map((category) => ({
      id: category?.id || category?.term_id || category?.slug || category?.name,
      name: decodeText(category?.name || ''),
      slug: category?.slug || slugify(category?.name || ''),
      count: Number(category?.count || 0),
      url: category?.url || `${window.RIFNOTE_SEARCH?.homeUrl || '/'}category/${category?.slug || slugify(category?.name || '')}/`,
    }))
    .filter((category) => category.name);
}

function runtimeHomePills() {
  if (!Array.isArray(window.RIFNOTE_SEARCH?.homePills)) {
    return defaultHomePills;
  }

  return normalizeHomePills(window.RIFNOTE_SEARCH.homePills);
}

function Badge({ children, tone = '' }) {
  return <span className={`rs-badge ${tone}`}>{children}</span>;
}

function trackStoryClick(story, eventType = 'source_click', query = '') {
  trackAnalyticsEvent({
    event_type: eventType,
    post_id: story.id,
    publisher_id: story.publisher_id,
    source_name: decodeText(story.source_name),
    target_url: story.read_full_story_url || story.original_url || story.source_url,
    query_text: query,
  });
}

function isInternalUrl(url = '') {
  if (!url || url === '#') {
    return true;
  }

  try {
    return new URL(url, window.location.origin).origin === window.location.origin;
  } catch (_) {
    return false;
  }
}

function linkPropsForUrl(url = '') {
  return isInternalUrl(url) ? {} : { target: '_blank', rel: 'noreferrer' };
}

function storyReadUrl(story) {
  if (story?.is_rifnote_story) {
    return story.permalink || story.story_url || story.read_full_story_url || '#';
  }

  return story?.read_full_story_url || story?.original_url || story?.story_url || story?.permalink || '#';
}

function Card({ children, className = '', accent = false }) {
  return <section className={`rs-card ${accent ? 'accent' : ''} ${className}`}>{children}</section>;
}

function CardHeader({ title, action }) {
  return (
    <div className="rs-card-header">
      <h2>{title}</h2>
      {action}
    </div>
  );
}

function MediaUploadField({ label, value, accept = 'image/*', note = '', onUploaded }) {
  const [status, setStatus] = useState({ loading: false, error: '' });

  async function handleFile(event) {
    const file = event.target.files?.[0];

    if (!file) {
      return;
    }

    setStatus({ loading: true, error: '' });

    try {
      const payload = await uploadMedia(file);
      onUploaded(payload.url || '');
      setStatus({ loading: false, error: '' });
    } catch (error) {
      setStatus({ loading: false, error: error.message });
    } finally {
      event.target.value = '';
    }
  }

  const isVideo = /\.(mp4|m4v|webm)(\?.*)?$/i.test(value || '');

  return (
    <div className="rs-upload-field">
      <span>{label}</span>
      <label className="rs-upload-drop">
        <input type="file" accept={accept} onChange={handleFile} disabled={status.loading} />
        <b>{status.loading ? 'Uploading...' : value ? 'Replace media' : 'Choose media'}</b>
        <small>{note || 'Upload from your device into the Rifnote Media Library.'}</small>
      </label>
      {value ? (
        <div className="rs-upload-preview">
          {isVideo ? <video src={value} muted controls preload="metadata" /> : <img src={value} alt="" loading="lazy" />}
          <button type="button" onClick={() => onUploaded('')}>Clear media</button>
        </div>
      ) : null}
      {status.error ? <p className="rs-form-error">{status.error}</p> : null}
    </div>
  );
}

function SourceBadge({ story }) {
  const label = story.source_domain ? `${decodeText(story.source_name)} · ${decodeText(story.source_domain)}` : decodeText(story.source_name);
  const content = (
    <>
      <SourceLogo story={story} />
      <span>Source: {label}</span>
    </>
  );

  if (story.source_profile_url) {
    return <a className="rs-source-link" href={story.source_profile_url}>{content}</a>;
  }

  if (story.source_url) {
    return <a className="rs-source-link" href={story.source_url} target="_blank" rel="noreferrer" onClick={() => trackStoryClick(story, 'source_click', '')}>{content}</a>;
  }

  return <span className="rs-source-link">{content}</span>;
}

function SourceLogo({ story, size = 'default' }) {
  const initials = decodeText(story.source_initials || story.source_name || story.source_domain || 'R').slice(0, 2).toUpperCase();
  const logoMap = window.RIFNOTE_SEARCH?.sourceLogoMap || {};
  const domain = String(story.source_domain || domainFromUrl(story.source_url || story.original_url || story.read_full_story_url || '') || '').toLowerCase().replace(/^www\./, '');
  const logoUrl = story.source_logo_url || (domain && logoMap[domain] ? logoMap[domain] : '');

  return (
    <span className={`rs-source-logo ${size === 'small' ? 'is-small' : ''} ${size === 'large' ? 'is-large' : ''}`}>
      {logoUrl ? <img src={logoUrl} alt="" loading="lazy" onError={(event) => { event.currentTarget.style.display = 'none'; }} /> : null}
      <b>{initials}</b>
    </span>
  );
}

function domainFromUrl(url = '') {
  try {
    return new URL(url).hostname.replace(/^www\./, '');
  } catch (_) {
    return '';
  }
}

function SourceMention({ story, showDomain = false, showTime = false, className = '' }) {
  const label = decodeText(story?.source_name || story?.source_domain || 'Rifnote');
  const domain = showDomain && story?.source_domain ? ` · ${decodeText(story.source_domain)}` : '';
  const time = showTime ? ` · ${story?.published_at_human || formatDate(story?.published_at)}` : '';

  return (
    <span className={`rs-source-mention ${className}`}>
      <SourceLogo story={story || {}} size="small" />
      <span>{label}{domain}{time}</span>
    </span>
  );
}

function footballSearchResultCount(payload) {
  return ['matches', 'teams', 'competitions', 'players', 'stats']
    .reduce((total, key) => total + (payload?.[key]?.length ?? 0), 0);
}

function hasFootballSearchResults(payload) {
  return footballSearchResultCount(payload) > 0;
}

function storyTimeValue(story) {
  const value = story?.published_at || story?.date || story?.modified_at;
  const timestamp = value ? new Date(value).getTime() : 0;
  return Number.isNaN(timestamp) ? 0 : timestamp;
}

function slugify(value = '') {
  return String(value)
    .trim()
    .toLowerCase()
    .replace(/&/g, 'and')
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '');
}

function getStoryVideoUrl(story) {
  return story?.video_url || story?.video || story?.media_url || story?.embed_url || ((story?.media_type === 'video' || story?.source_type === 'video') ? (story?.original_url || story?.read_full_story_url) : '');
}

function socialPlatformFromUrl(url = '') {
  const value = String(url || '').toLowerCase();
  if (value.includes('x.com') || value.includes('twitter.com')) return 'X';
  if (value.includes('instagram.com')) return 'Instagram';
  if (value.includes('tiktok.com')) return 'TikTok';
  if (value.includes('facebook.com') || value.includes('fb.watch')) return 'Facebook';
  if (value.includes('threads.net')) return 'Threads';
  if (value.includes('reddit.com')) return 'Reddit';
  if (value.includes('youtube.com') || value.includes('youtu.be')) return 'YouTube';
  return '';
}

function getStorySocialPlatform(story = {}) {
  const stored = story.social_platform || story.platform || '';
  return stored ? decodeText(stored).replace(/\b\w/g, (letter) => letter.toUpperCase()) : socialPlatformFromUrl(story.original_url || story.read_full_story_url || story.source_url);
}

function isSocialStory(story = {}) {
  const sourceType = String(story.source_type || '').toLowerCase();
  const platform = getStorySocialPlatform(story).toLowerCase();
  if (platform === 'youtube') return false;
  if (sourceType === 'social') return true;
  if (story.social_platform || story.social_author_handle || story.social_post_id || story.social_embed_html) return true;
  return Boolean(platform);
}

function trimWords(value = '', limit = 23) {
  const words = decodeText(value).replace(/\s+/g, ' ').trim().split(' ').filter(Boolean);
  if (words.length <= limit) return words.join(' ');
  return `${words.slice(0, limit).join(' ')}...`;
}

function youtubeVideoId(url = '') {
  const value = String(url || '');
  const match = value.match(/(?:v=|youtu\.be\/|shorts\/|embed\/)([A-Za-z0-9_-]{6,})/);
  return match?.[1] || '';
}

function youtubePreviewSrc(story, autoplay = false) {
  const id = youtubeVideoId(getStoryVideoUrl(story) || story.original_url || story.read_full_story_url);
  if (!id) return '';
  return `https://www.youtube.com/embed/${id}?autoplay=${autoplay ? '1' : '0'}&mute=0&controls=0&rel=0&modestbranding=1&playsinline=1&start=0&end=15`;
}

function youtubeThumbnail(story) {
  const id = youtubeVideoId(getStoryVideoUrl(story) || story.original_url || story.read_full_story_url);
  return id ? `https://i.ytimg.com/vi/${id}/hqdefault.jpg` : '';
}

function vimeoVideoId(url = '') {
  const value = String(url || '');
  const match = value.match(/vimeo\.com\/(?:video\/)?([0-9]{6,})/);
  return match?.[1] || '';
}

function externalVideoEmbedUrl(url = '', options = {}) {
  const value = String(url || '');
  const muted = options.muted !== false;
  const youtubeId = youtubeVideoId(value);
  if (youtubeId) {
    return `https://www.youtube-nocookie.com/embed/${youtubeId}?autoplay=1&mute=${muted ? '1' : '0'}&loop=1&playlist=${youtubeId}&controls=0&disablekb=1&fs=0&iv_load_policy=3&rel=0&modestbranding=1&playsinline=1`;
  }

  const vimeoId = vimeoVideoId(value);
  if (vimeoId) {
    const background = muted ? '&background=1' : '';
    return `https://player.vimeo.com/video/${vimeoId}?autoplay=1&muted=${muted ? '1' : '0'}&loop=1${background}&controls=0&title=0&byline=0&portrait=0&badge=0&dnt=1`;
  }

  return '';
}

function loadExternalScript(src, id, onLoad = () => {}) {
  if (!src || typeof document === 'undefined') return;
  const existing = id ? document.getElementById(id) : null;
  if (existing) {
    if (existing.dataset.loaded === 'true') {
      onLoad();
    } else {
      existing.addEventListener('load', onLoad, { once: true });
    }
    return;
  }

  const script = document.createElement('script');
  if (id) script.id = id;
  script.src = src;
  script.async = true;
  script.onload = () => {
    script.dataset.loaded = 'true';
    onLoad();
  };
  document.body.appendChild(script);
}

function getStoryEmbedHtml(story = {}) {
  return story?.social_embed_html || story?.embed_html || story?.oembed_html || story?.embed || '';
}

function getStoryEmbedUrl(story = {}) {
  return story?.embed_url || story?.original_url || story?.read_full_story_url || story?.source_url || '';
}

function shouldResolveRemoteEmbed(story = {}) {
  const platform = getStorySocialPlatform(story).toLowerCase();
  const sourceType = String(story.source_type || story.media_type || '').toLowerCase();
  if (!getStoryEmbedUrl(story) || getStoryEmbedHtml(story)) return false;
  if (['instagram', 'facebook', 'x', 'twitter', 'tiktok', 'threads', 'reddit', 'vimeo'].includes(platform)) return true;
  return sourceType === 'social' || sourceType === 'video';
}

function useResolvedStoryEmbed(story = {}) {
  const initialHtml = getStoryEmbedHtml(story);
  const embedUrl = getStoryEmbedUrl(story);
  const [html, setHtml] = useState(initialHtml);

  useEffect(() => {
    setHtml(initialHtml);

    if (!shouldResolveRemoteEmbed(story)) {
      return undefined;
    }

    let cancelled = false;
    getSocialEmbed(embedUrl)
      .then((payload) => {
        if (!cancelled) {
          setHtml(payload?.embed_html || '');
        }
      })
      .catch(() => {
        if (!cancelled) setHtml('');
      });

    return () => {
      cancelled = true;
    };
  }, [initialHtml, embedUrl, story?.id]);

  return html;
}

function appPageUrl(slug = '') {
  const home = window.RIFNOTE_SEARCH?.homeUrl || '/';
  const base = home.endsWith('/') ? home : `${home}/`;
  const cleanSlug = String(slug || '').replace(/^\/+|\/+$/g, '');
  return cleanSlug ? `${base}${cleanSlug}/` : base;
}

function searchUrl(query = '', extra = {}) {
  const url = new URL(appPageUrl('search'), window.location.origin);
  const trimmedQuery = String(query || '').trim();
  if (trimmedQuery) url.searchParams.set('q', trimmedQuery);
  Object.entries(extra).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') {
      url.searchParams.set(key, String(value));
    }
  });
  return url.href;
}

function footballCompetitionUrl(league = '', season = '') {
  const configured = window.RIFNOTE_SEARCH?.footballCompetitionsUrl || appPageUrl('football-competitions');
  const url = new URL(configured, window.location.origin);

  if (league) url.searchParams.set('league', league);
  if (season) url.searchParams.set('season', season);

  return url.href;
}

function useSearchState() {
  const params = new URLSearchParams(window.location.search);
  const categoryParam = params.get('category') ?? 'All News';
  const initialCategory = rifnoteCategories.find((category) => category.toLowerCase() === categoryParam.toLowerCase()) ?? categoryParam;
  const [query, setQuery] = useState(params.get('rsq') ?? params.get('q') ?? '');
  const [category, setCategory] = useState(initialCategory);
  const [dateRange, setDateRange] = useState(params.get('date_range') ?? 'all');
  const [sort, setSort] = useState(params.get('sort') ?? 'relevance');
  const [page, setPage] = useState(Number(params.get('page') ?? 1));

  return { query, setQuery, category, setCategory, dateRange, setDateRange, sort, setSort, page, setPage };
}

function App({ mode }) {
  const state = useSearchState();
  const [results, setResults] = useState([]);
  const [sponsored, setSponsored] = useState([]);
  const [pagination, setPagination] = useState({ page: 1, total: 0, total_pages: 1 });
  const [loading, setLoading] = useState(true);
  const [aiAnswer, setAiAnswer] = useState(null);
  const [aiLoading, setAiLoading] = useState(false);
  const [error, setError] = useState('');
  const [noResultInsights, setNoResultInsights] = useState(null);
  const [footballResults, setFootballResults] = useState(null);
  const [activeTab, setActiveTab] = useState('All');
  const [liveRailOpen, setLiveRailOpen] = useState(false);
  const [homeLeadStory, setHomeLeadStory] = useState(null);
  const [homeNotes, setHomeNotes] = useState(null);
  const [homeNotesArchiveUrl, setHomeNotesArchiveUrl] = useState('');
  const homepagePills = useMemo(() => runtimeHomePills(), []);
  const siteCategories = useMemo(() => runtimeSiteCategories(), []);
  const [homePill, setHomePill] = useState(homepagePills[0]?.category || 'Notes');
  const [homeUtilityTab, setHomeUtilityTab] = useState('');
  const isHome = !state.query.trim() && state.category === 'All News' && state.dateRange === 'all' && state.sort === 'relevance';
  const showHomeCategories = isHome && homeUtilityTab === 'categories';
  const homeStories = useMemo(() => results, [results]);
  const activeHomePill = useMemo(() => homepagePills.find((pill) => pill.category === homePill) || homepagePills[0] || defaultHomePills[0], [homePill, homepagePills]);
  const topStories = useMemo(() => homeStories.filter((story) => !homeLeadStory?.id || story.id !== homeLeadStory.id).slice(0, 10), [homeLeadStory?.id, homeStories]);
  const footballTabAvailable = hasFootballSearchResults(footballResults);
  const latestResults = useMemo(() => [...results].sort((first, second) => storyTimeValue(second) - storyTimeValue(first)), [results]);
  const videoResults = useMemo(() => results.filter((story) => getStoryVideoUrl(story)), [results]);
  const socialResults = useMemo(() => results.filter((story) => isSocialStory(story)), [results]);
  const visibleSearchTabs = useMemo(() => searchTabs.filter((tab) => {
    if (tab === 'Football') return footballTabAvailable;
    if (tab === 'Videos') return videoResults.length > 0;
    if (tab === 'Social') return socialResults.length > 0;
    return true;
  }), [footballTabAvailable, socialResults.length, videoResults.length]);
  const sourceCount = useMemo(() => new Set(results.map((story) => story.source_domain || story.source_name).filter(Boolean)).size, [results]);
  const searchTabCount = activeTab === 'Football' ? footballSearchResultCount(footballResults) : activeTab === 'Videos' ? videoResults.length : activeTab === 'Social' ? socialResults.length : activeTab === 'Sources' ? sourceCount : pagination.total;
  const searchTabLabel = activeTab === 'Football' ? 'football hit' : activeTab === 'Videos' ? 'video' : activeTab === 'Social' ? 'social post' : activeTab === 'Sources' ? 'source' : 'result';

  useEffect(() => {
    trackAnalyticsEvent({
      event_type: 'page_view',
      category: state.category,
      query_text: state.query,
      metadata: {
        mode,
        page: state.page,
        active_tab: activeTab,
      },
    });
  }, [activeTab, mode, state.category, state.page, state.query]);

  useEffect(() => {
    const openLive = () => setLiveRailOpen(true);
    document.addEventListener('rifnote:open-live', openLive);
    return () => document.removeEventListener('rifnote:open-live', openLive);
  }, []);

  function updateHomePill(pill) {
    const nextPill = pill || homepagePills[0] || defaultHomePills[0];
    setHomeUtilityTab('');
    setHomePill(nextPill.category);
    trackAnalyticsEvent({ event_type: 'homepage_pill_used', category: nextPill.category, metadata: { label: nextPill.label } });
  }

  function toggleHomeCategories() {
    setHomeUtilityTab((current) => {
      const next = current === 'categories' ? '' : 'categories';
      if (next) {
        trackAnalyticsEvent({ event_type: 'homepage_pill_used', category: 'Categories', metadata: { label: 'Categories' } });
      }
      return next;
    });
  }

  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    setError('');
    setFootballResults(null);

    searchRifnote({
      query: state.query,
      category: state.category,
      dateRange: state.dateRange,
      sort: state.sort,
      page: state.page,
      perPage: 10,
    })
      .then((payload) => {
        if (!cancelled) {
          setResults(payload.results ?? []);
          setSponsored(payload.sponsored ?? []);
          setPagination(payload.pagination ?? { page: 1, total: 0, total_pages: 1 });
          setNoResultInsights(payload.no_result_insights ?? null);
          setFootballResults(payload.football_results ?? null);
        }
      })
      .catch((requestError) => {
        if (!cancelled) {
          setError(requestError.message);
          setResults([]);
          setSponsored([]);
          setNoResultInsights(null);
          setFootballResults(null);
        }
      })
      .finally(() => !cancelled && setLoading(false));

    return () => {
      cancelled = true;
    };
  }, [state.category, state.dateRange, state.page, state.query, state.sort]);

  useEffect(() => {
    if (!visibleSearchTabs.includes(activeTab)) {
      setActiveTab('All');
    }

    if (activeTab === 'Top') {
      setActiveTab('All');
    }
  }, [activeTab, visibleSearchTabs]);

  useEffect(() => {
    let cancelled = false;

    if (!isHome) {
      setHomeLeadStory(null);
      setHomeNotes(null);
      setHomeNotesArchiveUrl('');
      return undefined;
    }

    setHomeLeadStory(null);
    setHomeNotes(null);
    setHomeNotesArchiveUrl('');

    Promise.allSettled([activeHomePill.is_notes ? getHomeLeadStory() : Promise.resolve({ story: null }), getHomeNotes({ pill: activeHomePill.category })])
      .then(([leadResult, notesResult]) => {
        if (!cancelled) {
          setHomeLeadStory(leadResult.status === 'fulfilled' ? (leadResult.value?.story ?? null) : null);
          setHomeNotes(notesResult.status === 'fulfilled' ? (notesResult.value?.stories ?? []) : []);
          setHomeNotesArchiveUrl(notesResult.status === 'fulfilled' ? (notesResult.value?.archive_url ?? '') : '');
        }
      })
      .catch(() => {
        if (!cancelled) {
          setHomeLeadStory(null);
          setHomeNotes([]);
          setHomeNotesArchiveUrl('');
        }
      });

    return () => {
      cancelled = true;
    };
  }, [activeHomePill.category, activeHomePill.is_notes, isHome]);

  useEffect(() => {
    if (mode !== 'app') {
      return;
    }

    const params = new URLSearchParams();

    if (state.query.trim()) params.set('q', state.query.trim());
    if (state.category !== 'All News') params.set('category', state.category);
    if (state.dateRange !== 'all') params.set('date_range', state.dateRange);
    if (state.sort !== 'relevance') params.set('sort', state.sort);
    if (state.page > 1) params.set('page', String(state.page));

    const queryString = params.toString();
    const nextUrl = `${window.location.pathname}${queryString ? `?${queryString}` : ''}`;

    if (`${window.location.pathname}${window.location.search}` !== nextUrl) {
      window.history.replaceState({}, '', nextUrl);
    }
  }, [mode, state.category, state.dateRange, state.page, state.query, state.sort]);

  useEffect(() => {
    let cancelled = false;

    if (isHome || !showAiCards) {
      setAiAnswer(null);
      setAiLoading(false);
      return undefined;
    }

    setAiLoading(true);
    getRifnoteAiAnswer({
      query: state.query,
      category: state.category,
      dateRange: state.dateRange,
      sort: state.sort,
    })
      .then((answer) => !cancelled && setAiAnswer(answer ?? null))
      .catch(() => !cancelled && setAiAnswer(null))
      .finally(() => !cancelled && setAiLoading(false));

    return () => {
      cancelled = true;
    };
  }, [state.category, state.dateRange, state.query, state.sort, isHome]);

  function submitSearch(event) {
    event.preventDefault();
    state.setPage(1);
  }

  function withLiveRail(content, className = '') {
    const railState = {
      ...state,
      setQuery: (query) => {
        window.location.href = searchUrl(query);
      },
      setCategory: (category) => {
        window.location.href = searchUrl('', category && category !== 'All News' ? { category } : {});
      },
      setPage: () => {},
    };
    return <SitewideLiveLayout state={state} liveState={railState} className={className}>{content}</SitewideLiveLayout>;
  }

  const featuredFootballMatches = Array.isArray(window.RIFNOTE_SEARCH?.featuredFootballMatches) ? window.RIFNOTE_SEARCH.featuredFootballMatches : [];
  const activeFeaturedFootballMatches = featuredFootballMatches.filter((fixture) => fixture && !isFootballFixtureFinished(fixture));
  const isElectionTakeoverActive = Boolean(window.RIFNOTE_SEARCH?.electionTakeover?.enabled);
  const hasFeaturedFootballTakeover = activeFeaturedFootballMatches.length > 0;
  const hasAdminHomepageMedia = Boolean(window.RIFNOTE_SEARCH?.homeSearchMediaUrl) && !isElectionTakeoverActive && !hasFeaturedFootballTakeover;
  const hasHomeSearchMedia = Boolean(window.RIFNOTE_SEARCH?.homeSearchMediaUrl || isElectionTakeoverActive || hasFeaturedFootballTakeover);
  const showMobileTakeoverLogo = hasHomeSearchMedia && !hasAdminHomepageMedia;

  useEffect(() => {
    const header = document.querySelector('.rs-plugin-header');

    if (!header) {
      return;
    }

    const isSearchHome = mode === 'app' && isHome;
    const hasMobileTakeoverHeader = isSearchHome && showMobileTakeoverLogo;
    const logoSize = Math.max(28, Number(window.RIFNOTE_SEARCH?.homeTakeoverLogoSizeMobile || 40));

    header.classList.toggle('is-search-home', isSearchHome);
    header.classList.toggle('has-mobile-home-takeover', hasMobileTakeoverHeader);

    if (hasMobileTakeoverHeader) {
      header.style.setProperty('--rs-mobile-home-logo-size', `${logoSize}px`);
    } else {
      header.style.removeProperty('--rs-mobile-home-logo-size');
    }
  }, [isHome, mode, showMobileTakeoverLogo]);

  if (mode === 'search-bar') {
    return <SearchPanel state={state} onSubmit={submitSearch} compact />;
  }

  if (mode === 'trending') {
    return <TrendingTopics state={state} />;
  }

  if (mode === 'live-scores') {
    return <LiveScores />;
  }

  if (mode === 'football-hub') {
    return <FootballHub />;
  }

  if (mode === 'football-competitions') {
    return withLiveRail(<FootballCompetitionsPage />);
  }

  if (mode === 'team-search') {
    return withLiveRail(<FootballTeamsDirectory />);
  }

  if (mode === 'player-search') {
    return withLiveRail(<FootballPlayersDirectory />);
  }

  if (mode === 'transfer-tracker') {
    return withLiveRail(<TransferNewsPage />);
  }

  if (mode === 'weather') {
    return withLiveRail(<WeatherPage />);
  }

  if (mode === 'contact') {
    return withLiveRail(<ContactPage />);
  }

  if (mode === 'ai-answer') {
    return withLiveRail((
      <main className="rs-shell compact-page">
        <section className="rs-page-head">
          <div>
            <Badge tone="danger">Quick take</Badge>
            <h1>Ask Rifnote what the story is.</h1>
            <p>Drop a topic and we’ll pull together the clearest take from the sources we can trust.</p>
          </div>
          <SearchPanel state={state} onSubmit={submitSearch} />
        </section>
        {aiLoading ? <AiLoading /> : aiAnswer ? (aiAnswer.available ? <AiAnswer answer={aiAnswer} /> : <AiUnavailable answer={aiAnswer} />) : <Card><CardHeader title="Search something first" action={<Badge>AI</Badge>} /><p>Try a team, player, person, or headline. If the sources are solid, Rifnote gives you the gist.</p></Card>}
      </main>
    ));
  }

  if (mode === 'publisher-signup') {
    return withLiveRail(<PublisherSignupPanel />);
  }

  if (mode === 'publisher-submit') {
    return withLiveRail(<SubmitNewsPanel />);
  }

  if (mode === 'publisher-docs') {
    return withLiveRail(<PublisherDocsPage />);
  }

  if (mode === 'publisher-dashboard') {
    return withLiveRail(<PublisherDashboard />);
  }

  if (['legal-request', 'legal-dmca', 'legal-opt-out'].includes(mode)) {
    return withLiveRail(<LegalRequestPanel mode={mode} />);
  }

  if (mode === 'beta-feedback') {
    return withLiveRail(<BetaFeedbackPanel />);
  }

  if (mode === 'story-cluster') {
    return <StoryClusterPage />;
  }

  if (mode === 'source-profile') {
    return withLiveRail(<SourceProfilePage />);
  }

  if (mode === 'daily-briefing') {
    return withLiveRail(<DailyBriefingPage />);
  }

  if (mode === 'for-you') {
    return withLiveRail(<ForYouPage />);
  }

  if (mode === 'newsletter-signup') {
    return withLiveRail(<NewsletterSignup />);
  }

  if (mode === 'advertiser-signup') {
    return withLiveRail(<AdvertiserSignupPanel />);
  }

  if (mode === 'sponsor-request') {
    return withLiveRail(<SponsorRequestPanel />);
  }

  if (mode === 'advertiser-dashboard') {
    return withLiveRail(<AdvertiserDashboard />);
  }

  if (mode === 'widget-trending') {
    return <TrendingWidget />;
  }

  if (mode === 'sitewide-live') {
    return withLiveRail(<span className="rs-public-live-placeholder" aria-hidden="true" />);
  }

  return (
    <main className="rs-shell rs-search-page">
      {isHome ? (
        <section className={`rs-google-home ${hasHomeSearchMedia ? 'has-home-media' : ''}`}>
          {hasHomeSearchMedia ? (
            <HomeSearchMedia primary featuredFootballMatches={activeFeaturedFootballMatches} />
          ) : (
            <div className="rs-orbit-logo" aria-label="Rifnote Search">
              <h1 className="rs-google-logo">
                <strong>Rifnote</strong>
              </h1>
              {[
                ['news', <Newspaper size={20} />],
                ['ball', <Trophy size={20} />],
                ['map', <MapIcon size={20} />],
                ['goal', <Goal size={20} />],
                ['world', <Globe2 size={20} />],
                ['live', <Radio size={20} />],
                ['policy', <Landmark size={20} />],
              ].map(([key, icon]) => (
                <span className={`rs-orbit-icon ${key}`} aria-hidden="true" key={key}>{icon}</span>
              ))}
            </div>
          )}
          <SearchPanel state={state} onSubmit={submitSearch} compact="home" />
          <HomeQuickLinks activePill={homePill} items={homepagePills} onSelect={updateHomePill} showCategories={Boolean(siteCategories.length)} categoriesActive={showHomeCategories} onCategoriesToggle={toggleHomeCategories} />
        </section>
      ) : null}

      <section className={isHome ? 'rs-home-grid' : 'rs-search-results-layout'}>
        <div className="rs-main">
          {isHome ? (
            <>
              {showHomeCategories ? (
                <HomeCategoryBrowser categories={siteCategories} />
              ) : (
                <HomeHighlights activePill={activeHomePill.label} activeCategory={activeHomePill.category} archiveUrl={homeNotesArchiveUrl} leadStory={homeLeadStory} notes={homeNotes} loading={homeNotes === null} />
              )}
            </>
          ) : (
            <>
              <h1 className="rs-sr-only">Search results for {state.query || state.category}</h1>
              <div className="rs-google-tabs">
                {visibleSearchTabs.map((tab) => <button className={activeTab === tab ? 'active' : ''} key={tab} type="button" onClick={() => setActiveTab(tab)}>{tab}</button>)}
              </div>
              <div className="rs-google-summary">
                <span>{searchTabCount ? `${searchTabCount} ${searchTabLabel}${searchTabCount === 1 ? '' : 's'} found` : 'Nothing solid yet'}</span>
                <span>{state.category !== 'All News' ? state.category : 'All categories'} · {state.sort} · {state.dateRange === 'all' ? 'Any time' : state.dateRange}</span>
              </div>
              {error ? <Card><CardHeader title="Search hit a snag" action={<Badge tone="danger">REST</Badge>} /><p>{error}</p></Card> : null}
              {activeTab === 'All' ? (
                <>
                  {showAiCards ? (aiLoading ? <AiLoading /> : aiAnswer ? (aiAnswer.available ? <AiAnswer answer={aiAnswer} /> : <AiUnavailable answer={aiAnswer} />) : null) : null}
                  {footballTabAvailable ? <CompactFootballSearchResults payload={footballResults} onOpenFootball={() => setActiveTab('Football')} /> : null}
                  <CompactMediaSearchSnippets socialResults={socialResults} query={state.query} onOpenSocial={() => setActiveTab('Social')} />
                  <SponsoredPlacements placements={sponsored} query={state.query} />
                  {loading ? <LoadingGrid /> : <CompactResultList results={results} query={state.query} state={state} insights={noResultInsights} />}
                  <Pagination pagination={pagination} onPageChange={state.setPage} />
                </>
              ) : null}
              {activeTab === 'Latest' ? (
                <>
                  {loading ? <LoadingGrid /> : <ResultList results={latestResults} query={state.query} state={state} insights={noResultInsights} />}
                  <Pagination pagination={pagination} onPageChange={state.setPage} />
                </>
              ) : null}
              {activeTab === 'Sources' ? (loading ? <LoadingGrid /> : <SourceResultsPanel results={results} query={state.query} />) : null}
              {activeTab === 'Videos' ? (loading ? <LoadingGrid /> : <VideoResultsPanel results={videoResults} query={state.query} state={state} />) : null}
              {activeTab === 'Social' ? (loading ? <LoadingGrid /> : <SocialResultsPanel results={socialResults} query={state.query} state={state} />) : null}
              {activeTab === 'Football' && footballTabAvailable ? <FootballSearchResults payload={footballResults} /> : null}
            </>
          )}
        </div>
        <LiveRail state={state} open={liveRailOpen} onClose={() => setLiveRailOpen(false)} />
      </section>
      <button className={`rs-live-drawer-backdrop ${liveRailOpen ? 'open' : ''}`} type="button" aria-label="Close live updates" onClick={() => setLiveRailOpen(false)} />
      <BottomNav state={state} onLiveOpen={() => setLiveRailOpen(true)} />
    </main>
  );
}

function SponsoredPlacements({ placements = [], query = '' }) {
  useEffect(() => {
    placements.forEach((placement) => {
      trackAnalyticsEvent({
        event_type: 'ad_impression',
        target_url: placement.target_url,
        query_text: query,
        metadata: {
          sponsor_name: placement.sponsor_name,
          placement_id: placement.id,
          placement: placement.placement || 'search',
        },
      });
    });
  }, [placements, query]);

  if (!placements.length) {
    return null;
  }

  return (
    <section className="rs-sponsored" aria-label="Sponsored placements">
      {placements.map((placement) => (
        <a
          href={placement.target_url}
          key={placement.id}
          target="_blank"
          rel="nofollow sponsored noreferrer"
          onClick={() => {
            trackSponsoredClick(placement.id).catch(() => {});
            trackAnalyticsEvent({ event_type: 'sponsored_click', target_url: placement.target_url, query_text: query, metadata: { sponsor_name: placement.sponsor_name, placement_id: placement.id, placement: placement.placement || 'search' } });
          }}
        >
          <span>{placement.label || 'Sponsored'}</span>
          <strong>{placement.title}</strong>
          <small>{placement.sponsor_name}</small>
        </a>
      ))}
    </section>
  );
}

function SitewideLiveLayout({ children, state, liveState = state, className = '' }) {
  const [liveRailOpen, setLiveRailOpen] = useState(false);

  useEffect(() => {
    const openLive = () => setLiveRailOpen(true);
    document.addEventListener('rifnote:open-live', openLive);
    return () => document.removeEventListener('rifnote:open-live', openLive);
  }, []);

  return (
    <main className={`rs-shell rs-sitewide-live-page ${className ? `is-${className}` : ''}`}>
      <section className="rs-sitewide-live-layout">
        <div className="rs-sitewide-live-main">
          {children}
        </div>
        <LiveRail state={liveState} open={liveRailOpen} onClose={() => setLiveRailOpen(false)} />
      </section>
      <button className={`rs-live-drawer-backdrop ${liveRailOpen ? 'open' : ''}`} type="button" aria-label="Close live updates" onClick={() => setLiveRailOpen(false)} />
      <BottomNav state={state} onLiveOpen={() => setLiveRailOpen(true)} />
    </main>
  );
}

function CompactMediaSearchSnippets({ socialResults = [], query = '', onOpenSocial = () => {} }) {
  const socialItems = socialResults.slice(0, 2);

  if (!socialItems.length) {
    return null;
  }

  return (
    <section className="rs-media-search-snippets" aria-label="Media and social results">
      {socialItems.length ? (
        <article className="rs-media-snippet is-social">
          <div>
            <Badge>Social</Badge>
            <h3>Social posts around this search</h3>
            <div className="rs-social-snippet-list">
              {socialItems.map((story) => (
                <a href={story.read_full_story_url || story.original_url || '#'} key={`social-snippet-${story.id}`} target="_blank" rel="noreferrer" onClick={() => trackStoryClick(story, 'social_all_snippet_click', query)}>
                  <SourceLogo story={story} size="small" />
                  <span>
                    <b>{decodeText(story.headline)}</b>
                    <small>{getStorySocialPlatform(story) || decodeText(story.source_name || story.source_domain || 'Social')} · {story.published_at_human || formatDate(story.published_at)}</small>
                  </span>
                </a>
              ))}
            </div>
          </div>
          <button className="rs-link-button" type="button" onClick={onOpenSocial}>Open Social tab</button>
        </article>
      ) : null}
    </section>
  );
}

function CompactFootballSearchResults({ payload, onOpenFootball = () => {} }) {
  const matches = payload?.matches ?? [];
  const teams = payload?.teams ?? [];
  const competitions = payload?.competitions ?? [];
  const total = footballSearchResultCount(payload);

  if (!payload?.query || !total) {
    return null;
  }

  return (
    <Card className="rs-football-compact-card">
      <CardHeader title="Football matches this search" action={<button className="rs-link-button" type="button" onClick={onOpenFootball}>Open Football tab</button>} />
      <div className="rs-football-compact-grid">
        {matches.slice(0, 2).map((fixture) => <FootballSearchMatch fixture={fixture} key={`compact-${fixture.id || `${fixture.home?.name}-${fixture.away?.name}-${fixture.date}`}`} />)}
        {!matches.length ? teams.slice(0, 4).map((team) => (
          <button className="rs-football-entity-chip" key={`compact-team-${team.id || team.name}`} type="button" onClick={onOpenFootball}>
            {team.logo ? <img src={team.logo} alt="" loading="lazy" /> : <Shield size={16} />}
            <span>{team.name}</span>
            <small>{team.matches} match{team.matches === 1 ? '' : 'es'}</small>
          </button>
        )) : null}
        {!matches.length && !teams.length ? competitions.slice(0, 3).map((competition) => (
          <button className="rs-football-entity-chip" key={`compact-comp-${competition.id || competition.name}`} type="button" onClick={onOpenFootball}>
            {competition.logo ? <img src={competition.logo} alt="" loading="lazy" /> : <Trophy size={16} />}
            <span>{competition.name}</span>
            <small>{competition.season || competition.country}</small>
          </button>
        )) : null}
      </div>
    </Card>
  );
}

function FootballSearchResults({ payload }) {
  const matches = payload?.matches ?? [];
  const teams = payload?.teams ?? [];
  const competitions = payload?.competitions ?? [];
  const players = payload?.players ?? [];
  const stats = payload?.stats ?? [];
  const total = matches.length + teams.length + competitions.length + players.length + stats.length;

  if (!payload?.query || !total) {
    return null;
  }

  return (
    <Card className="rs-football-search-card">
      <CardHeader title="Football results" action={<Badge>{payload.source || 'saved'}</Badge>} />
      {matches.length ? (
        <div className="rs-football-search-section">
          <h3>Matches</h3>
          <div className="rs-football-search-grid">
            {matches.slice(0, 4).map((fixture) => <FootballSearchMatch fixture={fixture} key={fixture.id || `${fixture.home?.name}-${fixture.away?.name}-${fixture.date}`} />)}
          </div>
        </div>
      ) : null}
      {teams.length || competitions.length ? (
        <div className="rs-football-search-section compact">
          {teams.slice(0, 6).map((team) => (
            <a className="rs-football-entity-chip" href={searchUrl(team.name)} key={`team-${team.id || team.name}`}>
              {team.logo ? <img src={team.logo} alt="" loading="lazy" /> : <Shield size={16} />}
              <span>{team.name}</span>
              <small>{team.matches} match{team.matches === 1 ? '' : 'es'}</small>
            </a>
          ))}
          {competitions.slice(0, 4).map((competition) => (
            <a className="rs-football-entity-chip" href={searchUrl(competition.name)} key={`competition-${competition.id || competition.name}`}>
              {competition.logo ? <img src={competition.logo} alt="" loading="lazy" /> : <Trophy size={16} />}
              <span>{competition.name}</span>
              <small>{competition.season || competition.country}</small>
            </a>
          ))}
        </div>
      ) : null}
      {players.length ? (
        <div className="rs-football-search-section">
          <h3>Players</h3>
          <div className="rs-football-player-list">
            {players.slice(0, 5).map((player) => (
              <article key={`player-${player.id || player.name}-${player.team}`}>
                {player.team_logo ? <img src={player.team_logo} alt="" loading="lazy" /> : <UserRound size={18} />}
                <div>
                  <strong>{player.name}</strong>
                  <span>{[player.team, player.context].filter(Boolean).join(' · ')}</span>
                </div>
              </article>
            ))}
          </div>
        </div>
      ) : null}
      {stats.length ? (
        <div className="rs-football-search-section">
          <h3>Stats</h3>
          <div className="rs-football-stat-list">
            {stats.slice(0, 3).map((item) => (
              <article key={`stat-${item.team}-${item.fixture?.id}`}>
                {item.team_logo ? <img src={item.team_logo} alt="" loading="lazy" /> : <TrendingUp size={18} />}
                <div>
                  <strong>{item.team}</strong>
                  <span>{item.statistics.slice(0, 3).map((stat) => `${stat.type}: ${stat.value}`).join(' · ')}</span>
                </div>
              </article>
            ))}
          </div>
        </div>
      ) : null}
    </Card>
  );
}

function FootballSearchMatch({ fixture }) {
  const status = fixture.status_short || '';
  const isUpcoming = status === 'NS';
  const homeGoals = fixture.goals?.home ?? '-';
  const awayGoals = fixture.goals?.away ?? '-';
  const leagueName = getFootballCompetitionLabel(fixture, { includeRound: false });
  const fixtureDate = formatDate(fixture.date);
  const fixtureTime = formatTime(fixture.date);
  const matchState = isUpcoming ? (formatCountdown(fixture.date) || 'Upcoming') : (fixture.status_long || status || 'Match');

  return (
    <a className="rs-football-search-match" href={appPageUrl('football')}>
      <span className="rs-football-search-league">{fixture.league?.logo ? <img src={fixture.league.logo} alt="" loading="lazy" /> : <Trophy size={15} />}{leagueName}</span>
      <div>
        <FootballSearchTeam team={fixture.home} />
        <strong>{isUpcoming ? 'vs' : `${homeGoals} - ${awayGoals}`}</strong>
        <FootballSearchTeam team={fixture.away} align="right" />
      </div>
      <small className="rs-football-search-time">
        <span>{fixtureDate}</span>
        <span>{fixtureTime}</span>
        <span>{matchState}</span>
      </small>
      <MatchMarkers fixture={fixture} compact />
      <AggregateChip fixture={fixture} compact />
    </a>
  );
}

function FootballSearchTeam({ team = {}, align = '' }) {
  return (
    <span className={`rs-football-search-team ${align}`}>
      {team.logo ? <img src={team.logo} alt="" loading="lazy" /> : null}
      <b title={team.name || 'TBD'}>{shortTeamName(team.name || 'TBD')}</b>
    </span>
  );
}

function SourceResultsPanel({ results = [], query = '' }) {
  const sources = useMemo(() => {
    const grouped = new Map();

    results.forEach((story) => {
      const name = decodeText(story.source_name || story.source_domain || 'Unknown source');
      const domain = decodeText(story.source_domain || '');
      const key = domain || name;
      const current = grouped.get(key) || {
        key,
        name,
        domain,
        logoUrl: story.source_logo_url || '',
        initials: story.source_initials || name.slice(0, 2).toUpperCase(),
        url: story.source_profile_url || story.source_url || story.original_url || story.read_full_story_url,
        latest: storyTimeValue(story),
        stories: [],
      };

      if (!current.logoUrl && story.source_logo_url) {
        current.logoUrl = story.source_logo_url;
      }

      current.latest = Math.max(current.latest, storyTimeValue(story));
      current.stories.push(story);
      grouped.set(key, current);
    });

    return [...grouped.values()].sort((first, second) => second.stories.length - first.stories.length || second.latest - first.latest);
  }, [results]);

  if (!sources.length) {
    return (
      <Card className="rs-tab-empty">
        <CardHeader title="No sources matched this yet" action={<Badge>Sources</Badge>} />
        <p>Try a wider keyword or clear a filter. Once sources show up, this tab groups the coverage by publisher so you can see who is carrying the story.</p>
      </Card>
    );
  }

  return (
    <section className="rs-source-results">
      {sources.map((source) => (
        <Card className="rs-source-result-card" key={source.key}>
          <div className="rs-source-result-head">
            <div>
              <SourceLogo story={{ source_name: source.name, source_domain: source.domain, source_logo_url: source.logoUrl, source_initials: source.initials }} size="large" />
              <div>
                <h2>{source.name}</h2>
                <p>{source.domain || 'Publisher source'} · {source.stories.length} stor{source.stories.length === 1 ? 'y' : 'ies'}</p>
              </div>
            </div>
            {source.url ? <a className="rs-button ghost" href={source.url} target="_blank" rel="noreferrer">Open source <ExternalLink size={14} /></a> : null}
          </div>
          <div className="rs-source-story-list">
            {source.stories.slice(0, 4).map((story) => (
              <a href={story.story_url || story.read_full_story_url || story.original_url} key={`${source.key}-${story.id}`} onClick={() => trackStoryClick(story, 'source_tab_story_click', query)}>
                <strong>{decodeText(story.headline)}</strong>
                <span>{story.published_at_human || formatDate(story.published_at)}</span>
              </a>
            ))}
          </div>
        </Card>
      ))}
    </section>
  );
}

function VideoResultsPanel({ results = [], query = '', state }) {
  if (!results.length) {
    return (
      <Card className="rs-tab-empty rs-video-empty">
        <CardHeader title="No video clips in this search" action={<Badge>Videos</Badge>} />
        <p>We checked the saved story metadata and didn’t find video-backed coverage for this query yet. Try names, clubs, events, or switch back to Top for written coverage.</p>
        <div className="rs-pills">
          {['Football', 'Politics', 'Entertainment', 'World'].map((item) => (
            <button key={item} type="button" onClick={() => { state?.setCategory(item); state?.setPage(1); }}>{item}</button>
          ))}
        </div>
      </Card>
    );
  }

  return (
    <section className="rs-video-results">
      {results.map((story) => (
        <VideoStoryCard story={story} query={query} key={`video-${story.id}`} />
      ))}
    </section>
  );
}

function SocialResultsPanel({ results = [], query = '', state }) {
  if (!results.length) {
    return (
      <Card className="rs-tab-empty rs-social-empty">
        <CardHeader title="No social posts in this search yet" action={<Badge>Social</Badge>} />
        <p>When tweets, Threads, Reddit posts, TikToks, Instagram posts, or Facebook links are imported, they’ll collect here as a separate pulse around the story.</p>
        <div className="rs-pills">
          {['Nigeria', 'Football', 'Politics', 'Entertainment'].map((item) => (
            <button key={item} type="button" onClick={() => { state?.setCategory(item); state?.setPage(1); }}>{item}</button>
          ))}
        </div>
      </Card>
    );
  }

  return (
    <section className="rs-social-results">
      {results.map((story) => (
        <SocialStoryCard story={story} query={query} key={`social-${story.id}`} />
      ))}
    </section>
  );
}

function SocialStoryCard({ story, query = '' }) {
  const platform = getStorySocialPlatform(story) || 'Social';
  const handle = story.social_author_handle || story.author_handle || story.author_name || '';
  const metrics = story.social_metrics && typeof story.social_metrics === 'object' ? story.social_metrics : {};
  const metricEntries = Object.entries(metrics).filter(([, value]) => value !== '' && value !== null && value !== undefined).slice(0, 3);
  const storyUrl = story.read_full_story_url || story.original_url || story.story_url || '#';
  const embedHtml = useResolvedStoryEmbed(story);

  return (
    <article className="rs-social-result-card">
      <AdminStoryActions story={story} compact />
      <div className="rs-social-result-head">
        <SourceLogo story={story} size="small" />
        <span>{platform}</span>
        {handle ? <small>{decodeText(handle)}</small> : null}
        <small>{story.published_at_human || formatDate(story.published_at)}</small>
      </div>
      <h2><a href={storyUrl} target="_blank" rel="noreferrer" onClick={() => trackStoryClick(story, 'social_result_click', query)}>{decodeText(story.headline)}</a></h2>
      {showStoryExcerpts ? <p>{trimWords(story.excerpt || story.summary, 23)}</p> : null}
      {embedHtml ? <SmartEmbedHtml html={embedHtml} className="rs-social-result-embed" /> : null}
      <footer>
        {metricEntries.length ? (
          <div className="rs-social-metrics">
            {metricEntries.map(([key, value]) => <span key={`${story.id}-${key}`}>{decodeText(key)}: {value}</span>)}
          </div>
        ) : <span>{decodeText(story.source_name || story.source_domain || 'Social source')}</span>}
        <a href={storyUrl} target="_blank" rel="noreferrer" onClick={() => trackStoryClick(story, 'social_source_click', query)}>Open post <ExternalLink size={14} /></a>
      </footer>
    </article>
  );
}

function SmartEmbedHtml({ html = '', className = '' }) {
  const embedRef = useRef(null);

  useEffect(() => {
    const node = embedRef.current;
    if (!node || !html) return;

    if (node.querySelector('.twitter-tweet, blockquote.twitter-tweet')) {
      const hydrateTweets = () => window.requestAnimationFrame(() => window.twttr?.widgets?.load?.(node));
      if (window.twttr?.widgets?.load) {
        hydrateTweets();
      } else {
        loadExternalScript('https://platform.twitter.com/widgets.js', 'rifnote-twitter-widgets', hydrateTweets);
      }
    }

    if (node.querySelector('.instagram-media')) {
      loadExternalScript('https://www.instagram.com/embed.js', 'rifnote-instagram-embed', () => window.instgrm?.Embeds?.process?.());
    }

    if (node.querySelector('.fb-post, .fb-video')) {
      loadExternalScript('https://connect.facebook.net/en_US/sdk.js#xfbml=1&version=v20.0', 'rifnote-facebook-sdk', () => window.FB?.XFBML?.parse?.(node));
    }

    if (node.querySelector('.tiktok-embed')) {
      loadExternalScript('https://www.tiktok.com/embed.js', 'rifnote-tiktok-embed', () => window.tiktokEmbedLoad?.());
    }
  }, [html]);

  if (!html) {
    return null;
  }

  return <div ref={embedRef} className={`rs-smart-embed-html ${className}`} dangerouslySetInnerHTML={{ __html: html }} />;
}

function LiveRail({ state, open = false, onClose = () => {} }) {
  return (
    <aside className={`rs-live-rail ${open ? 'open' : ''}`} aria-label="Live updates">
      <div className="rs-live-drawer-head">
        <strong>Live updates</strong>
        <button type="button" onClick={onClose} aria-label="Close live updates">×</button>
      </div>
      <LiveScores live />
      <TrendingTopics state={state} live />
      <SignalCard title="Markets" icon={<DollarSign size={18} />} live type="market" />
      <SignalCard title="Weather" icon={<CloudSun size={18} />} live type="weather" />
    </aside>
  );
}

function useLiveInterval(callback, delay = 30000, enabled = true) {
  useEffect(() => {
    if (!enabled) {
      return undefined;
    }

    let cancelled = false;

    function run() {
      if (!cancelled) {
        callback();
      }
    }

    run();
    const timer = window.setInterval(run, delay);

    return () => {
      cancelled = true;
      window.clearInterval(timer);
    };
  }, [callback, delay, enabled]);
}

function useCarouselIndex(items = [], delay = 15000) {
  const count = Array.isArray(items) ? items.length : 0;
  const [activeIndex, setActiveIndex] = useState(0);

  useEffect(() => {
    setActiveIndex(0);
  }, [count]);

  useEffect(() => {
    if (count <= 1) {
      return undefined;
    }

    const timer = window.setInterval(() => {
      setActiveIndex((index) => (index + 1) % count);
    }, delay);

    return () => window.clearInterval(timer);
  }, [count, delay]);

  return [activeIndex, setActiveIndex];
}

function BetaFeedbackPanel() {
  const params = new URLSearchParams(window.location.search);
  const [form, setForm] = useState({
    feedback_type: params.get('type') || 'general',
    rating: '5',
    requester_email: '',
    message: '',
    context_url: params.get('context') || window.location.href,
    query_text: params.get('q') || '',
  });
  const [status, setStatus] = useState({ loading: false, message: '', error: '' });

  function updateField(field, value) {
    setForm((current) => ({ ...current, [field]: value }));
  }

  async function submitForm(event) {
    event.preventDefault();
    setStatus({ loading: true, message: '', error: '' });

    try {
      const response = await submitBetaFeedback(form);
      setStatus({ loading: false, message: response.message || 'Got it. Thanks for helping us sharpen Rifnote.', error: '' });
      setForm((current) => ({ ...current, message: '' }));
    } catch (error) {
      setStatus({ loading: false, message: '', error: error.message });
    }
  }

  return (
    <main className="rs-shell compact-page">
      <section className="rs-page-head rs-beta-head">
        <div>
          <Badge tone="danger">Public beta</Badge>
          <h1>Help us make Rifnote feel right.</h1>
          <p>Tell us what slapped, what dragged, what confused you, or what needs to move faster. We actually read this.</p>
        </div>
        <Card className="rs-legal-note">
          <CardHeader title="What we are tuning" action={<Trophy size={18} />} />
          <ul>
            <li>Better results, less noise.</li>
            <li>Mobile that feels smooth.</li>
            <li>Sources people can trust.</li>
          </ul>
        </Card>
      </section>
      <Card>
        <CardHeader title="Tell us straight" action={<Badge>{form.feedback_type}</Badge>} />
        <form className="rs-submit-form" onSubmit={submitForm}>
          <label>Feedback type<select value={form.feedback_type} onChange={(event) => updateField('feedback_type', event.target.value)}><option value="general">General</option><option value="bug">Bug</option><option value="ranking">Ranking</option><option value="publisher">Publisher</option><option value="mobile">Mobile</option><option value="ai">AI answer</option></select></label>
          <label>Rating<select value={form.rating} onChange={(event) => updateField('rating', event.target.value)}><option value="5">5 - Excellent</option><option value="4">4 - Good</option><option value="3">3 - Needs work</option><option value="2">2 - Frustrating</option><option value="1">1 - Broken</option></select></label>
          <label>Email optional<input type="email" value={form.requester_email} onChange={(event) => updateField('requester_email', event.target.value)} placeholder="you@example.com" /></label>
          <label>Related query optional<input value={form.query_text} onChange={(event) => updateField('query_text', event.target.value)} placeholder="Osimhen transfer" /></label>
          <label>Context URL<input type="url" value={form.context_url} onChange={(event) => updateField('context_url', event.target.value)} /></label>
          <label>Feedback<textarea required rows="6" value={form.message} onChange={(event) => updateField('message', event.target.value)} placeholder="What felt off? What should hit better? Drop the honest version." /></label>
          {status.error ? <p className="rs-form-error">{status.error}</p> : null}
          {status.message ? <p className="rs-form-success">{status.message}</p> : null}
          <button className="rs-button primary" type="submit" disabled={status.loading}>{status.loading ? 'Sending...' : 'Send it'}</button>
        </form>
      </Card>
    </main>
  );
}

function LegalRequestPanel({ mode = 'legal-request' }) {
  const isOptOut = mode === 'legal-opt-out';
  const isDmca = mode === 'legal-dmca';
  const [form, setForm] = useState({
    request_type: isOptOut ? 'opt_out' : 'dmca',
    requester_name: '',
    requester_email: '',
    organization: '',
    domain: '',
    url: '',
    details: '',
    confirmed: false,
  });
  const [status, setStatus] = useState({ loading: false, message: '', error: '' });

  function updateField(field, value) {
    setForm((current) => ({ ...current, [field]: value }));
  }

  async function submitForm(event) {
    event.preventDefault();
    setStatus({ loading: true, message: '', error: '' });

    try {
      const response = await submitLegalRequest(form);
      setStatus({ loading: false, message: response.message || 'We got your request and will review it.', error: '' });
    } catch (error) {
      setStatus({ loading: false, message: '', error: error.message });
    }
  }

  const title = isOptOut ? 'Want your source removed?' : isDmca ? 'Report a copyright issue.' : 'Send us a legal note.';
  const copy = isOptOut
    ? 'Tell us the domain or feed you control and we’ll review the opt-out.'
    : 'Flag a result, source link, or publisher feed entry that needs a closer look.';

  return (
    <main className="rs-shell compact-page">
      <section className="rs-page-head rs-legal-head">
        <div>
          <Badge tone="danger">Rights and safety</Badge>
          <h1>{title}</h1>
          <p>{copy} Rifnote shows snippets, keeps sources visible, and sends readers back to the original publishers.</p>
        </div>
        <Card className="rs-legal-note">
          <CardHeader title="How we keep it fair" action={<Shield size={18} />} />
          <ul>
            <li>Publisher names stay visible.</li>
            <li>No full article copying in public submissions.</li>
            <li>Blocked domains and robots rules are respected.</li>
          </ul>
        </Card>
      </section>
      <Card>
        <CardHeader title={isOptOut ? 'Opt-out details' : 'Request details'} action={<Badge>{form.request_type.replace('_', ' ')}</Badge>} />
        <form className="rs-submit-form" onSubmit={submitForm}>
          {!isOptOut && !isDmca ? (
            <label>Request type<select value={form.request_type} onChange={(event) => updateField('request_type', event.target.value)}><option value="dmca">DMCA removal</option><option value="opt_out">Publisher opt-out</option><option value="correction">Correction</option><option value="other">Other</option></select></label>
          ) : null}
          <label>Your name<input required value={form.requester_name} onChange={(event) => updateField('requester_name', event.target.value)} placeholder="Full name" /></label>
          <label>Email<input required type="email" value={form.requester_email} onChange={(event) => updateField('requester_email', event.target.value)} placeholder="you@example.com" /></label>
          <label>Organization<input value={form.organization} onChange={(event) => updateField('organization', event.target.value)} placeholder="Publisher, company or rights holder" /></label>
          <label>Domain<input required={isOptOut} value={form.domain} onChange={(event) => updateField('domain', event.target.value)} placeholder="publisher.com" /></label>
          <label>Rifnote result or source URL<input required={isDmca} type="url" value={form.url} onChange={(event) => updateField('url', event.target.value)} placeholder="https://publisher.com/story or Rifnote result URL" /></label>
          <label>Request details<textarea required rows="6" value={form.details} onChange={(event) => updateField('details', event.target.value)} placeholder={isOptOut ? 'Confirm the domain/feed you control and the opt-out scope.' : 'Describe the copyrighted work, the URL to review, and the action requested.'} /></label>
          <label className="rs-check"><input required type="checkbox" checked={form.confirmed} onChange={(event) => updateField('confirmed', event.target.checked)} /> I confirm this request is accurate and I am authorized to submit it.</label>
          {status.error ? <p className="rs-form-error">{status.error}</p> : null}
          {status.message ? <p className="rs-form-success">{status.message}</p> : null}
          <button className="rs-button primary" type="submit" disabled={status.loading}>{status.loading ? 'Sending...' : 'Send request'}</button>
        </form>
      </Card>
    </main>
  );
}

function ContactPage() {
  return (
    <main className="rs-shell compact-page rs-contact-page">
      <section className="rs-contact-hero">
        <Badge tone="danger">Contact</Badge>
        <h1>Reach the Rifnote desk.</h1>
        <p>For publisher support, advert help, story tips, partnerships, and platform questions.</p>
      </section>

      <section className="rs-contact-grid" aria-label="Rifnote contact details">
        <article>
          <span><MapIcon size={22} /></span>
          <small>Office address</small>
          <strong>3 Mercy Seat Close, Beckley Estate Lagos.</strong>
        </article>
        <article>
          <span><Newspaper size={22} /></span>
          <small>Stories and publishers</small>
          <strong>Send stories, corrections, and publisher requests through Rifnote’s publisher desk.</strong>
          <a href={appPageUrl('submit-news')}>Send a Story</a>
        </article>
        <article>
          <span><TrendingUp size={22} /></span>
          <small>Advertisers</small>
          <strong>Build campaigns for search, stories, football, live updates, and high-intent audiences.</strong>
          <a href={appPageUrl('advertise')}>Build Campaign</a>
        </article>
      </section>
    </main>
  );
}

function SubmitNewsPanel() {
  const [form, setForm] = useState({
    publisher_name: '',
    website_url: '',
    contact_email: '',
    headline: '',
    original_url: '',
    excerpt: '',
    category: 'Football',
    tags: '',
    image_url: '',
    author: '',
    country: '',
    rss_feed_url: '',
    sitemap_url: '',
    rights_confirmed: false,
    permission_confirmed: false,
  });
  const [status, setStatus] = useState({ loading: false, message: '', error: '' });

  function updateField(field, value) {
    setForm((current) => ({ ...current, [field]: value }));
  }

  async function submitForm(event) {
    event.preventDefault();
    setStatus({ loading: true, message: '', error: '' });

    try {
      const response = await submitPublisherStory(form);
      setStatus({ loading: false, message: response.message || 'Story received. We’ll review it soon.', error: '' });
    } catch (error) {
      setStatus({ loading: false, message: '', error: error.message });
    }
  }

  return (
    <main className="rs-shell compact-page">
      <section className="rs-page-head">
        <div>
          <Badge tone="danger">Publisher lane</Badge>
          <h1>Put your story on Rifnote.</h1>
          <p>Send the headline, a tight excerpt, and the original link. We help readers discover it and then send them back to you.</p>
        </div>
      </section>
      <Card>
        <CardHeader title="Drop the story details" action={<Badge>Review</Badge>} />
        <form className="rs-submit-form" onSubmit={submitForm}>
          <label>Publisher name<input required value={form.publisher_name} onChange={(event) => updateField('publisher_name', event.target.value)} placeholder="Your publication" /></label>
          <label>Website URL<input required type="url" value={form.website_url} onChange={(event) => updateField('website_url', event.target.value)} placeholder="https://example.com" /></label>
          <label>Contact email<input required type="email" value={form.contact_email} onChange={(event) => updateField('contact_email', event.target.value)} placeholder="editor@example.com" /></label>
          <label>Country<input value={form.country} onChange={(event) => updateField('country', event.target.value)} placeholder="Nigeria" /></label>
          <label>Headline<input required value={form.headline} onChange={(event) => updateField('headline', event.target.value)} placeholder="Story headline" /></label>
          <label>Original story URL<input required type="url" value={form.original_url} onChange={(event) => updateField('original_url', event.target.value)} placeholder="https://publisher.com/story" /></label>
          <label>Short excerpt<textarea required rows="4" value={form.excerpt} onChange={(event) => updateField('excerpt', event.target.value)} placeholder="Keep it short. No full article paste." /></label>
          <label>Category<select value={form.category} onChange={(event) => updateField('category', event.target.value)}><option>Football</option><option>Politics</option><option>World</option><option>Business</option><option>Tech</option><option>Entertainment</option></select></label>
          <label>Tags<input value={form.tags} onChange={(event) => updateField('tags', event.target.value)} placeholder="World Cup, Messi" /></label>
          <MediaUploadField label="Story image" value={form.image_url} accept="image/*" note="JPG, PNG, GIF or WebP. We’ll attach it to the story review." onUploaded={(url) => updateField('image_url', url)} />
          <label>Author<input value={form.author} onChange={(event) => updateField('author', event.target.value)} placeholder="Reporter name" /></label>
          <label>RSS feed URL<input type="url" value={form.rss_feed_url} onChange={(event) => updateField('rss_feed_url', event.target.value)} placeholder="https://publisher.com/feed" /></label>
          <label>Sitemap URL<input type="url" value={form.sitemap_url} onChange={(event) => updateField('sitemap_url', event.target.value)} placeholder="https://publisher.com/sitemap.xml" /></label>
          <label className="rs-check"><input required type="checkbox" checked={form.rights_confirmed} onChange={(event) => updateField('rights_confirmed', event.target.checked)} /> I confirm I have rights to submit this headline, excerpt, image and source link.</label>
          <label className="rs-check"><input required type="checkbox" checked={form.permission_confirmed} onChange={(event) => updateField('permission_confirmed', event.target.checked)} /> I allow Rifnote to show metadata, snippets, and source links so people can find this story.</label>
          {status.error ? <p className="rs-form-error">{status.error}</p> : null}
          {status.message ? <p className="rs-form-success">{status.message}</p> : null}
          <button className="rs-button primary" type="submit" disabled={status.loading}>{status.loading ? 'Sending...' : 'Send for review'}</button>
        </form>
      </Card>
      <Card>
        <CardHeader title="Publisher API" action={<Badge>Platform</Badge>} />
        <div className="rs-api-docs">
          <p>Approved publishers can submit stories with an API key generated by Rifnote admin. Send the key as <code>X-Rifnote-API-Key</code> or a Bearer token.</p>
          <pre><code>{`POST /wp-json/rifnote/v1/publisher/api/submit
X-Rifnote-API-Key: rfs_your_key
Content-Type: application/json

{
  "headline": "Story headline",
  "original_url": "https://publisher.com/story",
  "excerpt": "Short summary or excerpt",
  "category": "Football",
  "tags": "Nigeria, Transfers",
  "image_url": "https://publisher.com/image.jpg",
  "author": "Reporter name",
  "published_at": "2026-07-08T12:00:00Z"
}`}</code></pre>
          <p>Register a webhook to receive signed publisher events. Rifnote signs the JSON body with <code>X-Rifnote-Signature: sha256=...</code>.</p>
          <pre><code>{`POST /wp-json/rifnote/v1/publisher/api/webhooks
X-Rifnote-API-Key: rfs_your_key
Content-Type: application/json

{
  "endpoint_url": "https://publisher.com/rifnote-webhook",
  "events": ["api_submit", "api_submit_failed"]
}`}</code></pre>
          <p>Recent API and webhook activity appears in the publisher dashboard and the Rifnote admin console.</p>
        </div>
      </Card>
    </main>
  );
}

function PublisherSignupPanel() {
  const [form, setForm] = useState({
    publisher_name: '',
    website_url: '',
    contact_email: '',
    country: 'Nigeria',
    categories: 'Politics, Football, Business',
    rss_feed_url: '',
    sitemap_url: '',
    logo_url: '',
  });
  const [status, setStatus] = useState({ loading: false, message: '', error: '', loginUrl: '', dashboardUrl: '' });

  function updateField(field, value) {
    setForm((current) => ({ ...current, [field]: value }));
  }

  async function submitForm(event) {
    event.preventDefault();
    setStatus({ loading: true, message: '', error: '', loginUrl: '', dashboardUrl: '' });

    try {
      const response = await submitPublisherSignup(form);
      setStatus({
        loading: false,
        message: response.message || 'Publisher account received.',
        error: '',
        loginUrl: response.login_url || '',
        dashboardUrl: response.dashboard_url || '',
      });
    } catch (error) {
      setStatus({ loading: false, message: '', error: error.message, loginUrl: '', dashboardUrl: '' });
    }
  }

  return (
    <main className="rs-shell compact-page rs-signup-page">
      <section className="rs-page-head rs-product-head">
        <div>
          <Badge tone="danger">Publisher signup</Badge>
          <h1>Bring your newsroom into Rifnote.</h1>
          <p>Create a publisher account, connect your source, and get a dashboard for submissions, feed health, API events and story performance.</p>
        </div>
        <div className="rs-mini-command">
          <Shield size={18} />
          <span>Source-first discovery. Readers still go back to you.</span>
        </div>
      </section>

      <section className="rs-dashboard-grid">
        <Card>
          <CardHeader title="Create publisher account" action={<Badge>Signup</Badge>} />
          <form className="rs-submit-form" onSubmit={submitForm}>
            <label>Publisher / newsroom name<input required value={form.publisher_name} onChange={(event) => updateField('publisher_name', event.target.value)} placeholder="The Punch, Rifnote Sports..." /></label>
            <label>Website URL<input required type="url" value={form.website_url} onChange={(event) => updateField('website_url', event.target.value)} placeholder="https://publisher.com" /></label>
            <label>Editor / account email<input required type="email" value={form.contact_email} onChange={(event) => updateField('contact_email', event.target.value)} placeholder="editor@publisher.com" /></label>
            <label>Country<input value={form.country} onChange={(event) => updateField('country', event.target.value)} placeholder="Nigeria" /></label>
            <label>Coverage lanes<input value={form.categories} onChange={(event) => updateField('categories', event.target.value)} placeholder="Politics, Football, Business" /></label>
            <MediaUploadField label="Publisher logo" value={form.logo_url} accept="image/*" note="Optional. Upload the logo Rifnote should show for this source." onUploaded={(url) => updateField('logo_url', url)} />
            <label>RSS feed URL<input type="url" value={form.rss_feed_url} onChange={(event) => updateField('rss_feed_url', event.target.value)} placeholder="https://publisher.com/feed" /></label>
            <label>Sitemap URL<input type="url" value={form.sitemap_url} onChange={(event) => updateField('sitemap_url', event.target.value)} placeholder="https://publisher.com/sitemap.xml" /></label>
            <input type="text" name="website" tabIndex="-1" autoComplete="off" className="rs-hp-field" onChange={() => {}} />
            {status.error ? <p className="rs-form-error">{status.error}</p> : null}
            {status.message ? <p className="rs-form-success">{status.message}</p> : null}
            <button className="rs-button primary" type="submit" disabled={status.loading}>{status.loading ? 'Creating...' : 'Create publisher account'}</button>
            <div className="rs-actions">
              {status.loginUrl ? <a className="rs-button ghost" href={status.loginUrl}>Sign in</a> : null}
              {status.dashboardUrl ? <a className="rs-button ghost" href={status.dashboardUrl}>Open dashboard</a> : null}
            </div>
          </form>
        </Card>
        <Card>
          <CardHeader title="What you unlock" action={<Badge>Publisher hub</Badge>} />
          <ul className="rs-clean-list">
            <li>Track submitted stories and editorial review status.</li>
            <li>Connect RSS, sitemap and API submissions.</li>
            <li>See source clicks, feed health and webhook activity.</li>
            <li>Request verification so your stories move faster.</li>
          </ul>
          <div className="rs-actions">
            <a className="rs-button ghost" href="/publisher-docs/">Read publisher guide</a>
            <a className="rs-button ghost" href="/submit-news/">Send one story first</a>
          </div>
        </Card>
      </section>
    </main>
  );
}

function PublisherDocsPage() {
  const endpoints = [
    ['Send a story', 'POST /wp-json/rifnote/v1/publisher/api/submit', 'Push headline, excerpt, source, and original URL.'],
    ['Add a webhook', 'POST /wp-json/rifnote/v1/publisher/api/webhooks', 'Get signed updates when submissions land or fail.'],
    ['Check activity', 'GET /wp-json/rifnote/v1/publisher/api/events', 'See what your source has been sending in.'],
  ];

  return (
    <main className="rs-shell compact-page rs-docs-page">
      <section className="rs-page-head rs-product-head">
        <div>
          <Badge tone="danger">Publisher hub</Badge>
          <h1>Plug your newsroom into Rifnote.</h1>
          <p>Send stories, keep your feed healthy, and get readers back to the original article where they belong.</p>
        </div>
        <div className="rs-mini-command">
          <ExternalLink size={18} />
          <span>Your API keys live in the publisher console</span>
        </div>
      </section>

      <section className="rs-stat-grid rs-product-stats">
        <DashboardStat label="API auth" value="Key" note="Header or bearer token" />
        <DashboardStat label="Content model" value="Snippet" note="No full article copies" />
        <DashboardStat label="Events" value="Signed" note="Webhook verification ready" />
      </section>

      <section className="rs-dashboard-grid">
        <Card>
          <CardHeader title="How it flows" action={<Badge>4 steps</Badge>} />
          <div className="rs-doc-steps">
            {['Send your source', 'Get approved and receive an API key', 'Submit stories or connect RSS', 'Watch clicks, events and feed health'].map((step, index) => (
              <article key={step}><span>{index + 1}</span><strong>{step}</strong></article>
            ))}
          </div>
        </Card>
        <Card>
          <CardHeader title="Keep it clean" action={<Shield size={18} />} />
          <ul className="rs-clean-list">
            <li>Headlines, short excerpts, metadata and original URLs only.</li>
            <li>Attribution stays visible. Outbound links stay prominent.</li>
            <li>Opt-out and DMCA flows are built in.</li>
          </ul>
        </Card>
      </section>

      <Card>
        <CardHeader title="API endpoints" action={<Badge>REST</Badge>} />
        <div className="rs-endpoint-list">
          {endpoints.map(([title, endpoint, copy]) => (
            <article key={endpoint}>
              <div><strong>{title}</strong><span>{copy}</span></div>
              <code>{endpoint}</code>
            </article>
          ))}
        </div>
      </Card>

      <Card>
        <CardHeader title="Example story drop" action={<Badge>JSON</Badge>} />
        <pre className="rs-code-block"><code>{`POST /wp-json/rifnote/v1/publisher/api/submit
X-Rifnote-API-Key: rfs_your_key
Content-Type: application/json

{
  "headline": "Story headline",
  "original_url": "https://publisher.com/story",
  "excerpt": "Short source-backed excerpt",
  "category": "Football",
  "tags": "Nigeria, Transfers",
  "published_at": "2026-07-10T12:00:00Z"
}`}</code></pre>
      </Card>
    </main>
  );
}

function PublisherDashboard() {
  const params = new URLSearchParams(window.location.search);
  const [dashboard, setDashboard] = useState(null);
  const [diagnostics, setDiagnostics] = useState(null);
  const [status, setStatus] = useState({ loading: true, error: '' });

  useEffect(() => {
    let cancelled = false;
    setStatus({ loading: true, error: '' });

    getPublisherStats({ publisherId: params.get('publisher_id') ?? '' })
      .then((payload) => {
        if (!cancelled) {
          setDashboard(payload);
          setStatus({ loading: false, error: '' });
          if (payload?.profile?.id) {
            getFeedDiagnostics(payload.profile.id).then((feed) => !cancelled && setDiagnostics(feed)).catch(() => {});
          }
        }
      })
      .catch((error) => {
        if (!cancelled) {
          setStatus({ loading: false, error: error.message });
        }
      });

    return () => {
      cancelled = true;
    };
  }, []);

  if (status.loading) {
    return (
      <main className="rs-shell compact-page">
        <Card className="rs-skeleton"><CardHeader title="Publisher hub" action={<Badge>Loading</Badge>} /><p>Getting your source profile and feed status.</p></Card>
      </main>
    );
  }

  if (status.error) {
    return (
      <main className="rs-shell compact-page">
        <section className="rs-page-head rs-dashboard-head">
          <div>
            <Badge tone="danger">Publisher hub</Badge>
            <h1>Sign in to see your source stats.</h1>
            <p>Use the same email from onboarding to check posts, clicks, feed health, and status.</p>
          </div>
          <div className="rs-actions">
            <a className="rs-button primary" href="/wp-login.php">Sign in</a>
            <a className="rs-button ghost" href="/publisher-signup/">Create publisher account</a>
          </div>
        </section>
        <Card><CardHeader title="Couldn’t open your hub" action={<Badge>Auth</Badge>} /><p>{status.error}</p></Card>
      </main>
    );
  }

  const profile = dashboard?.profile ?? {};
  const stats = dashboard?.stats ?? {};

  return (
    <main className="rs-shell compact-page rs-dashboard">
      <section className="rs-page-head rs-dashboard-head">
        <div>
          <Badge tone="danger">Publisher hub</Badge>
          <h1>{profile.publisher_name || 'Publisher profile'}</h1>
          <p>Watch your posts, indexed stories, outbound clicks, feed health, and source status in one place.</p>
        </div>
        <div className="rs-profile-card">
          <strong>{profile.approval_status || 'pending'}</strong>
          <span>Approval status</span>
          <a href={profile.website_url} target="_blank" rel="noreferrer" onClick={() => trackAnalyticsEvent({ event_type: 'source_click', publisher_id: profile.id, source_name: profile.publisher_name, target_url: profile.website_url })}>{profile.website_url}</a>
        </div>
      </section>

      <section className="rs-stat-grid">
        <DashboardStat label="Stories sent" value={stats.submitted_posts ?? 0} />
        <DashboardStat label="Stories live" value={stats.indexed_posts ?? 0} />
        <DashboardStat label="Clicks sent" value={stats.clicks_sent ?? 0} note={stats.analytics_ready ? '' : 'Analytics begins in Milestone 12'} />
        <DashboardStat label="In review" value={stats.pending_posts ?? 0} />
        <DashboardStat label="Impressions" value={stats.impressions ?? 0} />
        <DashboardStat label="CTR" value={`${stats.ctr ?? 0}%`} />
      </section>

      <section className="rs-dashboard-grid">
        <Card>
          <CardHeader title="Source profile" action={<Badge>{profile.verification_status || 'pending'}</Badge>} />
          <dl className="rs-profile-list">
            <div><dt>Contact</dt><dd>{profile.contact_email || 'Not set'}</dd></div>
            <div><dt>Country</dt><dd>{profile.country || 'Not set'}</dd></div>
            <div><dt>Categories</dt><dd>{profile.categories || 'Not set'}</dd></div>
            <div><dt>Authority score</dt><dd>{Number(profile.source_authority_score ?? 0).toFixed(2)}</dd></div>
            <div><dt>Indexing mode</dt><dd>{profile.auto_approve ? 'Trusted auto-index' : 'Review before indexing'}</dd></div>
          </dl>
        </Card>

        <Card>
          <CardHeader title="Feed health" action={<Badge>{profile.feed_status || 'pending'}</Badge>} />
          <dl className="rs-profile-list">
            <div><dt>RSS feed</dt><dd>{profile.rss_feed_url ? <a href={profile.rss_feed_url} target="_blank" rel="noreferrer" onClick={() => trackAnalyticsEvent({ event_type: 'source_click', publisher_id: profile.id, source_name: profile.publisher_name, target_url: profile.rss_feed_url })}>{profile.rss_feed_url}</a> : 'Not set'}</dd></div>
            <div><dt>Last crawled</dt><dd>{formatDate(profile.feed_last_checked) || 'Never'}</dd></div>
            <div><dt>Last error</dt><dd>{profile.feed_last_error || 'None'}</dd></div>
            {diagnostics ? <div><dt>Diagnostics</dt><dd>{diagnostics.valid_xml ? 'Valid XML' : 'Needs review'} · {diagnostics.duplicate_rate ?? 0}% duplicates · {diagnostics.item_count ?? 0} items</dd></div> : null}
          </dl>
        </Card>
      </section>

      <Card>
        <CardHeader title="Stories you sent" action={<Badge>{stats.submitted_posts ?? 0} total</Badge>} />
        <DashboardTable
          empty="No stories sent yet."
          rows={(dashboard?.submissions ?? []).map((submission) => ({
            title: submission.headline,
            meta: `${submission.category || 'News'} · ${formatDate(submission.created_at)}`,
            status: submission.status,
            url: submission.original_url,
            postId: submission.wp_post_id,
            publisherId: profile.id,
            sourceName: profile.publisher_name,
          }))}
        />
      </Card>

      <section className="rs-dashboard-grid">
        <Card>
          <CardHeader title="Top queries" action={<Badge>30 days</Badge>} />
          <AnalyticsMiniList rows={dashboard?.analytics?.top_queries ?? []} empty="No query impressions yet." />
        </Card>
        <Card>
          <CardHeader title="Top stories" action={<Badge>Impressions</Badge>} />
          <AnalyticsMiniList rows={dashboard?.analytics?.top_stories ?? []} empty="No story impressions yet." />
        </Card>
      </section>

      <Card>
        <CardHeader title="API activity" action={<Badge>{dashboard?.api_events?.length ?? 0} recent</Badge>} />
        <div className="rs-dashboard-table">
          {(dashboard?.api_events ?? []).length ? (dashboard.api_events ?? []).map((event) => (
            <article key={event.id}>
              <div>
                <strong>{event.event_type}</strong>
                <span>{event.message || event.request_id}</span>
              </div>
              <Badge>{event.status}</Badge>
              <span>{formatDate(event.created_at)}</span>
            </article>
          )) : <p>No publisher API events yet. Rotate an API key in admin, then submit through the publisher API.</p>}
        </div>
      </Card>

      <section className="rs-dashboard-grid">
        <Card>
          <CardHeader title="Webhooks" action={<Badge>{dashboard?.webhooks?.length ?? 0}</Badge>} />
          <div className="rs-dashboard-table">
            {(dashboard?.webhooks ?? []).length ? (dashboard.webhooks ?? []).map((webhook) => (
              <article key={webhook.id}>
                <div>
                  <strong>{webhook.endpoint_url}</strong>
                  <span>{(webhook.events ?? []).join(', ') || 'All publisher events'}</span>
                </div>
                <Badge>{webhook.status}</Badge>
                <span>{webhook.last_success_at ? `Last success ${formatDate(webhook.last_success_at)}` : 'No successful delivery yet'}</span>
              </article>
            )) : <p>No webhook endpoints registered yet.</p>}
          </div>
        </Card>
        <Card>
          <CardHeader title="Webhook deliveries" action={<Badge>{dashboard?.webhook_deliveries?.length ?? 0}</Badge>} />
          <div className="rs-dashboard-table">
            {(dashboard?.webhook_deliveries ?? []).length ? (dashboard.webhook_deliveries ?? []).map((delivery) => (
              <article key={delivery.id}>
                <div>
                  <strong>{delivery.event_type}</strong>
                  <span>{delivery.target_url}</span>
                </div>
                <Badge>{delivery.status}</Badge>
                <span>{delivery.http_status || '-'}</span>
              </article>
            )) : <p>No webhook delivery attempts yet.</p>}
          </div>
        </Card>
      </section>

      <Card>
        <CardHeader title="Indexed posts" action={<Badge>{stats.indexed_posts ?? 0} live</Badge>} />
        <DashboardTable
          empty="No indexed posts yet."
          rows={(dashboard?.indexed_posts ?? []).map((post) => ({
            title: post.headline,
            meta: `${post.source_type || 'source'} · ${formatDate(post.published_at)} · ${post.clicks_sent ?? 0} clicks`,
            status: 'indexed',
            url: post.read_full_story_url || post.original_url || post.url,
            postId: post.id,
            publisherId: profile.id,
            sourceName: profile.publisher_name,
          }))}
        />
      </Card>
    </main>
  );
}

function DashboardStat({ label, value, note = '' }) {
  return (
    <Card className="rs-stat-card">
      <span>{label}</span>
      <strong>{value}</strong>
      {note ? <small>{note}</small> : null}
    </Card>
  );
}

function AnalyticsMiniList({ rows, empty }) {
  if (!rows.length) {
    return <p>{empty}</p>;
  }

  return (
    <div className="rs-analytics-mini">
      {rows.map((row) => (
        <div key={`${row.label}-${row.total}`}>
          <span>{row.label}</span>
          <strong>{row.total}</strong>
        </div>
      ))}
    </div>
  );
}

function DashboardTable({ rows, empty }) {
  if (!rows.length) {
    return <p>{empty}</p>;
  }

  return (
    <div className="rs-dashboard-table">
      {rows.map((row) => (
        <article key={`${row.title}-${row.url}`}>
          <div>
            <strong>{row.title}</strong>
            <span>{row.meta}</span>
          </div>
          <Badge>{row.status}</Badge>
          {row.url ? <a className="rs-button ghost" href={row.url} target="_blank" rel="noreferrer" onClick={() => trackAnalyticsEvent({ event_type: 'source_click', source_name: row.sourceName, target_url: row.url, post_id: row.postId, publisher_id: row.publisherId })}>Open</a> : null}
        </article>
      ))}
    </div>
  );
}

function SearchPanel({ state, onSubmit, compact = false }) {
  const [draftQuery, setDraftQuery] = useState(state.query);
  const [suggestions, setSuggestions] = useState([]);
  const variant = compact === 'google' ? 'google' : compact === 'home' ? 'home' : compact ? 'compact' : 'full';
  const homepagePlaceholder = String(window.RIFNOTE_SEARCH?.homeSearchPlaceholder || '').trim() || 'Search news and trends';
  const searchPlaceholder = variant === 'home' ? homepagePlaceholder : 'Search news and trends';

  useEffect(() => {
    setDraftQuery(state.query);
  }, [state.query]);

  useEffect(() => {
    let cancelled = false;

    if (state.query.trim().length < 2) {
      setSuggestions([]);
      return undefined;
    }

    getSuggestions({ query: state.query, limit: 6 })
      .then((payload) => !cancelled && setSuggestions(payload.suggestions ?? []))
      .catch(() => !cancelled && setSuggestions([]));

    return () => {
      cancelled = true;
    };
  }, [state.query]);

  function setCategory(category) {
    state.setCategory(category);
    state.setPage(1);
    trackAnalyticsEvent({ event_type: 'category_filter_used', category });
  }

  function commitDraft() {
    const nextQuery = draftQuery.trim();

    if (nextQuery !== state.query) {
      state.setQuery(nextQuery);
    }

    state.setPage(1);
    setSuggestions([]);
    return nextQuery;
  }

  function submitDraft(event) {
    event.preventDefault();
    onSubmit?.(event, commitDraft());
  }

  function handleSearchKeyDown(event) {
    if (event.key !== 'Enter') {
      return;
    }

    event.preventDefault();
    onSubmit?.(event, commitDraft());
  }

  function setSort(sort) {
    state.setSort(sort);
    state.setPage(1);
  }

  function setDateRange(dateRange) {
    state.setDateRange(dateRange);
    state.setPage(1);
  }

  return (
    <form className={`rs-search-panel ${variant}`} onSubmit={submitDraft}>
      <div className="rs-search-input">
        {variant === 'home' ? <Search className="rs-home-mobile-search-glyph" aria-hidden="true" /> : null}
        <input value={draftQuery} onChange={(event) => setDraftQuery(event.target.value)} onKeyDown={handleSearchKeyDown} placeholder={searchPlaceholder} />
        <button className="rs-button primary icon-only" type="submit" aria-label="Search Rifnote">
          <Search size={22} aria-hidden="true" />
        </button>
      </div>
      {suggestions.length ? (
        <div className="rs-suggestions">
          {suggestions.map((suggestion) => (
            <button key={`${suggestion.type}-${suggestion.value}`} type="button" onClick={() => { setDraftQuery(suggestion.value); state.setQuery(suggestion.value); state.setPage(1); setSuggestions([]); trackAnalyticsEvent({ event_type: 'suggestion_selected', query_text: suggestion.value, metadata: { type: suggestion.type } }); }}>
              <span>{suggestion.label}</span>
              <small>{suggestion.type}</small>
            </button>
          ))}
        </div>
      ) : null}
      {variant === 'full' ? (
        <>
          <div className="rs-pills">{rifnoteCategories.map((category) => <button className={state.category === category ? 'active' : ''} key={category} type="button" onClick={() => setCategory(category)}>{category}</button>)}</div>
          <div className="rs-filters">
            <select aria-label="Sort results" value={state.sort} onChange={(event) => setSort(event.target.value)}><option value="relevance">Relevance</option><option value="latest">Latest</option></select>
            <select aria-label="Date range" value={state.dateRange} onChange={(event) => setDateRange(event.target.value)}><option value="all">Any time</option><option value="24h">Past 24 hours</option><option value="7d">Past 7 days</option><option value="30d">Past 30 days</option></select>
          </div>
        </>
      ) : null}
    </form>
  );
}

function StoryClusterPage() {
  const clusterId = decodeURIComponent(window.location.pathname.split('/').filter(Boolean).pop() || '');
  const [payload, setPayload] = useState(null);
  const [status, setStatus] = useState({ loading: true, error: '' });
  const [liveRailOpen, setLiveRailOpen] = useState(false);
  const sidebarState = useMemo(() => ({
    setQuery: (query) => {
      window.location.href = searchUrl(query);
    },
    setCategory: () => {},
    setPage: () => {},
  }), []);

  useEffect(() => {
    const openLive = () => setLiveRailOpen(true);
    document.addEventListener('rifnote:open-live', openLive);
    return () => document.removeEventListener('rifnote:open-live', openLive);
  }, []);

  useEffect(() => {
    let cancelled = false;
    getStoryCluster(clusterId)
      .then((data) => { if (!cancelled) { setPayload(data); setStatus({ loading: false, error: '' }); } })
      .catch((error) => !cancelled && setStatus({ loading: false, error: error.message }));
    return () => { cancelled = true; };
  }, [clusterId]);

  if (status.loading) return <main className="rs-shell compact-page"><Card><CardHeader title="Story hub" action={<Badge>Loading</Badge>} /><p>Pulling the coverage together.</p></Card></main>;
  if (status.error) return <main className="rs-shell compact-page"><Card><CardHeader title="Couldn’t find that story" action={<Badge tone="danger">404</Badge>} /><p>{status.error}</p></Card></main>;

  const stories = payload.stories ?? [];
  const leadStory = payload.lead_story ?? stories[0] ?? {};
  const headline = decodeText(payload.headline || leadStory.headline || 'Full coverage');
  const summary = decodeText(payload.summary || leadStory.excerpt || '');
  const heroImage = decodeText(payload.image_url || leadStory.image || leadStory.image_url || '');
  const sourceNames = Array.isArray(payload.sources) ? payload.sources : Object.values(payload.sources ?? {});
  const sourceCount = Number(payload.source_count ?? sourceNames.length ?? stories.length);
  const leadUrl = leadStory.read_full_story_url || leadStory.original_url || leadStory.source_url;

  return (
    <main className="rs-shell compact-page rs-story-aggregation-page">
      <section className="rs-story-aggregation-layout">
        <div className="rs-story-aggregation-main">
          <article className="rs-story-hero-card">
            <div className="rs-story-hero-media">
              {heroImage ? <img src={heroImage} alt="" loading="eager" /> : <span><Newspaper size={42} /> Story hub</span>}
            </div>
            <div className="rs-story-hero-copy">
              <Badge tone="danger">Story hub</Badge>
              <h1>{headline}</h1>
              <p>{summary}</p>
              <div className="rs-story-hero-meta">
                <span>{sourceCount || stories.length || 1} source{sourceCount === 1 ? '' : 's'}</span>
                <span>{stories.length || 1} report{stories.length === 1 ? '' : 's'}</span>
                <span>Updated {formatDate(payload.generated_at)}</span>
              </div>
              <div className="rs-story-hero-actions">
                {leadUrl ? <a className="rs-button primary" href={leadUrl} target="_blank" rel="noreferrer" onClick={() => trackStoryClick(leadStory, 'lead_source_click', headline)}>Open lead source <ExternalLink size={16} /></a> : null}
                <a className="rs-button ghost" href={searchUrl(headline)}>Search the angle <Search size={16} /></a>
              </div>
            </div>
          </article>
          <Card>
            <CardHeader title="More on this story" action={<Badge>{stories.length} items</Badge>} />
            <ResultList results={stories} query={headline} state={sidebarState} />
          </Card>
        </div>
        <LiveRail state={sidebarState} open={liveRailOpen} onClose={() => setLiveRailOpen(false)} />
      </section>
      <button className={`rs-live-drawer-backdrop ${liveRailOpen ? 'open' : ''}`} type="button" aria-label="Close live updates" onClick={() => setLiveRailOpen(false)} />
      <BottomNav state={sidebarState} onLiveOpen={() => setLiveRailOpen(true)} />
    </main>
  );
}

function SourceProfilePage() {
  const domain = decodeURIComponent(window.location.pathname.split('/').filter(Boolean).pop() || '');
  const [payload, setPayload] = useState(null);
  const [status, setStatus] = useState({ loading: true, error: '' });

  useEffect(() => {
    let cancelled = false;
    getSourceProfile(domain)
      .then((data) => { if (!cancelled) { setPayload(data); setStatus({ loading: false, error: '' }); } })
      .catch((error) => !cancelled && setStatus({ loading: false, error: error.message }));
    return () => { cancelled = true; };
  }, [domain]);

  if (status.loading) return <main className="rs-shell compact-page"><Card><CardHeader title="Source profile" action={<Badge>Loading</Badge>} /><p>Checking this source.</p></Card></main>;
  if (status.error) return <main className="rs-shell compact-page"><Card><CardHeader title="Couldn’t open this source" action={<Badge tone="danger">404</Badge>} /><p>{status.error}</p></Card></main>;

  return (
    <main className="rs-shell compact-page">
      <section className="rs-page-head">
        <div>
          <Badge tone="danger">Source check</Badge>
          <div className="rs-source-profile-title">
            <SourceLogo story={payload} size="large" />
            <h1>{decodeText(payload.source_name)}</h1>
          </div>
          <p>{payload.domain} · authority score {Number(payload.trust?.source_authority_score ?? 0).toFixed(0)} · {payload.trust?.verified ? 'Verified' : 'Not verified yet'}</p>
          <a className="rs-button primary" href={payload.website_url} target="_blank" rel="noreferrer">Visit source <ExternalLink size={16} /></a>
        </div>
      </section>
      <section className="rs-stat-grid">
        <DashboardStat label="Verified" value={payload.trust?.verified ? 'Yes' : 'No'} />
        <DashboardStat label="Approved" value={payload.trust?.approved ? 'Yes' : 'No'} />
        <DashboardStat label="Recent stories" value={payload.trust?.recent_story_count ?? 0} />
        <DashboardStat label="Blocked" value={payload.trust?.blocked ? 'Yes' : 'No'} />
      </section>
      <Card><CardHeader title="Latest from this source" action={<Badge>{payload.stories?.length ?? 0} stories</Badge>} /><ResultList results={payload.stories ?? []} query="" state={{ setQuery: () => {}, setCategory: () => {}, setPage: () => {} }} /></Card>
    </main>
  );
}

function DailyBriefingPage() {
  const [briefing, setBriefing] = useState(null);
  const [status, setStatus] = useState({ loading: true, error: '' });

  useEffect(() => {
    let cancelled = false;
    getDailyBriefing({ limit: 10 })
      .then((payload) => { if (!cancelled) { setBriefing(payload); setStatus({ loading: false, error: '' }); } })
      .catch((error) => !cancelled && setStatus({ loading: false, error: error.message }));
    return () => { cancelled = true; };
  }, []);

  if (status.loading) return <main className="rs-shell compact-page"><Card><CardHeader title="Daily drop" action={<Badge>Loading</Badge>} /><p>Getting today’s mix ready.</p></Card></main>;
  if (status.error) return <main className="rs-shell compact-page"><Card><CardHeader title="Daily drop is offline" action={<Badge tone="danger">REST</Badge>} /><p>{status.error}</p></Card></main>;

  return (
    <main className="rs-shell compact-page">
      <section className="rs-page-head">
        <div>
          <Badge tone="danger">{briefing.date}</Badge>
          <h1>{briefing.title}</h1>
          <p>{briefing.intro}</p>
        </div>
        <Card>
          <CardHeader title="Ready to send" action={<Badge>API</Badge>} />
          <p>This drop can power email, push, and public briefing pages.</p>
        </Card>
      </section>
      <Card>
        <CardHeader title="Top stories" action={<Badge>{briefing.stories?.length ?? 0} stories</Badge>} />
        <div className="rs-football-news">
          {(briefing.stories ?? []).map((story) => (
            <a href={story.story_url || story.read_full_story_url} key={story.headline}>
              <strong>{decodeText(story.headline)}</strong>
          <span><SourceMention story={story} /> · score {Number(story.score ?? 0).toFixed(2)}</span>
            </a>
          ))}
        </div>
      </Card>
      <Card>
        <CardHeader title="Trending topics" action={<Badge>Today</Badge>} />
        <div className="rs-pills">{(briefing.trending_topics ?? []).map((topic) => <a className="rs-badge" href={searchUrl(topic.topic)} key={topic.topic}>{topic.topic}</a>)}</div>
      </Card>
    </main>
  );
}

function ForYouPage() {
  const [anonKey] = useState(() => getAnonKey());
  const [topic, setTopic] = useState('');
  const [email, setEmail] = useState('');
  const [payload, setPayload] = useState({ preferences: [], saved_stories: [], notifications: [], results: [] });
  const [status, setStatus] = useState({ loading: true, error: '', message: '' });

  function refresh() {
    setStatus((current) => ({ ...current, loading: true, error: '' }));
    getForYou({ anonKey, limit: 12 })
      .then((data) => { setPayload(data); setStatus({ loading: false, error: '', message: '' }); })
      .catch((error) => setStatus({ loading: false, error: error.message, message: '' }));
  }

  useEffect(() => {
    registerDevice({ anon_key: anonKey }).catch(() => {});
    refresh();
  }, [anonKey]);

  async function followTopic(event) {
    event.preventDefault();
    if (!topic.trim()) return;
    await savePreference({ anon_key: anonKey, preference_type: 'topic', preference_value: topic.trim() });
    if (email.trim()) await saveAlert({ anon_key: anonKey, alert_type: 'topic', alert_value: topic.trim(), email: email.trim(), channel: 'email' });
    setTopic('');
    setStatus({ loading: false, error: '', message: 'Locked in. Your feed will learn from this.' });
    refresh();
  }

  return (
    <main className="rs-shell compact-page">
      <section className="rs-page-head">
        <div>
          <Badge tone="danger">Your lane</Badge>
          <h1>Build your Rifnote feed.</h1>
          <p>Follow teams, players, topics, publishers, or searches. Rifnote keeps the good stuff closer.</p>
        </div>
        <Card>
          <CardHeader title="What are you tracking?" action={<Badge>Alerts</Badge>} />
          <form className="rs-submit-form" onSubmit={followTopic}>
            <label>Topic, team, player or search<input value={topic} onChange={(event) => setTopic(event.target.value)} placeholder="Osimhen, Nigeria, Arsenal..." /></label>
            <label>Email optional<input type="email" value={email} onChange={(event) => setEmail(event.target.value)} placeholder="you@example.com" /></label>
            <button className="rs-button primary" type="submit">Follow</button>
            {status.message ? <p className="rs-form-success">{status.message}</p> : null}
          </form>
        </Card>
      </section>
      <Card><CardHeader title="Your picks" action={<Badge>{payload.preferences?.length ?? 0}</Badge>} /><div className="rs-pills">{(payload.preferences ?? []).map((pref) => <span className="rs-badge" key={pref.id}>{pref.preference_type}: {pref.preference_value}</span>)}</div></Card>
      <NotificationCenter anonKey={anonKey} initialNotifications={payload.notifications ?? []} />
      <SavedStories stories={payload.saved_stories ?? []} />
      {status.loading ? <LoadingGrid /> : <ResultList results={payload.results ?? []} query="" state={{ setQuery: () => {}, setPage: () => {}, category: '' }} />}
    </main>
  );
}

function NotificationCenter({ anonKey, initialNotifications = [] }) {
  const [notifications, setNotifications] = useState(initialNotifications);

  useEffect(() => {
    setNotifications(initialNotifications);
  }, [initialNotifications]);

  async function markRead(notification) {
    setNotifications((current) => current.map((item) => item.id === notification.id ? { ...item, status: 'read' } : item));
    await updateNotification({ id: notification.id, status: 'read' }).catch(() => {});
    getNotifications({ anonKey, limit: 8 }).then((payload) => setNotifications(payload.notifications ?? [])).catch(() => {});
  }

  return (
    <Card>
      <CardHeader title="Your alerts" action={<Badge>{notifications.length}</Badge>} />
      {notifications.length ? (
        <div className="rs-notification-list">
          {notifications.map((notification) => (
            <article key={notification.id} className={notification.status === 'read' ? 'read' : ''}>
              <div>
                <strong>{decodeText(notification.title)}</strong>
                <span>{decodeText(notification.message)}</span>
              </div>
              {notification.target_url ? <a className="rs-button ghost" href={notification.target_url}>Open</a> : null}
              <button className="rs-button ghost" type="button" onClick={() => markRead(notification)}>Mark read</button>
            </article>
          ))}
        </div>
      ) : <p>No alerts yet. Follow something and Rifnote will keep watch.</p>}
    </Card>
  );
}

function SavedStories({ stories = [] }) {
  if (!stories.length) {
    return null;
  }

  return (
    <Card>
      <CardHeader title="Saved for later" action={<Badge>{stories.length}</Badge>} />
      <div className="rs-saved-story-list">
        {stories.map((story) => (
          <a href={story.story_url || story.read_full_story_url} key={`${story.id}-${story.headline}`}>
            <strong>{decodeText(story.headline)}</strong>
            <SourceMention story={story} />
          </a>
        ))}
      </div>
    </Card>
  );
}

function NewsletterSignup() {
  const [form, setForm] = useState({ email: '', topics: '' });
  const [status, setStatus] = useState({ loading: false, message: '', error: '' });

  async function submitForm(event) {
    event.preventDefault();
    setStatus({ loading: true, message: '', error: '' });
    try {
      const response = await subscribeNewsletter({ ...form, source: 'newsletter_page' });
      setStatus({ loading: false, message: response.message || 'You’re in. Watch your inbox.', error: '' });
    } catch (error) {
      setStatus({ loading: false, message: '', error: error.message });
    }
  }

  return (
    <main className="rs-shell compact-page">
      <section className="rs-page-head">
        <div>
          <Badge tone="danger">Daily drop</Badge>
          <h1>Get the stories you actually care about.</h1>
          <p>Pick your beats and get a tight briefing, with links back to the original sources.</p>
        </div>
        <Card className="rs-brief-card">
          <CardHeader title="What lands in your inbox" action={<Badge>Daily</Badge>} />
          <ul className="rs-clean-list">
            <li>Top stories by topic.</li>
            <li>Trending searches and story clusters.</li>
            <li>Football, politics, business and tech.</li>
          </ul>
        </Card>
      </section>
      <section className="rs-dashboard-grid">
        {['Football', 'Nigeria', 'Politics', 'Business'].map((topicName) => (
          <button className="rs-topic-card" key={topicName} type="button" onClick={() => setForm((current) => ({ ...current, topics: current.topics ? `${current.topics}, ${topicName}` : topicName }))}>
            <span>{topicName}</span>
            <strong>Add to my drop</strong>
          </button>
        ))}
      </section>
      <Card className="rs-conversion-card">
        <CardHeader title="Join the list" action={<Badge>Email</Badge>} />
        <form className="rs-submit-form" onSubmit={submitForm}>
          <label>Email<input required type="email" value={form.email} onChange={(event) => setForm((current) => ({ ...current, email: event.target.value }))} placeholder="you@example.com" /></label>
          <label>Topics<input value={form.topics} onChange={(event) => setForm((current) => ({ ...current, topics: event.target.value }))} placeholder="Football, Politics, Nigeria" /></label>
          {status.error ? <p className="rs-form-error">{status.error}</p> : null}
          {status.message ? <p className="rs-form-success">{status.message}</p> : null}
          <button className="rs-button primary" type="submit" disabled={status.loading}>{status.loading ? 'Saving...' : 'Plug me in'}</button>
        </form>
      </Card>
    </main>
  );
}

const AFRICAN_MARKETS = [
  'Nigeria', 'Ghana', 'Kenya', 'South Africa', 'Egypt', 'Morocco', 'Ethiopia', 'Tanzania', 'Uganda', 'Rwanda', 'Senegal', 'Cote d’Ivoire', 'Cameroon', 'Angola', 'Zambia', 'Zimbabwe', 'Botswana', 'Namibia', 'Algeria', 'Tunisia', 'Benin', 'Togo', 'Sierra Leone', 'Liberia', 'Mali', 'Niger', 'Burkina Faso', 'DR Congo', 'Congo', 'Gabon', 'Mozambique', 'Malawi', 'Somalia', 'Sudan', 'South Sudan', 'The Gambia', 'Guinea', 'Guinea-Bissau', 'Equatorial Guinea', 'Cape Verde', 'Mauritius', 'Seychelles', 'Madagascar', 'Lesotho', 'Eswatini', 'Burundi', 'Chad', 'Central African Republic', 'Eritrea', 'Djibouti', 'Libya', 'Mauritania', 'Sao Tome and Principe'
];

const AFRICAN_STATE_OPTIONS = {
  Nigeria: ['Nationwide', 'Abia', 'Abuja FCT', 'Adamawa', 'Akwa Ibom', 'Anambra', 'Bauchi', 'Bayelsa', 'Benue', 'Borno', 'Cross River', 'Delta', 'Ebonyi', 'Edo', 'Ekiti', 'Enugu', 'Gombe', 'Imo', 'Jigawa', 'Kaduna', 'Kano', 'Katsina', 'Kebbi', 'Kogi', 'Kwara', 'Lagos', 'Nasarawa', 'Niger', 'Ogun', 'Ondo', 'Osun', 'Oyo', 'Plateau', 'Rivers', 'Sokoto', 'Taraba', 'Yobe', 'Zamfara'],
  Ghana: ['Nationwide', 'Greater Accra', 'Ashanti', 'Western', 'Central', 'Eastern', 'Northern', 'Volta', 'Upper East', 'Upper West', 'Bono', 'Bono East', 'Ahafo', 'Savannah', 'North East', 'Oti', 'Western North'],
  Kenya: ['Nationwide', 'Nairobi', 'Mombasa', 'Kisumu', 'Nakuru', 'Kiambu', 'Uasin Gishu', 'Machakos', 'Kajiado', 'Kilifi', 'Kakamega', 'Meru', 'Nyeri', 'Murang’a'],
  'South Africa': ['Nationwide', 'Gauteng', 'Western Cape', 'KwaZulu-Natal', 'Eastern Cape', 'Free State', 'Limpopo', 'Mpumalanga', 'North West', 'Northern Cape'],
  Egypt: ['Nationwide', 'Cairo', 'Giza', 'Alexandria', 'Dakahlia', 'Red Sea', 'Beheira', 'Fayoum', 'Gharbia', 'Ismailia', 'Luxor', 'Aswan', 'Port Said', 'Suez'],
  Morocco: ['Nationwide', 'Casablanca-Settat', 'Rabat-Sale-Kenitra', 'Marrakesh-Safi', 'Fes-Meknes', 'Tangier-Tetouan-Al Hoceima', 'Souss-Massa', 'Oriental'],
  Ethiopia: ['Nationwide', 'Addis Ababa', 'Oromia', 'Amhara', 'Tigray', 'Somali', 'Afar', 'Sidama', 'Dire Dawa'],
  Tanzania: ['Nationwide', 'Dar es Salaam', 'Dodoma', 'Arusha', 'Mwanza', 'Zanzibar', 'Mbeya', 'Morogoro', 'Tanga'],
  Uganda: ['Nationwide', 'Central', 'Eastern', 'Northern', 'Western', 'Kampala'],
  Rwanda: ['Nationwide', 'Kigali', 'Northern Province', 'Southern Province', 'Eastern Province', 'Western Province'],
  Senegal: ['Nationwide', 'Dakar', 'Thies', 'Saint-Louis', 'Diourbel', 'Kaolack', 'Ziguinchor', 'Tambacounda'],
};

function SponsorRequestPanel() {
  const [form, setForm] = useState({
    sponsor_name: '',
    contact_email: '',
    advertiser_type: 'Brand',
    phone: '',
    company_website: '',
    campaign_title: '',
    target_url: '',
    objective: 'awareness',
    placements: ['search_top_intent', 'live_updates_sidebar'],
    category: 'All',
    query_match: '',
    audience_country: 'Nigeria',
    audience_state: 'Lagos',
    audience_locations: 'Nigeria, Lagos',
    audience_age_min: '18',
    audience_age_max: '34',
    audience_gender: 'all',
    interests: 'football, breaking news, entertainment, politics',
    pricing_model: 'daily',
    budget: '150000',
    start_date: '',
    end_date: '',
    creative_headline: '',
    creative_text: '',
    creative_image_url: '',
  });
  const [inventory, setInventory] = useState({ currency: 'NGN', placements: [], objectives: [] });
  const [status, setStatus] = useState({ loading: false, message: '', error: '', checkoutUrl: '', estimate: null });

  useEffect(() => {
    getAdInventory().then(setInventory).catch(() => {});
  }, []);

  function updateField(field, value) {
    setForm((current) => ({ ...current, [field]: value }));
  }

  function updateAudienceCountry(value) {
    const states = AFRICAN_STATE_OPTIONS[value] || ['Nationwide'];
    const nextState = states.includes('Lagos') ? 'Lagos' : states[0];
    setForm((current) => ({
      ...current,
      audience_country: value,
      audience_state: nextState,
      audience_locations: nextState === 'Nationwide' ? value : `${value}, ${nextState}`,
    }));
  }

  function updateAudienceState(value) {
    setForm((current) => ({
      ...current,
      audience_state: value,
      audience_locations: value === 'Nationwide' ? current.audience_country : `${current.audience_country}, ${value}`,
    }));
  }

  function togglePlacement(id) {
    setForm((current) => {
      const selected = current.placements.includes(id)
        ? current.placements.filter((placement) => placement !== id)
        : [...current.placements, id];
      return { ...current, placements: selected.length ? selected : [id] };
    });
  }

  const estimate = useMemo(() => estimateAdvertCampaign(form, inventory), [form, inventory]);
  const selectedPlacements = useMemo(() => (inventory.placements || []).filter((placement) => form.placements.includes(placement.id)), [inventory.placements, form.placements]);

  async function submitForm(event) {
    event.preventDefault();
    setStatus({ loading: true, message: '', error: '', checkoutUrl: '', estimate: null });

    try {
      const response = await submitSponsorRequest({ ...form, total_budget: estimate.total });
      setStatus({
        loading: false,
        message: response.message || 'Campaign request received. We’ll review the setup.',
        error: '',
        checkoutUrl: response.checkout_url || '',
        estimate: response.estimate || estimate,
      });
    } catch (error) {
      setStatus({ loading: false, message: '', error: error.message, checkoutUrl: '', estimate: null });
    }
  }

  return (
    <main className="rs-shell compact-page rs-advert-page">
      <section className="rs-advert-intro">
        <div>
          <Badge tone="danger">Advertise on Rifnote</Badge>
          <h1>Build a campaign where the gist is already hot.</h1>
          <p>Pick your goal, choose the pressure points, set your audience, and send it in. We keep ads clearly labelled and away from editorial decisions.</p>
        </div>
        <div className="rs-advert-kpis">
          <DashboardStat label="Starting from" value="₦12.5k" note="Per day, per slot" />
          <DashboardStat label="Pressure points" value={(inventory.placements || []).length || 9} note="Search, football, story hubs" />
          <DashboardStat label="Targeting" value="Intent" note="Topics, age, city, interests" />
        </div>
      </section>

      <form className="rs-ad-builder" onSubmit={submitForm}>
        <div className="rs-ad-builder-main">
          <Card className="rs-ad-section">
            <CardHeader title="1. Advertiser account" action={<Badge>Account</Badge>} />
            <p className="rs-ad-section-note">Use the email you want tied to the advertiser account. Rifnote will create or reuse the account when this brief is sent.</p>
            <div className="rs-submit-form">
              <label>Brand / advertiser name<input required value={form.sponsor_name} onChange={(event) => updateField('sponsor_name', event.target.value)} placeholder="Rifnote Labs, Nike, Zenith..." /></label>
              <label>Work email<input required type="email" value={form.contact_email} onChange={(event) => updateField('contact_email', event.target.value)} placeholder="media@example.com" /></label>
              <label>Advertiser type<select value={form.advertiser_type} onChange={(event) => updateField('advertiser_type', event.target.value)}><option>Brand</option><option>Agency</option><option>Creator</option><option>Publisher</option><option>Political / advocacy</option><option>SME</option></select></label>
              <label>Phone / WhatsApp<input value={form.phone} onChange={(event) => updateField('phone', event.target.value)} placeholder="+234..." /></label>
              <label>Company website<input type="url" value={form.company_website} onChange={(event) => updateField('company_website', event.target.value)} placeholder="https://brand.com" /></label>
              <label>Landing page<input required type="url" value={form.target_url} onChange={(event) => updateField('target_url', event.target.value)} placeholder="https://brand.com/campaign" /></label>
            </div>
          </Card>

          <Card className="rs-ad-section">
            <CardHeader title="2. Campaign goal" action={<Badge>Objective</Badge>} />
            <div className="rs-objective-grid">
              {(inventory.objectives || []).map((objective) => (
                <button className={`rs-objective-card ${form.objective === objective.id ? 'active' : ''}`} type="button" key={objective.id} onClick={() => updateField('objective', objective.id)}>
                  <span>{objective.name}</span>
                  <small>{objective.multiplier > 1 ? `${Math.round((objective.multiplier - 1) * 100)}% priority boost` : 'Base rate'}</small>
                </button>
              ))}
            </div>
            <div className="rs-submit-form">
              <label>Campaign title<input required value={form.campaign_title} onChange={(event) => updateField('campaign_title', event.target.value)} placeholder="Make it short and punchy" /></label>
              <label>Category<select value={form.category} onChange={(event) => updateField('category', event.target.value)}><option>All</option><option>Football</option><option>Politics</option><option>World</option><option>Nigeria</option><option>Business</option><option>Tech</option><option>Entertainment</option></select></label>
              <label>Keyword / intent match<input value={form.query_match} onChange={(event) => updateField('query_match', event.target.value)} placeholder="Example: Osimhen transfer, Lagos events, election" /></label>
              <label>Pricing model<select value={form.pricing_model} onChange={(event) => updateField('pricing_model', event.target.value)}><option value="daily">Daily booked slots</option><option value="cpm">CPM estimate</option><option value="sponsorship">Flat sponsorship</option></select></label>
            </div>
          </Card>

          <Card className="rs-ad-section">
            <CardHeader title="3. Pressure points" action={<Badge>{selectedPlacements.length} selected</Badge>} />
            <div className="rs-placement-grid">
              {(inventory.placements || []).map((placement) => (
                <button className={`rs-placement-option ${form.placements.includes(placement.id) ? 'active' : ''}`} type="button" key={placement.id} onClick={() => togglePlacement(placement.id)}>
                  <span className="rs-placement-area">{placement.area}</span>
                  <strong>{placement.name}</strong>
                  <small>{placement.description}</small>
                  <b>{formatNaira(placement.price)} / {placement.unit}</b>
                </button>
              ))}
            </div>
          </Card>

          <Card className="rs-ad-section">
            <CardHeader title="4. Audience and schedule" action={<Badge>Demography</Badge>} />
            <div className="rs-submit-form">
              <label>Country<select value={form.audience_country} onChange={(event) => updateAudienceCountry(event.target.value)}>{AFRICAN_MARKETS.map((country) => <option key={country} value={country}>{country}</option>)}</select></label>
              <label>State / region<select value={form.audience_state} onChange={(event) => updateAudienceState(event.target.value)}>{(AFRICAN_STATE_OPTIONS[form.audience_country] || ['Nationwide']).map((state) => <option key={state} value={state}>{state}</option>)}</select></label>
              <label>Interests<input value={form.interests} onChange={(event) => updateField('interests', event.target.value)} placeholder="Football, streetwear, fintech, campus..." /></label>
              <label>Age min<input type="number" min="13" max="80" value={form.audience_age_min} onChange={(event) => updateField('audience_age_min', event.target.value)} /></label>
              <label>Age max<input type="number" min="13" max="80" value={form.audience_age_max} onChange={(event) => updateField('audience_age_max', event.target.value)} /></label>
              <label>Gender<select value={form.audience_gender} onChange={(event) => updateField('audience_gender', event.target.value)}><option value="all">All genders</option><option value="female">Women</option><option value="male">Men</option><option value="custom">Custom / mixed brief</option></select></label>
              <label>Budget ceiling<input value={form.budget} onChange={(event) => updateField('budget', event.target.value)} placeholder="150000" /></label>
              <label>Start date<input type="date" value={form.start_date} onChange={(event) => updateField('start_date', event.target.value)} /></label>
              <label>End date<input type="date" value={form.end_date} onChange={(event) => updateField('end_date', event.target.value)} /></label>
            </div>
          </Card>

          <Card className="rs-ad-section">
            <CardHeader title="5. Creative" action={<Badge>Preview brief</Badge>} />
            <div className="rs-submit-form">
              <label>Ad headline<input value={form.creative_headline} onChange={(event) => updateField('creative_headline', event.target.value)} placeholder="Win big this matchday" /></label>
              <MediaUploadField label="Creative media" value={form.creative_image_url} accept="image/*,video/mp4,video/webm" note="Upload an image, GIF, MP4 or WebM creative for review." onUploaded={(url) => updateField('creative_image_url', url)} />
              <label>Ad copy<textarea value={form.creative_text} onChange={(event) => updateField('creative_text', event.target.value)} placeholder="Tell us what the audience should feel, know or do." /></label>
            </div>
          </Card>
        </div>

        <aside className="rs-ad-summary">
          <Card>
            <CardHeader title="Campaign estimate" action={<Badge>NGN</Badge>} />
            <div className="rs-ad-price">{formatNaira(estimate.total)}</div>
            <p>{estimate.days} day{estimate.days === 1 ? '' : 's'} · about {estimate.impressions.toLocaleString()} impressions before final review.</p>
            <div className="rs-ad-summary-list">
              {selectedPlacements.map((placement) => <span key={placement.id}>{placement.name}<b>{formatNaira(placement.price)}</b></span>)}
            </div>
            <button className="rs-button primary" type="submit" disabled={status.loading}>{status.loading ? 'Sending...' : 'Send campaign brief'}</button>
            {status.checkoutUrl ? <a className="rs-button ghost" href={status.checkoutUrl}>Continue to checkout</a> : null}
            {status.error ? <p className="rs-form-error">{status.error}</p> : null}
            {status.message ? <p className="rs-form-success">{status.message}</p> : null}
          </Card>
        </aside>
      </form>
    </main>
  );
}

function AdvertiserSignupPanel() {
  const [form, setForm] = useState({
    sponsor_name: '',
    contact_email: '',
    advertiser_type: 'Brand',
    advertiser_role: '',
    phone: '',
    company_website: '',
    goals: 'Reach young readers around news, football and trends',
  });
  const [status, setStatus] = useState({ loading: false, message: '', error: '', loginUrl: '', dashboardUrl: '', advertiseUrl: '' });

  function updateField(field, value) {
    setForm((current) => ({ ...current, [field]: value }));
  }

  async function submitForm(event) {
    event.preventDefault();
    setStatus({ loading: true, message: '', error: '', loginUrl: '', dashboardUrl: '', advertiseUrl: '' });

    try {
      const response = await submitAdvertiserSignup(form);
      setStatus({
        loading: false,
        message: response.message || 'Advertiser account ready.',
        error: '',
        loginUrl: response.login_url || '',
        dashboardUrl: response.dashboard_url || '',
        advertiseUrl: response.advertise_url || '',
      });
    } catch (error) {
      setStatus({ loading: false, message: '', error: error.message, loginUrl: '', dashboardUrl: '', advertiseUrl: '' });
    }
  }

  return (
    <main className="rs-shell compact-page rs-signup-page rs-advertiser-signup-page">
      <section className="rs-advert-intro">
        <div>
          <Badge tone="danger">Advertiser signup</Badge>
          <h1>Get your brand into the hot zones.</h1>
          <p>Create an advertiser account for campaign briefs, status tracking, payment proof, performance cards and reports.</p>
        </div>
        <div className="rs-advert-kpis">
          <DashboardStat label="Audience" value="Youth" note="News, football, trends" />
          <DashboardStat label="Targeting" value="Intent" note="Geo, device, category" />
          <DashboardStat label="Reports" value="Live" note="Impressions, clicks, CTR" />
        </div>
      </section>

      <section className="rs-dashboard-grid">
        <Card>
          <CardHeader title="Create advertiser account" action={<Badge>Signup</Badge>} />
          <form className="rs-submit-form" onSubmit={submitForm}>
            <label>Brand / advertiser name<input required value={form.sponsor_name} onChange={(event) => updateField('sponsor_name', event.target.value)} placeholder="Nike, Zenith, Campus brand..." /></label>
            <label>Work email<input required type="email" value={form.contact_email} onChange={(event) => updateField('contact_email', event.target.value)} placeholder="media@brand.com" /></label>
            <label>Advertiser type<select value={form.advertiser_type} onChange={(event) => updateField('advertiser_type', event.target.value)}><option>Brand</option><option>Agency</option><option>Creator</option><option>Publisher</option><option>Political / advocacy</option><option>SME</option></select></label>
            <label>Your role<input value={form.advertiser_role} onChange={(event) => updateField('advertiser_role', event.target.value)} placeholder="Founder, media buyer, marketing lead..." /></label>
            <label>Phone / WhatsApp<input value={form.phone} onChange={(event) => updateField('phone', event.target.value)} placeholder="+234..." /></label>
            <label>Company website<input type="url" value={form.company_website} onChange={(event) => updateField('company_website', event.target.value)} placeholder="https://brand.com" /></label>
            <label>What are you trying to achieve?<textarea rows="4" value={form.goals} onChange={(event) => updateField('goals', event.target.value)} /></label>
            <input type="text" name="website" tabIndex="-1" autoComplete="off" className="rs-hp-field" onChange={() => {}} />
            {status.error ? <p className="rs-form-error">{status.error}</p> : null}
            {status.message ? <p className="rs-form-success">{status.message}</p> : null}
            <button className="rs-button primary" type="submit" disabled={status.loading}>{status.loading ? 'Creating...' : 'Create advertiser account'}</button>
            <div className="rs-actions">
              {status.loginUrl ? <a className="rs-button ghost" href={status.loginUrl}>Sign in</a> : null}
              {status.advertiseUrl ? <a className="rs-button ghost" href={status.advertiseUrl}>Build campaign</a> : null}
            </div>
          </form>
        </Card>
        <Card>
          <CardHeader title="What happens next" action={<Badge>Ads hub</Badge>} />
          <ul className="rs-clean-list">
            <li>Set your password from the email WordPress sends.</li>
            <li>Build a campaign with pressure points, audience and budget.</li>
            <li>Track approval, payment, pacing and campaign performance.</li>
            <li>Download reports when the campaign starts moving.</li>
          </ul>
          <div className="rs-actions">
            <a className="rs-button ghost" href="/advertise/">See campaign builder</a>
            <a className="rs-button ghost" href="/advertiser-dashboard/">Open dashboard</a>
          </div>
        </Card>
      </section>
    </main>
  );
}

function AdvertiserDashboard() {
  const [dashboard, setDashboard] = useState(null);
  const [status, setStatus] = useState({ loading: true, error: '' });
  const [paymentProof, setPaymentProof] = useState({});
  const [proofStatus, setProofStatus] = useState({});
  const [profileForm, setProfileForm] = useState({ name: '', type: '', phone: '', website: '' });
  const [profileStatus, setProfileStatus] = useState({ loading: false, error: '', message: '' });

  const loadDashboard = useCallback(() => {
    setStatus({ loading: true, error: '' });
    return getAdvertiserDashboard()
      .then((payload) => {
        setDashboard(payload);
        setStatus({ loading: false, error: '' });
      })
      .catch((error) => setStatus({ loading: false, error: error.message }));
  }, []);

  useEffect(() => {
    let cancelled = false;
    setStatus({ loading: true, error: '' });

    getAdvertiserDashboard()
      .then((payload) => {
        if (!cancelled) {
          setDashboard(payload);
          setStatus({ loading: false, error: '' });
        }
      })
      .catch((error) => !cancelled && setStatus({ loading: false, error: error.message }));

    return () => {
      cancelled = true;
    };
  }, []);

  useEffect(() => {
    if (!dashboard?.profile) {
      return;
    }

    setProfileForm({
      name: dashboard.profile.name || '',
      type: dashboard.profile.type || '',
      phone: dashboard.profile.phone || '',
      website: dashboard.profile.website || '',
    });
  }, [dashboard?.profile?.name, dashboard?.profile?.type, dashboard?.profile?.phone, dashboard?.profile?.website]);

  function updateProfileField(field, value) {
    setProfileForm((current) => ({ ...current, [field]: value }));
  }

  async function submitProfile(event) {
    event.preventDefault();
    setProfileStatus({ loading: true, error: '', message: '' });

    try {
      const result = await updateAdvertiserProfile(profileForm);
      setProfileStatus({ loading: false, error: '', message: result?.message || 'Profile updated.' });
      await loadDashboard();
    } catch (error) {
      setProfileStatus({ loading: false, error: error.message, message: '' });
    }
  }

  function updateProof(id, field, value) {
    setPaymentProof((current) => ({
      ...current,
      [id]: {
        ...(current[id] || {}),
        [field]: value,
      },
    }));
  }

  async function submitProof(event, campaign) {
    event.preventDefault();
    const proof = paymentProof[campaign.id] || {};
    setProofStatus((current) => ({ ...current, [campaign.id]: { loading: true, error: '', message: '' } }));

    try {
      const result = await submitAdvertiserPaymentProof({
        request_id: campaign.id,
        payment_reference: proof.payment_reference || '',
        payment_amount: proof.payment_amount || campaign.estimated_price || '',
        payment_note: proof.payment_note || '',
      });
      setProofStatus((current) => ({
        ...current,
        [campaign.id]: { loading: false, error: '', message: result?.message || 'Payment proof sent. Rifnote will review it shortly.' },
      }));
      await loadDashboard();
    } catch (error) {
      setProofStatus((current) => ({
        ...current,
        [campaign.id]: { loading: false, error: error.message, message: '' },
      }));
    }
  }

  if (status.loading) {
    return <main className="rs-shell compact-page"><Card><CardHeader title="Loading advertiser dashboard" action={<Badge>Ads</Badge>} /><p>Pulling your campaign reports now.</p></Card></main>;
  }

  if (status.error) {
    return <main className="rs-shell compact-page"><Card><CardHeader title="Dashboard could not load" action={<Badge tone="danger">Error</Badge>} /><p className="rs-form-error">{status.error}</p></Card></main>;
  }

  if (!dashboard?.authenticated) {
    return (
      <main className="rs-shell compact-page rs-advert-dashboard">
        <section className="rs-advert-intro">
          <div>
            <Badge tone="danger">Advertiser dashboard</Badge>
            <h1>Your campaigns live here.</h1>
            <p>{dashboard?.message || 'Sign in with the email you used for your advert brief to see campaign status, reports, pacing and conversions.'}</p>
          </div>
          <div className="rs-advert-kpis">
            <DashboardStat label="Reports" value="CTR" note="Clicks and outcomes" />
            <DashboardStat label="Pacing" value="Live" note="Delivery health" />
            <DashboardStat label="Exports" value="CSV" note="Admin-backed" />
          </div>
        </section>
        <Card>
          <CardHeader title="Sign in to continue" action={<Badge>Private</Badge>} />
          <p>Use the account Rifnote created from your advert brief email. Once you are in, campaign reports show automatically.</p>
          <div className="rs-actions">
            {dashboard?.login_url ? <a className="rs-button primary" href={dashboard.login_url}>Sign in</a> : null}
            {dashboard?.register_url ? <a className="rs-button ghost" href={dashboard.register_url}>Create account</a> : null}
            <a className="rs-button ghost" href="/advertise/">Build a campaign</a>
          </div>
        </Card>
      </main>
    );
  }

  const summary = dashboard.summary || {};
  const campaigns = dashboard.campaigns || [];
  const pacingById = new Map((dashboard.pacing || []).map((row) => [Number(row.id), row]));

  return (
    <main className="rs-shell compact-page rs-advert-dashboard">
      <section className="rs-advert-intro">
        <div>
          <Badge tone="danger">Advertiser dashboard</Badge>
          <h1>Track the campaigns carrying your brand.</h1>
          <p>See what is live, what is waiting for review, how delivery is pacing, and whether clicks are turning into real outcomes.</p>
        </div>
        <div className="rs-advert-kpis">
          <DashboardStat label="Campaigns" value={summary.campaigns || 0} note="Submitted briefs" />
          <DashboardStat label="Spend booked" value={formatNaira(summary.spend || 0)} note="Estimated value" />
          <DashboardStat label="CTR" value={`${Number(summary.ctr || 0).toFixed(2)}%`} note={`${summary.clicks || 0} clicks`} />
        </div>
      </section>

      <div className="rs-ad-dashboard-grid">
        <Card>
          <CardHeader title="Performance snapshot" action={<Badge>30 days</Badge>} />
          <div className="rs-analytics-mini">
            <div><strong>{Number(summary.impressions || 0).toLocaleString()}</strong><span>Impressions</span></div>
            <div><strong>{Number(summary.clicks || 0).toLocaleString()}</strong><span>Clicks</span></div>
            <div><strong>{Number(summary.conversions || 0).toLocaleString()}</strong><span>Conversions</span></div>
            <div><strong>{formatNaira(summary.conversion_value || 0)}</strong><span>Conversion value</span></div>
          </div>
        </Card>

        <Card>
          <CardHeader title="Conversion endpoint" action={<Badge>Pixel</Badge>} />
          <p>Send this to your developer or paste a 1x1 tracking pixel on the thank-you page for leads, signups or sales.</p>
          <pre className="rs-code-snippet"><code>{dashboard.conversion_endpoint}</code></pre>
        </Card>

        <Card>
          <CardHeader title="Advertiser profile" action={<Badge>Settings</Badge>} />
          <form className="rs-ad-profile-form" onSubmit={submitProfile}>
            <label>
              Brand name
              <input value={profileForm.name} onChange={(event) => updateProfileField('name', event.target.value)} placeholder="Your brand or agency" />
            </label>
            <label>
              Advertiser type
              <select value={profileForm.type} onChange={(event) => updateProfileField('type', event.target.value)}>
                <option value="">Choose type</option>
                <option value="brand">Brand</option>
                <option value="agency">Agency</option>
                <option value="publisher">Publisher</option>
                <option value="creator">Creator</option>
                <option value="other">Other</option>
              </select>
            </label>
            <label>
              Phone / WhatsApp
              <input value={profileForm.phone} onChange={(event) => updateProfileField('phone', event.target.value)} placeholder="+234..." />
            </label>
            <label>
              Website
              <input value={profileForm.website} onChange={(event) => updateProfileField('website', event.target.value)} placeholder="https://brand.com" />
            </label>
            <button className="rs-button primary" type="submit" disabled={profileStatus.loading}>
              {profileStatus.loading ? 'Saving...' : 'Save profile'}
            </button>
            {profileStatus.message ? <p className="rs-payment-proof-status">{profileStatus.message}</p> : null}
            {profileStatus.error ? <p className="rs-form-error">{profileStatus.error}</p> : null}
          </form>
        </Card>
      </div>

      <section className="rs-ad-campaign-list">
        <CardHeader title="Your campaigns" action={<Badge>{campaigns.length} total</Badge>} />
        {campaigns.length ? campaigns.map((campaign) => {
          const stats = campaign.stats || {};
          const pace = pacingById.get(Number(campaign.id));
          const creative = campaign.creative || {};
          const variants = Array.isArray(creative.variants) ? creative.variants : [];
          const assets = Array.isArray(creative.assets) ? creative.assets : [];
          const timeline = Array.isArray(campaign.timeline) ? [...campaign.timeline].reverse().slice(0, 4) : [];
          return (
            <article className="rs-ad-campaign-card" key={campaign.id}>
              <div>
                <Badge>{campaign.status}</Badge>
                <h2>{campaign.title}</h2>
                <p>{campaign.placements || 'Placement review pending'} · {campaign.objective}</p>
              </div>
              <div className="rs-ad-campaign-stats">
                <span><b>{Number(stats.impressions || 0).toLocaleString()}</b> views</span>
                <span><b>{Number(stats.clicks || 0).toLocaleString()}</b> clicks</span>
                <span><b>{Number(stats.ctr || 0).toFixed(2)}%</b> CTR</span>
                <span><b>{Number(stats.conversions || 0).toLocaleString()}</b> conv.</span>
              </div>
              <div className="rs-ad-campaign-footer">
                <span>{pace?.signal || 'Waiting for pacing data.'}</span>
                <b>{formatNaira(campaign.estimated_price || 0)}</b>
                {campaign.checkout_url ? <a className="rs-button ghost" href={campaign.checkout_url}>Checkout</a> : null}
              </div>
              {(creative.headline || variants.length || assets.length) ? (
                <div className="rs-creative-summary">
                  <Badge tone={creative.status === 'approved' ? 'success' : creative.status === 'rejected' ? 'danger' : ''}>{creative.status || 'draft'}</Badge>
                  <div>
                    <strong>{creative.headline || campaign.title}</strong>
                    <span>{variants.length || 1} copy variant{(variants.length || 1) === 1 ? '' : 's'} · {assets.length || (creative.image_url ? 1 : 0)} asset{(assets.length || (creative.image_url ? 1 : 0)) === 1 ? '' : 's'}</span>
                  </div>
                  {creative.cta ? <b>{creative.cta}</b> : null}
                  {creative.review_note ? <p>{creative.review_note}</p> : null}
                </div>
              ) : null}
              {campaign.status_note ? (
                <div className="rs-ad-workflow-note">
                  <strong>Latest ad ops note</strong>
                  <span>{campaign.status_note}</span>
                </div>
              ) : null}
              {timeline.length ? (
                <div className="rs-ad-timeline">
                  <strong>Campaign timeline</strong>
                  {timeline.map((entry, index) => (
                    <div key={`${campaign.id}-${entry.at || index}`}>
                      <time>{entry.at ? formatDate(entry.at) : 'Just now'}</time>
                      <span>{entry.from || 'new'} → {entry.to || campaign.status}</span>
                      {entry.note ? <small>{entry.note}</small> : null}
                    </div>
                  ))}
                </div>
              ) : null}
              {campaign.payment_reference ? (
                <div className="rs-payment-proof-note">
                  <Badge tone={campaign.status === 'payment_review' ? 'danger' : ''}>Payment proof</Badge>
                  <span>{campaign.payment_reference}</span>
                  {Number(campaign.payment_amount || 0) > 0 ? <b>{formatNaira(campaign.payment_amount)}</b> : null}
                  <small>{campaign.status === 'payment_review' ? 'Reviewing payment now.' : `Status: ${campaign.status}`}</small>
                </div>
              ) : null}
              {['new', 'reviewing', 'approved', 'payment_review'].includes(campaign.status) ? (
                <form className="rs-payment-proof-form" onSubmit={(event) => submitProof(event, campaign)}>
                  <div>
                    <label htmlFor={`proof-ref-${campaign.id}`}>Payment reference</label>
                    <input
                      id={`proof-ref-${campaign.id}`}
                      value={paymentProof[campaign.id]?.payment_reference || ''}
                      onChange={(event) => updateProof(campaign.id, 'payment_reference', event.target.value)}
                      placeholder="Bank transfer / Paystack ref"
                    />
                  </div>
                  <div>
                    <label htmlFor={`proof-amount-${campaign.id}`}>Amount paid</label>
                    <input
                      id={`proof-amount-${campaign.id}`}
                      type="number"
                      min="0"
                      value={paymentProof[campaign.id]?.payment_amount || ''}
                      onChange={(event) => updateProof(campaign.id, 'payment_amount', event.target.value)}
                      placeholder={String(campaign.estimated_price || '')}
                    />
                  </div>
                  <div className="wide">
                    <label htmlFor={`proof-note-${campaign.id}`}>Note</label>
                    <textarea
                      id={`proof-note-${campaign.id}`}
                      value={paymentProof[campaign.id]?.payment_note || ''}
                      onChange={(event) => updateProof(campaign.id, 'payment_note', event.target.value)}
                      placeholder="Tell ad ops what to confirm: bank name, sender name, receipt link, or anything useful."
                    />
                  </div>
                  <button className="rs-button primary" type="submit" disabled={proofStatus[campaign.id]?.loading}>
                    {proofStatus[campaign.id]?.loading ? 'Sending...' : 'Submit payment proof'}
                  </button>
                  {proofStatus[campaign.id]?.message ? <p className="rs-payment-proof-status">{proofStatus[campaign.id].message}</p> : null}
                  {proofStatus[campaign.id]?.error ? <p className="rs-form-error">{proofStatus[campaign.id].error}</p> : null}
                </form>
              ) : null}
            </article>
          );
        }) : (
          <Card>
            <CardHeader title="No campaigns yet" action={<Badge>Start</Badge>} />
            <p>Build your first Rifnote campaign and it will show here after submission.</p>
            <a className="rs-button primary" href="/advertise/">Build a campaign</a>
          </Card>
        )}
      </section>
    </main>
  );
}

function estimateAdvertCampaign(form, inventory) {
  const placements = inventory.placements || [];
  const objectives = inventory.objectives || [];
  const selected = placements.filter((placement) => form.placements.includes(placement.id));
  const fallback = placements.find((placement) => placement.id === 'search_top_intent');
  const active = selected.length ? selected : fallback ? [fallback] : [];
  const daily = active.reduce((sum, placement) => sum + Number(placement.price || 0), 0);
  const impressions = active.reduce((sum, placement) => sum + Number(placement.impressions || 0), 0);
  const objective = objectives.find((item) => item.id === form.objective);
  const multiplier = Number(objective?.multiplier || 1);
  const start = form.start_date ? new Date(form.start_date) : null;
  const end = form.end_date ? new Date(form.end_date) : null;
  const days = start && end && end >= start ? Math.max(1, Math.ceil((end - start) / 86400000) + 1) : 1;

  return {
    days,
    impressions: impressions * days,
    total: Math.round(daily * days * multiplier),
  };
}

function formatNaira(value) {
  return `₦${Number(value || 0).toLocaleString('en-NG', { maximumFractionDigits: 0 })}`;
}

function TrendingWidget() {
  const [payload, setPayload] = useState({ topics: [] });

  const refreshWidget = useCallback(() => {
    getWidget('trending').then(setPayload).catch(() => {});
  }, []);

  useLiveInterval(refreshWidget, 900000);

  return (
    <Card>
      <CardHeader title="Trending on Rifnote" action={<Badge>Widget</Badge>} />
      <div className="rs-pills">{(payload.topics ?? []).map((topic) => <a className="rs-badge" href={searchUrl(topic.topic)} key={topic.topic}>{topic.topic}</a>)}</div>
    </Card>
  );
}

function formatDateInput(value) {
  const date = value instanceof Date ? value : new Date(value);

  if (Number.isNaN(date.getTime())) {
    return formatDateInput(new Date());
  }

  const year = date.getFullYear();
  const month = String(date.getMonth() + 1).padStart(2, '0');
  const day = String(date.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

function addDaysToInput(value, days) {
  const date = new Date(`${value}T12:00:00`);
  date.setDate(date.getDate() + days);
  return formatDateInput(date);
}

function footballDateLabel(value) {
  const today = formatDateInput(new Date());
  const yesterday = addDaysToInput(today, -1);
  const tomorrow = addDaysToInput(today, 1);

  if (value === today) {
    return 'Today';
  }
  if (value === yesterday) {
    return 'Yesterday';
  }
  if (value === tomorrow) {
    return 'Tomorrow';
  }

  const date = new Date(`${value}T12:00:00`);
  return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat(undefined, { weekday: 'short', month: 'short', day: 'numeric' }).format(date);
}

function FootballDateNav({ selectedDate, onChange }) {
  const today = formatDateInput(new Date());
  const dateInputRef = useRef(null);
  const quickDates = [
    ['yesterday', 'Yesterday', addDaysToInput(today, -1)],
    ['today', 'Today', today],
    ['tomorrow', 'Tomorrow', addDaysToInput(today, 1)],
  ];

  return (
    <div className="rs-football-date-nav" aria-label="Match date navigation">
      <button type="button" onClick={() => onChange(addDaysToInput(selectedDate, -1))}>‹</button>
      <div className="rs-football-date-quick">
        {quickDates.map(([key, label, value]) => (
          <button className={selectedDate === value ? 'active' : ''} type="button" key={key} onClick={() => onChange(value)}>{label}</button>
        ))}
      </div>
      <button type="button" onClick={() => onChange(addDaysToInput(selectedDate, 1))}>›</button>
      <label onClick={() => dateInputRef.current?.showPicker?.()}>
        <CalendarDays size={16} />
        <span>{footballDateLabel(selectedDate)}</span>
        <input ref={dateInputRef} type="date" value={selectedDate} onChange={(event) => onChange(event.target.value || today)} />
      </label>
    </div>
  );
}

function FootballHub() {
  const [livePayload, setLivePayload] = useState({ fixtures: [], provider: 'database', configured: false, updated_at: '', poll_after: 30 });
  const [upcomingPayload, setUpcomingPayload] = useState({ fixtures: [], provider: 'database', configured: false, updated_at: '', poll_after: 300 });
  const [finishedPayload, setFinishedPayload] = useState({ fixtures: [], provider: 'database', configured: false, updated_at: '', poll_after: 300 });
  const [datePayload, setDatePayload] = useState({ fixtures: [], provider: 'database', configured: false, updated_at: '', poll_after: 300 });
  const [scoreStatus, setScoreStatus] = useState({ loading: true, error: '', lastRefresh: null });
  const [activeBoard, setActiveBoard] = useState('live');
  const [activeCompetition, setActiveCompetition] = useState('all');
  const initialFixtureParam = useRef(getInitialFootballFixtureParam());
  const [selectedDate, setSelectedDate] = useState(() => getInitialFootballDate());
  const [selectedFixture, setSelectedFixture] = useState(null);
  const [modalFixture, setModalFixture] = useState(null);
  const [matchDetailOpen, setMatchDetailOpen] = useState(false);
  const [footballStories, setFootballStories] = useState([]);
  const footballRequestRef = useRef(0);

  const refreshFootball = useCallback(({ force = false } = {}) => {
    const requestId = footballRequestRef.current + 1;
    footballRequestRef.current = requestId;
    setScoreStatus((current) => ({ ...current, loading: true, error: '' }));

    Promise.all([
      getFootballLive({ force }),
      getFootballUpcoming({ next: 30, force }),
      getFootballFinished({ limit: 30, force }),
      getFootballFixtures({ date: selectedDate, force }),
    ])
      .then(([live, upcoming, finished, selectedDay]) => {
        if (requestId !== footballRequestRef.current) {
          return;
        }

        setLivePayload(live);
        setUpcomingPayload(upcoming);
        setFinishedPayload(finished);
        setDatePayload(selectedDay);
        setScoreStatus({ loading: false, error: '', lastRefresh: new Date() });
      })
      .catch((error) => {
        if (requestId !== footballRequestRef.current) {
          return;
        }

        setScoreStatus({ loading: false, error: error.message, lastRefresh: new Date() });
      });
  }, [selectedDate]);

  const footballPollDelay = Math.max(10000, Math.min(120000, Number(livePayload.poll_after || 15) * 1000));
  useLiveInterval(refreshFootball, footballPollDelay);

  useEffect(() => {
    let cancelled = false;

    searchRifnote({ query: '', category: 'Football', sort: 'latest', perPage: 6 })
      .then((payload) => {
        if (!cancelled) {
          setFootballStories(payload.results || []);
        }
      })
      .catch(() => {
        if (!cancelled) {
          setFootballStories([]);
        }
      });

    return () => {
      cancelled = true;
    };
  }, []);

  useEffect(() => {
    setActiveCompetition('all');
    setSelectedFixture(null);
    setMatchDetailOpen(false);
  }, [activeBoard, selectedDate]);

  const dateFixtures = useMemo(
    () => (datePayload.fixtures ?? []).filter((fixture) => isFixtureOnInputDate(fixture, selectedDate)),
    [datePayload.fixtures, selectedDate],
  );
  const liveFixtures = useMemo(
    () => mergeFixtures([...(livePayload.fixtures ?? []).filter((fixture) => isFixtureOnInputDate(fixture, selectedDate) && isFixtureLiveNow(fixture)), ...dateFixtures.filter(isFixtureLiveNow)]),
    [dateFixtures, livePayload.fixtures, selectedDate],
  );
  const upcomingFixtures = useMemo(
    () => mergeFixtures([...(upcomingPayload.fixtures ?? []).filter((fixture) => isFixtureOnInputDate(fixture, selectedDate) && isFixtureUpcoming(fixture)), ...dateFixtures.filter(isFixtureUpcoming)]),
    [dateFixtures, upcomingPayload.fixtures, selectedDate],
  );
  const finishedFixtures = useMemo(
    () => mergeFixtures([...(finishedPayload.fixtures ?? []).filter((fixture) => isFixtureOnInputDate(fixture, selectedDate) && isFixtureFinishedResult(fixture)), ...dateFixtures.filter(isFixtureFinishedResult)]),
    [dateFixtures, finishedPayload.fixtures, selectedDate],
  );
  const allFixtures = useMemo(() => mergeFixtures([...liveFixtures, ...upcomingFixtures, ...finishedFixtures]), [liveFixtures, upcomingFixtures, finishedFixtures]);
  const nearestFixture = useMemo(() => getNearestFixture(upcomingFixtures), [upcomingFixtures]);

  useEffect(() => {
    if (scoreStatus.loading || activeBoard !== 'live' || liveFixtures.length) {
      return;
    }

    if (upcomingFixtures.length) {
      setActiveBoard('upcoming');
      return;
    }

    if (finishedFixtures.length) {
      setActiveBoard('finished');
    }
  }, [activeBoard, finishedFixtures.length, liveFixtures.length, scoreStatus.loading, upcomingFixtures.length]);

  useEffect(() => {
    const target = initialFixtureParam.current;

    if (!target || !allFixtures.length) {
      return;
    }

    const fixture = allFixtures.find((item) => getFixtureDeepLinkId(item) === target || getFixtureKey(item) === target);

    if (!fixture) {
      return;
    }

    setActiveBoard(getFixtureBoardKey(fixture));
    setActiveCompetition('all');
    setSelectedFixture(fixture);
    setMatchDetailOpen(true);
    initialFixtureParam.current = '';
  }, [allFixtures]);

  useEffect(() => {
    if (!modalFixture) {
      return undefined;
    }

    const closeOnEscape = (event) => {
      if (event.key === 'Escape') {
        setModalFixture(null);
      }
    };

    window.addEventListener('keydown', closeOnEscape);
    return () => window.removeEventListener('keydown', closeOnEscape);
  }, [modalFixture]);

  const boardFixtures = activeBoard === 'live' ? liveFixtures : activeBoard === 'upcoming' ? upcomingFixtures : activeBoard === 'finished' ? finishedFixtures : allFixtures;
  const competitionTabs = useMemo(() => getCompetitionTabs(boardFixtures), [boardFixtures]);
  const filteredBoardFixtures = activeCompetition === 'all'
    ? boardFixtures
    : boardFixtures.filter((fixture) => getCompetitionKey(fixture) === activeCompetition);
  const hasAnyFixtures = allFixtures.length > 0;
  const focusedFixture = filteredBoardFixtures.find((fixture) => getFixtureKey(fixture) === getFixtureKey(selectedFixture))
    || filteredBoardFixtures[0]
    || boardFixtures[0]
    || nearestFixture
    || allFixtures[0]
    || null;
  const footballConfigured = !!(datePayload.configured || livePayload.configured || upcomingPayload.configured || finishedPayload.configured);
  const footballStatusMessage = scoreStatus.error || datePayload.message || livePayload.message || upcomingPayload.message || (footballConfigured ? 'Saved fixtures' : 'Setup needed');
  const totalLive = liveFixtures.length;
  const totalUpcoming = upcomingFixtures.length;
  const totalFinished = finishedFixtures.length;
  const handleFixtureFocus = useCallback((fixture) => {
    setSelectedFixture(fixture);
    setMatchDetailOpen(true);
  }, []);

  return (
    <main className={`rs-shell compact-page rs-football-page rs-pitchside-page ${matchDetailOpen ? 'is-match-detail-open' : ''}`}>
      <section className="rs-pitchside-app">
        <div className="rs-pitchside-controls">
          <FootballDateNav selectedDate={selectedDate} onChange={setSelectedDate} />
          <div className="rs-pitchside-health">
            <span className={scoreStatus.loading ? 'is-loading' : ''}><i />{footballConfigured ? 'Live data' : 'Setup'}</span>
            <small>{footballStatusMessage}</small>
            {scoreStatus.lastRefresh ? <small>{scoreStatus.lastRefresh.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}</small> : null}
            <button type="button" onClick={() => refreshFootball({ force: true })}>Sync</button>
          </div>
        </div>

        <nav className="rs-pitchside-datebar" aria-label="Match filters">
          {[
            ['live', 'Live', totalLive],
            ['upcoming', 'Upcoming', totalUpcoming],
            ['finished', 'Finished', totalFinished],
            ['all', 'All', allFixtures.length],
          ].map(([key, label, count]) => (
            <button className={activeBoard === key ? 'active' : ''} type="button" key={key} onClick={() => setActiveBoard(key)}>
              {label}<b>{count}</b>
            </button>
          ))}
        </nav>

        <CompetitionTabs activeCompetition={activeCompetition} fixturesCount={boardFixtures.length} tabs={competitionTabs} onSelect={setActiveCompetition} />

        {hasAnyFixtures || scoreStatus.loading ? (
          <div className="rs-pitchside-layout">
            <FootballPitchsideList
              fixtures={filteredBoardFixtures}
              loading={scoreStatus.loading}
              mode={activeBoard}
              nearestFixture={nearestFixture}
              focusedFixture={focusedFixture}
              onFocus={handleFixtureFocus}
            />
            <FootballPitchsideDetail fixture={focusedFixture} loading={scoreStatus.loading} configured={footballConfigured} onBack={() => setMatchDetailOpen(false)} />
          </div>
        ) : (
          <FootballPitchsideNoMatches
            stories={footballStories}
            message={scoreStatus.error || 'No matches in this view right now.'}
          />
        )}
      </section>
      <MatchDetailsModal fixture={modalFixture} onClose={() => setModalFixture(null)} />
    </main>
  );
}

function FootballStandingsPanel({ payload = {}, loading = false, error = '', maxGroups = 4, maxRows = 18 }) {
  const groups = Array.isArray(payload.groups) ? payload.groups : [];

  if (loading && !groups.length) {
    return (
      <section className="rs-football-standings is-loading" aria-label="Competition table">
        <header>
          <h2>Table</h2>
          <span>Loading</span>
        </header>
      </section>
    );
  }

  if (!groups.length) {
    if (!error && !payload.message) {
      return null;
    }

    return (
      <section className="rs-football-standings is-empty" aria-label="Competition table">
        <header>
          <h2>Table</h2>
          <span>Not available</span>
        </header>
        <p>{error || payload.message || 'No league or cup table is stored for this competition yet.'}</p>
      </section>
    );
  }

  return (
    <section className="rs-football-standings" aria-label="Competition table">
      <header>
        <h2>{payload.league?.name || 'Competition table'}</h2>
        <span>{payload.season || payload.league?.season || ''}</span>
      </header>
      <div className="rs-standings-groups">
        {groups.slice(0, maxGroups).map((group) => (
          <div className="rs-standings-group" key={group.name || 'table'}>
            {group.name && group.name !== 'default' ? <h3>{group.name}</h3> : null}
            <table className="rs-standings-table">
              <thead>
                <tr>
                  <th>#</th>
                  <th>Team</th>
                  <th>P</th>
                  <th>W</th>
                  <th>D</th>
                  <th>L</th>
                  <th>GD</th>
                  <th>Pts</th>
                </tr>
              </thead>
              <tbody>
                {(group.rows || []).slice(0, maxRows).map((row) => (
                  <tr key={`${group.name}-${row.team?.id || row.team?.name}`}>
                    <td>{row.rank}</td>
                    <td>
                      {row.team?.logo ? <img src={row.team.logo} alt="" loading="lazy" /> : null}
                      <span>{shortTeamName(row.team?.name || 'Team', 24)}</span>
                      {row.form ? <small>{row.form}</small> : null}
                    </td>
                    <td>{row.played ?? '-'}</td>
                    <td>{row.win ?? '-'}</td>
                    <td>{row.draw ?? '-'}</td>
                    <td>{row.lose ?? '-'}</td>
                    <td>{row.goals_diff ?? '-'}</td>
                    <td><b>{row.points ?? '-'}</b></td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        ))}
      </div>
    </section>
  );
}

function FootballCompetitionsPage() {
  const initialParams = useMemo(() => new URLSearchParams(window.location.search), []);
  const [selection, setSelection] = useState({
    league: initialParams.get('league') || '',
    season: initialParams.get('season') || '',
  });
  const [payload, setPayload] = useState({ competitions: [], standings: { groups: [] }, top_scorers: { players: [] } });
  const [status, setStatus] = useState({ loading: true, error: '' });

  useEffect(() => {
    let cancelled = false;
    setStatus({ loading: true, error: '' });

    getFootballCompetition(selection)
      .then((nextPayload) => {
        if (cancelled) {
          return;
        }

        setPayload(nextPayload || { competitions: [], standings: { groups: [] }, top_scorers: { players: [] } });
        setStatus({ loading: false, error: '' });
      })
      .catch((error) => {
        if (!cancelled) {
          setPayload({ competitions: [], standings: { groups: [] }, top_scorers: { players: [] } });
          setStatus({ loading: false, error: error.message || 'Competition data unavailable.' });
        }
      });

    return () => {
      cancelled = true;
    };
  }, [selection.league, selection.season]);

  const competitions = Array.isArray(payload.competitions) ? payload.competitions : [];
  const league = payload.league || payload.standings?.league || payload.top_scorers?.league || {};
  const selectedKey = `${selection.league || league.id || ''}:${selection.season || league.season || ''}`;

  const handleCompetitionChange = (event) => {
    const [leagueId, season] = String(event.target.value || '').split(':');
    const nextSelection = { league: leagueId || '', season: season || '' };
    const nextUrl = footballCompetitionUrl(nextSelection.league, nextSelection.season);
    window.history.replaceState({}, '', nextUrl);
    setSelection(nextSelection);
  };

  return (
    <main className="rs-shell compact-page rs-competition-page">
      <section className="rs-competition-hero">
        <div>
          <Badge>Competition room</Badge>
          <h1>{league?.name || 'Tables and scorers'}</h1>
          <p>League tables, cup group tables, and the players cooking in front of goal.</p>
        </div>
        <label>
          <span>Competition</span>
          <select value={selectedKey} onChange={handleCompetitionChange}>
            {competitions.length ? competitions.map((competition) => (
              <option key={`${competition.league_id}:${competition.season}`} value={`${competition.league_id}:${competition.season}`}>
                {competition.label || `League ${competition.league_id}`} · {competition.season}
              </option>
            )) : (
              <option value={selectedKey}>{league?.name || 'Configured competition'}</option>
            )}
          </select>
        </label>
      </section>
      {status.error ? <p className="rs-competition-alert">{status.error}</p> : null}
      <section className="rs-competition-grid">
        <FootballStandingsPanel payload={payload.standings || payload} loading={status.loading} error={status.error} maxGroups={12} maxRows={30} />
        <FootballTopScorersPanel payload={payload.top_scorers} loading={status.loading} />
      </section>
    </main>
  );
}

function FootballTopScorersPanel({ payload = {}, loading = false }) {
  const players = Array.isArray(payload?.players) ? payload.players : [];

  if (loading && !players.length) {
    return (
      <section className="rs-football-scorers is-loading" aria-label="Top scorers">
        <header>
          <h2>Top scorers</h2>
          <span>Loading</span>
        </header>
      </section>
    );
  }

  if (!players.length) {
    return (
      <section className="rs-football-scorers is-empty" aria-label="Top scorers">
        <header>
          <h2>Top scorers</h2>
          <span>Not available</span>
        </header>
        <p>{payload?.message || 'No scorers are stored for this competition yet.'}</p>
      </section>
    );
  }

  return (
    <section className="rs-football-scorers" aria-label="Top scorers">
      <header>
        <h2>Top scorers</h2>
        <span>{payload.league?.season || ''}</span>
      </header>
      <div className="rs-scorers-list">
        {players.slice(0, 20).map((row, index) => (
          <article key={`${row.player?.id || row.player?.name}-${row.team?.id || row.team?.name}`}>
            <b>{index + 1}</b>
            {row.player?.photo ? <img src={row.player.photo} alt="" loading="lazy" /> : <UserRound size={22} />}
            <div>
              <strong>{row.player?.name || 'Player'}</strong>
              <span>{row.team?.logo ? <img src={row.team.logo} alt="" loading="lazy" /> : null}{row.team?.name || 'Team'}</span>
            </div>
            <em>{row.goals ?? 0}</em>
            <small>{row.assists ?? 0} ast · {row.appearances ?? 0} app</small>
          </article>
        ))}
      </div>
    </section>
  );
}

function mergeFixtures(fixtures = []) {
  const map = new globalThis.Map();

  fixtures.forEach((fixture) => {
    const key = fixture.id || `${fixture.home?.name}-${fixture.away?.name}-${fixture.date}`;
    if (!map.has(key)) {
      map.set(key, fixture);
    }
  });

  return Array.from(map.values()).sort((a, b) => (a.timestamp || Date.parse(a.date) / 1000 || 0) - (b.timestamp || Date.parse(b.date) / 1000 || 0));
}

function getNearestFixture(fixtures = []) {
  const now = Date.now() / 1000;

  return [...fixtures]
    .filter((fixture) => (fixture.timestamp || Date.parse(fixture.date) / 1000 || 0) >= now)
    .sort((a, b) => (a.timestamp || 0) - (b.timestamp || 0))[0] || null;
}

function fixtureDateInput(fixture = {}) {
  if (!fixture?.date && !fixture?.timestamp) {
    return '';
  }

  return formatDateInput(fixture.date || Number(fixture.timestamp) * 1000);
}

function getInitialFootballFixtureParam() {
  try {
    const params = new URLSearchParams(window.location.search);
    return params.get('fixture') || params.get('fixture_id') || params.get('match') || '';
  } catch (error) {
    return '';
  }
}

function getInitialFootballDate() {
  try {
    const params = new URLSearchParams(window.location.search);
    const date = params.get('date') || '';

    if (/^\d{4}-\d{2}-\d{2}$/.test(date)) {
      return date;
    }
  } catch (error) {
    // Keep the football page usable if the browser blocks URL parsing.
  }

  return formatDateInput(new Date());
}

function getFixtureDeepLinkId(fixture = {}) {
  return String(fixture.fixture_id || fixture.id || fixture.fixture?.id || '');
}

function getFixtureBoardKey(fixture = {}) {
  const status = String(fixture.status_short || '').toUpperCase();

  if (isFixtureFinishedResult(fixture)) {
    return 'finished';
  }

  if (isFixtureUpcoming(fixture)) {
    return 'upcoming';
  }

  return isFixtureLiveNow(fixture) ? 'live' : 'all';
}

function isFixtureOnInputDate(fixture = {}, selectedDate = '') {
  return !selectedDate || fixtureDateInput(fixture) === selectedDate;
}

function getCompetitionKey(fixture = {}) {
  if (fixture.league?.id) {
    return `league-${fixture.league.id}`;
  }

  return String(fixture.watchlist_label || fixture.league?.name || fixture.league?.country || 'football')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-|-$/g, '') || 'football';
}

function getFootballRoundLabel(fixture = {}) {
  const clean = String(fixture?.league?.round_clean || fixture?.round_clean || '').trim();

  if (clean) {
    return clean;
  }

  let round = String(fixture?.league?.round || fixture?.round || '').trim();
  const league = String(fixture?.league?.name || fixture?.watchlist_label || '').trim();

  if (league) {
    round = round.replace(new RegExp(`^${escapeRegExp(league)}\\s*[-–—]\\s*`, 'i'), '').trim();
  }

  if (!round || /^regular\s+season(?:\s*[-–—]\s*\d+)?$/i.test(round)) {
    return '';
  }

  return round.replace(/\s*[-–—]\s*regular\s+season(?:\s*[-–—]\s*\d+)?/ig, '').trim();
}

function escapeRegExp(value = '') {
  return String(value).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

function getFootballCompetitionLabel(fixture = {}, options = {}) {
  const includeRound = options.includeRound !== false;
  const league = fixture?.league?.name || fixture?.watchlist_label || 'Football';
  const round = includeRound ? getFootballRoundLabel(fixture) : '';

  return [league, round].filter(Boolean).join(' · ');
}

function getFixtureStatusMarker(fixture = {}) {
  const status = String(fixture.status_short || fixture.fixture?.status?.short || '').toUpperCase();

  if (['PEN', 'P'].includes(status)) return 'PK';
  if (status === 'AET') return 'AET';
  if (['ET', 'BT'].includes(status)) return 'ET';
  if (['HT', 'FT'].includes(status)) return status;

  return '';
}

function getLiveSourceLabel(value = 'Live') {
  const label = String(value || 'Live').trim();
  return /api/i.test(label) ? 'Live' : label;
}

function getFixtureMarkers(fixture = {}, details = {}) {
  const rows = [
    ...(Array.isArray(details.markers) ? details.markers : []),
    ...(Array.isArray(fixture.markers) ? fixture.markers : []),
  ];
  const statusMarker = getFixtureStatusMarker(fixture);

  if (statusMarker && !rows.some((row) => String(row.kind || '').toLowerCase() === 'status' && String(row.label || '').toUpperCase() === statusMarker)) {
    rows.push({ kind: 'status', label: statusMarker, red: true, minute: fixture.elapsed || '' });
  }

  const seen = new Set();

  return rows
    .map((row) => ({
      kind: String(row.kind || row.type || 'marker').toLowerCase(),
      label: String(row.label || row.detail || row.type || '').trim(),
      minute: row.minute ?? row.elapsed ?? '',
      extra: row.extra ?? '',
      team: row.team?.name || row.team || '',
      player: row.player?.name || row.player || '',
      red: row.red !== false,
    }))
    .filter((row) => row.label || row.kind)
    .filter((row) => {
      const key = `${row.kind}:${row.label}:${row.minute}:${row.team}:${row.player}`;
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    })
    .sort((a, b) => Number(a.minute || 999) - Number(b.minute || 999))
    .slice(0, 8);
}

function MatchMarkers({ fixture = {}, details = {}, compact = false }) {
  const markers = getFixtureMarkers(fixture, details);

  if (!markers.length) {
    return null;
  }

  return (
    <div className={`rs-match-markers ${compact ? 'compact' : ''}`} aria-label="Match markers">
      {markers.map((marker, index) => {
        const minute = marker.minute ? `${marker.minute}${marker.extra ? `+${marker.extra}` : ''}'` : '';
        const kind = marker.kind === 'var' ? 'VAR' : marker.kind === 'goal' ? 'Goal' : marker.label.toUpperCase();
        const title = [minute, kind, marker.team, marker.player].filter(Boolean).join(' · ');

        return (
          <span className={`rs-match-marker is-${marker.kind} ${marker.red ? 'is-red' : ''}`} key={`${marker.kind}-${marker.label}-${marker.minute}-${index}`} title={title}>
            {marker.kind === 'goal' ? <Goal size={12} /> : marker.kind === 'var' ? <Radio size={12} /> : null}
            {minute ? <b>{minute}</b> : null}
            <em>{kind}</em>
            {!compact && marker.player ? <small>{marker.player}</small> : null}
          </span>
        );
      })}
    </div>
  );
}

function AggregateChip({ fixture = {}, compact = false }) {
  const aggregate = fixture.aggregate || {};
  const label = aggregate.label || (aggregate.home !== undefined && aggregate.away !== undefined ? `Agg ${aggregate.home}-${aggregate.away}` : '');
  const leg = fixture.leg_label || '';

  if (!label && !leg) {
    return null;
  }

  return (
    <div className={`rs-aggregate-row ${compact ? 'compact' : ''}`}>
      {leg ? <span>{leg}</span> : null}
      {label ? <strong>{label}</strong> : null}
    </div>
  );
}

function getCompetitionTabs(fixtures = []) {
  const competitions = new Map();

  fixtures.forEach((fixture) => {
    const key = getCompetitionKey(fixture);
    const existing = competitions.get(key);

    if (existing) {
      existing.count += 1;
      return;
    }

    competitions.set(key, {
      key,
      count: 1,
      logo: fixture.league?.logo || '',
      label: getFootballCompetitionLabel(fixture, { includeRound: false }),
      country: fixture.league?.country || '',
    });
  });

  return Array.from(competitions.values()).sort((a, b) => b.count - a.count || a.label.localeCompare(b.label));
}

function CompetitionTabs({ activeCompetition = 'all', fixturesCount = 0, tabs = [], onSelect }) {
  if (!tabs.length) {
    return null;
  }

  return (
    <div className="rs-competition-tabs" aria-label="Filter matches by competition">
      <button className={activeCompetition === 'all' ? 'active' : ''} type="button" onClick={() => onSelect('all')}>
        <span>All competitions</span>
        <b>{fixturesCount}</b>
      </button>
      {tabs.map((tab) => (
        <button className={activeCompetition === tab.key ? 'active' : ''} type="button" key={tab.key} onClick={() => onSelect(tab.key)}>
          {tab.logo ? <img src={tab.logo} alt="" loading="lazy" /> : null}
          <span>{tab.label}</span>
          <b>{tab.count}</b>
        </button>
      ))}
    </div>
  );
}

function getFixtureKey(fixture = {}) {
  if (!fixture) {
    return '';
  }

  return String(fixture.id || `${fixture.home?.name || 'home'}-${fixture.away?.name || 'away'}-${fixture.date || ''}`);
}

function getFixtureClock(fixture = {}) {
  const status = fixture.status_short || '';

  if (fixture.elapsed) {
    return `${fixture.elapsed}${fixture.extra ? `+${fixture.extra}` : ''}'`;
  }

  if (status === 'NS') {
    return formatCountdown(fixture.date) || formatTime(fixture.date);
  }

  return status || formatTime(fixture.date);
}

function getFixtureProgress(fixture = {}) {
  if (isFixtureFinishedResult(fixture)) {
    return 100;
  }

  if (!fixture.elapsed) {
    return 0;
  }

  return Math.max(4, Math.min(100, Math.round((Number(fixture.elapsed) / 90) * 100)));
}

function isFixtureLiveNow(fixture = {}) {
  const status = String(fixture.status_short || fixture.fixture?.status?.short || '').toUpperCase();
  return ['1H', 'HT', '2H', 'ET', 'BT', 'P', 'SUSP', 'INT', 'LIVE'].includes(status);
}

function isFixtureUpcoming(fixture = {}) {
  const status = String(fixture.status_short || fixture.fixture?.status?.short || '').toUpperCase();
  return ['NS', 'TBD'].includes(status);
}

function isFixtureFinishedResult(fixture = {}) {
  const status = String(fixture.status_short || fixture.fixture?.status?.short || '').toUpperCase();
  return ['FT', 'AET', 'PEN'].includes(status);
}

function isFixtureStaleOrCancelled(fixture = {}) {
  const status = String(fixture.status_short || fixture.fixture?.status?.short || '').toUpperCase();
  return ['PST', 'CANC', 'ABD', 'AWD', 'WO'].includes(status);
}

function FootballPitchsideList({ fixtures = [], loading = false, mode = 'live', nearestFixture = null, focusedFixture = null, onFocus }) {
  if (loading && !fixtures.length) {
    return (
      <section className="rs-pitchside-list" aria-label="Match list">
        {[0, 1, 2, 3].map((row) => <div className="rs-pitchside-row is-loading" key={row} />)}
      </section>
    );
  }

  const list = fixtures.length ? fixtures : (nearestFixture ? [nearestFixture] : []);
  const grouped = groupFixturesByCompetition(list);

  return (
    <section className="rs-pitchside-list" aria-label="Match list">
      {list.length ? grouped.map((group) => (
        <div className="rs-pitchside-comp" key={group.key}>
          <div className="rs-pitchside-comp-name">
            {group.logo ? <img src={group.logo} alt="" loading="lazy" /> : <Trophy size={16} />}
            <span>{group.label}</span>
            <b>{group.fixtures.length}</b>
          </div>
          {group.fixtures.map((fixture) => (
            <FootballPitchsideRow
              fixture={fixture}
              focused={getFixtureKey(fixture) === getFixtureKey(focusedFixture)}
              key={getFixtureKey(fixture)}
              onFocus={onFocus}
            />
          ))}
        </div>
      )) : (
        <div className="rs-pitchside-empty">
          <Radio size={20} />
          <strong>{mode === 'live' ? 'No live games right now.' : 'No fixtures saved here yet.'}</strong>
          <span>{mode === 'live' ? 'Upcoming matches appear when they are inside the 24-hour window.' : 'Check your tracked competition IDs and season in Football settings.'}</span>
        </div>
      )}
      {nearestFixture && !fixtures.length ? <button className="rs-pitchside-open" type="button" onClick={() => onFocus?.(nearestFixture)}>Open nearest match</button> : null}
    </section>
  );
}

function groupFixturesByCompetition(fixtures = []) {
  const groups = new Map();

  fixtures.forEach((fixture) => {
    const key = getCompetitionKey(fixture);
    if (!groups.has(key)) {
      groups.set(key, {
        key,
        label: getFootballCompetitionLabel(fixture, { includeRound: false }),
        logo: fixture.league?.logo || '',
        fixtures: [],
      });
    }
    groups.get(key).fixtures.push(fixture);
  });

  return Array.from(groups.values());
}

function FootballPitchsideRow({ fixture, focused = false, onFocus }) {
  const clock = getFixtureClock(fixture);
  const progress = getFixtureProgress(fixture);
  const live = isFixtureLiveNow(fixture);

  return (
    <button className={`rs-pitchside-row ${focused ? 'selected' : ''}`} type="button" onClick={() => onFocus?.(fixture)}>
      <span className={`rs-pitchside-status ${live ? 'live' : ''}`}>{live ? <i /> : null}{clock}</span>
      <div className="rs-pitchside-teams">
        <FootballPitchsideTeam team={fixture.home} goals={fixture.goals?.home} />
        <FootballPitchsideTeam team={fixture.away} goals={fixture.goals?.away} dim={!live && fixture.status_short === 'NS'} />
      </div>
      <div className={`rs-pitchside-progress ${live ? 'is-live' : ''}`}>
        <span style={{ width: `${progress}%` }} />
        <i />
      </div>
      <MatchMarkers fixture={fixture} compact />
      <AggregateChip fixture={fixture} compact />
    </button>
  );
}

function FootballPitchsideTeam({ team = {}, goals = '-', dim = false }) {
  return (
    <span className={`rs-pitchside-team ${dim ? 'is-dim' : ''}`}>
      {team.logo ? <img src={team.logo} alt="" loading="lazy" /> : <i>{shortTeamName(team.name || 'Team').slice(0, 2).toUpperCase()}</i>}
      <b>{team.name || 'Team'}</b>
      <strong>{goals ?? '-'}</strong>
    </span>
  );
}

function FootballPitchsideDetail({ fixture, loading = false, configured = false, onBack }) {
  const [activeTab, setActiveTab] = useState('summary');
  const [detailsPayload, setDetailsPayload] = useState(null);
  const [detailsStatus, setDetailsStatus] = useState({ loading: false, error: '' });
  const [relatedStories, setRelatedStories] = useState([]);

  useEffect(() => {
    if (!fixture?.id) {
      setDetailsPayload(null);
      setRelatedStories([]);
      setDetailsStatus({ loading: false, error: '' });
      return undefined;
    }

    let cancelled = false;
    setActiveTab('summary');
    setDetailsStatus({ loading: true, error: '' });
    setRelatedStories([]);

    Promise.allSettled([
      getFootballFixtureDetails(fixture.id),
      searchRifnote({ query: `${fixture.home?.name || ''} ${fixture.away?.name || ''}`.trim(), category: 'Football', sort: 'latest', perPage: 5 }),
    ])
      .then(([detailsResult, storiesResult]) => {
        if (cancelled) {
          return;
        }

        if (detailsResult.status === 'fulfilled') {
          setDetailsPayload(detailsResult.value);
          setDetailsStatus({ loading: false, error: '' });
        } else {
          setDetailsPayload(null);
          setDetailsStatus({ loading: false, error: detailsResult.reason?.message || 'Match details unavailable.' });
        }

        if (storiesResult.status === 'fulfilled') {
          setRelatedStories(storiesResult.value?.results || []);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [fixture?.id, fixture?.home?.name, fixture?.away?.name]);

  if (loading && !fixture) {
    return <section className="rs-pitchside-detail is-loading" aria-label="Selected match"><div /></section>;
  }

  if (!fixture) {
    return (
      <section className="rs-pitchside-detail empty" aria-label="Selected match">
        <Goal size={42} />
        <h2>{configured ? 'Nothing scheduled in this view.' : 'Football setup needed.'}</h2>
        <p>{configured ? 'Switch tabs or sync the saved fixtures.' : 'Add your football data credentials, season, and league/cup IDs in Football settings.'}</p>
      </section>
    );
  }

  const live = isFixtureLiveNow(fixture);
  const clock = getFixtureClock(fixture);
  const progress = getFixtureProgress(fixture);
  const venue = [fixture.venue?.name, fixture.venue?.city].filter(Boolean).join(' · ');
  const detailedFixture = detailsPayload?.fixture || fixture;
  const details = detailsPayload?.details || {};

  return (
    <section className="rs-pitchside-detail" aria-label="Selected match">
      <button className="rs-pitchside-mobile-back" type="button" onClick={onBack}>
        <ArrowLeft size={18} />
        Matches
      </button>
      <header>
        <span>{getFootballCompetitionLabel(fixture)}</span>
        <b>{venue || formatDate(fixture.date)}</b>
      </header>
      <div className="rs-pitchside-scoreboard">
        <FootballPitchsideSide team={fixture.home} />
        <div>
          <strong>{fixture.goals?.home ?? '-'}<em>–</em>{fixture.goals?.away ?? '-'}</strong>
          <span className={live ? 'is-live' : ''}>{live ? <i /> : null}{clock}</span>
        </div>
        <FootballPitchsideSide team={fixture.away} />
      </div>
      <div className={`rs-pitchside-progress detail ${live ? 'is-live' : ''}`}>
        <span style={{ width: `${progress}%` }} />
        <i />
      </div>
      <MatchMarkers fixture={detailedFixture} details={details} />
      <AggregateChip fixture={detailedFixture} />
      <div className="rs-pitchside-detail-meta">
        <span><CalendarDays size={15} />{formatDate(fixture.date)}</span>
        <span><Shield size={15} />{fixture.referee || 'Referee TBC'}</span>
      </div>
      {fixture.league?.id && fixture.league?.season ? (
        <a className="rs-competition-room-link" href={footballCompetitionUrl(fixture.league.id, fixture.league.season)}>
          View table and top scorers
          <ArrowRight size={16} />
        </a>
      ) : null}
      <div className="rs-pitchside-inline-room">
        <MatchDetailsSections
          activeTab={activeTab}
          details={details}
          error={detailsStatus.error || detailsPayload?.message || ''}
          fixture={detailedFixture}
          loading={detailsStatus.loading}
          onTabChange={setActiveTab}
          stories={relatedStories}
        />
      </div>
    </section>
  );
}

function FootballPitchsideSide({ team = {} }) {
  return (
    <div className="rs-pitchside-side">
      {team.logo ? <img src={team.logo} alt="" loading="lazy" /> : <i>{shortTeamName(team.name || 'Team').slice(0, 2).toUpperCase()}</i>}
      <strong>{team.name || 'Team'}</strong>
    </div>
  );
}

function FootballPitchsideNoMatches({ stories = [], message = '' }) {
  return (
    <section className="rs-pitchside-no-matches stories-only">
      <aside>
        <strong>Football News</strong>
        {stories.length ? (
          <ul className="rs-pitchside-story-list">
            {stories.slice(0, 6).map((story) => (
              <li key={story.id || story.headline}>
                <SourceLogo story={story} />
                <a href={story.read_full_story_url || story.original_url || story.permalink} target="_blank" rel="noreferrer" onClick={() => trackStoryClick(story, 'read_full_story_click', 'Football page fallback')}>{decodeText(story.headline)}</a>
                <small>{story.published_at_human || formatDate(story.published_at)}</small>
              </li>
            ))}
          </ul>
        ) : <p>{message || 'No football stories posted yet.'}</p>}
      </aside>
    </section>
  );
}

function FeaturedMatchCard({ fixture, configured = false, loading = false, onSelect }) {
  if (loading && !fixture) {
    return <Card className="rs-featured-match rs-skeleton"><CardHeader title="Match focus" action={<Badge>Loading</Badge>} /><p>Refreshing scores.</p></Card>;
  }

  if (!fixture) {
    return (
      <Card className="rs-featured-match empty">
        <CardHeader title="Nothing on the board yet" action={<Badge>{configured ? '24h' : 'Setup'}</Badge>} />
        <div className="rs-featured-empty">
          <Goal size={40} />
          <h2>{configured ? 'No live or upcoming games right now.' : 'Football setup needed.'}</h2>
          <p>{configured ? 'The next saved kickoff will show here.' : 'Add football data credentials and competition IDs in Football settings.'}</p>
        </div>
      </Card>
    );
  }

  const status = fixture.status_short || '';
  const isLive = !['FT', 'AET', 'PEN', 'NS', 'PST', 'CANC'].includes(status);
  const clock = fixture.elapsed ? `${fixture.elapsed}${fixture.extra ? `+${fixture.extra}` : ''}'` : status === 'NS' ? (formatCountdown(fixture.date) || formatTime(fixture.date)) : (status || 'TBD');
  const title = isLive ? 'Live now' : status === 'FT' ? 'Latest result' : 'Next match';

  return (
    <Card className={`rs-featured-match ${isLive ? 'is-live' : ''}`}>
      <CardHeader title={title} action={<Badge tone={isLive ? 'danger' : ''}>{isLive ? 'Live' : clock}</Badge>} />
      <div className="rs-featured-match-league">
        {fixture.league?.logo ? <img src={fixture.league.logo} alt="" loading="lazy" /> : <Trophy size={20} />}
        <span>{getFootballCompetitionLabel(fixture)}</span>
      </div>
      <div className="rs-featured-scoreline">
        <FeaturedTeam team={fixture.home} />
        <div>
          <strong>{fixture.goals?.home ?? '-'} - {fixture.goals?.away ?? '-'}</strong>
          <Badge>{clock}</Badge>
        </div>
        <FeaturedTeam team={fixture.away} align="right" />
      </div>
      <div className="rs-featured-match-meta">
        <span><CalendarDays size={15} /> {formatDate(fixture.date)}</span>
        <span><Clock3 size={15} /> {formatTime(fixture.date)}</span>
        <span><MapIcon size={15} /> {[fixture.venue?.name, fixture.venue?.city].filter(Boolean).join(' · ') || 'Venue TBC'}</span>
      </div>
      <button className="rs-button primary" type="button" onClick={() => onSelect?.(fixture)}>Open match room <ArrowRight size={16} /></button>
    </Card>
  );
}

function FeaturedTeam({ team = {}, align = 'left' }) {
  return (
    <div className={`rs-featured-team ${align === 'right' ? 'is-right' : ''}`}>
      {team.logo ? <img src={team.logo} alt="" loading="lazy" /> : <span className="rs-team-dot" />}
      <strong>{team.name || 'Team'}</strong>
    </div>
  );
}

function FootballMatchTicker({ fixtures = [], loading = false, onSelect }) {
  if (loading && !fixtures.length) {
    return (
      <section className="rs-football-ticker" aria-label="Match ticker">
        {[0, 1, 2].map((item) => <div className="rs-ticker-match is-loading" key={item}><span /></div>)}
      </section>
    );
  }

  if (!fixtures.length) {
    return (
      <section className="rs-football-ticker empty" aria-label="Match ticker">
        <Radio size={18} />
        <span>No live or 24-hour games saved right now.</span>
      </section>
    );
  }

  return (
    <section className="rs-football-ticker" aria-label="Match ticker">
      {fixtures.map((fixture) => {
        const status = fixture.elapsed ? `${fixture.elapsed}${fixture.extra ? `+${fixture.extra}` : ''}'` : fixture.status_short === 'NS' ? (formatCountdown(fixture.date) || formatTime(fixture.date)) : (fixture.status_short || formatTime(fixture.date));
        return (
          <button type="button" className="rs-ticker-match" key={fixture.id || `${fixture.home?.name}-${fixture.away?.name}-${fixture.date}`} onClick={() => onSelect?.(fixture)}>
            <span>{fixture.league?.logo ? <img src={fixture.league.logo} alt="" loading="lazy" /> : <Trophy size={15} />}{fixture.league?.name || fixture.watchlist_label || 'Football'}</span>
            <strong>
              <small>{shortTeamName(fixture.home?.name || 'Home')}</small>
              <b>{fixture.goals?.home ?? '-'}</b>
              <em>{status}</em>
              <b>{fixture.goals?.away ?? '-'}</b>
              <small>{shortTeamName(fixture.away?.name || 'Away')}</small>
            </strong>
            <MatchMarkers fixture={fixture} compact />
            <AggregateChip fixture={fixture} compact />
          </button>
        );
      })}
    </section>
  );
}

function LiveScoreBoard({ fixtures = [], loading = false, mode = 'live', nearestFixture = null, onSelect }) {
  if (loading && !fixtures.length) {
    return <div className="rs-scoreboard-grid"><Card className="rs-skeleton"><p>Loading matches, fixtures, and competition tabs.</p></Card></div>;
  }

  if (!fixtures.length) {
    const emptyTitle = mode === 'live' ? 'No live games right now' : mode === 'finished' ? 'No results yet' : 'No fixtures here yet';
    const emptyMessage = mode === 'live'
      ? 'Live games will drop here once they kick off.'
      : mode === 'finished'
        ? 'Finished games from your tracked competitions will show here.'
        : 'No games in this slice. Check the competition setup if this feels wrong.';

    return (
      <Card className="rs-score-empty">
        <CardHeader title={emptyTitle} action={<Badge>{mode}</Badge>} />
        {nearestFixture ? (
          <div className="rs-score-empty-next">
            <strong>{nearestFixture.home?.name || 'Home'} vs {nearestFixture.away?.name || 'Away'}</strong>
            <span>Next kickoff in {formatCountdown(nearestFixture.date) || 'a moment'} at {formatTime(nearestFixture.date)}</span>
            <button className="rs-button primary" type="button" onClick={() => onSelect?.(nearestFixture)}>Match details</button>
          </div>
        ) : <p>{emptyMessage}</p>}
      </Card>
    );
  }

  return (
    <div className="rs-scoreboard-grid">
      {fixtures.map((fixture) => <LiveMatchCard fixture={fixture} key={fixture.id || `${fixture.home?.name}-${fixture.away?.name}-${fixture.date}`} onSelect={onSelect} />)}
    </div>
  );
}

function LiveMatchCard({ fixture, onSelect }) {
  const status = fixture.status_short || fixture.status_long || '';
  const isLive = !['FT', 'AET', 'PEN', 'NS', 'PST', 'CANC'].includes(status);
  const homeGoals = fixture.goals?.home ?? '-';
  const awayGoals = fixture.goals?.away ?? '-';
  const venue = [fixture.venue?.name, fixture.venue?.city].filter(Boolean).join(' · ');
  const countdown = status === 'NS' ? formatCountdown(fixture.date) : '';
  const matchClock = fixture.elapsed ? `${fixture.elapsed}${fixture.extra ? `+${fixture.extra}` : ''}'` : (countdown || status || 'TBD');

  return (
    <article className={`rs-live-match ${isLive ? 'is-live' : ''}`}>
      <div className="rs-live-match-league">
        <span>{fixture.league?.logo ? <img src={fixture.league.logo} alt="" loading="lazy" /> : null}{fixture.league?.name || 'Football'}</span>
        <small>{getFootballRoundLabel(fixture) || fixture.league?.country || ''}</small>
      </div>
      <div className="rs-live-match-teams">
        <TeamLine team={fixture.home} goals={homeGoals} />
        <TeamLine team={fixture.away} goals={awayGoals} />
      </div>
      <div className="rs-live-match-status">
        <Badge tone={isLive ? 'danger' : ''}>{matchClock}</Badge>
        <small>{status === 'NS' ? `Kickoff ${formatTime(fixture.date)}` : (fixture.status_long || formatDate(fixture.date))}</small>
      </div>
      <MatchMarkers fixture={fixture} compact />
      <AggregateChip fixture={fixture} compact />
      <div className="rs-live-match-details">
        <span>{venue || formatDate(fixture.date)}</span>
        {fixture.referee ? <span>Ref: {fixture.referee}</span> : null}
        {fixture.league?.country ? <span>{fixture.league.country}</span> : null}
        <button className="rs-match-details-button" type="button" onClick={() => onSelect?.(fixture)}>Match details</button>
      </div>
    </article>
  );
}

function TeamLine({ team = {}, goals = '-' }) {
  return (
    <div>
      {team.logo ? <img src={team.logo} alt="" loading="lazy" /> : <span className="rs-team-dot" />}
      <strong>{team.name || 'Team'}</strong>
      <b>{goals}</b>
    </div>
  );
}

function MatchDetailsModal({ fixture, onClose }) {
  const [activeTab, setActiveTab] = useState('goals');
  const [detailsPayload, setDetailsPayload] = useState(null);
  const [detailsStatus, setDetailsStatus] = useState({ loading: false, error: '' });
  const [relatedStories, setRelatedStories] = useState([]);

  useEffect(() => {
    if (!fixture?.id) {
      setDetailsPayload(null);
      return undefined;
    }

    let cancelled = false;
    setActiveTab('summary');
    setDetailsStatus({ loading: true, error: '' });
    setRelatedStories([]);

    Promise.allSettled([
      getFootballFixtureDetails(fixture.id),
      searchRifnote({ query: `${fixture.home?.name || ''} ${fixture.away?.name || ''}`.trim(), category: 'Football', sort: 'latest', perPage: 5 }),
    ])
      .then(([detailsResult, storiesResult]) => {
        if (cancelled) {
          return;
        }

        if (detailsResult.status === 'fulfilled') {
          setDetailsPayload(detailsResult.value);
          setDetailsStatus({ loading: false, error: '' });
        } else {
          setDetailsPayload(null);
          setDetailsStatus({ loading: false, error: detailsResult.reason?.message || 'Match details unavailable.' });
        }

        if (storiesResult.status === 'fulfilled') {
          setRelatedStories(storiesResult.value?.results || []);
        }
      });

    return () => {
      cancelled = true;
    };
  }, [fixture?.id]);

  if (!fixture) {
    return null;
  }

  const modalFixture = detailsPayload?.fixture || fixture;
  const details = detailsPayload?.details || {};
  const homeGoals = fixture.goals?.home ?? '-';
  const awayGoals = fixture.goals?.away ?? '-';
  const venue = [fixture.venue?.name, fixture.venue?.city].filter(Boolean).join(' · ');
  const status = fixture.elapsed ? `${fixture.elapsed}${fixture.extra ? `+${fixture.extra}` : ''}'` : fixture.status_long || fixture.status_short || 'Scheduled';
  const scoreRows = [
    ['Half time', fixture.score?.halftime?.home, fixture.score?.halftime?.away],
    ['Full time', fixture.score?.fulltime?.home, fixture.score?.fulltime?.away],
    ['Extra time', fixture.score?.extratime?.home, fixture.score?.extratime?.away],
    ['Penalties', fixture.score?.penalty?.home, fixture.score?.penalty?.away],
  ].filter((row) => row[1] !== null && row[1] !== undefined && row[2] !== null && row[2] !== undefined);

  const modal = (
    <div className="rs-match-modal-backdrop" role="presentation" onClick={onClose}>
      <section className="rs-match-modal" role="dialog" aria-modal="true" aria-label="Match details" onClick={(event) => event.stopPropagation()}>
        <button className="rs-match-modal-close" type="button" aria-label="Close match details" onClick={onClose}>×</button>
        <header className="rs-match-modal-head">
          {fixture.league?.logo ? <img src={fixture.league.logo} alt="" loading="lazy" /> : null}
          <div>
            <span>{fixture.league?.country || fixture.watchlist_label || 'Football'}</span>
            <h2>{fixture.league?.name || 'Match details'}</h2>
            <p>{getFootballRoundLabel(fixture) || formatDate(fixture.date)}</p>
          </div>
        </header>

        <div className="rs-match-modal-score">
          <MatchModalTeam team={modalFixture.home} />
          <strong>{homeGoals} - {awayGoals}</strong>
          <MatchModalTeam team={modalFixture.away} align="right" />
        </div>

        <MatchMarkers fixture={modalFixture} details={details} />
        <AggregateChip fixture={modalFixture} />

        <div className="rs-match-modal-meta">
          <span><b>Status</b>{status}</span>
          <span><b>Kickoff</b>{formatDate(fixture.date)}</span>
          <span><b>Venue</b>{venue || 'Venue TBC'}</span>
          <span><b>Referee</b>{fixture.referee || 'Not assigned'}</span>
        </div>

        {scoreRows.length ? (
          <div className="rs-match-modal-breakdown">
            {scoreRows.map(([label, home, away]) => (
              <div key={label}>
                <span>{label}</span>
                <strong>{home} - {away}</strong>
              </div>
            ))}
          </div>
        ) : null}

        <MatchDetailsSections
          activeTab={activeTab}
          details={details}
          error={detailsStatus.error || detailsPayload?.message || ''}
          fixture={modalFixture}
          loading={detailsStatus.loading}
          onTabChange={setActiveTab}
          stories={relatedStories}
        />
      </section>
    </div>
  );

  return createPortal(modal, document.body);
}

function MatchDetailsSections({ activeTab = 'summary', details = {}, fixture = {}, stories = [], loading = false, error = '', onTabChange }) {
  const tabs = [
    ['summary', 'Summary'],
    ['goals', 'Goal scorers'],
    ['stats', 'Stats'],
    ['timeline', 'Timeline'],
    ['h2h', 'H2H'],
    ['squads', 'Squad list'],
    ['news', 'News'],
  ];

  return (
    <>
      <div className="rs-match-tabs" role="tablist" aria-label="Match details sections">
        {tabs.map(([key, label]) => (
          <button className={activeTab === key ? 'active' : ''} type="button" role="tab" aria-selected={activeTab === key} key={key} onClick={() => onTabChange?.(key)}>
            {label}
          </button>
        ))}
      </div>

      <MatchDetailsTab
        activeTab={activeTab}
        details={details}
        fixture={fixture}
        stories={stories}
        loading={loading}
        error={error}
      />
    </>
  );
}

function MatchDetailsTab({ activeTab, details = {}, fixture = {}, stories = [], loading = false, error = '' }) {
  if (loading) {
    return <div className="rs-match-tab-panel"><p>Loading match details...</p></div>;
  }

  if (error) {
    return <div className="rs-match-tab-panel"><p>{error}</p></div>;
  }

  if (activeTab === 'summary') {
    return (
      <div className="rs-match-tab-panel rs-match-summary-panel">
        <article>
          <Flame size={18} />
          <div><strong>Match state</strong><span>{fixture.status_long || fixture.status_short || 'Scheduled'} · {fixture.league?.name || 'Football'}</span></div>
        </article>
        <article>
          <MapIcon size={18} />
          <div><strong>Venue</strong><span>{[fixture.venue?.name, fixture.venue?.city].filter(Boolean).join(' · ') || 'Venue TBC'}</span></div>
        </article>
        <article>
          <Newspaper size={18} />
          <div><strong>Related coverage</strong><span>{stories.length ? `${stories.length} indexed football stories found` : 'No related stories indexed yet'}</span></div>
        </article>
      </div>
    );
  }

  if (activeTab === 'goals') {
    const goals = details.goalscorers || [];
    return (
      <div className="rs-match-tab-panel">
        {goals.length ? goals.map((goal, index) => <EventRow event={goal} key={`${goal.elapsed}-${goal.player?.name}-${index}`} />) : <EmptyMatchTab label="No goal scorers stored for this fixture yet." />}
      </div>
    );
  }

  if (activeTab === 'stats') {
    return <StatsPanel statistics={details.statistics || []} />;
  }

  if (activeTab === 'timeline') {
    const timeline = details.timeline || [];
    return (
      <div className="rs-match-tab-panel">
        {timeline.length ? timeline.map((event, index) => <EventRow event={event} key={`${event.elapsed}-${event.type}-${index}`} />) : <EmptyMatchTab label="No match timeline stored for this fixture yet." />}
      </div>
    );
  }

  if (activeTab === 'h2h') {
    const rows = details.h2h || [];
    return (
      <div className="rs-match-tab-panel rs-h2h-list">
        {rows.length ? rows.map((row) => (
          <article key={row.id || `${row.home?.name}-${row.away?.name}-${row.date}`}>
            <span>{row.league?.name || 'Football'} · {formatDate(row.date)}</span>
            <strong>{row.home?.name || 'Home'} {row.goals?.home ?? '-'} - {row.goals?.away ?? '-'} {row.away?.name || 'Away'}</strong>
          </article>
        )) : <EmptyMatchTab label={`No stored H2H rows for ${fixture.home?.name || 'home'} vs ${fixture.away?.name || 'away'} yet.`} />}
      </div>
    );
  }

  if (activeTab === 'news') {
    return <MatchNewsPanel stories={stories} fixture={fixture} />;
  }

  return <SquadsPanel squads={details.squads || []} />;
}

function MatchNewsPanel({ stories = [], fixture = {} }) {
  return (
    <div className="rs-match-tab-panel rs-match-news-panel">
      {stories.length ? stories.map((story) => (
        <a href={story.read_full_story_url || story.original_url} key={story.id} target="_blank" rel="noreferrer" onClick={() => trackStoryClick(story, 'read_full_story_click', `${fixture.home?.name || ''} ${fixture.away?.name || ''}`)}>
          <strong>{decodeText(story.headline)}</strong>
          <SourceMention story={story} showTime />
        </a>
      )) : <EmptyMatchTab label="No related football coverage indexed for these teams yet." />}
    </div>
  );
}

function EventRow({ event }) {
  const minute = event.elapsed ? `${event.elapsed}${event.extra ? `+${event.extra}` : ''}'` : '—';

  return (
    <article className="rs-event-row">
      <Badge tone={event.type === 'Goal' ? 'danger' : ''}>{minute}</Badge>
      {event.team?.logo ? <img src={event.team.logo} alt="" loading="lazy" /> : <span className="rs-team-dot" />}
      <div>
        <strong>{event.player?.name || event.type || 'Match event'}</strong>
        <span>{event.type}{event.detail ? ` · ${event.detail}` : ''}{event.assist?.name ? ` · Assist: ${event.assist.name}` : ''}</span>
      </div>
    </article>
  );
}

function StatsPanel({ statistics = [] }) {
  if (!statistics.length) {
    return <div className="rs-match-tab-panel"><EmptyMatchTab label="No stored match statistics yet." /></div>;
  }

  const statTypes = Array.from(new Set(statistics.flatMap((team) => (team.statistics || []).map((stat) => stat.type))));

  return (
    <div className="rs-match-tab-panel rs-stats-panel">
      {statTypes.map((type) => (
        <article key={type}>
          <span>{type}</span>
          <strong>{statistics[0]?.statistics?.find((stat) => stat.type === type)?.value || '—'}</strong>
          <b>{statistics[1]?.statistics?.find((stat) => stat.type === type)?.value || '—'}</b>
        </article>
      ))}
    </div>
  );
}

function SquadsPanel({ squads = [] }) {
  if (!squads.length) {
    return <div className="rs-match-tab-panel"><EmptyMatchTab label="No stored squad list for this fixture yet." /></div>;
  }

  return (
    <div className="rs-match-tab-panel rs-squads-panel">
      {squads.map((squad) => (
        <article key={squad.team?.id || squad.team?.name}>
          <header>
            {squad.team?.logo ? <img src={squad.team.logo} alt="" loading="lazy" /> : <span className="rs-team-dot" />}
            <div>
              <strong>{squad.team?.name || 'Team'}</strong>
              <span>{squad.formation || 'Formation TBC'} · Coach: {squad.coach?.name || 'TBC'}</span>
            </div>
          </header>
          <div>
            <b>Starting XI</b>
            {(squad.startXI || []).slice(0, 14).map((player) => <span key={`${player.id}-${player.name}`}>{player.number ? `${player.number}. ` : ''}{player.name} {player.pos ? `(${player.pos})` : ''}</span>)}
          </div>
          <div>
            <b>Substitutes</b>
            {(squad.substitutes || []).slice(0, 14).map((player) => <span key={`${player.id}-${player.name}`}>{player.number ? `${player.number}. ` : ''}{player.name} {player.pos ? `(${player.pos})` : ''}</span>)}
          </div>
        </article>
      ))}
    </div>
  );
}

function EmptyMatchTab({ label }) {
  return <p className="rs-match-tab-empty">{label}</p>;
}

function MatchModalTeam({ team = {}, align = 'left' }) {
  return (
    <div className={`rs-match-modal-team ${align === 'right' ? 'is-right' : ''}`}>
      {team.logo ? <img src={team.logo} alt="" loading="lazy" /> : <span className="rs-team-dot" />}
      <span>{team.name || 'Team'}</span>
    </div>
  );
}

function FixturesCard({ fixtures = [], configured = false, message = '', onSelect }) {
  return (
    <Card>
      <CardHeader title="Upcoming in 24 hours" action={<CalendarDays size={18} />} />
      <div className="rs-fixture-list">
        {fixtures.length ? fixtures.slice(0, 6).map((fixture) => (
          <article key={fixture.id || `${fixture.home?.name}-${fixture.away?.name}-${fixture.date}`}>
            <span>{getFootballCompetitionLabel(fixture)}</span>
            <div className="rs-fixture-teams">
              <FixtureTeam team={fixture.home} />
              <strong>vs</strong>
              <FixtureTeam team={fixture.away} align="right" />
            </div>
            <Badge>{formatCountdown(fixture.date) || formatDate(fixture.date)}</Badge>
            <small>{formatTime(fixture.date)}</small>
            <button className="rs-mini-link" type="button" onClick={() => onSelect?.(fixture)}>Details</button>
          </article>
        )) : (
          <article>
            <span>{configured ? 'Live data' : 'Setup needed'}</span>
            <strong>{message || (configured ? 'No upcoming fixtures stored for the configured leagues/cups in the next 24 hours.' : 'Add your football data key and league/cup IDs in Football settings.')}</strong>
            <Badge>{configured ? 'Live' : 'Config'}</Badge>
          </article>
        )}
      </div>
    </Card>
  );
}

function FootballCoverage({ stories = [], loading = false }) {
  return (
    <Card className="rs-football-coverage">
      <CardHeader title="Latest football coverage" action={<Badge>{loading ? 'Loading' : `${stories.length} stories`}</Badge>} />
      <div className="rs-football-news">
        {stories.length ? stories.slice(0, 8).map((story) => (
          <a href={story.read_full_story_url || story.original_url} key={`${story.id}-${story.headline}`} target="_blank" rel="noreferrer" onClick={() => trackStoryClick(story, 'read_full_story_click', 'Football')}>
            <strong>{decodeText(story.headline)}</strong>
            <SourceMention story={story} showTime />
          </a>
        )) : <p>No football stories indexed yet. Add football posts, RSS publishers or TheNewsAPI imports to populate this desk.</p>}
      </div>
    </Card>
  );
}

function FixtureTeam({ team = {}, align = 'left' }) {
  return (
    <span className={`rs-fixture-team ${align === 'right' ? 'is-right' : ''}`}>
      {team.logo ? <img src={team.logo} alt="" loading="lazy" /> : <span className="rs-team-dot" />}
      <b>{team.name || (align === 'right' ? 'Away' : 'Home')}</b>
    </span>
  );
}

function FootballTeamsDirectory() {
  const [payload, setPayload] = useState({ teams: [], competitions: [] });
  const [selectedLeague, setSelectedLeague] = useState('all');
  const [selectedTeam, setSelectedTeam] = useState(null);
  const [teamSearch, setTeamSearch] = useState('');
  const [profile, setProfile] = useState(null);
  const [status, setStatus] = useState({ loading: true, error: '' });
  const [profileStatus, setProfileStatus] = useState({ loading: false, error: '' });

  useEffect(() => {
    let cancelled = false;
    const [league, season] = selectedLeague === 'all' ? ['', ''] : selectedLeague.split(':');

    setStatus({ loading: true, error: '' });
    getFootballTeams({ league, season, limit: 160 })
      .then((data) => {
        if (cancelled) return;
        setPayload(data);
        setSelectedTeam((current) => current || data.teams?.[0] || null);
      })
      .catch((error) => !cancelled && setStatus({ loading: false, error: error.message }))
      .finally(() => !cancelled && setStatus((current) => ({ ...current, loading: false })));

    return () => {
      cancelled = true;
    };
  }, [selectedLeague]);

  useEffect(() => {
    if (!selectedTeam?.id) {
      setProfile(null);
      return undefined;
    }

    let cancelled = false;
    setProfileStatus({ loading: true, error: '' });
    getFootballTeamProfile(selectedTeam.id, { limit: 14 })
      .then((data) => !cancelled && setProfile(data))
      .catch((error) => !cancelled && setProfileStatus({ loading: false, error: error.message }))
      .finally(() => !cancelled && setProfileStatus((current) => ({ ...current, loading: false })));

    return () => {
      cancelled = true;
    };
  }, [selectedTeam?.id]);

  const competitions = payload.competitions ?? [];
  const teams = payload.teams ?? [];
  const normalizedTeamSearch = teamSearch.trim().toLowerCase();
  const visibleTeams = normalizedTeamSearch ? teams.filter((team) => [
    team.name,
    team.league?.name,
    team.league?.country,
    team.league?.season,
  ].filter(Boolean).join(' ').toLowerCase().includes(normalizedTeamSearch)) : teams;
  const stats = profile?.stats ?? selectedTeam ?? {};
  const fixtures = profile?.fixtures ?? [];
  const players = profile?.players ?? [];
  const latestNews = profile?.latest_news ?? [];

  return (
    <main className="rs-shell compact-page rs-teams-page">
      <div className="rs-competition-tabs rs-team-league-tabs" aria-label="Filter teams by competition">
        <button className={selectedLeague === 'all' ? 'active' : ''} type="button" onClick={() => { setSelectedLeague('all'); setSelectedTeam(null); }}>
          <span>All tracked leagues</span>
          <b>{teams.length}</b>
        </button>
        {competitions.map((competition) => {
          const key = `${competition.id}:${competition.season}`;
          return (
            <button className={selectedLeague === key ? 'active' : ''} type="button" key={key} onClick={() => { setSelectedLeague(key); setSelectedTeam(null); }}>
              {competition.logo ? <img src={competition.logo} alt="" loading="lazy" /> : <Trophy size={18} />}
              <span>{competition.name}</span>
              <b>{competition.season || ''}</b>
            </button>
          );
        })}
      </div>

      {status.error ? <Card><CardHeader title="Couldn’t load teams" action={<Badge tone="danger">REST</Badge>} /><p>{status.error}</p></Card> : null}

      <section className="rs-team-directory-layout">
        <Card className="rs-team-directory-card">
          <CardHeader title="Team directory" action={<Badge>{status.loading ? 'Loading' : `${visibleTeams.length} teams`}</Badge>} />
          <label className="rs-directory-search">
            <Search size={17} />
            <input value={teamSearch} onChange={(event) => setTeamSearch(event.target.value)} placeholder="Search team, league, country..." />
          </label>
          <div className="rs-team-directory-grid">
            {visibleTeams.map((team) => (
              <button className={selectedTeam?.id === team.id ? 'active' : ''} type="button" key={team.id} onClick={() => setSelectedTeam(team)}>
                {team.logo ? <img src={team.logo} alt="" loading="lazy" /> : <span className="rs-team-dot" />}
                <strong>{team.name}</strong>
                <span>{team.league?.name || 'Tracked league'}</span>
                <small>{team.matches} saved match{team.matches === 1 ? '' : 'es'}</small>
              </button>
            ))}
            {!status.loading && !teams.length ? <p>No teams saved yet. Run a football sync or history backfill in the Control Center.</p> : null}
            {!status.loading && teams.length > 0 && !visibleTeams.length ? <p>No team matches “{teamSearch}”. Try a club, country or competition name.</p> : null}
          </div>
        </Card>

        <div className="rs-team-profile-stack">
          <Card className="rs-team-profile-hero">
            {selectedTeam ? (
              <>
                <div>
                  {selectedTeam.logo ? <img src={selectedTeam.logo} alt="" loading="lazy" /> : <span className="rs-team-dot" />}
                  <div>
                    <Badge>{selectedTeam.league?.name || 'Team'}</Badge>
                    <h2>{selectedTeam.name}</h2>
                    <p>{selectedTeam.league?.country || 'Tracked competition'} · {selectedTeam.league?.season || 'Season'}</p>
                  </div>
                </div>
                <div className="rs-team-stat-strip">
                  <span><strong>{stats.matches ?? 0}</strong><small>Matches</small></span>
                  <span><strong>{stats.wins ?? 0}</strong><small>Wins</small></span>
                  <span><strong>{stats.draws ?? 0}</strong><small>Draws</small></span>
                  <span><strong>{stats.losses ?? 0}</strong><small>Losses</small></span>
                  <span><strong>{stats.goal_difference ?? ((stats.goals_for ?? 0) - (stats.goals_against ?? 0))}</strong><small>GD</small></span>
                </div>
              </>
            ) : <p>Select a team to see the profile.</p>}
          </Card>

          <section className="rs-team-detail-grid">
            <Card>
              <CardHeader title="Latest fixtures" action={<Badge>{profileStatus.loading ? 'Loading' : fixtures.length}</Badge>} />
              <div className="rs-team-fixture-list">
                {fixtures.slice(0, 6).map((fixture) => (
                  <article key={fixture.id}>
                    <span>{fixture.league?.name || 'Football'} · {formatDate(fixture.date)}</span>
                    <strong>{fixture.home?.name} {fixture.goals?.home ?? '-'} - {fixture.goals?.away ?? '-'} {fixture.away?.name}</strong>
                    <small>{fixture.status_long || fixture.status_short || formatTime(fixture.date)}</small>
                  </article>
                ))}
                {!profileStatus.loading && !fixtures.length ? <p>No fixtures saved for this team yet.</p> : null}
              </div>
            </Card>

            <Card>
              <CardHeader title="Players" action={<Badge>{players.length ? `${players.length}` : 'Squad'}</Badge>} />
              <div className="rs-team-player-grid">
                {players.slice(0, 18).map((player) => (
                  <span key={`${player.id}-${player.name}`}>
                    <b>{player.number || '-'}</b>
                    <strong>{player.name}</strong>
                    <small>{player.pos || 'Player'}</small>
                  </span>
                ))}
                {!profileStatus.loading && !players.length ? <p>No squad list yet. Sync fixture details to fill this out.</p> : null}
              </div>
            </Card>
          </section>

          <Card>
            <CardHeader title="Latest news talking about this team" action={<Badge>{latestNews.length} stories</Badge>} />
            <div className="rs-football-news compact">
              {latestNews.map((story) => (
                <a href={story.read_full_story_url || story.original_url} key={`${selectedTeam?.id}-${story.id}`} target="_blank" rel="noreferrer" onClick={() => trackStoryClick(story, 'read_full_story_click', selectedTeam?.name || '')}>
                  <strong>{decodeText(story.headline)}</strong>
                  <SourceMention story={story} showTime />
                </a>
              ))}
              {!profileStatus.loading && !latestNews.length ? <p>No recent stories for {selectedTeam?.name || 'this team'} yet.</p> : null}
            </div>
          </Card>
        </div>
      </section>
    </main>
  );
}

function FootballPlayersDirectory() {
  const [payload, setPayload] = useState({ players: [], teams: [] });
  const [selectedTeam, setSelectedTeam] = useState('all');
  const [selectedPlayer, setSelectedPlayer] = useState(null);
  const [playerSearch, setPlayerSearch] = useState('');
  const [profile, setProfile] = useState(null);
  const [status, setStatus] = useState({ loading: true, error: '' });
  const [profileStatus, setProfileStatus] = useState({ loading: false, error: '' });

  useEffect(() => {
    let cancelled = false;
    setStatus({ loading: true, error: '' });
    getFootballPlayers({ team: selectedTeam === 'all' ? '' : selectedTeam, limit: 180 })
      .then((data) => {
        if (cancelled) return;
        setPayload(data);
        setSelectedPlayer((current) => current || data.players?.[0] || null);
      })
      .catch((error) => !cancelled && setStatus({ loading: false, error: error.message }))
      .finally(() => !cancelled && setStatus((current) => ({ ...current, loading: false })));

    return () => {
      cancelled = true;
    };
  }, [selectedTeam]);

  useEffect(() => {
    if (!selectedPlayer?.name && !selectedPlayer?.id) {
      setProfile(null);
      return undefined;
    }

    let cancelled = false;
    setProfileStatus({ loading: true, error: '' });
    getFootballPlayerProfile({ playerId: selectedPlayer.id || '', playerName: selectedPlayer.name || '', limit: 16 })
      .then((data) => !cancelled && setProfile(data))
      .catch((error) => !cancelled && setProfileStatus({ loading: false, error: error.message }))
      .finally(() => !cancelled && setProfileStatus((current) => ({ ...current, loading: false })));

    return () => {
      cancelled = true;
    };
  }, [selectedPlayer?.id, selectedPlayer?.name]);

  const players = payload.players ?? [];
  const teams = payload.teams ?? [];
  const normalizedPlayerSearch = playerSearch.trim().toLowerCase();
  const visiblePlayers = normalizedPlayerSearch ? players.filter((player) => [
    player.name,
    player.team?.name,
    player.pos,
    player.number,
  ].filter(Boolean).join(' ').toLowerCase().includes(normalizedPlayerSearch)) : players;
  const profilePlayer = profile?.player || selectedPlayer;
  const stats = profile?.stats ?? selectedPlayer ?? {};
  const latestNews = profile?.latest_news ?? [];
  const fixtures = profile?.fixtures ?? [];
  const events = profile?.events ?? [];

  return (
    <main className="rs-shell compact-page rs-teams-page rs-players-page">
      <div className="rs-competition-tabs rs-team-league-tabs" aria-label="Filter players by team">
        <button className={selectedTeam === 'all' ? 'active' : ''} type="button" onClick={() => { setSelectedTeam('all'); setSelectedPlayer(null); }}>
          <span>All teams</span>
          <b>{players.length}</b>
        </button>
        {teams.slice(0, 24).map((team) => (
          <button className={String(selectedTeam) === String(team.id) ? 'active' : ''} type="button" key={`${team.id}-${team.name}`} onClick={() => { setSelectedTeam(team.id || 'all'); setSelectedPlayer(null); }}>
            {team.logo ? <img src={team.logo} alt="" loading="lazy" /> : <Shield size={18} />}
            <span>{team.name}</span>
          </button>
        ))}
      </div>

      {status.error ? <Card><CardHeader title="Couldn’t load players" action={<Badge tone="danger">REST</Badge>} /><p>{status.error}</p></Card> : null}

      <section className="rs-team-directory-layout">
        <Card className="rs-team-directory-card">
          <CardHeader title="Player directory" action={<Badge>{status.loading ? 'Loading' : `${visiblePlayers.length} players`}</Badge>} />
          <label className="rs-directory-search">
            <Search size={17} />
            <input value={playerSearch} onChange={(event) => setPlayerSearch(event.target.value)} placeholder="Search player, team, role..." />
          </label>
          <div className="rs-team-directory-grid rs-player-directory-grid">
            {visiblePlayers.map((player) => (
              <button className={selectedPlayer?.name === player.name && selectedPlayer?.team?.name === player.team?.name ? 'active' : ''} type="button" key={`${player.id}-${player.name}-${player.team?.name}`} onClick={() => setSelectedPlayer(player)}>
                {player.team?.logo ? <img src={player.team.logo} alt="" loading="lazy" /> : <span className="rs-team-dot" />}
                <strong>{player.name}</strong>
                <span>{player.team?.name || 'Team'} · {player.pos || 'Player'}</span>
                <small>{player.appearances} app{player.appearances === 1 ? '' : 's'}</small>
              </button>
            ))}
            {!status.loading && !players.length ? <p>No player data yet. Sync fixture details or run a history backfill.</p> : null}
            {!status.loading && players.length > 0 && !visiblePlayers.length ? <p>No player matches “{playerSearch}”. Try a name, team or position.</p> : null}
          </div>
        </Card>

        <div className="rs-team-profile-stack">
          <Card className="rs-team-profile-hero rs-player-profile-hero">
            {profilePlayer ? (
              <>
                <div>
                  {profilePlayer.team?.logo ? <img src={profilePlayer.team.logo} alt="" loading="lazy" /> : <span className="rs-team-dot" />}
                  <div>
                    <Badge>{profilePlayer.team?.name || 'Player profile'}</Badge>
                    <h2>{profilePlayer.name}</h2>
                    <p>{profilePlayer.pos || 'Footballer'}{profilePlayer.number ? ` · #${profilePlayer.number}` : ''}</p>
                  </div>
                </div>
                <div className="rs-team-stat-strip">
                  <span><strong>{stats.appearances ?? 0}</strong><small>Apps</small></span>
                  <span><strong>{stats.starts ?? 0}</strong><small>Starts</small></span>
                  <span><strong>{stats.goals ?? 0}</strong><small>Goals</small></span>
                  <span><strong>{stats.assists ?? 0}</strong><small>Assists</small></span>
                  <span><strong>{stats.cards ?? 0}</strong><small>Cards</small></span>
                </div>
              </>
            ) : <p>Select a player to open the profile.</p>}
          </Card>

          <section className="rs-team-detail-grid">
            <Card>
              <CardHeader title="Recent appearances" action={<Badge>{profileStatus.loading ? 'Loading' : fixtures.length}</Badge>} />
              <div className="rs-team-fixture-list">
                {fixtures.slice(0, 6).map((fixture) => (
                  <article key={fixture.id}>
                    <span>{fixture.league?.name || 'Football'} · {formatDate(fixture.date)}</span>
                    <strong>{fixture.home?.name} {fixture.goals?.home ?? '-'} - {fixture.goals?.away ?? '-'} {fixture.away?.name}</strong>
                    <small>{fixture.status_long || fixture.status_short || formatTime(fixture.date)}</small>
                  </article>
                ))}
                {!profileStatus.loading && !fixtures.length ? <p>No saved appearances yet.</p> : null}
              </div>
            </Card>

            <Card>
              <CardHeader title="Match moments" action={<Badge>{events.length} events</Badge>} />
              <div className="rs-team-fixture-list rs-player-event-list">
                {events.slice(0, 6).map((item, index) => (
                  <article key={`${item.fixture?.id}-${index}`}>
                    <span>{item.event?.elapsed ? `${item.event.elapsed}'` : 'Moment'} · {item.fixture?.home?.name} vs {item.fixture?.away?.name}</span>
                    <strong>{item.event?.type || 'Event'} {item.event?.detail ? `· ${item.event.detail}` : ''}</strong>
                    <small>{formatDate(item.fixture?.date)}</small>
                  </article>
                ))}
                {!profileStatus.loading && !events.length ? <p>No goals, cards or assists saved yet.</p> : null}
              </div>
            </Card>
          </section>

          <Card>
            <CardHeader title="Latest news around this player" action={<Badge>{latestNews.length} stories</Badge>} />
            <div className="rs-football-news compact">
              {latestNews.map((story) => (
                <a href={story.read_full_story_url || story.original_url} key={`${profilePlayer?.name}-${story.id}`} target="_blank" rel="noreferrer" onClick={() => trackStoryClick(story, 'read_full_story_click', profilePlayer?.name || '')}>
                  <strong>{decodeText(story.headline)}</strong>
                  <SourceMention story={story} showTime />
                </a>
              ))}
              {!profileStatus.loading && !latestNews.length ? <p>No recent stories mention {profilePlayer?.name || 'this player'} yet.</p> : null}
            </div>
          </Card>
        </div>
      </section>
    </main>
  );
}

function TransferNewsPage() {
  const [payload, setPayload] = useState({ stories: [], topics: [], sources: 0 });
  const [status, setStatus] = useState({ loading: true, error: '' });

  useEffect(() => {
    let cancelled = false;
    setStatus({ loading: true, error: '' });
    getFootballTransfers({ limit: 30 })
      .then((data) => !cancelled && setPayload(data))
      .catch((error) => !cancelled && setStatus({ loading: false, error: error.message }))
      .finally(() => !cancelled && setStatus((current) => ({ ...current, loading: false })));

    return () => {
      cancelled = true;
    };
  }, []);

  const stories = payload.stories ?? [];
  const lead = stories[0] || null;
  const sources = payload.sources ?? new Set(stories.map((story) => story.source_domain).filter(Boolean)).size;
  const topics = payload.topics ?? [];

  return (
    <main className="rs-shell compact-page rs-transfer-page">
      <section className="rs-stat-grid rs-product-stats">
        <DashboardStat label="Transfer stories" value={stories.length} note="Indexed" />
        <DashboardStat label="Sources" value={sources} note="Covering the window" />
        <DashboardStat label="Updated" value={payload.updated_at ? formatTime(payload.updated_at) : 'Now'} note="Latest scan" />
      </section>

      {status.error ? <Card><CardHeader title="Couldn’t load transfers" action={<Badge tone="danger">REST</Badge>} /><p>{status.error}</p></Card> : null}

      {lead ? (
        <Card className="rs-transfer-lead">
          <Badge>Big talk</Badge>
          <h2>{decodeText(lead.headline)}</h2>
          <p>{decodeText(lead.excerpt || 'Open the source to follow the full transfer context.')}</p>
          <div>
            <SourceMention story={lead} showTime />
            <a href={lead.read_full_story_url || lead.original_url} target="_blank" rel="noreferrer" onClick={() => trackStoryClick(lead, 'read_full_story_click', 'transfer')}>Read story <ExternalLink size={15} /></a>
          </div>
        </Card>
      ) : null}

      <section className="rs-transfer-layout">
        <Card className="rs-transfer-card rs-product-card">
          <CardHeader title="Transfer news stream" action={<Badge>{status.loading ? 'Loading' : `${stories.length} stories`}</Badge>} />
          <div className="rs-transfer-news-list">
            {stories.slice(lead ? 1 : 0).map((story) => (
              <article key={`${story.id}-${story.headline}`}>
                <div>
                  <SourceMention story={story} showTime />
                  <h3>{decodeText(story.headline)}</h3>
                  <p>{decodeText(story.excerpt || '')}</p>
                </div>
                <a href={story.read_full_story_url || story.original_url} target="_blank" rel="noreferrer" onClick={() => trackStoryClick(story, 'read_full_story_click', 'transfer')}>Open <ExternalLink size={15} /></a>
              </article>
            ))}
            {!status.loading && !stories.length ? <p>{payload.message || 'No transfer stories yet. Add football sources or imports with transfer keywords.'}</p> : null}
          </div>
        </Card>

        <Card className="rs-transfer-topics">
          <CardHeader title="Window buzz" action={<Flame size={18} />} />
          <div className="rs-pills">
            {topics.length ? topics.map((topic) => <button type="button" key={topic}>{topic}</button>) : (
              <>
                <button type="button">transfer</button>
                <button type="button">loan</button>
                <button type="button">contract</button>
              </>
            )}
          </div>
          <p>Buzz tags from saved transfer stories. CustomGPT can sharpen these when you start tagging batches.</p>
        </Card>
      </section>
    </main>
  );
}

function MobileHomeTakeoverLogo() {
  const logoUrl = window.RIFNOTE_SEARCH?.siteIconUrl || window.RIFNOTE_SEARCH?.siteLogoUrl || '';
  const logoSize = Math.max(28, Number(window.RIFNOTE_SEARCH?.homeTakeoverLogoSizeMobile || 40));

  if (!logoUrl) {
    return null;
  }

  return (
    <a
      className="rs-home-takeover-mobile-logo"
      href={window.RIFNOTE_SEARCH?.homeUrl || '/'}
      style={{ '--rs-home-takeover-logo-size': `${logoSize}px` }}
      aria-label="Rifnote home"
    >
      <img src={logoUrl} alt="Rifnote" loading="eager" />
    </a>
  );
}

function HomeSearchMedia({ primary = false, featuredFootballMatches = [] }) {
  const [takeover, setTakeover] = useState(window.RIFNOTE_SEARCH?.electionTakeover || null);
  const [soundOn, setSoundOn] = useState(false);
  const mediaUrl = window.RIFNOTE_SEARCH?.homeSearchMediaUrl || '';
  const mediaType = window.RIFNOTE_SEARCH?.homeSearchMediaType || 'image';
  const linkUrl = window.RIFNOTE_SEARCH?.homeSearchMediaLinkUrl || '';
  const footballFixtures = Array.isArray(featuredFootballMatches)
    ? featuredFootballMatches.filter((fixture) => fixture && !isFootballFixtureFinished(fixture))
    : [];

  useEffect(() => {
    let cancelled = false;
    let timer = null;

    async function loadElectionTakeover() {
      try {
        const restUrl = window.RIFNOTE_SEARCH?.restUrl || '/wp-json/';
        const endpoint = `${restUrl.replace(/\/$/, '')}/rifnote/v1/election/takeover`;
        const response = await fetch(endpoint);
        if (!response.ok) {
          return;
        }
        const payload = await response.json();
        if (!cancelled) {
          setTakeover(payload);
        }
      } catch (_) {}
    }

    loadElectionTakeover();
    timer = window.setInterval(loadElectionTakeover, 30000);

    return () => {
      cancelled = true;
      if (timer) {
        window.clearInterval(timer);
      }
    };
  }, []);

  if (takeover?.enabled) {
    return <ElectionTakeover takeover={takeover} primary={primary} />;
  }

  if (footballFixtures.length) {
    return <HomeFeaturedFootballScoreboards fixtures={footballFixtures} primary={primary} />;
  }

  if (!mediaUrl) {
    return null;
  }

  const embedUrl = externalVideoEmbedUrl(mediaUrl, { muted: !soundOn });
  const isEmbed = Boolean(embedUrl);
  const isUploadedVideo = !isEmbed && mediaType === 'video';
  const mediaClassName = `rs-home-search-media ${primary ? 'is-primary' : ''} ${linkUrl && !isEmbed ? 'is-linkable' : ''} ${isEmbed ? 'is-video-embed' : ''} ${isUploadedVideo ? 'is-uploaded-video' : ''}`;
  const media = isEmbed ? (
    <iframe
      src={embedUrl}
      title="Rifnote homepage featured video"
      loading="eager"
      allow="autoplay; fullscreen; picture-in-picture; encrypted-media"
      allowFullScreen
    />
  ) : isUploadedVideo ? (
    <video src={mediaUrl} autoPlay muted={!soundOn} loop playsInline controls preload="metadata" />
  ) : (
    <img src={mediaUrl} alt="" loading="eager" />
  );
  const soundHint = (isEmbed || isUploadedVideo) ? (
    <button
      className={`rs-home-search-sound-toggle ${soundOn ? 'is-on' : ''}`}
      type="button"
      onClick={(event) => {
        event.preventDefault();
        event.stopPropagation();
        setSoundOn((current) => !current);
      }}
      aria-label={soundOn ? 'Mute featured video' : 'Play featured video with sound'}
    >
      {soundOn ? <Volume2 size={17} strokeWidth={2.5} /> : <VolumeX size={17} strokeWidth={2.5} />}
      <span>{soundOn ? 'Sound on' : 'Tap for sound'}</span>
    </button>
  ) : null;

  if (linkUrl && !isEmbed) {
    return (
      <a className={mediaClassName} href={linkUrl} aria-label="Open featured homepage story">
        {media}
        {soundHint}
      </a>
    );
  }

  return (
    <div className={mediaClassName} aria-hidden={!(isEmbed || isUploadedVideo)}>
      {media}
      {soundHint}
    </div>
  );
}

function mergeFeaturedFixtureDetails(fixture = {}, payload = {}) {
  const updatedFixture = payload?.fixture || payload?.data?.fixture || null;
  const details = payload?.details || updatedFixture?.details || {};
  const timeline = Array.isArray(details.timeline) ? details.timeline : (Array.isArray(updatedFixture?.timeline) ? updatedFixture.timeline : fixture.timeline);
  const goalscorers = Array.isArray(details.goalscorers) ? details.goalscorers : (Array.isArray(updatedFixture?.goalscorers) ? updatedFixture.goalscorers : fixture.goalscorers);

  return {
    ...fixture,
    ...(updatedFixture || {}),
    details: {
      ...(fixture.details || {}),
      ...details,
    },
    timeline: Array.isArray(timeline) ? timeline : [],
    events: Array.isArray(timeline) ? timeline : (Array.isArray(fixture.events) ? fixture.events : []),
    goalscorers: Array.isArray(goalscorers) ? goalscorers : [],
  };
}

function useFeaturedFootballFixtures(fixtures = []) {
  const initialFixtures = useMemo(() => (
    Array.isArray(fixtures) ? fixtures.filter((fixture) => fixture && !isFootballFixtureFinished(fixture)) : []
  ), [fixtures]);
  const [liveFixtures, setLiveFixtures] = useState(initialFixtures);
  const liveFixturesRef = useRef(initialFixtures);
  const initialFixturesRef = useRef(initialFixtures);
  const signature = initialFixtures.map((fixture) => getFixtureDeepLinkId(fixture) || `${fixture.home?.name || ''}-${fixture.away?.name || ''}-${fixture.date || ''}`).join('|');

  useEffect(() => {
    initialFixturesRef.current = initialFixtures;
    liveFixturesRef.current = initialFixtures;
    setLiveFixtures(initialFixtures);
  }, [signature]);

  useEffect(() => {
    liveFixturesRef.current = liveFixtures;
  }, [liveFixtures]);

  const refreshFeaturedFixtures = useCallback(() => {
    const currentFixtures = liveFixturesRef.current?.length ? liveFixturesRef.current : initialFixturesRef.current;
    const targets = (currentFixtures || []).filter((fixture) => fixture && getFixtureDeepLinkId(fixture));

    if (!targets.length) {
      return;
    }

    Promise.allSettled(targets.map((fixture) => (
      getFootballFixtureDetails(getFixtureDeepLinkId(fixture), { force: !isFootballFixtureFinished(fixture) })
        .then((payload) => mergeFeaturedFixtureDetails(fixture, payload))
    )))
      .then((results) => {
        const nextById = new Map();

        results.forEach((result, index) => {
          const fallback = targets[index];
          const fixture = result.status === 'fulfilled' ? result.value : fallback;
          const id = getFixtureDeepLinkId(fixture) || getFixtureDeepLinkId(fallback);

          if (id) {
            nextById.set(String(id), fixture);
          }
        });

        setLiveFixtures((current) => {
          const base = current.length ? current : initialFixturesRef.current;
          return base
            .map((fixture) => {
              const id = getFixtureDeepLinkId(fixture);
              return id && nextById.has(String(id)) ? nextById.get(String(id)) : fixture;
            })
            .filter((fixture) => fixture && !isFootballFixtureFinished(fixture));
        });
      })
      .catch(() => {});
  }, []);

  useLiveInterval(refreshFeaturedFixtures, 30000, initialFixtures.length > 0);

  return liveFixtures.filter((fixture) => fixture && !isFootballFixtureFinished(fixture));
}

function HomeFeaturedFootballScoreboards({ fixtures = [], primary = false }) {
  const cleanFixtures = useFeaturedFootballFixtures(fixtures);
  const [active, setActive] = useState(0);
  const [scoreMemory, setScoreMemory] = useState({});
  const [goalFlash, setGoalFlash] = useState(null);
  const [clockTick, setClockTick] = useState(() => Date.now());
  const seenGoalFlashRef = useRef(new Set());
  const scoreSignature = cleanFixtures.map((fixture) => {
    const id = fixture.fixture_id || fixture.id || fixture.fixture?.id || `${fixture.home?.name || 'home'}-${fixture.away?.name || 'away'}-${fixture.date || ''}`;
    return `${id}:${fixture.goals?.home ?? 0}-${fixture.goals?.away ?? 0}`;
  }).join('|');

  useEffect(() => {
    if (cleanFixtures.length < 2) {
      return undefined;
    }

    const timer = window.setInterval(() => {
      setActive((current) => (current + 1) % cleanFixtures.length);
    }, 9000);

    return () => window.clearInterval(timer);
  }, [cleanFixtures.length]);

  useEffect(() => {
    if (!cleanFixtures.length) {
      return undefined;
    }

    const timer = window.setInterval(() => setClockTick(Date.now()), 15000);
    return () => window.clearInterval(timer);
  }, [cleanFixtures.length]);

  useEffect(() => {
    if (!cleanFixtures.length) {
      return;
    }

    setScoreMemory((current) => {
      const next = { ...current };
      let flash = null;

      cleanFixtures.forEach((fixture) => {
        const id = fixture.fixture_id || fixture.id || fixture.fixture?.id || `${fixture.home?.name || 'home'}-${fixture.away?.name || 'away'}-${fixture.date || ''}`;
        const homeScore = Number(fixture.goals?.home ?? 0);
        const awayScore = Number(fixture.goals?.away ?? 0);
        const previous = current[id];

        if (previous && (homeScore !== previous.home || awayScore !== previous.away)) {
          const isGoal = homeScore > previous.home || awayScore > previous.away;
          const hasCancelledGoal = !isGoal && hasExplicitVarCancellation(fixture);

          if (!isGoal && !hasCancelledGoal) {
            next[id] = { home: homeScore, away: awayScore };
            return;
          }

          const isHomeGoal = isGoal ? homeScore > previous.home : homeScore < previous.home;
          const team = isHomeGoal ? fixture.home : fixture.away;
          const scorer = isGoal ? extractGoalScorer(fixture, isHomeGoal) : extractGoalScorer(fixture, isHomeGoal, true);
          const flashSignature = `${id}:${isGoal ? 'goal' : 'var'}:${homeScore}-${awayScore}:${scorer}`;

          if (seenGoalFlashRef.current.has(flashSignature)) {
            next[id] = { home: homeScore, away: awayScore };
            return;
          }

          seenGoalFlashRef.current.add(flashSignature);

          flash = {
            id,
            type: isGoal ? 'goal' : 'var',
            team: team?.name || (isHomeGoal ? 'Home team' : 'Away team'),
            logo: team?.logo || '',
            scorer,
            score: `${fixture.goals?.home ?? '-'} - ${fixture.goals?.away ?? '-'}`,
          };
        }

        next[id] = { home: homeScore, away: awayScore };
      });

      if (flash) {
        setGoalFlash(flash);
      }

      return next;
    });
  }, [scoreSignature]);

  useEffect(() => {
    if (!goalFlash) {
      return undefined;
    }

    if (goalFlash.type === 'var') {
      playVarDecisionSound();
    } else {
      playGoalCelebrationSound();
    }

    const timer = window.setTimeout(() => setGoalFlash(null), 5200);
    return () => window.clearTimeout(timer);
  }, [goalFlash]);

  if (!cleanFixtures.length) {
    return null;
  }

  const fixture = cleanFixtures[Math.min(active, cleanFixtures.length - 1)] || cleanFixtures[0];
  const status = fixture.status_short || '';
  const isLive = !['FT', 'AET', 'PEN', 'NS', 'PST', 'CANC'].includes(status);
  const scoreHome = fixture.goals?.home ?? '-';
  const scoreAway = fixture.goals?.away ?? '-';
  const isUpcoming = ['NS', 'TBD'].includes(status);
  const clock = getFeaturedMatchClock(fixture, clockTick);
  const headline = getFootballCompetitionLabel(fixture);
  const venue = [fixture.venue?.name, fixture.venue?.city].filter(Boolean).join(' · ');
  const centerValue = isUpcoming ? (formatTime(fixture.date) || clock || 'TBD') : `${scoreHome} - ${scoreAway}`;
  const matchUrl = getFootballFixtureUrl(fixture);
  const canTestGoalAnimation = Boolean(window.RIFNOTE_SEARCH?.canManageOptions);
  const goalScorers = getFeaturedGoalScorers(fixture);

  function move(direction) {
    setActive((current) => (current + direction + cleanFixtures.length) % cleanFixtures.length);
  }

  function testGoalAnimation() {
    const isHomeGoal = Number(scoreHome || 0) >= Number(scoreAway || 0);
    const team = isHomeGoal ? fixture.home : fixture.away;

    setGoalFlash({
      id: `admin-test-${Date.now()}`,
      type: 'goal',
      team: team?.name || (isHomeGoal ? 'Home team' : 'Away team'),
      logo: team?.logo || '',
      scorer: 'Goal update',
      score: isUpcoming ? '1 - 0' : `${scoreHome} - ${scoreAway}`,
    });
  }

  function testVarAnimation() {
    const isHomeGoal = Number(scoreHome || 0) >= Number(scoreAway || 0);
    const team = isHomeGoal ? fixture.home : fixture.away;

    setGoalFlash({
      id: `admin-var-test-${Date.now()}`,
      type: 'var',
      team: team?.name || (isHomeGoal ? 'Home team' : 'Away team'),
      logo: team?.logo || '',
      scorer: 'Goal cancelled after review',
      score: isUpcoming ? '0 - 0' : `${scoreHome} - ${scoreAway}`,
    });
  }

  return (
    <section className={`rs-home-featured-football ${primary ? 'is-primary' : ''} ${isLive ? 'is-live' : ''}`} aria-label="Featured football match">
      {goalFlash ? (
        <a className={`rs-home-goal-flash is-${goalFlash.type || 'goal'}`} href={matchUrl} role="status" aria-live="polite" aria-label={`${goalFlash.type === 'var' ? 'VAR update' : 'Goal'} for ${goalFlash.team}. Open match details`}>
          <span className="rs-home-goal-post-scene" aria-hidden="true">
            <i className="rs-home-goal-post-frame" />
            <i className="rs-home-goal-net" />
            <em className="rs-home-goal-ball" />
            {goalFlash.type === 'var' ? <strong className="rs-home-var-screen">VAR</strong> : null}
          </span>
          <span className="rs-home-goal-team-mark">
            {goalFlash.logo ? <img src={goalFlash.logo} alt="" loading="eager" /> : <i>{String(goalFlash.team || 'GO').slice(0, 2).toUpperCase()}</i>}
          </span>
          <span className="rs-home-goal-kicker">{goalFlash.type === 'var' ? 'VAR check' : 'Goal'}</span>
          <b>{goalFlash.team}</b>
          <small>{goalFlash.scorer}</small>
          <strong>{goalFlash.score}</strong>
          <u>Tap for match details</u>
        </a>
      ) : null}
      <div className="rs-home-featured-football-top">
        <span className="rs-home-football-league">{headline}</span>
      </div>
      <a className="rs-home-scoreboard" href={matchUrl} aria-label={`Open ${fixture.home?.name || 'home team'} vs ${fixture.away?.name || 'away team'} match page`}>
        <HomeScoreboardTeam team={fixture.home} large />
        <div className="rs-home-scoreboard-score">
          <b>{centerValue}</b>
          {isLive && clock ? <small>{clock}</small> : null}
        </div>
        <HomeScoreboardTeam team={fixture.away} align="right" large />
      </a>
      {goalScorers.length ? <FeaturedGoalScorers goals={goalScorers} /> : null}
      {venue ? <div className="rs-home-football-venue">Venue: <b>{venue}</b></div> : null}
      {canTestGoalAnimation ? (
        <div className="rs-home-goal-test-row">
          <button className="rs-home-goal-test" type="button" onClick={testGoalAnimation}>
            Test goal animation
          </button>
          <button className="rs-home-goal-test" type="button" onClick={testVarAnimation}>
            Test VAR animation
          </button>
        </div>
      ) : null}
      {cleanFixtures.length > 1 ? (
        <div className="rs-home-scoreboard-controls is-hidden-visual" aria-label="Featured match carousel controls">
          <button type="button" onClick={() => move(-1)} aria-label="Previous featured match"><ArrowLeft size={15} /></button>
          <span>{active + 1}/{cleanFixtures.length}</span>
          <button type="button" onClick={() => move(1)} aria-label="Next featured match"><ArrowRight size={15} /></button>
        </div>
      ) : null}
    </section>
  );
}

function isFootballFixtureFinished(fixture) {
  const status = String(fixture?.status_short || fixture?.fixture?.status?.short || '').toUpperCase();
  const finishedStatuses = ['FT', 'AET', 'PEN', 'PST', 'CANC', 'ABD', 'AWD', 'WO'];

  if (finishedStatuses.includes(status)) {
    return true;
  }

  const elapsed = Number(fixture?.elapsed ?? fixture?.fixture?.status?.elapsed ?? 0);
  const goalsHome = fixture?.goals?.home;
  const goalsAway = fixture?.goals?.away;

  return elapsed >= 120 && goalsHome !== null && goalsHome !== undefined && goalsAway !== null && goalsAway !== undefined;
}

function getFootballFixtureUrl(fixture = {}) {
  const fallback = window.RIFNOTE_SEARCH?.featuredFootballUrl || `${window.RIFNOTE_SEARCH?.homeUrl || '/'}football/`;
  const fixtureId = getFixtureDeepLinkId(fixture);

  if (!fixtureId) {
    return fallback;
  }

  try {
    const url = new URL(fallback, window.location.origin);
    url.searchParams.set('fixture', fixtureId);

    const date = fixtureDateInput(fixture);

    if (date) {
      url.searchParams.set('date', date);
    }

    return url.toString();
  } catch (error) {
    const separator = fallback.includes('?') ? '&' : '?';
    const date = fixtureDateInput(fixture);
    return `${fallback}${separator}fixture=${encodeURIComponent(fixtureId)}${date ? `&date=${encodeURIComponent(date)}` : ''}`;
  }
}

function getFeaturedMatchClock(fixture = {}, now = Date.now()) {
  const status = String(fixture.status_short || fixture.fixture?.status?.short || '').toUpperCase();
  const elapsed = Number(fixture.elapsed ?? fixture.fixture?.status?.elapsed ?? 0);
  const extra = Number(fixture.extra ?? fixture.fixture?.status?.extra ?? 0);

  if (elapsed > 0) {
    return `${elapsed}${extra ? `+${extra}` : ''}'`;
  }

  if (status === 'NS' || status === 'TBD') {
    return formatCountdown(fixture.date || fixture.fixture?.date, now) || formatTime(fixture.date || fixture.fixture?.date) || 'TBD';
  }

  return status || formatTime(fixture.date || fixture.fixture?.date) || 'TBD';
}

function hasExplicitVarCancellation(fixture = {}) {
  const candidates = [
    ...(Array.isArray(fixture.events) ? fixture.events : []),
    ...(Array.isArray(fixture.timeline) ? fixture.timeline : []),
    ...(Array.isArray(fixture.goalscorers) ? fixture.goalscorers : []),
    ...(Array.isArray(fixture.goal_scorers) ? fixture.goal_scorers : []),
  ];

  return candidates.some((event) => {
    const text = [
      event.type,
      event.detail,
      event.event_type,
      event.kind,
      event.comments,
      event.comment,
      event.description,
    ].filter(Boolean).join(' ').toLowerCase();

    return text.includes('var') || text.includes('disallowed') || text.includes('cancelled') || text.includes('canceled') || text.includes('goal cancelled') || text.includes('goal canceled');
  });
}

function extractGoalScorer(fixture, isHomeGoal, cancelled = false) {
  const candidates = [
    ...(Array.isArray(fixture.events) ? fixture.events : []),
    ...(Array.isArray(fixture.timeline) ? fixture.timeline : []),
    ...(Array.isArray(fixture.goalscorers) ? fixture.goalscorers : []),
    ...(Array.isArray(fixture.goal_scorers) ? fixture.goal_scorers : []),
  ];
  const teamId = isHomeGoal ? (fixture.home?.id || fixture.teams?.home?.id) : (fixture.away?.id || fixture.teams?.away?.id);
  const teamName = isHomeGoal ? (fixture.home?.name || fixture.teams?.home?.name) : (fixture.away?.name || fixture.teams?.away?.name);
  const goalEvents = candidates
    .filter((event) => {
      const eventType = `${event.type || event.detail || event.event_type || event.kind || ''}`.toLowerCase();
      const isGoal = eventType.includes('goal') || eventType.includes('penalty');
      const eventTeamId = event.team?.id || event.team_id;
      const eventTeamName = event.team?.name || event.team_name;
      return isGoal && (!teamId || !eventTeamId || Number(eventTeamId) === Number(teamId)) && (!teamName || !eventTeamName || eventTeamName === teamName);
    })
    .sort((a, b) => Number(b.elapsed || b.time?.elapsed || b.minute || 0) - Number(a.elapsed || a.time?.elapsed || a.minute || 0));
  const latest = goalEvents[0] || fixture.last_goal || fixture.latest_goal || null;
  const scorer = latest?.player?.name || latest?.player_name || latest?.scorer || latest?.name || '';
  const minute = latest?.elapsed || latest?.time?.elapsed || latest?.minute || '';

  if (scorer && minute) {
    return `${scorer} · ${minute}'`;
  }

  if (cancelled) {
    return scorer ? `${scorer} · goal cancelled` : 'Goal cancelled after review';
  }

  return scorer || 'Goal update';
}

function getFeaturedGoalScorers(fixture = {}) {
  const candidates = [
    ...(Array.isArray(fixture.events) ? fixture.events : []),
    ...(Array.isArray(fixture.timeline) ? fixture.timeline : []),
    ...(Array.isArray(fixture.goalscorers) ? fixture.goalscorers : []),
    ...(Array.isArray(fixture.goal_scorers) ? fixture.goal_scorers : []),
  ];
  const homeId = Number(fixture.home?.id || fixture.teams?.home?.id || 0);
  const awayId = Number(fixture.away?.id || fixture.teams?.away?.id || 0);
  const homeName = fixture.home?.name || fixture.teams?.home?.name || '';
  const awayName = fixture.away?.name || fixture.teams?.away?.name || '';
  const seen = new Set();

  return candidates
    .map((event, index) => {
      const eventText = [
        event.type,
        event.detail,
        event.event_type,
        event.kind,
        event.comments,
        event.comment,
        event.description,
      ].filter(Boolean).join(' ').toLowerCase();

      if (!eventText.includes('goal') && !eventText.includes('penalty')) {
        return null;
      }

      if (eventText.includes('missed') || eventText.includes('disallowed') || eventText.includes('cancelled') || eventText.includes('canceled') || eventText.includes('var')) {
        return null;
      }

      const scorer = event.player?.name || event.player_name || event.scorer || event.name || event.goal_scorer || '';
      if (!scorer) {
        return null;
      }

      const minute = Number(event.elapsed || event.time?.elapsed || event.minute || event.time || 0);
      const extra = Number(event.extra || event.time?.extra || event.added_time || 0);
      const eventTeamId = Number(event.team?.id || event.team_id || 0);
      const eventTeamName = event.team?.name || event.team_name || event.club || '';
      const isHome = (eventTeamId && homeId && eventTeamId === homeId) || (!eventTeamId && eventTeamName && homeName && eventTeamName === homeName);
      const isAway = (eventTeamId && awayId && eventTeamId === awayId) || (!eventTeamId && eventTeamName && awayName && eventTeamName === awayName);
      const team = isHome ? fixture.home : (isAway ? fixture.away : (event.team || {}));
      const teamName = team?.name || eventTeamName || '';
      const key = `${minute || index}-${scorer}-${teamName}`;

      if (seen.has(key)) {
        return null;
      }

      seen.add(key);

      return {
        key,
        minute,
        extra,
        scorer,
        assist: typeof event.assist === 'string'
          ? event.assist
          : (event.assist?.name || event.assist_name || event.assisted_by || event.assist_player || ''),
        teamName,
        teamLogo: team?.logo || event.team?.logo || '',
        isHome,
      };
    })
    .filter(Boolean)
    .sort((a, b) => (a.minute || 0) - (b.minute || 0))
    .slice(0, 8);
}

function FeaturedGoalScorers({ goals = [] }) {
  if (!goals.length) {
    return null;
  }

  const homeGoals = goals.filter((goal) => goal.isHome);
  const awayGoals = goals.filter((goal) => !goal.isHome);
  const hasAssists = goals.some((goal) => goal.assist);

  function renderGoal(goal) {
    const minute = formatFeaturedGoalMinute(goal);

    return (
      <span className="rs-home-goal-line-item" key={goal.key}>
        <b>{minute}</b>
        <strong>{formatFeaturedPlayerName(goal.scorer)}</strong>
      </span>
    );
  }

  function renderAssist(goal) {
    const minute = formatFeaturedGoalMinute(goal);

    if (!goal.assist) {
      return null;
    }

    return (
      <span className="rs-home-goal-line-item" key={`assist-${goal.key}`}>
        <b>{minute}</b>
        <strong>{formatFeaturedPlayerName(goal.assist)}</strong>
      </span>
    );
  }

  return (
    <div className="rs-home-goal-scorers" aria-label="Goal scorers and assists">
      <div className="rs-home-goal-row is-scorers">
        <div className="rs-home-goal-side is-home">{homeGoals.length ? homeGoals.map(renderGoal) : <span className="rs-home-goal-empty">-</span>}</div>
        <span className="rs-home-goal-label">Goals</span>
        <div className="rs-home-goal-side is-away">{awayGoals.length ? awayGoals.map(renderGoal) : <span className="rs-home-goal-empty">-</span>}</div>
      </div>
      {hasAssists ? (
        <div className="rs-home-goal-row is-assists">
          <div className="rs-home-goal-side is-home">{homeGoals.map(renderAssist).filter(Boolean)}</div>
          <span className="rs-home-goal-label">Assists</span>
          <div className="rs-home-goal-side is-away">{awayGoals.map(renderAssist).filter(Boolean)}</div>
        </div>
      ) : null}
    </div>
  );
}

function formatFeaturedGoalMinute(goal = {}) {
  return goal.minute ? `${goal.minute}${goal.extra ? `+${goal.extra}` : ''}'` : 'Goal';
}

function formatFeaturedPlayerName(name = '') {
  const clean = String(name || '').replace(/\s+/g, ' ').trim();
  const parts = clean.split(' ').filter(Boolean);

  if (parts.length < 2) {
    return clean;
  }

  return `${parts[0].charAt(0).toUpperCase()}. ${parts.slice(1).join(' ')}`;
}

function playVarDecisionSound() {
  if (typeof window === 'undefined') {
    return;
  }

  const AudioContext = window.AudioContext || window.webkitAudioContext;

  if (!AudioContext) {
    return;
  }

  try {
    const context = new AudioContext();
    const master = context.createGain();
    master.gain.setValueAtTime(0.0001, context.currentTime);
    master.gain.exponentialRampToValueAtTime(0.14, context.currentTime + 0.04);
    master.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 1.25);
    master.connect(context.destination);

    [220, 185, 146].forEach((frequency, index) => {
      const oscillator = context.createOscillator();
      const gain = context.createGain();
      oscillator.type = 'square';
      oscillator.frequency.setValueAtTime(frequency, context.currentTime + (index * 0.18));
      gain.gain.setValueAtTime(0.0001, context.currentTime + (index * 0.18));
      gain.gain.exponentialRampToValueAtTime(0.13, context.currentTime + 0.04 + (index * 0.18));
      gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 0.16 + (index * 0.18));
      oscillator.connect(gain);
      gain.connect(master);
      oscillator.start(context.currentTime + (index * 0.18));
      oscillator.stop(context.currentTime + 0.24 + (index * 0.18));
    });

    window.setTimeout(() => context.close().catch(() => {}), 1400);
  } catch (error) {
    // Browsers can block audio until interaction; the VAR visual still runs.
  }
}

function playGoalCelebrationSound() {
  if (typeof window === 'undefined') {
    return;
  }

  const AudioContext = window.AudioContext || window.webkitAudioContext;

  if (!AudioContext) {
    return;
  }

  try {
    const context = new AudioContext();
    const master = context.createGain();
    master.gain.setValueAtTime(0.0001, context.currentTime);
    master.gain.exponentialRampToValueAtTime(0.18, context.currentTime + 0.05);
    master.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 1.45);
    master.connect(context.destination);

    [261.63, 329.63, 392, 523.25].forEach((frequency, index) => {
      const oscillator = context.createOscillator();
      const gain = context.createGain();
      oscillator.type = index % 2 ? 'triangle' : 'sine';
      oscillator.frequency.setValueAtTime(frequency, context.currentTime + (index * 0.08));
      gain.gain.setValueAtTime(0.0001, context.currentTime);
      gain.gain.exponentialRampToValueAtTime(0.16, context.currentTime + 0.08 + (index * 0.08));
      gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 0.75 + (index * 0.08));
      oscillator.connect(gain);
      gain.connect(master);
      oscillator.start(context.currentTime + (index * 0.08));
      oscillator.stop(context.currentTime + 1.1 + (index * 0.08));
    });

    const bufferSize = context.sampleRate * 1.2;
    const buffer = context.createBuffer(1, bufferSize, context.sampleRate);
    const data = buffer.getChannelData(0);

    for (let i = 0; i < bufferSize; i += 1) {
      const envelope = Math.sin((i / bufferSize) * Math.PI);
      data[i] = (Math.random() * 2 - 1) * envelope * 0.16;
    }

    const crowd = context.createBufferSource();
    const crowdGain = context.createGain();
    crowd.buffer = buffer;
    crowdGain.gain.setValueAtTime(0.0001, context.currentTime);
    crowdGain.gain.exponentialRampToValueAtTime(0.12, context.currentTime + 0.12);
    crowdGain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 1.35);
    crowd.connect(crowdGain);
    crowdGain.connect(master);
    crowd.start(context.currentTime + 0.06);
    crowd.stop(context.currentTime + 1.35);

    window.setTimeout(() => context.close().catch(() => {}), 1600);
  } catch (error) {
    // Some browsers block audio until interaction. The visual celebration still runs.
  }
}

function HomeScoreboardTeam({ team = {}, align = 'left', large = false }) {
  const displayName = shortTeamName(team.name || 'Team', 18);
  return (
    <span className={`rs-home-scoreboard-team ${align === 'right' ? 'is-right' : ''} ${large ? 'is-large' : ''}`}>
      {team.logo ? <img src={team.logo} alt="" loading="lazy" /> : <i>{displayName.slice(0, 2).toUpperCase()}</i>}
      <b title={team.name || 'Team'}>{displayName}</b>
    </span>
  );
}

function ElectionTakeover({ takeover, primary = false }) {
  const [secondsLeft, setSecondsLeft] = useState(Math.max(0, Number(takeover?.countdown_seconds || 0)));
  const isLive = takeover?.phase === 'live';
  const parties = Array.isArray(takeover?.parties) ? takeover.parties : [];
  const scope = takeover?.scope === 'national' ? 'national' : 'state';
  const lgaResults = Array.isArray(takeover?.lga_results) ? takeover.lga_results : [];
  const stateResults = Array.isArray(takeover?.state_results) ? takeover.state_results : [];
  const pulseResults = scope === 'national' ? stateResults : lgaResults;
  const coverageFallback = scope === 'national' ? 'Nigeria election' : 'Osun election';
  const coverageUrl = takeover?.coverage_url || searchUrl(coverageFallback);
  const mediaUrl = takeover?.media_url || '';
  const mediaType = takeover?.media_type || 'image';
  const electionState = scope === 'national' ? 'Nigeria' : decodeText(takeover?.state || 'Osun');

  useEffect(() => {
    setSecondsLeft(Math.max(0, Number(takeover?.countdown_seconds || 0)));
  }, [takeover?.countdown_seconds]);

  useEffect(() => {
    if (isLive || secondsLeft <= 0) {
      return undefined;
    }

    const timer = window.setInterval(() => {
      setSecondsLeft((value) => Math.max(0, value - 1));
    }, 1000);

    return () => window.clearInterval(timer);
  }, [isLive, secondsLeft]);

  const countdown = countdownParts(secondsLeft);
  const reportingText = scope === 'national'
    ? (takeover?.states_total ? `${Number(takeover.states_reporting || 0).toLocaleString()} of ${Number(takeover.states_total || 0).toLocaleString()} states` : 'National results desk')
    : (takeover?.lgas_total ? `${Number(takeover.lgas_reporting || 0).toLocaleString()} of ${Number(takeover.lgas_total || 0).toLocaleString()} LGAs` : 'Results desk');
  const unitsText = takeover?.units_total
    ? `${Number(takeover.units_reporting || 0).toLocaleString()} of ${Number(takeover.units_total || 0).toLocaleString()} polling units`
    : '';

  return (
    <section className={`rs-election-takeover ${primary ? 'is-primary' : ''} ${isLive ? 'is-live' : 'is-countdown'}`}>
      <div className="rs-election-copy">
        <span className="rs-election-eyebrow">{decodeText(takeover?.eyebrow || 'Osun Decides')}</span>
        <h1>{decodeText(takeover?.title || 'Osun Decides')}</h1>
        {takeover?.subtitle ? <p>{decodeText(takeover.subtitle)}</p> : null}
        {!isLive ? (
          <div className="rs-election-countdown" aria-label="Election countdown">
            {countdown.map((part) => (
              <span key={part.label}>
                <strong>{part.value}</strong>
                <small>{part.label}</small>
              </span>
            ))}
          </div>
        ) : (
          <div className="rs-election-live-meta">
            <Badge tone="success">Live results</Badge>
            <span>{reportingText}</span>
            {unitsText ? <span>{unitsText}</span> : null}
          </div>
        )}
        <a className="rs-election-coverage-link" href={coverageUrl}>
          {`Aggregate ${electionState} election news`} <ArrowRight size={18} />
        </a>
      </div>

      <div className="rs-election-visual">
        {mediaUrl && !isLive ? (
          mediaType === 'video' ? <video src={mediaUrl} autoPlay muted loop playsInline preload="metadata" /> : <img src={mediaUrl} alt="" loading="eager" />
        ) : (
          <div className="rs-election-results">
            <div className="rs-election-results-head">
              <strong>{isLive ? 'Result tracker' : 'Ready for result night'}</strong>
              <small>{takeover?.last_update_label || (isLive ? 'Updating live' : 'Countdown mode')}</small>
            </div>
            <div className="rs-election-party-list">
              {parties.slice(0, 6).map((party) => (
                <article className="rs-election-party" key={`${party.name}-${party.candidate}`}>
                  <div className="rs-election-party-logo" style={{ '--party-color': party.color || '#ed1c24' }}>
                    {party.logo_url ? <img src={party.logo_url} alt="" loading="lazy" /> : <span>{String(party.name || 'P').slice(0, 2).toUpperCase()}</span>}
                  </div>
                  <div>
                    <b>{decodeText(party.name)}</b>
                    {party.candidate ? <small>{decodeText(party.candidate)}</small> : null}
                    <i><em style={{ width: `${Math.max(4, Number(party.bar_width || 0))}%`, background: party.color || '#ed1c24' }} /></i>
                  </div>
                  <strong>{Number(party.votes || 0).toLocaleString()}</strong>
                </article>
              ))}
            </div>
            {pulseResults.length ? (
              <div className="rs-election-lga-pulse" aria-label={scope === 'national' ? 'Nigeria state results' : `${electionState} LGA results`}>
                <div>
                  <strong>{scope === 'national' ? 'State pulse' : `${electionState} LGA pulse`}</strong>
                  <small>{`${Number(scope === 'national' ? takeover?.states_reporting || 0 : takeover?.lgas_reporting || 0).toLocaleString()} reporting`}</small>
                </div>
                <div className="rs-election-lga-list">
                  {pulseResults.slice(0, 6).map((row) => (
                    <span key={row.key || row.lga || row.state}>
                      <b>{decodeText(scope === 'national' ? row.state : row.lga)}</b>
                      <em>{row.leader_name ? `${decodeText(row.leader_name)} ${Number(row.leader_votes || 0).toLocaleString()}` : decodeText(row.status || 'Pending')}</em>
                    </span>
                  ))}
                </div>
              </div>
            ) : null}
            <p>{decodeText(takeover?.result_note || 'Awaiting official result updates.')}</p>
          </div>
        )}
      </div>
    </section>
  );
}

function countdownParts(totalSeconds) {
  const seconds = Math.max(0, Number(totalSeconds || 0));
  const days = Math.floor(seconds / 86400);
  const hours = Math.floor((seconds % 86400) / 3600);
  const minutes = Math.floor((seconds % 3600) / 60);
  const secs = Math.floor(seconds % 60);

  return [
    { label: 'days', value: String(days).padStart(2, '0') },
    { label: 'hrs', value: String(hours).padStart(2, '0') },
    { label: 'mins', value: String(minutes).padStart(2, '0') },
    { label: 'secs', value: String(secs).padStart(2, '0') },
  ];
}

function HomeQuickLinks({ activePill = 'Notes', items = defaultHomePills, onSelect, showCategories = false, categoriesActive = false, onCategoriesToggle }) {
  return (
    <div className="rs-google-quicklinks" aria-label="Homepage filters">
      {items.map((item) => (
        <button className={activePill === item.category ? 'active' : ''} key={`${item.label}-${item.category}`} type="button" onClick={() => onSelect?.(item)}>
          <span>{item.label}</span>
        </button>
      ))}
      {showCategories ? (
        <button className={`rs-home-categories-tab ${categoriesActive ? 'active' : ''}`} type="button" onClick={onCategoriesToggle}>
          <span>Categories</span>
        </button>
      ) : null}
    </div>
  );
}

function HomeCategoryBrowser({ categories = [] }) {
  return (
    <Card className="rs-home-category-browser">
      <div className="rs-home-category-head">
        <h2>Categories</h2>
      </div>
      <div className="rs-home-category-pills">
        {categories.length ? categories.map((category) => (
          <a href={category.url} key={category.id || category.slug}>
            <span>{category.name}</span>
            {category.count ? <small>{category.count.toLocaleString()}</small> : null}
          </a>
        )) : (
          <p>No categories are available.</p>
        )}
      </div>
    </Card>
  );
}

function AdminStoryActions({ story, compact = false }) {
  const canManage = Boolean(window.RIFNOTE_SEARCH?.canManagePosts);
  const [isDeleting, setIsDeleting] = useState(false);

  if (!canManage || !story || (!story.admin_edit_url && !story.admin_delete_url)) {
    return null;
  }

  const title = decodeText(story.headline || 'this story');

  async function handleDelete(event) {
    if (!window.confirm(`Move "${title}" to trash?`)) {
      return;
    }

    setIsDeleting(true);

    try {
      await trashStory(story.id);
      const item = event.currentTarget.closest('article');
      if (item) {
        item.classList.add('is-admin-trashed');
        window.setTimeout(() => item.remove(), 180);
      }
    } catch (_) {
      if (story.admin_delete_url) {
        window.location.href = story.admin_delete_url;
        return;
      }
      window.alert('Rifnote could not move this story to trash.');
      setIsDeleting(false);
    }
  }

  return (
    <div className={`rs-admin-story-actions ${compact ? 'is-compact' : ''}`} aria-label="Admin story actions">
      {story.admin_edit_url ? (
        <a href={story.admin_edit_url} aria-label={`Edit ${title}`} title="Edit story">
          <Pencil size={15} />
        </a>
      ) : null}
      {story.admin_delete_url ? (
        <button className="is-delete" type="button" aria-label={`Delete ${title}`} title="Move to trash" onClick={handleDelete} disabled={isDeleting}>
          <Trash2 size={15} />
        </button>
      ) : null}
    </div>
  );
}

function HomeHighlights({ activePill = 'Notes', activeCategory = 'Notes', archiveUrl = '', leadStory = null, notes = null, loading }) {
  const isFeaturedTab = activeCategory === '__featured__' || activeCategory === 'Featured';
  const isNotes = activePill === 'Notes' && !isFeaturedTab;
  const noteStories = useMemo(() => (Array.isArray(notes) ? notes.slice(0, 5) : []), [notes]);
  const [openNoteId, setOpenNoteId] = useState('');
  const title = isNotes ? 'Live Notes' : activePill;
  const archiveLabel = isNotes ? 'See All Notes' : `See All ${activePill}`;
  const archiveHref = archiveUrl || `${window.RIFNOTE_SEARCH?.homeUrl || '/'}category/${slugify(activeCategory || activePill)}/`;
  const emptyTitle = activePill === 'Notes' ? 'Notes are being curated' : `${activePill} is quiet right now`;
  const emptyCopy = activePill === 'Notes'
    ? 'The editorial team will pin five quick story summaries here from the backend.'
    : `No hand-picked ${activePill.toLowerCase()} headlines yet. Assign posts to this pill from the post editor or the Posts list.`;

  useEffect(() => {
    if (!noteStories.length) {
      setOpenNoteId('');
      return undefined;
    }

    const syncFromHash = () => {
      const hash = window.location.hash.replace('#', '');
      const hashMatch = noteStories.some((story) => `home-pill-${slugify(activeCategory || activePill)}-${story.id}` === hash);
      setOpenNoteId(hashMatch ? hash : '');
    };

    syncFromHash();
    window.addEventListener('hashchange', syncFromHash);
    return () => window.removeEventListener('hashchange', syncFromHash);
  }, [activeCategory, activePill, noteStories]);

  function toggleNote(noteId) {
    setOpenNoteId((current) => {
      const next = current === noteId ? '' : noteId;
      if (next) {
        window.history.replaceState(null, '', `#${next}`);
      }
      return next;
    });
  }

  if (loading) {
    return <LoadingGrid />;
  }

  return (
    <Card className={`rs-notes-card ${isNotes ? 'is-notes' : 'is-headlines'}`}>
      <CardHeader title={title} />
      <div className="rs-notes-list">
        {noteStories.length ? noteStories.map((story) => {
          const hasStoryHub = Boolean(story.has_story_hub && story.story_url);
          const storyUrl = hasStoryHub ? story.story_url : (story.read_full_story_url || story.original_url || '#');
          const source = decodeText(story.source_name || story.source_domain || 'Rifnote');
          const excerpt = decodeText(story.excerpt || story.summary || 'A quick source-backed note is ready for this story.');
          const fullContent = story.full_content || story.content || story.body || '';
          const plainContent = decodeText(story.raw_content || story.text || '');
          const noteId = `home-pill-${slugify(activeCategory || activePill)}-${story.id}`;
          const isOpen = openNoteId === noteId;
          return (
          <article className={`rs-live-note-accordion ${isOpen ? 'is-open' : ''}`} id={noteId} key={`${story.cluster_id}-${story.id}`}>
            <div className="rs-live-note-main">
              <button className="rs-live-note-trigger" type="button" aria-expanded={isOpen} aria-controls={`${noteId}-panel`} onClick={() => toggleNote(noteId)}>
                <SourceLogo story={story} />
                <span>
                  <small>{source} · {story.published_at_human || formatDate(story.published_at)}</small>
                  <b>{decodeText(story.headline)}</b>
                </span>
              </button>
              <AdminStoryActions story={story} compact />
              <div className="rs-live-note-panel" id={`${noteId}-panel`} hidden={!isOpen}>
                {isOpen ? (
                  <>
                    <HomeStoryEmbed story={story} activePill={activePill} />
                    {fullContent ? (
                      <div className="rs-home-pill-full-content" dangerouslySetInnerHTML={{ __html: fullContent }} />
                    ) : plainContent ? (
                      <p>{plainContent}</p>
                    ) : (
                      <p>{excerpt}</p>
                    )}
                    {hasStoryHub ? (
                      <footer>
                        <a className="rs-note-breakdown-link" href={storyUrl} onClick={() => trackStoryClick(story, 'full_coverage_click', '')}>Breakdown <ArrowRight size={13} /></a>
                      </footer>
                    ) : null}
                    <HomeStoryShare story={story} noteId={noteId} />
                  </>
                ) : null}
              </div>
            </div>
          </article>
        );
        }) : (
          <article className="rs-notes-empty">
            <div>
              <h3>{emptyTitle}</h3>
              <p>{emptyCopy}</p>
            </div>
          </article>
        )}
      </div>
      {noteStories.length ? (
        <a className={`rs-home-archive-link ${isNotes ? 'is-notes' : ''}`} href={archiveHref}>
          {archiveLabel} <ArrowRight size={15} />
        </a>
      ) : null}
    </Card>
  );
}

function HomeStoryEmbed({ story, activePill = 'Notes' }) {
  const videoUrl = getStoryVideoUrl(story) || story.original_url || story.read_full_story_url || '';
  const previewSrc = youtubePreviewSrc(story, true);
  const thumbnail = story.image || story.image_url || youtubeThumbnail(story);
  const embedHtml = useResolvedStoryEmbed(story);
  const platform = getStorySocialPlatform(story);
  const sourceUrl = story.original_url || story.read_full_story_url || story.source_url || '';

  if (previewSrc) {
    return (
      <div className="rs-home-story-embed is-youtube">
        <YouTubePreview story={story} videoUrl={videoUrl} previewSrc={previewSrc} thumbnail={thumbnail} query={`home_${activePill}`} />
      </div>
    );
  }

  if (embedHtml) {
    return (
      <div className="rs-home-story-embed is-social">
        <SmartEmbedHtml html={embedHtml} className="rs-home-social-embed-html" />
        {sourceUrl ? (
          <a className="rs-home-embed-source" href={sourceUrl} target="_blank" rel="noreferrer" onClick={() => trackStoryClick(story, 'home_embed_source_click', activePill)}>
            Open on {platform || decodeText(story.source_name || 'source')} <ExternalLink size={13} />
          </a>
        ) : null}
      </div>
    );
  }

  if (isSocialStory(story) && sourceUrl) {
    return (
      <a className="rs-home-story-embed is-social-link" href={sourceUrl} target="_blank" rel="noreferrer" onClick={() => trackStoryClick(story, 'home_social_source_click', activePill)}>
        <SourceLogo story={story} size="small" />
        <span>
          <small>{platform || decodeText(story.source_name || 'Social')}</small>
          <b>Open the original post</b>
        </span>
        <ExternalLink size={14} />
      </a>
    );
  }

  return null;
}

function HomeStoryShare({ story, noteId }) {
  const shareUrl = story.share_url || story.permalink || story.story_url || story.canonical_url || `${window.location.origin}${window.location.pathname}${window.location.search}#${noteId}`;
  const title = decodeText(story.headline || 'Rifnote story');
  const encodedUrl = encodeURIComponent(shareUrl);
  const encodedTitle = encodeURIComponent(title);

  function copyShareLink() {
    navigator.clipboard?.writeText(shareUrl).catch(() => {});
  }

  return (
    <div className="rs-home-story-share" aria-label="Share this story">
      <span>Share:</span>
      <a className="is-x" href={`https://twitter.com/intent/tweet?url=${encodedUrl}&text=${encodedTitle}`} target="_blank" rel="noreferrer" aria-label="Share on X"><ShareGlyph type="x" /></a>
      <a className="is-facebook" href={`https://www.facebook.com/sharer/sharer.php?u=${encodedUrl}`} target="_blank" rel="noreferrer" aria-label="Share on Facebook"><ShareGlyph type="facebook" /></a>
      <a className="is-whatsapp" href={`https://api.whatsapp.com/send?text=${encodedTitle}%20${encodedUrl}`} target="_blank" rel="noreferrer" aria-label="Share on WhatsApp"><ShareGlyph type="whatsapp" /></a>
      <a className="is-email" href={`mailto:?subject=${encodedTitle}&body=${encodedUrl}`} aria-label="Share by email"><ShareGlyph type="email" /></a>
      <button className="is-copy" type="button" onClick={copyShareLink} aria-label="Copy story link"><ShareGlyph type="copy" /></button>
    </div>
  );
}

function ShareGlyph({ type }) {
  if (type === 'x') {
    return (
      <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="M14.4 10.5 21.2 3h-1.7l-5.9 6.5L8.9 3H3.5l7.1 9.8L3.5 21h1.7l6.2-7 5 7h5.4l-7.4-10.5Zm-2.2 2.4-.7-1L5.8 4.3h2.3l4.6 6.2.7 1 6 8.2h-2.3l-4.9-6.8Z" />
      </svg>
    );
  }

  if (type === 'facebook') {
    return (
      <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="M14.2 8.2V6.6c0-.8.2-1.3 1.3-1.3h1.6V2.4c-.8-.1-1.6-.2-2.4-.2-2.4 0-4 1.5-4 4.1v1.9H8v3.2h2.7V22h3.5V11.4h2.7l.4-3.2h-3.1Z" />
      </svg>
    );
  }

  if (type === 'whatsapp') {
    return (
      <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="M12.1 3.1a8.7 8.7 0 0 0-7.4 13.3L3.6 21l4.7-1.2a8.7 8.7 0 1 0 3.8-16.7Zm0 15.8a7 7 0 0 1-3.5-.9l-.3-.2-2.8.7.8-2.7-.2-.3a7 7 0 1 1 6 3.4Zm3.9-5.2c-.2-.1-1.3-.6-1.5-.7s-.4-.1-.5.1-.6.7-.7.9-.3.2-.5.1a5.7 5.7 0 0 1-2.8-2.5c-.2-.3 0-.4.1-.5l.4-.5c.1-.2.2-.3.3-.5a.5.5 0 0 0 0-.5l-.7-1.6c-.2-.4-.4-.4-.5-.4h-.5a1 1 0 0 0-.7.3 2.8 2.8 0 0 0-.9 2.1 4.9 4.9 0 0 0 1 2.6 11.1 11.1 0 0 0 4.2 3.7 14 14 0 0 0 1.4.5 3.4 3.4 0 0 0 1.5.1 2.5 2.5 0 0 0 1.7-1.2 2 2 0 0 0 .1-1.2c-.1-.1-.2-.1-.4-.2Z" />
      </svg>
    );
  }

  if (type === 'email') {
    return (
      <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
        <path d="M4 6.5A2.5 2.5 0 0 1 6.5 4h11A2.5 2.5 0 0 1 20 6.5v11a2.5 2.5 0 0 1-2.5 2.5h-11A2.5 2.5 0 0 1 4 17.5v-11Zm2.2-.7 5.8 5.1 5.8-5.1H6.2Zm12 2-5.5 4.8a1 1 0 0 1-1.4 0L5.8 7.8v9.7c0 .4.3.7.7.7h11c.4 0 .7-.3.7-.7V7.8Z" />
      </svg>
    );
  }

  return (
    <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
      <path d="M9.2 14.8a1 1 0 0 1 0-1.4l4.2-4.2a1 1 0 0 1 1.4 1.4l-4.2 4.2a1 1 0 0 1-1.4 0Zm-2.8 2.8a4 4 0 0 1 0-5.7l2-2a1 1 0 1 1 1.4 1.4l-2 2a2 2 0 1 0 2.9 2.9l2-2a1 1 0 1 1 1.4 1.4l-2 2a4 4 0 0 1-5.7 0Zm7.8-7.8a1 1 0 0 1 0-1.4l2-2a2 2 0 1 1 2.9 2.9l-2 2a1 1 0 1 1-1.4-1.4l2-2a2 2 0 0 0-2.9-2.9l-2 2a1 1 0 0 1-1.4 0Z" />
    </svg>
  );
}

function TopHeadline({ story }) {
  const hasStoryHub = Boolean(story.has_story_hub && story.story_url);
  const storyUrl = hasStoryHub ? story.story_url : (story.read_full_story_url || story.original_url || '#');
  const imageUrl = story.image || story.image_url || '';

  return (
    <article className="rs-top-headline">
      <a className="rs-top-headline-media" href={storyUrl} target={hasStoryHub ? undefined : '_blank'} rel={hasStoryHub ? undefined : 'noreferrer'} aria-label={`${hasStoryHub ? 'More on' : 'Read'} ${decodeText(story.headline)}`} onClick={() => trackStoryClick(story, hasStoryHub ? 'full_coverage_click' : 'read_full_story_click', '')}>
        {imageUrl ? <img src={imageUrl} alt="" loading="lazy" /> : <span><Newspaper size={34} /></span>}
      </a>
      <div className="rs-top-headline-copy">
        <div className="rs-top-headline-meta">
          <Badge tone="danger">Main story</Badge>
          <SourceMention story={story} showDomain />
          <span>{story.published_at_human || formatDate(story.published_at)}</span>
        </div>
        <h3>{decodeText(story.headline)}</h3>
        <p>{decodeText(story.excerpt || story.summary || '')}</p>
        <div className="rs-top-headline-actions">
          <a className="rs-button primary" href={storyUrl} target={hasStoryHub ? undefined : '_blank'} rel={hasStoryHub ? undefined : 'noreferrer'} onClick={() => trackStoryClick(story, hasStoryHub ? 'full_coverage_click' : 'read_full_story_click', '')}>{hasStoryHub ? 'See the full picture' : 'Read the source'} <ArrowRight size={16} /></a>
          {story.read_full_story_url || story.original_url ? <a className="rs-button ghost" href={story.read_full_story_url || story.original_url} target="_blank" rel="noreferrer" onClick={() => trackStoryClick(story, 'read_full_story_click', '')}>Open source <ExternalLink size={16} /></a> : null}
        </div>
      </div>
    </article>
  );
}

function CategorySpotlight({ state }) {
  const spotlight = rifnoteCategories.filter((category) => category !== 'All News').slice(0, 6);

  return (
    <section className="rs-category-grid">
      {spotlight.map((category) => (
        <button key={category} type="button" onClick={() => { state.setCategory(category); state.setPage(1); }}>
          <span>{category}</span>
          <strong>{category === 'Football' ? 'Scores, transfers and matchday updates' : `Latest ${category.toLowerCase()} coverage`}</strong>
        </button>
      ))}
    </section>
  );
}

function AiLoading() {
  return <GlassLoader compact label="Rifnote take" />;
}

function AiAnswer({ answer }) {
  return (
    <Card accent>
      <CardHeader title="Rifnote take" action={<Badge>{answer.cached ? 'Saved take' : 'Source checked'}</Badge>} />
      <p>{answer.short_answer}</p>
      <ul>{(answer.key_points ?? []).map((point) => <li key={point}>{point}</li>)}</ul>
      <div className="rs-source-strip"><span>Sources used</span>{(answer.sources ?? []).map((source) => <a className="rs-badge rs-source-badge-chip" href={source.url} key={`${source.label}-${source.url}`} target="_blank" rel="noreferrer" onClick={() => trackAnalyticsEvent({ event_type: 'source_click', source_name: decodeText(source.label), target_url: source.url })}><SourceLogo story={{ source_name: source.label, source_domain: source.domain || '', source_logo_url: source.source_logo_url || '', source_initials: source.source_initials || '' }} size="small" />{decodeText(source.label)}</a>)}</div>
    </Card>
  );
}

function AiUnavailable({ answer }) {
  const labels = {
    missing_query: 'Start with a query',
    insufficient_sources: 'Not enough sources',
    not_configured: 'AI takes are off',
  };

  return (
    <Card>
      <CardHeader title={labels[answer.reason] ?? 'No quick take yet'} action={<Badge>Fallback</Badge>} />
      <p>{answer.message ?? 'No AI take for this one yet. The regular results are still here.'}</p>
      {answer.sources?.length ? (
        <div className="rs-source-strip">
          <span>Sources we found</span>
          {answer.sources.slice(0, 4).map((source) => <a className="rs-badge rs-source-badge-chip" href={source.url} key={`${source.source_name}-${source.url}`} target="_blank" rel="noreferrer" onClick={() => trackAnalyticsEvent({ event_type: 'source_click', source_name: decodeText(source.source_name), target_url: source.url })}><SourceLogo story={{ source_name: source.source_name, source_domain: source.domain || '', source_logo_url: source.source_logo_url || '', source_initials: source.source_initials || '' }} size="small" />{decodeText(source.source_name)}</a>)}
        </div>
      ) : null}
    </Card>
  );
}

function ResultList({ results, query, state, insights = null }) {
  const [alertEmail, setAlertEmail] = useState('');
  const [alertStatus, setAlertStatus] = useState({ loading: false, message: '', error: '' });

  async function submitNoResultAlert(event) {
    event.preventDefault();
    setAlertStatus({ loading: true, message: '', error: '' });

    try {
      const response = await subscribeNoResult({ query, email: alertEmail, category: state.category ?? '' });
      setAlertStatus({ loading: false, message: response.message || 'Cool. We’ll tell you when something lands.', error: '' });
    } catch (error) {
      setAlertStatus({ loading: false, message: '', error: error.message });
    }
  }

  if (!results.length) {
    const suggestionItems = insights?.suggestions?.length ? insights.suggestions : [];
    const relatedItems = insights?.related_topics ?? [];

    return (
      <Card>
        <CardHeader title="Nothing solid yet" action={<Badge>Try another angle</Badge>} />
        <p>{insights?.message || `We couldn’t find enough good coverage${query ? ` for "${query}"` : ''}. Try a different keyword, clear filters, or check back soon.`}</p>
        <div className="rs-pills">
          {suggestionItems.map((item) => <button key={`${item.type}-${item.value}`} type="button" onClick={() => { state.setQuery(item.value); state.setPage(1); }}>{decodeText(item.label)}</button>)}
        </div>
        {relatedItems.length ? <div className="rs-pills secondary">{relatedItems.map((item) => <button key={`${item.type}-${item.value}`} type="button" onClick={() => { state.setQuery(item.value); state.setPage(1); }}>{decodeText(item.label)}</button>)}</div> : null}
        {query ? (
          <form className="rs-no-result-alert" onSubmit={submitNoResultAlert}>
            <input type="email" value={alertEmail} onChange={(event) => setAlertEmail(event.target.value)} placeholder="Email me when it drops" />
            <button className="rs-button ghost" type="submit" disabled={alertStatus.loading}>{alertStatus.loading ? 'Saving...' : 'Keep me posted'}</button>
            {alertStatus.error ? <p className="rs-form-error">{alertStatus.error}</p> : null}
            {alertStatus.message ? <p className="rs-form-success">{alertStatus.message}</p> : null}
          </form>
        ) : null}
      </Card>
    );
  }

  return <section className="rs-results">{results.map((story) => <StoryCard story={story} query={query} key={`${story.cluster_id}-${story.id}`} />)}</section>;
}

function CompactResultList({ results, query, state, insights = null }) {
  if (!results.length) {
    return <ResultList results={results} query={query} state={state} insights={insights} />;
  }

  return (
    <section className="rs-results rs-results-compact">
      {results.map((story) => <CompactStoryCard story={story} query={query} key={`compact-${story.cluster_id}-${story.id}`} />)}
    </section>
  );
}

function CompactStoryCard({ story, query = '' }) {
  if (getStoryVideoUrl(story)) {
    return <VideoStoryCard story={story} query={query} compact />;
  }

  return <SearchResultItem story={story} query={query} compact />;
}

function StoryCard({ story, query = '' }) {
  return <SearchResultItem story={story} query={query} />;
}

function SearchResultItem({ story, query = '', compact = false }) {
  const hasStoryHub = Boolean(story.has_story_hub && story.story_url);
  const storyUrl = storyReadUrl(story);
  const titleLinkProps = linkPropsForUrl(storyUrl);

  return (
    <article className={`rs-story-card rs-search-result-item ${compact ? 'rs-story-card-compact' : ''}`}>
      <AdminStoryActions story={story} compact={compact} />
      <div className="rs-result-source-line">
        <SourceLogo story={story} size="small" />
        <span>{decodeText(story.source_name || story.source_domain || 'Rifnote')}</span>
      </div>
      <h2>
        <a href={storyUrl} {...titleLinkProps} onClick={() => trackStoryClick(story, 'read_full_story_click', query)}>{decodeText(story.headline)}</a>
      </h2>
      {showStoryExcerpts ? <p>{trimWords(story.excerpt, 23)}</p> : null}
      <div className="rs-result-time-row">
        <span>{story.published_at_human || formatDate(story.published_at)}</span>
        {hasStoryHub ? <a href={story.story_url} onClick={() => trackStoryClick(story, 'full_coverage_click', query)}>Full Coverage</a> : null}
      </div>
    </article>
  );
}

function VideoStoryCard({ story, query = '', compact = false }) {
  const videoUrl = getStoryVideoUrl(story);
  const previewSrc = youtubePreviewSrc(story, true);
  const thumbnail = story.image || story.image_url || youtubeThumbnail(story);
  const embedHtml = useResolvedStoryEmbed(story);

  return (
    <article className={`rs-video-result-card ${compact ? 'is-compact' : ''}`}>
      {previewSrc ? (
        <YouTubePreview
          story={story}
          videoUrl={videoUrl}
          previewSrc={previewSrc}
          thumbnail={thumbnail}
          query={query}
        />
      ) : embedHtml ? (
        <SmartEmbedHtml html={embedHtml} className="rs-video-result-embed" />
      ) : null}
      <SearchResultItem story={story} query={query} />
    </article>
  );
}

function YouTubePreview({ story, videoUrl = '', previewSrc = '', thumbnail = '', query = '' }) {
  const [playing, setPlaying] = useState(false);
  const [previewEnded, setPreviewEnded] = useState(false);

  useEffect(() => {
    if (!playing) {
      return undefined;
    }

    const timer = window.setTimeout(() => {
      setPlaying(false);
      setPreviewEnded(true);
    }, 15000);

    return () => window.clearTimeout(timer);
  }, [playing]);

  function playPreview() {
    setPreviewEnded(false);
    setPlaying(true);
    trackStoryClick(story, 'video_preview_play', query);
  }

  return (
    <div className={`rs-youtube-preview ${playing ? 'is-playing' : ''} ${previewEnded ? 'is-ended' : ''}`}>
      {playing ? (
        <iframe title={`15 second preview: ${decodeText(story.headline)}`} src={previewSrc} allow="autoplay; encrypted-media; picture-in-picture" allowFullScreen />
      ) : (
        <button className="rs-youtube-poster" type="button" onClick={playPreview} aria-label={`Play 15 second preview of ${decodeText(story.headline)}`}>
          {thumbnail ? <img src={thumbnail} alt="" loading="lazy" /> : null}
          <span className="rs-youtube-play"><Play size={24} fill="currentColor" /></span>
          <small>{previewEnded ? 'Preview ended' : '15 sec preview'}</small>
        </button>
      )}
      {previewEnded ? (
        <div className="rs-youtube-actions">
          <button type="button" onClick={playPreview}><RotateCcw size={15} /> Replay preview</button>
          <a href={videoUrl} target="_blank" rel="noreferrer" onClick={() => trackStoryClick(story, 'video_full_youtube_click', query)}>Watch full on YouTube <ExternalLink size={14} /></a>
        </div>
      ) : null}
    </div>
  );
}

function StoryClaims({ claims = [] }) {
  if (!claims.length) {
    return null;
  }

  return (
    <div className="rs-claim-list" aria-label="Fact-check metadata">
      {claims.map((claim) => (
        <article key={claim.id}>
          <Badge tone="danger">Claim checked</Badge>
          <div>
            <strong>{decodeText(claim.rating || 'Reviewed claim')}</strong>
            <span>{decodeText(claim.claim_text)}</span>
            {claim.review_summary ? <small>{decodeText(claim.review_summary)}</small> : null}
            {claim.review_url ? <a href={claim.review_url} target="_blank" rel="noreferrer">Review source <ExternalLink size={13} /></a> : null}
          </div>
        </article>
      ))}
    </div>
  );
}

function StoryCluster({ story }) {
  if (!story.related_stories?.length) {
    return null;
  }

  return (
    <details className="rs-cluster">
      <summary>More coverage from {story.cluster_count} stories</summary>
      <div className="rs-cluster-list">
        {story.related_stories.map((related) => (
          <a href={related.read_full_story_url || related.original_url} key={related.id} target="_blank" rel="noreferrer" onClick={() => trackStoryClick(related, 'source_click', '')}>
            <SourceMention story={related} />
            <strong>{decodeText(related.headline)}</strong>
          </a>
        ))}
      </div>
    </details>
  );
}

function Pagination({ pagination, onPageChange }) {
  const current = Number(pagination.page ?? 1);
  const totalPages = Number(pagination.total_pages ?? 1);

  if (totalPages <= 1) {
    return null;
  }

  return (
    <nav className="rs-pagination" aria-label="Search result pages">
      <button type="button" disabled={current <= 1} onClick={() => onPageChange(current - 1)}>Previous</button>
      <span>Page {current} of {totalPages}</span>
      <button type="button" disabled={current >= totalPages} onClick={() => onPageChange(current + 1)}>Next</button>
    </nav>
  );
}

function formatDate(value) {
  if (!value) {
    return '';
  }

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(date);
}

function decodeText(value = '') {
  if (value === null || value === undefined) {
    return '';
  }

  if (typeof document === 'undefined') {
    return String(value);
  }

  let text = String(value);
  const textarea = document.createElement('textarea');

  for (let index = 0; index < 4; index += 1) {
    textarea.innerHTML = text;
    const decoded = textarea.value;

    if (decoded === text) {
      break;
    }

    text = decoded;
  }

  return text;
}

function formatTime(value) {
  if (!value) {
    return 'TBD';
  }

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return 'TBD';
  }

  return new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' }).format(date);
}

function formatFullDateTime(value) {
  if (!value) {
    return 'Kickoff TBD';
  }

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return 'Kickoff TBD';
  }

  return new Intl.DateTimeFormat(undefined, {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date);
}

function formatCountdown(value, now = Date.now()) {
  if (!value) {
    return '';
  }

  const date = new Date(value);

  if (Number.isNaN(date.getTime())) {
    return '';
  }

  const diff = date.getTime() - now;

  if (diff <= 0) {
    return 'Starting soon';
  }

  const totalMinutes = Math.ceil(diff / 60000);
  const hours = Math.floor(totalMinutes / 60);
  const minutes = totalMinutes % 60;

  if (hours > 0) {
    return `${hours}h ${minutes}m`;
  }

  return `${minutes}m`;
}

function TopStories({ stories }) {
  return (
    <Card>
      <CardHeader title="What’s hot right now" action={<Badge>Ranked</Badge>} />
      <div className="rs-ranked">{stories.map((story, index) => <article key={story.id}><span>{index + 1}</span><div><h3>{decodeText(story.headline)}</h3><p><SourceMention story={story} /> · {story.published_at}</p></div></article>)}</div>
    </Card>
  );
}

function PwaFeatureDock() {
  const [open, setOpen] = useState(false);
  const [savedStories, setSavedStories] = useState(() => readSavedStories());
  const [preferences, setPreferences] = useState(() => readPwaPreferences());
  const [activeView, setActiveView] = useState('home');
  const [online, setOnline] = useState(() => navigator.onLine);
  const [notificationState, setNotificationState] = useState(() => (typeof Notification === 'undefined' ? 'unsupported' : Notification.permission));
  const [updateReady, setUpdateReady] = useState(false);
  const [refreshing, setRefreshing] = useState(false);
  const [showOnboarding, setShowOnboarding] = useState(() => isStandalonePwa() && window.localStorage?.getItem(pwaStorageKeys.onboarding) !== '1');
  const [catchup, setCatchup] = useState({ stories: 0, minutes: 0 });
  const isPwa = isStandalonePwa();
  const isMobile = Boolean(window.matchMedia?.('(max-width: 720px)')?.matches);
  const shouldShow = isPwa || isMobile;

  useEffect(() => {
    const syncSaved = () => setSavedStories(readSavedStories());
    const onOnline = () => setOnline(true);
    const onOffline = () => setOnline(false);

    window.addEventListener('storage', syncSaved);
    window.addEventListener('rifnote:saved-story', syncSaved);
    window.addEventListener('online', onOnline);
    window.addEventListener('offline', onOffline);

    return () => {
      window.removeEventListener('storage', syncSaved);
      window.removeEventListener('rifnote:saved-story', syncSaved);
      window.removeEventListener('online', onOnline);
      window.removeEventListener('offline', onOffline);
    };
  }, []);

  useEffect(() => {
    if (!isPwa) {
      return undefined;
    }

    const last = Number(window.localStorage?.getItem(pwaStorageKeys.lastCatchup) || 0);
    const minutes = last ? Math.max(1, Math.floor((Date.now() - last) / 60000)) : 0;
    setCatchup({ stories: savedStories.length, minutes });
    window.localStorage?.setItem(pwaStorageKeys.lastCatchup, String(Date.now()));

    const onVisible = () => {
      if (document.visibilityState === 'visible') {
        document.dispatchEvent(new CustomEvent('rifnote:pwa-resume'));
        setCatchup((current) => ({ ...current, minutes: 0 }));
      }
    };

    document.addEventListener('visibilitychange', onVisible);
    return () => document.removeEventListener('visibilitychange', onVisible);
  }, [isPwa, savedStories.length]);

  useEffect(() => {
    document.documentElement.classList.toggle('rs-pwa-compact', Boolean(preferences.compactMode));

    if ('setAppBadge' in navigator && isPwa) {
      if (savedStories.length) {
        navigator.setAppBadge(savedStories.length).catch(() => {});
      } else {
        navigator.clearAppBadge?.().catch(() => {});
      }
    }
  }, [isPwa, preferences.compactMode, savedStories.length]);

  useEffect(() => {
    let startY = 0;
    let pulling = false;

    function touchStart(event) {
      if (!isPwa || window.scrollY > 4 || event.touches.length !== 1) {
        return;
      }

      startY = event.touches[0].clientY;
      pulling = true;
    }

    function touchMove(event) {
      if (!pulling) {
        return;
      }

      const distance = event.touches[0].clientY - startY;
      document.documentElement.style.setProperty('--rs-pull-distance', `${Math.max(0, Math.min(72, distance))}px`);

      if (distance > 76) {
        pulling = false;
        setRefreshing(true);
        document.dispatchEvent(new CustomEvent('rifnote:pwa-refresh'));
        window.setTimeout(() => window.location.reload(), 260);
      }
    }

    function touchEnd() {
      pulling = false;
      document.documentElement.style.setProperty('--rs-pull-distance', '0px');
    }

    window.addEventListener('touchstart', touchStart, { passive: true });
    window.addEventListener('touchmove', touchMove, { passive: true });
    window.addEventListener('touchend', touchEnd, { passive: true });

    return () => {
      window.removeEventListener('touchstart', touchStart);
      window.removeEventListener('touchmove', touchMove);
      window.removeEventListener('touchend', touchEnd);
    };
  }, [isPwa]);

  useEffect(() => {
    if (!('serviceWorker' in navigator)) {
      return undefined;
    }

    function watchRegistration(registration) {
      if (!registration) {
        return;
      }

      if (registration.waiting) {
        setUpdateReady(true);
      }

      registration.addEventListener('updatefound', () => {
        const worker = registration.installing;
        if (!worker) return;
        worker.addEventListener('statechange', () => {
          if (worker.state === 'installed' && navigator.serviceWorker.controller) {
            setUpdateReady(true);
          }
        });
      });
    }

    const registrationPromise = navigator.serviceWorker.getRegistration ? navigator.serviceWorker.getRegistration() : Promise.resolve(null);
    registrationPromise.then(watchRegistration).catch(() => {});

    const onControllerChange = () => window.location.reload();
    navigator.serviceWorker.addEventListener('controllerchange', onControllerChange);
    return () => navigator.serviceWorker.removeEventListener('controllerchange', onControllerChange);
  }, []);

  if (!shouldShow) {
    return null;
  }

  async function requestPush() {
    if (typeof Notification === 'undefined') {
      setNotificationState('unsupported');
      return;
    }

    const permission = await Notification.requestPermission();
    setNotificationState(permission);
    navigator.vibrate?.(permission === 'granted' ? 24 : 8);
    await registerDevice({ anon_key: getAnonKey(), permission_status: permission, platform: isPwa ? 'pwa' : 'web' }).catch(() => {});
  }

  async function shareApp() {
    const url = window.RIFNOTE_SEARCH?.homeUrl || window.location.origin;
    if (navigator.share) {
      await navigator.share({ title: 'Rifnote', text: 'Search stories, scores and live notes on Rifnote.', url });
      return;
    }
    await navigator.clipboard?.writeText(url);
  }

  function addPreference(type, value) {
    const clean = value.trim();
    if (!clean) return;

    const next = {
      ...preferences,
      [type]: Array.from(new Set([...(preferences[type] || []), clean])).slice(0, 12),
    };
    setPreferences(next);
    navigator.vibrate?.(10);
    writePwaPreferences(next);
    savePreference({ anon_key: getAnonKey(), preference_type: type.replace(/s$/, ''), preference_value: clean, metadata: { source: 'pwa_command_center' } }).catch(() => {});
  }

  function removePreference(type, value) {
    const next = {
      ...preferences,
      [type]: (preferences[type] || []).filter((item) => item !== value),
    };
    setPreferences(next);
    navigator.vibrate?.(6);
    writePwaPreferences(next);
  }

  function togglePreferenceFlag(flag) {
    const next = { ...preferences, [flag]: !preferences[flag] };
    setPreferences(next);
    navigator.vibrate?.(8);
    writePwaPreferences(next);
  }

  function finishOnboarding() {
    window.localStorage?.setItem(pwaStorageKeys.onboarding, '1');
    setShowOnboarding(false);
  }

  function applyUpdate() {
    const registrationPromise = navigator.serviceWorker?.getRegistration ? navigator.serviceWorker.getRegistration() : Promise.resolve(null);
    registrationPromise.then((registration) => {
      registration?.waiting?.postMessage({ type: 'SKIP_WAITING' });
      setUpdateReady(false);
    }).catch(() => window.location.reload());
  }

  const openLabel = open ? 'Close app controls' : 'Open app controls';

  return (
    <>
      <div className={`rs-pwa-pull ${refreshing ? 'is-refreshing' : ''}`} aria-hidden="true">
        <span>{refreshing ? 'Refreshing' : 'Pull to refresh'}</span>
      </div>

      {updateReady ? (
        <aside className="rs-pwa-update-banner">
          <b>Fresh Rifnote is ready</b>
          <button type="button" onClick={applyUpdate}>Update</button>
        </aside>
      ) : null}

      {showOnboarding ? (
        <aside className="rs-pwa-onboarding" role="dialog" aria-modal="true" aria-label="Set up Rifnote">
          <div>
            <img src={window.RIFNOTE_SEARCH?.siteIconUrl || `${window.RIFNOTE_SEARCH?.pluginUrl || ''}public/rifnote-favicon.svg`} alt="" />
            <Badge tone="danger">Installed</Badge>
            <h2>Make Rifnote yours.</h2>
            <p>Pick teams, topics and cities once. The app will keep them close across live notes, alerts and your feed.</p>
            <div className="rs-pwa-onboarding-actions">
              <button type="button" onClick={() => { setActiveView('prefs'); setOpen(true); finishOnboarding(); }}>Set up feed</button>
              <button type="button" onClick={finishOnboarding}>Later</button>
            </div>
          </div>
        </aside>
      ) : null}

      <aside className={`rs-pwa-dock ${open ? 'is-open' : ''}`}>
        <button className="rs-pwa-dock-toggle" type="button" aria-label={openLabel} onClick={() => setOpen((current) => !current)}>
          <span><Radio size={20} /></span>
          <b>App</b>
        </button>
        {open ? (
          <section className="rs-pwa-dock-panel">
            <header>
              <div>
                <Badge tone={online ? '' : 'danger'}>{online ? 'Online' : 'Offline'}</Badge>
                <h2>Rifnote App</h2>
              <p>{isPwa ? 'Installed mode is active.' : 'Install Rifnote for the full app feel.'}{preferences.privateMode ? ' Private saves are on.' : ''}</p>
              </div>
              <button type="button" onClick={() => setOpen(false)} aria-label="Close app controls">×</button>
            </header>
            {isPwa && catchup.minutes > 2 ? (
              <div className="rs-pwa-catchup">
                <strong>You were away for {catchup.minutes}m</strong>
                <span>{catchup.stories} saved item{catchup.stories === 1 ? '' : 's'} ready offline.</span>
              </div>
            ) : null}
            <nav className="rs-pwa-dock-tabs">
              {[
                ['home', 'Home', <Home size={16} />],
                ['saved', 'Offline', <Bookmark size={16} />],
                ['alerts', 'Alerts', <Radio size={16} />],
                ['prefs', 'Feed', <UserRound size={16} />],
              ].map(([key, label, icon]) => (
                <button className={activeView === key ? 'active' : ''} type="button" key={key} onClick={() => setActiveView(key)}>
                  {icon}<span>{label}</span>
                </button>
              ))}
            </nav>

            {activeView === 'home' ? (
              <div className="rs-pwa-action-grid">
                <a href={`${window.RIFNOTE_SEARCH?.homeUrl || '/'}search/`}><Search size={18} />Search</a>
                <a href={`${window.RIFNOTE_SEARCH?.homeUrl || '/'}football/`}><Trophy size={18} />Football</a>
                <a href={`${window.RIFNOTE_SEARCH?.homeUrl || '/'}weather/`}><CloudSun size={18} />Weather</a>
                <button type="button" onClick={shareApp}><ExternalLink size={18} />Share app</button>
              </div>
            ) : null}

            {activeView === 'saved' ? (
              <div className="rs-pwa-saved-list">
                {savedStories.length ? savedStories.map((story) => (
                  <a href={story.story_url || story.read_full_story_url || story.original_url || '#'} key={`pwa-${story.id || story.headline}`}>
                    <SourceMention story={story} showTime />
                    <strong>{decodeText(story.headline)}</strong>
                  </a>
                )) : <p>No saved stories yet. Tap Save on search results to keep them here.</p>}
              </div>
            ) : null}

            {activeView === 'alerts' ? (
              <div className="rs-pwa-alerts-panel">
                <strong>Push setup</strong>
                <p>{notificationState === 'granted' ? 'Push is allowed on this device.' : notificationState === 'denied' ? 'Notifications are blocked in browser settings.' : 'Allow notifications for breaking stories, kickoffs and full-time alerts.'}</p>
                <button type="button" onClick={requestPush} disabled={notificationState === 'granted' || notificationState === 'unsupported'}>
                  {notificationState === 'granted' ? 'Push enabled' : notificationState === 'unsupported' ? 'Not supported' : 'Enable alerts'}
                </button>
              </div>
            ) : null}

            {activeView === 'prefs' ? (
              <PwaPreferencePanel preferences={preferences} onAdd={addPreference} onRemove={removePreference} onToggle={togglePreferenceFlag} />
            ) : null}
          </section>
        ) : null}
      </aside>
    </>
  );
}

function PwaPreferencePanel({ preferences, onAdd, onRemove, onToggle }) {
  const [drafts, setDrafts] = useState({ teams: '', topics: '', cities: '', sources: '' });

  return (
    <div className="rs-pwa-pref-panel">
      <section className="rs-pwa-pref-switches">
        <button className={preferences.compactMode ? 'active' : ''} type="button" onClick={() => onToggle('compactMode')}>
          Compact app mode
        </button>
        <button className={preferences.privateMode ? 'active' : ''} type="button" onClick={() => onToggle('privateMode')}>
          Private saved feed
        </button>
      </section>
      {[
        ['topics', 'Topics', 'Osimhen, elections, AI...'],
        ['teams', 'Teams', 'Chelsea, Nigeria, Arsenal...'],
        ['cities', 'Cities', 'Lagos, Abuja, New York...'],
        ['sources', 'Sources', 'BBC, Punch, Verge...'],
      ].map(([key, label, placeholder]) => (
        <section key={key}>
          <form onSubmit={(event) => { event.preventDefault(); onAdd(key, drafts[key]); setDrafts((current) => ({ ...current, [key]: '' })); }}>
            <label>{label}</label>
            <div>
              <input value={drafts[key]} onChange={(event) => setDrafts((current) => ({ ...current, [key]: event.target.value }))} placeholder={placeholder} />
              <button type="submit">Add</button>
            </div>
          </form>
          <div className="rs-pwa-pref-pills">
            {(preferences[key] || []).map((item) => (
              <button type="button" key={`${key}-${item}`} onClick={() => onRemove(key, item)}>{item} ×</button>
            ))}
          </div>
        </section>
      ))}
    </div>
  );
}

function BottomNav({ state, onLiveOpen = () => {} }) {
  const homeUrl = window.RIFNOTE_SEARCH?.homeUrl ? window.RIFNOTE_SEARCH.homeUrl.replace(/\/$/, '') : '';
  const goTo = (path) => { window.location.href = homeUrl ? `${homeUrl}${path}` : path; };
  const openMenu = () => {
    const trigger = document.querySelector('[data-rs-menu-open]');
    if (trigger) {
      trigger.click();
      return;
    }
    document.dispatchEvent(new CustomEvent('rifnote:open-menu'));
  };
  const items = [
    ['Home', <Home size={19} />, () => goTo('/search/')],
    ['Notes', <Newspaper size={19} />, () => goTo('/category/notes/')],
    ['Football', <Trophy size={19} />, () => goTo('/football/')],
    ['Live', <Radio size={19} />, onLiveOpen, 'live'],
    ['Menu', <Menu size={19} />, openMenu],
  ];

  return (
    <nav className="rs-bottom-nav" aria-label="Rifnote Search mobile navigation">
      {items.map(([label, icon, onClick, tone]) => <button className={tone ? `is-${tone}` : ''} key={label} type="button" onClick={onClick}>{icon}<span>{label}</span></button>)}
    </nav>
  );
}

function TrendingTopics({ state, live = false }) {
  const [topics, setTopics] = useState([]);
  const [updatedAt, setUpdatedAt] = useState(new Date());
  const [loading, setLoading] = useState(true);

  const refreshTopics = useCallback(() => {
    setLoading(true);
    getTrendingTopics({ limit: 10 })
      .then((payload) => {
        setTopics(payload.topics ?? []);
        setUpdatedAt(new Date());
      })
      .catch(() => {
        setTopics([]);
        setUpdatedAt(new Date());
      })
      .finally(() => setLoading(false));
  }, []);

  useLiveInterval(refreshTopics, 900000);

  return (
    <Card className={live ? 'rs-live-card' : ''}>
      <CardHeader title="Trending" action={<LiveBadge label={live ? 'Live' : 'Hot'} date={updatedAt} />} />
      <div className="rs-pills">
        {topics.map((topic) => <button key={topic.slug || topic.topic} type="button" onClick={() => state?.setQuery?.(topic.topic)}>{topic.topic}</button>)}
        {!topics.length ? <span className="rs-empty-mini">{loading ? 'Checking the latest keywords...' : 'No trending keywords saved yet.'}</span> : null}
      </div>
    </Card>
  );
}

function LiveScores({ live = false }) {
  const [updatedAt, setUpdatedAt] = useState(new Date());
  const [pulse, setPulse] = useState(0);
  const [fixtures, setFixtures] = useState([]);
  const [totalFixtures, setTotalFixtures] = useState(0);
  const [selectedFixture, setSelectedFixture] = useState(null);
  const [configured, setConfigured] = useState(false);
  const [provider, setProvider] = useState('');
  const [pollAfter, setPollAfter] = useState(live ? 15 : 60);
  const [scoreMode, setScoreMode] = useState('live');
  const refreshScores = useCallback(() => {
    Promise.allSettled([
      getFootballLive(),
      getFootballUpcoming({ next: 12 }),
    ])
      .then(([liveResult, upcomingResult]) => {
        const livePayload = liveResult.status === 'fulfilled' ? liveResult.value : {};
        const upcomingPayload = upcomingResult.status === 'fulfilled' ? upcomingResult.value : {};
        const liveFixtures = (livePayload.fixtures ?? []).filter(isFixtureLiveNow);
        const upcomingFixtures = (upcomingPayload.fixtures ?? []).filter(isFixtureUpcoming);
        const selectedPayload = liveFixtures.length ? livePayload : upcomingPayload;
        const selectedFixtures = liveFixtures.length ? liveFixtures : upcomingFixtures;
        setFixtures(selectedFixtures);
        setTotalFixtures(selectedFixtures.length);
        setScoreMode(liveFixtures.length ? 'live' : 'upcoming');
        setConfigured(!!(livePayload.configured || upcomingPayload.configured));
        setProvider(selectedPayload.provider || livePayload.provider || upcomingPayload.provider || '');
        setUpdatedAt(selectedPayload.updated_at ? new Date(selectedPayload.updated_at) : new Date());
        setPollAfter(Number(livePayload.poll_after || (live ? 15 : 60)));
        setPulse((current) => current + 1);
      })
      .catch(() => {
        setFixtures([]);
        setTotalFixtures(0);
        setConfigured(false);
        setProvider('');
        setUpdatedAt(new Date());
      });
  }, [live]);

  useLiveInterval(refreshScores, Math.max(10000, Math.min(120000, pollAfter * 1000)));
  const rows = fixtures.length ? fixtures.slice(0, 4).map((fixture) => ({
    id: fixture.id || `${fixture.home?.name}-${fixture.away?.name}`,
    fixture,
    home: fixture.home || {},
    away: fixture.away || {},
    homeScore: fixture.goals?.home ?? '-',
    awayScore: fixture.goals?.away ?? '-',
    status: fixture.elapsed ? `${fixture.elapsed}'` : (fixture.status_short === 'NS' ? formatCountdown(fixture.date) || formatTime(fixture.date) : (fixture.status_short || 'TBD')),
    kickoff: fixture.status_short === 'NS' ? formatTime(fixture.date) : '',
    league: fixture.league?.name || '',
  })) : [];

  return (
    <Card className={live ? 'rs-live-card' : ''}>
      <CardHeader title={scoreMode === 'live' ? 'Live scores' : 'Upcoming'} action={<LiveBadge label="Live" date={updatedAt} />} />
      {rows.length ? (
        <div className="rs-score-strip">{rows.map((row, index) => (
          <button className={pulse % 2 === index % 2 ? 'is-live-pulse rs-score-strip-row' : 'rs-score-strip-row'} type="button" key={row.id} onClick={() => setSelectedFixture(row.fixture)}>
            <LiveTeamMini team={row.home} />
            <strong>{row.homeScore} - {row.awayScore}</strong>
            <LiveTeamMini team={row.away} align="right" />
            <Badge>{row.status}</Badge>
            {row.kickoff ? <small>{row.kickoff}</small> : null}
          </button>
        ))}</div>
      ) : (
        <p>No live games right now. The next fixture shows here when it’s inside the 24-hour window.</p>
      )}
      {totalFixtures > 4 ? <a className="rs-see-all-matches" href={appPageUrl('football')}>See all matches</a> : null}
      <MatchDetailsModal fixture={selectedFixture} onClose={() => setSelectedFixture(null)} />
    </Card>
  );
}

function LiveTeamMini({ team = {}, align = 'left' }) {
  const fullName = decodeText(team.name || 'Team');
  const displayName = liveSidebarTeamName(fullName);

  return (
    <span className={`rs-live-team-mini ${align === 'right' ? 'is-right' : ''}`}>
      {team.logo ? <img src={team.logo} alt="" loading="lazy" /> : <i>{displayName.slice(0, 2).toUpperCase()}</i>}
      <b title={fullName} aria-label={fullName}>{displayName}</b>
    </span>
  );
}

function liveSidebarTeamName(name = '') {
  const compact = shortTeamName(name, 40)
    .replace(/\bUnited\b/gi, 'Utd')
    .replace(/\bWanderers\b/gi, 'Wdrs')
    .replace(/\bAthletic\b/gi, 'Ath')
    .replace(/\bSporting\b/gi, 'Sport')
    .replace(/\bInternational\b/gi, 'Intl')
    .replace(/\s+/g, ' ')
    .trim();

  if (compact.length <= 12) {
    return compact;
  }

  const initials = compact.match(/[\p{L}\p{N}]+/gu)?.map((word) => word[0]).join('').slice(0, 4).toUpperCase();
  return initials?.length > 1 ? initials : compact.slice(0, 12);
}

function shortTeamName(name = '', limit = 12) {
  const original = decodeText(name || 'Team').replace(/\s+/g, ' ').trim();
  const compactNames = {
    'Manchester City': 'Man City',
    'Manchester United': 'Man United',
    'Newcastle United': 'Newcastle',
    'Tottenham Hotspur': 'Tottenham',
    'Nottingham Forest': 'Nottm Forest',
    'West Ham United': 'West Ham',
    'Brighton & Hove Albion': 'Brighton',
    'Wolverhampton Wanderers': 'Wolves',
    'Paris Saint Germain': 'PSG',
    'Paris Saint-Germain': 'PSG',
    'Internazionale': 'Inter',
    'Inter Milan': 'Inter',
    'Borussia Mönchengladbach': 'Gladbach',
    'Borussia Monchengladbach': 'Gladbach',
    'Bayer 04 Leverkusen': 'Leverkusen',
    'Real Betis Balompie': 'Real Betis',
    'Real Betis Balompié': 'Real Betis',
  };
  const normalized = original.replace(/\s+FC$/i, '').trim();
  const mapped = compactNames[original] || compactNames[normalized];
  const clean = (mapped || normalized)
    .replace(/\b(Football Club|Association Football Club)\b/gi, '')
    .replace(/\s+/g, ' ')
    .trim();
  return clean.length > limit ? `${clean.slice(0, Math.max(1, limit - 1)).trim()}…` : clean || original;
}

function SignalCard({ title, icon, items = [], live = false, type = 'signal' }) {
  const loader = type === 'market' ? getLiveMarkets : type === 'weather' ? getLiveWeather : null;
  const [rows, setRows] = useState(() => normalizeSignalItems(items));
  const [updatedAt, setUpdatedAt] = useState(new Date());
  const [pollMs, setPollMs] = useState(900000);
  const [sourceLabel, setSourceLabel] = useState('Live');
  const [loading, setLoading] = useState(!!loader);
  const [visitorWeather, setVisitorWeather] = useState(null);

  const refreshSignals = useCallback(() => {
    if (!loader) {
      setUpdatedAt(new Date());
      return;
    }

    setLoading(true);
    loader()
      .then((payload) => {
        const nextRows = normalizeSignalItems(payload?.items);
        setRows(nextRows);

        setSourceLabel(getLiveSourceLabel(payload?.source_label || payload?.provider || 'Live'));
        setUpdatedAt(payload?.updated_at ? new Date(payload.updated_at) : new Date());
        setPollMs(Math.max(300000, Math.min(1800000, Number(payload?.poll_after || 900) * 1000)));
      })
      .catch(() => {
        setRows([]);
        setUpdatedAt(new Date());
      })
      .finally(() => setLoading(false));
  }, [loader]);

  useLiveInterval(refreshSignals, live ? pollMs : 180000);

  useEffect(() => {
    if (type !== 'weather' || visitorWeather || !navigator.geolocation) {
      return undefined;
    }

    let cancelled = false;
    navigator.geolocation.getCurrentPosition(
      (position) => {
        if (cancelled) {
          return;
        }

        const nextLocation = {
          latitude: Number(position.coords.latitude.toFixed(3)),
          longitude: Number(position.coords.longitude.toFixed(3)),
          label: 'Near you',
        };
        setVisitorWeather(nextLocation);
        setLoading(true);
        getLiveWeather(nextLocation)
          .then((payload) => {
            if (cancelled) {
              return;
            }
            setRows(normalizeSignalItems(payload?.items));
            setSourceLabel(getLiveSourceLabel(payload?.source_label || payload?.provider || 'Live'));
            setUpdatedAt(payload?.updated_at ? new Date(payload.updated_at) : new Date());
            setPollMs(Math.max(300000, Math.min(1800000, Number(payload?.poll_after || 900) * 1000)));
          })
          .catch(() => {})
          .finally(() => !cancelled && setLoading(false));
      },
      () => {},
      { enableHighAccuracy: false, maximumAge: 900000, timeout: 3500 }
    );

    return () => {
      cancelled = true;
    };
  }, [type, visitorWeather]);

  if (type === 'weather') {
    return (
      <WeatherSignalCard
        rows={rows}
        live={live}
        sourceLabel={sourceLabel}
        updatedAt={updatedAt}
        loading={loading}
      />
    );
  }

  if (type === 'market') {
    return (
      <MarketSignalCard
        rows={rows}
        live={live}
        sourceLabel={sourceLabel}
        updatedAt={updatedAt}
        loading={loading}
      />
    );
  }

  return (
    <Card className={live ? 'rs-live-card' : ''}>
      <CardHeader title={title} action={live ? <LiveBadge label={sourceLabel} date={updatedAt} /> : icon} />
      <div className="rs-signal-list">
        {rows.map(({ label, value, status, icon: rowIcon }) => (
          <article key={label}>
            <span className={`rs-signal-icon ${type}`}>{signalIcon(type, status, rowIcon)}</span>
            <span>{label}</span>
            <strong>{value}</strong>
            <Badge tone={signalTone(status)}>{status}</Badge>
          </article>
        ))}
        {!rows.length ? <p className="rs-empty-mini">{loading ? `Checking ${title.toLowerCase()}...` : `${title} data is not saved yet.`}</p> : null}
      </div>
    </Card>
  );
}

function MarketSignalCard({ rows = [], live = false, sourceLabel = 'Live', updatedAt = new Date(), loading = false }) {
  const [hero, ...rest] = rows;
  const [activeIndex] = useCarouselIndex(rows, 15000);
  const carouselRef = useRef(null);

  useEffect(() => {
    const track = carouselRef.current;
    const target = track?.children?.[activeIndex];
    if (target && typeof target.scrollIntoView === 'function') {
      target.scrollIntoView({ behavior: 'smooth', inline: 'start', block: 'nearest' });
    }
  }, [activeIndex]);

  return (
    <Card className={`${live ? 'rs-live-card ' : ''}rs-market-card`}>
      <CardHeader
        title="Markets"
        action={live ? <LiveBadge label={sourceLabel} date={updatedAt} /> : <DollarSign size={18} />}
      />
      {hero ? (
        <>
          <div className="rs-market-mobile-carousel" ref={carouselRef} aria-label="Market carousel">
            {rows.map((item) => (
              <article className="rs-market-slide" key={`market-slide-${item.label}`}>
                <div>
                  <span className="rs-market-icon">{marketSymbol(item.label)}</span>
                  <small>{item.status || 'Flat'}</small>
                </div>
                <strong>{item.label}</strong>
                <b>{item.value}</b>
                <MarketSparkline status={item.status} history={item.history} compact />
              </article>
            ))}
          </div>
          <div className="rs-market-hero">
            <span className="rs-market-icon">{marketSymbol(hero.label)}</span>
            <div>
              <small>{hero.status || 'Market watch'}</small>
              <strong>{hero.label}</strong>
            </div>
            <b>{hero.value}</b>
          </div>
          <MarketSparkline status={hero.status} history={hero.history} />
          {rest.length ? (
            <div className="rs-market-mini-list">
              {rest.slice(0, 4).map((item) => (
                <article key={item.label}>
                  <span>{marketSymbol(item.label)}</span>
                  <div>
                    <b>{item.label}</b>
                    <small>{item.status || 'Flat'}</small>
                  </div>
                  <strong>{item.value}</strong>
                  <MarketSparkline status={item.status} history={item.history} compact />
                </article>
              ))}
            </div>
          ) : null}
        </>
      ) : (
        <p className="rs-empty-mini">{loading ? 'Checking markets...' : 'Market data is not saved yet.'}</p>
      )}
    </Card>
  );
}

function MarketSparkline({ status = '', history = [], compact = false }) {
  const trend = String(status).includes('+') ? 'up' : String(status).includes('-') ? 'down' : 'flat';
  const points = marketSparklinePoints(history, trend);
  const lastPoint = points.split(' ').pop()?.split(',') || ['68', trend === 'down' ? '19' : trend === 'up' ? '8' : '13'];

  return (
    <span className={`rs-market-sparkline ${trend} ${compact ? 'is-compact' : ''}`} aria-hidden="true">
      <svg viewBox="0 0 70 24" focusable="false">
        <polyline points={points} />
        <circle cx={lastPoint[0]} cy={lastPoint[1]} r="2.8" />
      </svg>
    </span>
  );
}

function marketSparklinePoints(history = [], trend = 'flat') {
  const values = (Array.isArray(history) ? history : [])
    .map((point) => Number(point?.value))
    .filter((value) => Number.isFinite(value));

  if (values.length < 2) {
    return trend === 'up'
      ? '2,18 13,14 24,16 35,10 46,12 57,6 68,8'
      : trend === 'down'
        ? '2,6 13,9 24,8 35,12 46,14 57,16 68,19'
        : '2,13 13,12 24,13 35,12 46,13 57,12 68,13';
  }

  const sampled = values.length > 30 ? values.slice(-30) : values;
  const min = Math.min(...sampled);
  const max = Math.max(...sampled);
  const span = max - min || 1;
  const width = 66;
  const step = sampled.length > 1 ? width / (sampled.length - 1) : width;

  return sampled.map((value, index) => {
    const x = 2 + (index * step);
    const y = 20 - (((value - min) / span) * 16);
    return `${x.toFixed(1)},${y.toFixed(1)}`;
  }).join(' ');
}

function marketSymbol(label = '') {
  const base = String(label).split('/')[0].trim().toUpperCase();
  const symbols = {
    NGN: '₦',
    USD: '$',
    EUR: '€',
    GBP: '£',
    JPY: '¥',
    CNY: '¥',
    CAD: 'C$',
    AUD: 'A$',
    CHF: '₣',
    ZAR: 'R',
    GHS: '₵',
    KES: 'KSh',
  };

  return symbols[base] || base.slice(0, 3) || 'FX';
}

function WeatherPage() {
  const [payload, setPayload] = useState(null);
  const [query, setQuery] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const hasPayloadRef = useRef(false);

  const refreshWeather = useCallback((force = false) => {
    setLoading((current) => current || !hasPayloadRef.current);
    getWorldWeather({ force })
      .then((data) => {
        hasPayloadRef.current = true;
        setPayload(data);
        setError('');
      })
      .catch((err) => setError(err?.message || 'Weather is taking too long to load.'))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    refreshWeather(false);
  }, [refreshWeather]);

  useLiveInterval(() => refreshWeather(false), 900000);

  const items = normalizeSignalItems(payload?.items || []);
  const filteredItems = items.filter((item) => item.label.toLowerCase().includes(query.trim().toLowerCase()));
  const updatedAt = payload?.updated_at ? new Date(payload.updated_at) : null;

  return (
    <main className="rs-shell compact-page rs-weather-page">
      <section className="rs-weather-directory-head">
        <div>
          <Badge tone="danger">Weather desk</Badge>
          <h1>Weather across major cities.</h1>
          <p>Live city conditions from the Rifnote weather cache, refreshed every 15 minutes.</p>
        </div>
        <div className="rs-weather-directory-tools">
          <label>
            <Search size={18} />
            <input
              value={query}
              onChange={(event) => setQuery(event.target.value)}
              placeholder="Search city"
              aria-label="Search weather cities"
            />
          </label>
          <button type="button" onClick={() => refreshWeather(true)} disabled={loading}>
            {loading ? 'Refreshing...' : 'Refresh'}
          </button>
        </div>
      </section>

      <section className="rs-weather-directory-meta" aria-label="Weather source">
        <span><CloudSun size={17} /> {payload?.source_label || 'Open-Meteo'}</span>
        <span><Clock3 size={17} /> {updatedAt ? updatedAt.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : 'Fresh soon'}</span>
        <span><MapIcon size={17} /> {filteredItems.length} cities</span>
      </section>

      {error ? <p className="rs-weather-directory-error">{error}</p> : null}

      <section className="rs-weather-city-grid" aria-label="Major city weather">
        {filteredItems.map((item) => (
          <article className="rs-weather-city-card" key={item.label}>
            <div className="rs-weather-city-icon">{signalIcon('weather', item.status, item.icon)}</div>
            <div className="rs-weather-city-copy">
              <h2>{item.label}</h2>
              <p>{item.status || 'Current conditions'}</p>
            </div>
            <strong>{item.value}</strong>
            <footer>
              {item.humidity !== null && item.humidity !== undefined ? <span>Humidity {item.humidity}%</span> : null}
              {item.wind_speed !== null && item.wind_speed !== undefined ? <span>Wind {item.wind_speed} km/h</span> : null}
            </footer>
          </article>
        ))}
      </section>

      {!loading && !filteredItems.length ? (
        <p className="rs-empty-mini">No city matched that search.</p>
      ) : null}
    </main>
  );
}

function WeatherSignalCard({ rows = [], live = false, sourceLabel = 'Live', updatedAt = new Date(), loading = false }) {
  const [hero, ...forecast] = rows;
  const [activeIndex] = useCarouselIndex(rows, 15000);
  const carouselRef = useRef(null);

  useEffect(() => {
    const track = carouselRef.current;
    const target = track?.children?.[activeIndex];
    if (target && typeof target.scrollIntoView === 'function') {
      target.scrollIntoView({ behavior: 'smooth', inline: 'start', block: 'nearest' });
    }
  }, [activeIndex]);

  return (
    <Card className={`${live ? 'rs-live-card ' : ''}rs-weather-card`}>
      <CardHeader
        title="Weather"
        action={live ? <LiveBadge label={sourceLabel} date={updatedAt} /> : <CloudSun size={18} />}
      />
      {hero ? (
        <>
          <div className="rs-weather-mobile-carousel" ref={carouselRef} aria-label="Weather carousel">
            {rows.map((item) => (
              <article className="rs-weather-slide" key={`weather-slide-${item.label}`}>
                <span>{item.label}</span>
                <div>
                  <strong>{item.value}</strong>
                  <i>{signalIcon('weather', item.status, item.icon)}</i>
                </div>
                <b>{item.status || 'Current conditions'}</b>
              </article>
            ))}
          </div>
          <div className="rs-weather-hero">
            <div className="rs-weather-icon-xl">{signalIcon('weather', hero.status, hero.icon)}</div>
            <div className="rs-weather-now">
              <span><MapIcon size={14} /> {hero.label}</span>
              <strong>{hero.value}</strong>
              <p>{hero.status || 'Current conditions'}</p>
            </div>
          </div>
          <div className="rs-weather-meta">
            {hero.humidity !== null && hero.humidity !== undefined ? <span>Humidity {hero.humidity}%</span> : null}
            {hero.wind_speed !== null && hero.wind_speed !== undefined ? <span>Wind {hero.wind_speed} km/h</span> : null}
          </div>
          {forecast.length ? (
            <div className="rs-weather-forecast" aria-label="Other saved weather locations">
              {forecast.slice(0, 3).map((item) => (
                <article key={item.label}>
                  <span>{signalIcon('weather', item.status, item.icon)}</span>
                  <b>{item.label}</b>
                  <strong>{item.value}</strong>
                  <small>{item.status}</small>
                </article>
              ))}
            </div>
          ) : null}
        </>
      ) : (
        <p className="rs-empty-mini">{loading ? 'Checking the sky...' : 'Weather data is not saved yet.'}</p>
      )}
    </Card>
  );
}

function normalizeSignalItems(items = []) {
  return (Array.isArray(items) ? items : []).map((item) => {
    if (Array.isArray(item)) {
      return { label: item[0], value: item[1], status: item[2] };
    }

    return {
      label: item?.label || '',
      value: item?.value || '-',
      status: item?.status || '',
      icon: item?.icon || '',
      humidity: item?.humidity ?? null,
      wind_speed: item?.wind_speed ?? null,
      history: Array.isArray(item?.history) ? item.history : [],
      is_visitor_location: Boolean(item?.is_visitor_location),
    };
  }).filter((item) => item.label);
}

function signalTone(status = '') {
  const value = String(status);

  if (value.includes('+')) return 'success';
  if (value.includes('-')) return 'danger';
  return '';
}

function signalIcon(type, status = '', explicitIcon = '') {
  if (type === 'market') {
    return String(status).includes('+') ? <TrendingUp size={15} /> : <DollarSign size={15} />;
  }

  if (type === 'weather') {
    const weather = `${explicitIcon} ${status}`.toLowerCase();
    if (weather.includes('rain')) return <CloudRain size={15} />;
    if (weather.includes('cloud')) return <Cloud size={15} />;
    return <Sun size={15} />;
  }

  return <CloudSun size={15} />;
}

function LiveBadge({ label, date }) {
  return (
    <span className="rs-live-badge">
      <i />
      {label}
      <small>{date ? date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : ''}</small>
    </span>
  );
}

function LoadingGrid() {
  return (
    <div className="rs-results rs-loading-stack" aria-live="polite" aria-busy="true">
      <GlassLoader />
      <GlassLoader compact />
      <GlassLoader compact />
    </div>
  );
}

function GlassLoader({ compact = false, label = '' }) {
  return (
    <div className={`rs-glass-loader ${compact ? 'is-compact' : ''}`} role="status" aria-label={label || 'Loading'}>
      <span className="rs-glass-thumb" aria-hidden="true"></span>
      <span className="rs-glass-copy" aria-hidden="true">
        <span className="rs-glass-line is-wide"></span>
        <span className="rs-glass-line"></span>
        <span className="rs-glass-line is-short"></span>
      </span>
      <span className="rs-sr-only">{label || 'Loading'}</span>
    </div>
  );
}

const mountedRoots = new WeakSet();

function modeFromPath() {
  const path = window.location.pathname.replace(/\/+$/, '').split('/').filter(Boolean).pop() || '';
  const pathModes = {
    search: 'app',
    football: 'football-hub',
    weather: 'weather',
    contact: 'contact',
    'contact-us': 'contact',
    teams: 'team-search',
    players: 'player-search',
    transfers: 'transfer-tracker',
    advertise: 'sponsor-request',
    'publisher-signup': 'publisher-signup',
    'submit-news': 'publisher-submit',
    'publisher-dashboard': 'publisher-dashboard',
    'publisher-docs': 'publisher-docs',
    'advertiser-signup': 'advertiser-signup',
    'advertiser-dashboard': 'advertiser-dashboard',
    dmca: 'legal-dmca',
    'publisher-opt-out': 'legal-opt-out',
    'beta-feedback': 'beta-feedback',
    'daily-briefing': 'daily-briefing',
    'for-you': 'for-you',
    newsletter: 'newsletter-signup',
  };

  return pathModes[path] || 'app';
}

function resolveAppMode(node) {
  const mode = node.dataset.rifnoteMode || window.RIFNOTE_SEARCH?.currentMode || '';

  return mode || modeFromPath();
}

function mountRifnoteApps() {
  document.querySelectorAll('.rifnote-search-root').forEach((node) => {
    if (mountedRoots.has(node)) {
      return;
    }

    mountedRoots.add(node);
    createRoot(node).render(
      <>
        <App mode={resolveAppMode(node)} />
        <PwaFeatureDock />
      </>
    );
    window.requestAnimationFrame(() => {
      document.dispatchEvent(new CustomEvent('rifnote:app-ready'));
    });
  });
}

mountRifnoteApps();

const observer = new MutationObserver(() => mountRifnoteApps());
observer.observe(document.documentElement, { childList: true, subtree: true });
