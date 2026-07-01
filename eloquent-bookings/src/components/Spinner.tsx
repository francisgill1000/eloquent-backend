export function Spinner({ label }: { label?: string }) {
  return (
    <div style={{ display: 'flex', flexDirection: 'column', alignItems: 'center', gap: 12, padding: 48, color: 'var(--text-3)' }}>
      <span
        style={{
          width: 28, height: 28, borderRadius: '50%',
          border: '3px solid var(--border-2)', borderTopColor: 'var(--mint-400)',
          animation: 'c-spin 0.8s linear infinite',
        }}
      />
      {label && <span style={{ fontSize: 13 }}>{label}</span>}
      <style>{`@keyframes c-spin { to { transform: rotate(360deg); } }`}</style>
    </div>
  );
}
