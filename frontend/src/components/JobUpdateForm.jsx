import { useState, useRef } from 'react';
import { Upload, X } from 'lucide-react';
import api from '../api/axios';
import { confirmAction, showError, showSuccess } from '../utils/swal';

export default function JobUpdateForm({ jobId, onClose, onPosted }) {
  const [text, setText] = useState('');
  const [visibility, setVisibility] = useState('customer_visible');
  const [photos, setPhotos] = useState([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const fileRef = useRef();

  const handleFiles = (e) => {
    const files = Array.from(e.target.files || []);
    setPhotos((prev) => [...prev, ...files].slice(0, 10));
  };

  const removePhoto = (i) => setPhotos((prev) => prev.filter((_, idx) => idx !== i));

  const handleSubmit = async (e) => {
    e.preventDefault();
    if (!text.trim()) return;

    const ok = await confirmAction({
      title: 'Post progress update?',
      text: visibility === 'internal' ? 'Post this as an internal-only update?' : 'Post this update for the customer to see?',
      confirmText: 'Yes, post',
    });
    if (!ok) return;

    setLoading(true);
    setError('');
    const form = new FormData();
    form.append('update_text', text);
    form.append('visibility', visibility);
    photos.forEach((p) => form.append('photos[]', p));
    try {
      await api.post(`/jobs/${jobId}/updates`, form);
      await showSuccess('Progress update posted.');
      onPosted?.();
      onClose();
    } catch (err) {
      const msg = 'Progress update could not be posted. Please try again.';
      setError(msg);
      await showError(msg);
    } finally {
      setLoading(false);
    }
  };

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/50">
      <div className="bg-white rounded-xl shadow-xl w-full max-w-lg p-6">
        <h3 className="text-lg font-semibold text-slate-800 mb-4">Add Progress Update</h3>
        {error && <div className="bg-red-50 text-red-700 text-sm rounded-lg p-3 mb-4">{error}</div>}

        <form onSubmit={handleSubmit} className="space-y-4">
          <textarea
            value={text}
            onChange={(e) => setText(e.target.value)}
            rows={4}
            placeholder="Describe progress..."
            className="w-full border border-slate-300 rounded-lg px-3 py-2 text-sm"
            required
          />

          <div className="flex gap-4 text-sm">
            <label className="flex items-center gap-2">
              <input type="radio" checked={visibility === 'customer_visible'} onChange={() => setVisibility('customer_visible')} />
              Customer Visible
            </label>
            <label className="flex items-center gap-2">
              <input type="radio" checked={visibility === 'internal'} onChange={() => setVisibility('internal')} />
              Internal Only
            </label>
          </div>

          <div>
            <input type="file" ref={fileRef} accept="image/*" multiple className="hidden" onChange={handleFiles} />
            <button type="button" onClick={() => fileRef.current?.click()}
              className="flex items-center gap-2 text-sm text-blue-600 hover:underline">
              <Upload className="w-4 h-4" /> Add photos (max 10)
            </button>
            {photos.length > 0 && (
              <div className="flex flex-wrap gap-2 mt-2">
                {photos.map((p, i) => (
                  <div key={i} className="relative">
                    <img src={URL.createObjectURL(p)} alt="" className="w-16 h-16 object-cover rounded-lg border" />
                    <button type="button" onClick={() => removePhoto(i)} className="absolute -top-1 -right-1 bg-red-500 text-white rounded-full p-0.5">
                      <X className="w-3 h-3" />
                    </button>
                  </div>
                ))}
              </div>
            )}
          </div>

          <div className="flex gap-3 justify-end pt-2">
            <button type="button" onClick={onClose} className="px-4 py-2 text-sm text-slate-600 rounded-lg hover:bg-slate-100">Cancel</button>
            <button type="submit" disabled={loading} className="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700 disabled:opacity-60">
              {loading ? 'Posting...' : 'Post Update'}
            </button>
          </div>
        </form>
      </div>
    </div>
  );
}
