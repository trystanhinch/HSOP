import axios from 'axios';

const PRODUCTION_API = 'https://api.serviceop.ca/api';
const PRODUCTION_STORAGE = 'https://api.serviceop.ca';

function isProductionFrontendHost(hostname) {
  return (
    hostname === 'serviceop-vbstp.ondigitalocean.app'
    || hostname === 'serviceop.ca'
    || hostname === 'www.serviceop.ca'
    || hostname.endsWith('.serviceop.ca')
  );
}

/** Resolve at request time so production host detection works in the browser bundle. */
export function resolveApiBase() {
  const fromEnv = import.meta.env.VITE_API_URL?.trim();
  if (fromEnv && !fromEnv.startsWith('/')) {
    return fromEnv.replace(/\/$/, '');
  }
  if (typeof window !== 'undefined' && isProductionFrontendHost(window.location.hostname)) {
    return PRODUCTION_API;
  }
  return fromEnv || '/api';
}

function resolveStorageBase(apiBase) {
  const fromEnv = import.meta.env.VITE_STORAGE_URL?.trim();
  if (fromEnv && !fromEnv.startsWith('/')) {
    return fromEnv.replace(/\/$/, '');
  }
  if (typeof window !== 'undefined' && isProductionFrontendHost(window.location.hostname)) {
    return PRODUCTION_STORAGE;
  }
  return apiBase.replace(/\/api\/?$/, '');
}

/** Turn stored file paths into full public URLs (handles legacy /storage/ paths). */
export function storageUrl(path) {
  if (!path) return '';
  if (/^https?:\/\//i.test(path)) {
    // Legacy broken URLs: api.serviceop.ca/storage/... → api.serviceop.ca/api/files/...
    return path.replace(/^(https?:\/\/[^/]+)\/storage\//i, '$1/api/files/');
  }
  const base = resolveStorageBase(resolveApiBase());
  const normalized = path.startsWith('/') ? path : `/${path}`;
  if (normalized.startsWith('/storage/')) {
    return `${base}/api/files/${normalized.slice('/storage/'.length)}`;
  }
  if (normalized.startsWith('/api/files/')) {
    return `${base}${normalized}`;
  }
  return `${base}${normalized}`;
}

const api = axios.create({
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
});

api.interceptors.request.use((config) => {
  config.baseURL = resolveApiBase();
  const token = localStorage.getItem('token');
  if (token && token !== 'undefined' && token !== 'null') {
    config.headers.Authorization = `Bearer ${token}`;
  }
  if (config.data instanceof FormData) {
    delete config.headers['Content-Type'];
  }
  return config;
});

export default api;
