export default function PageHeader({ title, children }) {
  return (
    <div className="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
      <h2 className="text-xl font-semibold text-[#0F172A]">{title}</h2>
      {children && <div className="flex items-center gap-3 flex-wrap">{children}</div>}
    </div>
  );
}
