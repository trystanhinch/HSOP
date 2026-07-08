const MAX_PHOTO_BYTES = 10 * 1024 * 1024;
const MAX_PHOTOS = 10;
const ALLOWED_TYPES = new Set([
  'image/jpeg',
  'image/jpg',
  'image/png',
  'image/gif',
  'image/webp',
  'image/heic',
  'image/heif',
]);

export function validatePhotoFiles(files) {
  if (files.length > MAX_PHOTOS) {
    return `You can upload up to ${MAX_PHOTOS} photos per update.`;
  }

  for (const file of files) {
    if (file.size > MAX_PHOTO_BYTES) {
      return 'One or more photos is too large. Max size is 10 MB per photo.';
    }

    const type = (file.type || '').toLowerCase();
    const name = (file.name || '').toLowerCase();
    const allowedByType = type && ALLOWED_TYPES.has(type);
    const allowedByExt = /\.(jpe?g|png|gif|webp|heic|heif)$/i.test(name);

    if (!allowedByType && !allowedByExt) {
      return 'Only JPG, PNG, WEBP, and HEIC photos are supported.';
    }
  }

  return null;
}

export function parseProgressUpdateError(err) {
  const data = err?.response?.data;
  const status = err?.response?.status;

  if (status === 413) {
    return 'One or more photos is too large. Max size is 10 MB per photo.';
  }

  if (data?.errors) {
    const messages = Object.values(data.errors).flat().filter(Boolean);
    const joined = messages.join(' ').toLowerCase();

    if (joined.includes('too large') || joined.includes('may not be greater than')) {
      return 'One or more photos is too large. Max size is 10 MB per photo.';
    }
    if (joined.includes('may not have more than') && joined.includes('photos')) {
      return 'You can upload up to 10 photos per update.';
    }
    if (joined.includes('image') || joined.includes('mimes') || joined.includes('file of type')) {
      return 'Only JPG, PNG, WEBP, and HEIC photos are supported.';
    }

    if (messages[0]) return messages[0];
  }

  if (data?.message && data.message !== 'Server Error') {
    return data.message;
  }

  return 'Progress update could not be posted. Please try again.';
}
