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

/** Turn /storage/... paths into full URLs on live (frontend and API are on different hosts). */
export function storageUrl(path) {
  if (!path) return '';
  if (/^https?:\/\//i.test(path)) return path;
  const normalized = path.startsWith('/') ? path : `/${path}`;
  return `${resolveStorageBase(resolveApiBase())}${normalized}`;
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
