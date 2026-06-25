export default function KPICard({ title, value, icon: Icon, color = '#3B82F6' }) {
  return (
    <div className="bg-white rounded-lg shadow-sm p-6 border border-[#E2E8F0]">
      <div className="flex items-center justify-between">
        <div>
          <p className="text-sm text-[#64748B] font-medium">{title}</p>
          <p className="text-2xl font-bold text-[#0F172A] mt-1">{value}</p>
        </div>
        {Icon && (
          <div
            className="w-12 h-12 rounded-lg flex items-center justify-center"
            style={{ backgroundColor: `${color}15` }}
          >
            <Icon size={24} style={{ color }} />
          </div>
        )}
      </div>
    </div>
  );
}
