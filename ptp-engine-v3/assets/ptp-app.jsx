import { useState, useEffect, useCallback, useRef, useMemo, useReducer, createContext, useContext, Component } from "react";

/* ═══════════════════════════════════════════════════════════════
   PTP ENGINE v3.1 — PRODUCTION DESKTOP APP
   Cache layer, debouncing, error boundaries, all endpoints
   ═══════════════════════════════════════════════════════════════ */

// ─── CONFIG ───
const API_BASE = (window?.PTP_ENGINE?.api || "/wp-json/ptp-cc/v1").replace(/\/desktop\/?$/, "");
const NONCE = window?.PTP_ENGINE?.nonce || "";
const USER = window?.PTP_ENGINE?.user || "Luke";

// ─── API ───
async function api(path, method = "GET", body = null) {
  const opts = { method, headers: { "Content-Type": "application/json" }, credentials: "same-origin" };
  if (NONCE) opts.headers["X-WP-Nonce"] = NONCE;
  if (body) opts.body = JSON.stringify(body);
  const r = await fetch(`${API_BASE}/${path}`, opts);
  if (!r.ok) { const e = await r.json().catch(() => ({})); throw new Error(e.message || r.statusText); }
  return r.json();
}

// ─── CACHE LAYER ───
const _cache = {};
function cacheGet(key) { const c = _cache[key]; if (!c) return null; if (Date.now() > c.exp) { delete _cache[key]; return null; } return c.data; }
function cacheSet(key, data, ttl = 30000) { _cache[key] = { data, exp: Date.now() + ttl }; }
function cacheClear(prefix) { Object.keys(_cache).forEach(k => { if (!prefix || k.startsWith(prefix)) delete _cache[k]; }); }

// Cached API call
async function cachedApi(path, ttl = 30000) {
  const cached = cacheGet(path);
  if (cached) return cached;
  const data = await api(path);
  cacheSet(path, data, ttl);
  return data;
}

// ─── HOOKS ───
function useDebounce(value, delay = 200) {
  const [d, setD] = useState(value);
  useEffect(() => { const t = setTimeout(() => setD(value), delay); return () => clearTimeout(t); }, [value, delay]);
  return d;
}

function useCachedFetch(path, deps = [], ttl = 30000) {
  const [data, setData] = useState(() => cacheGet(path));
  const [loading, setLoading] = useState(!cacheGet(path));
  const [error, setError] = useState(null);
  const reload = useCallback(async (force = false) => {
    if (force) cacheClear(path);
    if (!force && cacheGet(path)) { setData(cacheGet(path)); setLoading(false); return; }
    setLoading(true); setError(null);
    try { const d = await cachedApi(path, ttl); setData(d); } catch (e) { setError(e.message); }
    setLoading(false);
  }, [path, ttl]);
  useEffect(() => { reload(); }, [path, ...deps]);
  return { data, loading, error, reload };
}

// ─── CONSTANTS ───
const STAGES = [
  { k: "New Lead", c: "#5C6BC0" }, { k: "Contacted", c: "#1E88E5" },
  { k: "Camp Registered", c: "#0097A7" }, { k: "Camp Attended", c: "#2E7D32" },
  { k: "48hr Window", c: "#FCB900" }, { k: "Training Converted", c: "#E65100" },
  { k: "Recurring", c: "#2D8A4E" }, { k: "VIP", c: "#C62828" },
];
const sc = k => STAGES.find(s => s.k === k)?.c || "#918F89";
const fm = n => "$" + Number(n || 0).toLocaleString("en", { minimumFractionDigits: 0 });
const fd = d => d ? new Date(d).toLocaleDateString("en", { month: "short", day: "numeric" }) : "--";
const ft = d => d ? new Date(d).toLocaleTimeString("en", { hour: "numeric", minute: "2-digit" }) : "";
const fp = p => { if (!p) return ""; const d = p.replace(/\D/g, "").slice(-10); return d.length === 10 ? `(${d.slice(0, 3)}) ${d.slice(3, 6)}-${d.slice(6)}` : p; };
const ini = n => (n || "?").split(" ").map(w => w[0] || "").join("").slice(0, 2).toUpperCase();

const V = { gold: "#FCB900", black: "#0A0A0A", white: "#FFF", bg: "#F5F4F0", card: "#FFF", border: "#E0DFDB", muted: "#918F89", text: "#1C1B18", light: "#FAFAF7", green: "#2D8A4E", red: "#C62828", blue: "#1565C0", purple: "#6A3EA1", orange: "#E65100", cyan: "#0097A7" };

// ─── ERROR BOUNDARY ───
class ErrorBound extends Component {
  state = { err: null };
  static getDerivedStateFromError(err) { return { err }; }
  render() {
    if (this.state.err) return (
      <div style={{ padding: 40, textAlign: "center" }}>
        <div style={{ fontFamily: "'Oswald',sans-serif", fontSize: 14, color: V.red, marginBottom: 8 }}>MODULE ERROR</div>
        <div style={{ fontSize: 12, color: V.muted, marginBottom: 16 }}>{this.state.err.message}</div>
        <button onClick={() => this.setState({ err: null })} style={{ ...btnStyle("ghost"), cursor: "pointer" }}>Retry</button>
      </div>
    );
    return this.props.children;
  }
}

// ─── STYLE HELPERS ───
const btnStyle = (v = "gold") => {
  const map = { gold: { background: V.gold, color: V.black, borderColor: V.gold }, dark: { background: V.black, color: V.white, borderColor: V.black }, ghost: { background: "transparent", color: V.text, borderColor: V.border }, danger: { background: "transparent", color: V.red, borderColor: V.red }, blue: { background: V.blue, color: V.white, borderColor: V.blue } };
  return { cursor: "pointer", fontFamily: "'Oswald',sans-serif", fontSize: 10, fontWeight: 600, textTransform: "uppercase", letterSpacing: 1.2, border: "2px solid", padding: "5px 12px", ...(map[v] || map.gold) };
};
const lblStyle = { fontFamily: "'Oswald',sans-serif", fontSize: 8, textTransform: "uppercase", letterSpacing: 1.2, color: V.muted, display: "block", marginBottom: 2 };
const secStyle = { fontFamily: "'Oswald',sans-serif", fontSize: 12, fontWeight: 600, textTransform: "uppercase", letterSpacing: 2, color: V.muted, marginBottom: 8 };
const monoStyle = { fontFamily: "'IBM Plex Mono',monospace" };
const headStyle = { fontFamily: "'Oswald',sans-serif" };
const inputStyle = { fontFamily: "'DM Sans',sans-serif", fontSize: 12, padding: "6px 8px", border: `2px solid ${V.border}`, outline: "none", background: V.white, color: V.text, width: "100%" };
const thStyle = { padding: "8px 12px", textAlign: "left", ...headStyle, fontSize: 8, textTransform: "uppercase", letterSpacing: 1.2, color: V.muted, fontWeight: 600, position: "sticky", top: 0, background: V.light, zIndex: 1 };

// ─── COMPONENTS ───
const Btn = ({ children, variant = "gold", style, ...p }) => <button style={{ ...btnStyle(variant), ...style }} {...p}>{children}</button>;
const Badge = ({ children, bg = V.muted }) => <span style={{ ...headStyle, fontSize: 9, fontWeight: 600, textTransform: "uppercase", letterSpacing: 0.8, padding: "2px 7px", color: "#fff", background: bg, display: "inline-block", whiteSpace: "nowrap" }}>{children}</span>;
const Card = ({ children, style, ...p }) => <div style={{ background: V.card, border: `2px solid ${V.border}`, padding: 16, ...style }} {...p}>{children}</div>;
const Stat = ({ label, value, color = V.black }) => <div style={{ background: V.card, border: `2px solid ${V.border}`, padding: "14px 16px" }}><div style={{ ...headStyle, fontSize: 26, fontWeight: 700, color, lineHeight: 1 }}>{value}</div><div style={{ ...headStyle, fontSize: 8, textTransform: "uppercase", letterSpacing: 1.2, color: V.muted, marginTop: 4 }}>{label}</div></div>;
const ST = ({ children }) => <div style={secStyle}>{children}</div>;
const Empty = ({ text }) => <div style={{ textAlign: "center", padding: 40, color: V.muted, fontSize: 12 }}>{text}</div>;

const Skeleton = ({ rows = 3, style }) => (
  <div style={style}>{Array.from({ length: rows }).map((_, i) => (
    <div key={i} style={{ height: 18, background: `linear-gradient(90deg, ${V.border} 25%, #EEEEE8 50%, ${V.border} 75%)`, backgroundSize: "200% 100%", animation: "shimmer 1.5s infinite", marginBottom: 8, borderRadius: 2, width: `${70 + Math.random() * 30}%` }} />
  ))}</div>
);

const PageHeader = ({ title, children }) => (
  <div style={{ background: V.white, borderBottom: `2px solid ${V.border}`, padding: "14px 24px", display: "flex", justifyContent: "space-between", alignItems: "center", flexShrink: 0 }}>
    <div style={{ ...headStyle, fontSize: 18, fontWeight: 700, textTransform: "uppercase", letterSpacing: 1 }}>{title}</div>
    <div style={{ display: "flex", gap: 6, alignItems: "center" }}>{children}</div>
  </div>
);

const Input = ({ label, style, inputRef, ...p }) => (
  <div style={{ marginBottom: 8 }}>
    {label && <label style={lblStyle}>{label}</label>}
    <input ref={inputRef} style={{ ...inputStyle, ...style }} {...p} />
  </div>
);

const Select = ({ label, children, style, ...p }) => (
  <div style={{ marginBottom: 8 }}>
    {label && <label style={lblStyle}>{label}</label>}
    <select style={{ ...inputStyle, ...style }} {...p}>{children}</select>
  </div>
);

const TabBar = ({ tabs, active, onChange }) => (
  <div style={{ display: "flex", gap: 4, padding: "8px 24px", borderBottom: `1px solid ${V.border}`, background: V.white }}>
    {tabs.map(t => <Btn key={t.k} variant={active === t.k ? "dark" : "ghost"} onClick={() => onChange(t.k)} style={{ fontSize: 9, padding: "4px 10px" }}>{t.l}{t.count !== undefined ? ` (${t.count})` : ""}</Btn>)}
  </div>
);

// ─── GLOBAL SEARCH (Cmd+K) ───
function CommandPalette({ onNav, onOpenContact }) {
  const [open, setOpen] = useState(false);
  const [q, setQ] = useState("");
  const [results, setResults] = useState([]);
  const inputRef = useRef(null);
  const dq = useDebounce(q, 250);

  useEffect(() => {
    const handler = e => { if ((e.metaKey || e.ctrlKey) && e.key === "k") { e.preventDefault(); setOpen(o => !o); } if (e.key === "Escape") setOpen(false); };
    window.addEventListener("keydown", handler);
    return () => window.removeEventListener("keydown", handler);
  }, []);

  useEffect(() => { if (open) setTimeout(() => inputRef.current?.focus(), 50); }, [open]);

  useEffect(() => {
    if (!dq || dq.length < 2) { setResults([]); return; }
    api(`search?q=${encodeURIComponent(dq)}`).then(r => setResults(r?.results || [])).catch(() => setResults([]));
  }, [dq]);

  if (!open) return null;

  const typeIcons = { application: "Pipeline", parent: "Contact", camp: "Camp", booking: "Booking" };

  return (
    <div onClick={() => setOpen(false)} style={{ position: "fixed", inset: 0, background: "rgba(0,0,0,.4)", zIndex: 999, display: "flex", alignItems: "flex-start", justifyContent: "center", paddingTop: 120 }}>
      <div onClick={e => e.stopPropagation()} style={{ width: 560, background: V.white, border: `2px solid ${V.gold}`, boxShadow: "0 20px 60px rgba(0,0,0,.2)" }}>
        <div style={{ padding: "12px 16px", borderBottom: `2px solid ${V.border}` }}>
          <input ref={inputRef} value={q} onChange={e => setQ(e.target.value)} placeholder="Search everything... (names, phones, emails)" style={{ ...inputStyle, border: "none", fontSize: 14, padding: 0 }} />
        </div>
        <div style={{ maxHeight: 400, overflowY: "auto" }}>
          {results.length === 0 && dq.length >= 2 && <div style={{ padding: 20, textAlign: "center", color: V.muted, fontSize: 12 }}>No results</div>}
          {results.map((r, i) => (
            <div key={i} onClick={() => {
              setOpen(false); setQ("");
              if (r.type === "parent") onOpenContact(r.id);
              else if (r.type === "application") onNav("pipeline");
              else onNav("customer360");
            }} style={{ padding: "10px 16px", cursor: "pointer", borderBottom: `1px solid ${V.border}`, display: "flex", justifyContent: "space-between", alignItems: "center" }}>
              <div><div style={{ fontWeight: 600, fontSize: 13 }}>{r.name}</div><div style={{ fontSize: 11, color: V.muted }}>{r.email || fp(r.phone)}{r.child_name ? ` | ${r.child_name}` : ""}</div></div>
              <Badge bg={V.purple}>{typeIcons[r.type] || r.type}</Badge>
            </div>
          ))}
        </div>
        <div style={{ padding: "8px 16px", background: V.light, borderTop: `1px solid ${V.border}`, fontSize: 10, color: V.muted, display: "flex", gap: 12 }}>
          <span><kbd style={{ ...monoStyle, background: V.white, padding: "1px 4px", border: `1px solid ${V.border}` }}>ESC</kbd> close</span>
          <span><kbd style={{ ...monoStyle, background: V.white, padding: "1px 4px", border: `1px solid ${V.border}` }}>Enter</kbd> open</span>
        </div>
      </div>
    </div>
  );
}

// ═══════════════════════════════════════════════
// DASHBOARD
// ═══════════════════════════════════════════════
function Dashboard({ onNav, onOpenContact }) {
  const { data, loading } = useCachedFetch("desktop/dashboard", [], 15000);
  if (loading && !data) return <><PageHeader title="Dashboard" /><div style={{ padding: 24 }}><Skeleton rows={6} /></div></>;
  if (!data) return <><PageHeader title="Dashboard" /><Empty text="Could not load dashboard" /></>;
  const p = data.pipeline || {};
  return (
    <>
      <PageHeader title="Dashboard">
        <span style={{ ...monoStyle, fontSize: 10, color: V.muted }}>Cmd+K to search</span>
        <Btn variant="ghost" onClick={() => { cacheClear("desktop/"); window.location.reload(); }}>Refresh</Btn>
      </PageHeader>
      <div style={{ flex: 1, overflowY: "auto", padding: "20px 24px" }}>
        <div style={{ display: "grid", gridTemplateColumns: "repeat(6,1fr)", gap: 12, marginBottom: 20 }}>
          <Stat label="Families" value={data.total_families} />
          <Stat label="Revenue" value={fm(data.total_revenue)} color={V.green} />
          <Stat label="Ad Spend" value={fm(data.spend_all)} color={V.blue} />
          <Stat label="CAC" value={`$${data.cac}`} color={V.purple} />
          <Stat label="Conv Rate" value={`${data.conv_rate}%`} color={V.gold} />
          <Stat label="Unread" value={data.unread} color={V.orange} />
        </div>
        {(data.w48_families || []).length > 0 && (
          <div style={{ background: "#FFFDE7", border: `2px solid ${V.gold}`, padding: 12, marginBottom: 16 }}>
            <div style={{ ...headStyle, fontSize: 10, textTransform: "uppercase", letterSpacing: 1.5, color: V.gold, marginBottom: 8, fontWeight: 700 }}>48HR WINDOW - FOLLOW UP NOW</div>
            {data.w48_families.map((c, i) => (
              <div key={i} style={{ display: "flex", justifyContent: "space-between", alignItems: "center", padding: "6px 0", borderBottom: i < data.w48_families.length - 1 ? "1px solid rgba(252,185,0,.2)" : "none" }}>
                <span style={{ fontSize: 12 }}><strong>{c.display_name}</strong> -- {fp(c.phone)}</span>
                <div style={{ display: "flex", gap: 4 }}>
                  <Btn onClick={() => { onNav("inbox"); setTimeout(() => window.__openThread?.(c.phone), 200); }} style={{ fontSize: 8, padding: "2px 8px" }}>MSG</Btn>
                  <Btn variant="ghost" onClick={() => onOpenContact(c.id)} style={{ fontSize: 8, padding: "2px 8px" }}>VIEW</Btn>
                </div>
              </div>
            ))}
          </div>
        )}
        <ST>Pipeline</ST>
        <Card style={{ padding: 14, marginBottom: 20 }}>
          <div style={{ display: "flex", gap: 2, marginBottom: 4 }}>
            {STAGES.map(s => <div key={s.k} style={{ flex: Math.max(p[s.k] || 0, 1), height: 36, display: "flex", alignItems: "center", justifyContent: "center", background: s.c }}><span style={{ ...headStyle, fontSize: 12, fontWeight: 700, color: "#fff" }}>{p[s.k] || 0}</span></div>)}
          </div>
          <div style={{ display: "flex", gap: 2 }}>{STAGES.map(s => <div key={s.k} style={{ flex: Math.max(p[s.k] || 0, 1), textAlign: "center", ...headStyle, fontSize: 7, textTransform: "uppercase", color: V.muted }}>{s.k}</div>)}</div>
        </Card>
        <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 16 }}>
          <div>
            <ST>Funnel</ST>
            <Card>
              {[{ l: "All Contacts", v: data.total_families }, { l: "Contacted+", v: data.total_families - (p["New Lead"] || 0) }, { l: "Camp Reg+", v: (p["Camp Registered"] || 0) + (p["Camp Attended"] || 0) + (p["48hr Window"] || 0) + (p["Training Converted"] || 0) + (p["Recurring"] || 0) + (p["VIP"] || 0) }, { l: "Training+", v: (p["Training Converted"] || 0) + (p["Recurring"] || 0) + (p["VIP"] || 0) }].map((r, i) => (
                <div key={i} style={{ marginBottom: 10 }}>
                  <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 3 }}><span style={{ fontSize: 11 }}>{r.l}</span><span style={{ ...monoStyle, fontSize: 11, fontWeight: 600 }}>{r.v}</span></div>
                  <div style={{ height: 3, background: "#EEEEE8" }}><div style={{ height: "100%", background: V.gold, width: `${(r.v / (data.total_families || 1)) * 100}%` }} /></div>
                </div>
              ))}
            </Card>
          </div>
          <div>
            <ST>Today</ST>
            <Card>
              <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 12 }}>
                {[{ l: "Msgs In", v: data.msgs_today_in || 0 }, { l: "Msgs Out", v: data.msgs_today_out || 0 }, { l: "New This Week", v: data.new_7d || 0, c: V.green }, { l: "Landing Leads", v: data.landing?.total || 0, c: V.blue }].map((s, i) => (
                  <div key={i}><div style={{ fontSize: 11, color: V.muted }}>{s.l}</div><div style={{ ...headStyle, fontSize: 20, fontWeight: 700, color: s.c || V.text }}>{s.v}</div></div>
                ))}
              </div>
            </Card>
          </div>
        </div>
      </div>
    </>
  );
}

// ═══════════════════════════════════════════════
// PIPELINE
// ═══════════════════════════════════════════════
function Pipeline() {
  const [filter, setFilter] = useState("all");
  const [search, setSearch] = useState("");
  const [selected, setSelected] = useState(null);
  const dSearch = useDebounce(search, 300);
  const { data, loading, reload } = useCachedFetch(`applications?status=${filter}&search=${encodeURIComponent(dSearch)}`, [filter, dSearch], 20000);
  const fuRef = useRef(null);

  const apps = data?.applications || [];
  const counts = data?.stage_counts || {};
  const statusC = { new: V.purple, contacted: V.blue, scheduled: V.cyan, accepted: V.green, converted: V.gold, lost: V.muted };
  const tempC = { hot: V.red, warm: V.orange, cold: V.blue };

  const update = async (id, d) => { await api(`applications/${id}`, "PATCH", d); cacheClear("applications"); reload(true); };
  const followUp = async (id) => { const msg = fuRef.current?.value; if (!msg?.trim()) return; await api(`applications/${id}/follow-up`, "POST", { body: msg }); fuRef.current.value = ""; };

  return (
    <>
      <PageHeader title="Pipeline"><span style={{ ...monoStyle, fontSize: 11, color: V.muted }}>{apps.length} apps</span></PageHeader>
      <div style={{ flex: 1, overflowY: "auto", padding: "20px 24px" }}>
        <div style={{ display: "flex", gap: 4, marginBottom: 16, flexWrap: "wrap" }}>
          {[{ k: "all", l: "All" }, { k: "new", l: "New" }, { k: "contacted", l: "Contacted" }, { k: "scheduled", l: "Scheduled" }, { k: "accepted", l: "Accepted" }, { k: "converted", l: "Converted" }, { k: "lost", l: "Lost" }].map(f => (
            <Btn key={f.k} variant={filter === f.k ? "dark" : "ghost"} onClick={() => setFilter(f.k)} style={{ fontSize: 9, padding: "4px 10px" }}>{f.l}{counts[f.k] !== undefined ? ` (${counts[f.k]})` : ""}</Btn>
          ))}
          <div style={{ marginLeft: "auto" }}><input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search..." style={{ ...inputStyle, width: 200 }} /></div>
        </div>
        {loading && !data ? <Skeleton rows={8} /> : apps.length === 0 ? <Empty text="No applications match" /> : (
          <Card style={{ padding: 0, maxHeight: "calc(100vh - 220px)", overflowY: "auto" }}>
            <table style={{ width: "100%", borderCollapse: "collapse" }}>
              <thead><tr>{["Parent", "Player", "Status", "Temp", "Days", "Follow-ups", ""].map(h => <th key={h} style={thStyle}>{h}</th>)}</tr></thead>
              <tbody>{apps.map(a => (
                <tr key={a.id} style={{ borderBottom: `1px solid ${V.border}`, cursor: "pointer" }} onClick={() => setSelected(a)}>
                  <td style={{ padding: "7px 12px" }}><span style={{ fontWeight: 600, color: V.blue }}>{a.parent_name}</span><br /><span style={{ fontSize: 10, color: V.muted }}>{a.email || fp(a.phone)}</span></td>
                  <td style={{ padding: "7px 12px", fontSize: 11 }}>{a.child_name || "--"}</td>
                  <td style={{ padding: "7px 12px" }}><Badge bg={statusC[a.status] || V.muted}>{a.status}</Badge></td>
                  <td style={{ padding: "7px 12px" }}><Badge bg={tempC[a.lead_temperature] || V.muted}>{a.lead_temperature || "--"}</Badge></td>
                  <td style={{ padding: "7px 12px", ...monoStyle, fontSize: 10 }}>{a.days_since_apply || 0}d</td>
                  <td style={{ padding: "7px 12px", ...monoStyle, fontSize: 10 }}>{a.follow_up_count || 0}</td>
                  <td style={{ padding: "7px 12px" }}>
                    <select style={{ fontSize: 10, padding: "2px 4px", border: `1px solid ${V.border}` }} value={a.status} onClick={e => e.stopPropagation()} onChange={e => update(a.id, { status: e.target.value })}>
                      {["new", "contacted", "scheduled", "accepted", "converted", "lost"].map(s => <option key={s}>{s}</option>)}
                    </select>
                  </td>
                </tr>
              ))}</tbody>
            </table>
          </Card>
        )}
        {selected && (
          <div style={{ position: "fixed", top: 0, right: 0, width: 480, height: "100vh", background: V.white, borderLeft: `3px solid ${V.gold}`, zIndex: 200, display: "flex", flexDirection: "column", boxShadow: "-4px 0 20px rgba(0,0,0,.1)" }}>
            <div style={{ padding: "16px 20px", borderBottom: `2px solid ${V.border}`, display: "flex", justifyContent: "space-between" }}>
              <div><div style={{ fontSize: 18, fontWeight: 700 }}>{selected.parent_name}</div><div style={{ fontSize: 11, color: V.muted }}>{fp(selected.phone)} | {selected.email}</div></div>
              <Btn variant="danger" onClick={() => setSelected(null)} style={{ fontSize: 9, padding: "3px 8px" }}>Close</Btn>
            </div>
            <div style={{ flex: 1, overflowY: "auto", padding: 20 }}>
              <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 8, marginBottom: 16 }}>
                <Select label="Status" value={selected.status} onChange={e => update(selected.id, { status: e.target.value })}>{["new", "contacted", "scheduled", "accepted", "converted", "lost"].map(s => <option key={s}>{s}</option>)}</Select>
                <Select label="Temperature" value={selected.lead_temperature || ""} onChange={e => update(selected.id, { lead_temperature: e.target.value })}><option value="">--</option><option>hot</option><option>warm</option><option>cold</option></Select>
              </div>
              <ST>Quick Follow-Up SMS</ST>
              <div style={{ display: "flex", gap: 4 }}>
                <input ref={fuRef} placeholder="Type follow-up message..." style={{ ...inputStyle, flex: 1 }} onKeyDown={e => e.key === "Enter" && followUp(selected.id)} />
                <Btn onClick={() => followUp(selected.id)}>Send</Btn>
              </div>
            </div>
          </div>
        )}
      </div>
    </>
  );
}

// ═══════════════════════════════════════════════
// CONTACTS (paginated, debounced)
// ═══════════════════════════════════════════════
function Contacts({ families, onRefresh, onOpenContact }) {
  const [search, setSearch] = useState("");
  const [stageFilter, setStageFilter] = useState("all");
  const [showAdd, setShowAdd] = useState(false);
  const [page, setPage] = useState(0);
  const dSearch = useDebounce(search, 250);
  const PER_PAGE = 50;

  const refs = { name: useRef(), phone: useRef(), email: useRef(), kid: useRef(), age: useRef(), club: useRef(), city: useRef(), state: useRef(), stage: useRef(), source: useRef(), tags: useRef() };

  const filtered = useMemo(() => {
    let f = families || [];
    if (stageFilter !== "all") f = f.filter(x => (x.tags || []).includes(stageFilter));
    if (dSearch) { const q = dSearch.toLowerCase(); f = f.filter(x => [x.display_name, x.phone, x.email].some(v => (v || "").toLowerCase().includes(q))); }
    return f;
  }, [families, dSearch, stageFilter]);

  const totalPages = Math.ceil(filtered.length / PER_PAGE);
  const visible = filtered.slice(page * PER_PAGE, (page + 1) * PER_PAGE);

  useEffect(() => { setPage(0); }, [dSearch, stageFilter]);

  const addFamily = async () => {
    const g = r => r.current?.value || "";
    await api("desktop/families", "POST", { name: g(refs.name), phone: g(refs.phone), email: g(refs.email), kid_name: g(refs.kid), kid_age: g(refs.age), club: g(refs.club), city: g(refs.city), state: g(refs.state), stage: g(refs.stage), source: g(refs.source), tags: g(refs.tags) });
    setShowAdd(false); cacheClear("desktop/"); onRefresh();
  };

  const deleteFamily = async id => { if (!confirm("Delete?")) return; await api(`desktop/families/${id}`, "DELETE"); cacheClear("desktop/"); onRefresh(); };

  const exportCSV = () => {
    const rows = [["Name", "Email", "Phone", "City", "State", "Stage", "LTV", "Kid", "Age", "Club"]];
    (families || []).forEach(f => { const k = (f.children || [])[0]; const st = (f.tags || []).find(t => STAGES.some(s => s.k === t)) || ""; rows.push([f.display_name, f.email, f.phone, f.city, f.state, st, f.total_spent || 0, k?.first_name, k?.age, k?.club]); });
    const csv = rows.map(r => r.map(c => `"${(c + "").replace(/"/g, '""')}"`).join(",")).join("\n");
    const a = document.createElement("a"); a.href = URL.createObjectURL(new Blob([csv], { type: "text/csv" })); a.download = `ptp-contacts-${new Date().toISOString().slice(0, 10)}.csv`; a.click();
  };

  return (
    <>
      <PageHeader title="Contacts">
        <span style={{ ...monoStyle, fontSize: 11, color: V.muted }}>{filtered.length} of {(families || []).length}</span>
        <Btn variant="ghost" onClick={exportCSV}>Export CSV</Btn>
        <Btn onClick={() => setShowAdd(!showAdd)}>{showAdd ? "Cancel" : "+ Add"}</Btn>
      </PageHeader>
      <div style={{ flex: 1, overflowY: "auto", padding: "0 24px" }}>
        {showAdd && (
          <Card style={{ margin: "16px 0", padding: 16 }}>
            <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: "0 10px" }}>
              <Input label="Parent Name" inputRef={refs.name} placeholder="Sarah Mitchell" />
              <Input label="Phone" inputRef={refs.phone} placeholder="6105550142" />
              <Input label="Email" inputRef={refs.email} placeholder="email@gmail.com" />
              <Input label="Kid Name" inputRef={refs.kid} /><Input label="Kid Age" inputRef={refs.age} type="number" /><Input label="Club" inputRef={refs.club} />
              <Input label="City" inputRef={refs.city} />
              <Select label="State" inputRef={refs.state}><option>PA</option><option>NJ</option><option>DE</option><option>MD</option><option>NY</option></Select>
              <Select label="Stage" inputRef={refs.stage}>{STAGES.map(s => <option key={s.k} value={s.k}>{s.k}</option>)}</Select>
              <Select label="Source" inputRef={refs.source}><option>manual</option><option>landing_page</option><option>meta_ads</option><option>google_ads</option><option>referral</option></Select>
              <Input label="Tags" inputRef={refs.tags} placeholder="Cherry Hill, Competitive" />
            </div>
            <Btn onClick={addFamily}>Save Contact</Btn>
          </Card>
        )}
        <div style={{ display: "flex", gap: 8, padding: "14px 0", position: "sticky", top: 0, background: V.bg, zIndex: 5, alignItems: "center" }}>
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search contacts..." style={{ ...inputStyle, maxWidth: 300 }} />
          <select style={{ ...inputStyle, minWidth: 140 }} value={stageFilter} onChange={e => setStageFilter(e.target.value)}>
            <option value="all">All ({(families || []).length})</option>
            {STAGES.map(s => <option key={s.k} value={s.k}>{s.k}</option>)}
          </select>
          {totalPages > 1 && (
            <div style={{ marginLeft: "auto", display: "flex", gap: 4, alignItems: "center" }}>
              <Btn variant="ghost" disabled={page === 0} onClick={() => setPage(p => p - 1)} style={{ padding: "3px 8px" }}>Prev</Btn>
              <span style={{ ...monoStyle, fontSize: 10, color: V.muted }}>{page + 1}/{totalPages}</span>
              <Btn variant="ghost" disabled={page >= totalPages - 1} onClick={() => setPage(p => p + 1)} style={{ padding: "3px 8px" }}>Next</Btn>
            </div>
          )}
        </div>
        <Card style={{ padding: 0 }}>
          {visible.length === 0 ? <Empty text="No contacts match" /> : (
            <table style={{ width: "100%", borderCollapse: "collapse" }}>
              <thead><tr>{["Parent", "Player", "Stage", "LTV", "Phone", "Location", ""].map(h => <th key={h} style={thStyle}>{h}</th>)}</tr></thead>
              <tbody>{visible.map(f => {
                const k = (f.children || [])[0]; const st = (f.tags || []).find(t => STAGES.some(s => s.k === t)) || "";
                return (
                  <tr key={f.id} style={{ borderBottom: `1px solid ${V.border}`, cursor: "pointer" }} onClick={() => onOpenContact(f.id)}>
                    <td style={{ padding: "7px 12px" }}><span style={{ fontWeight: 600, color: V.blue }}>{f.display_name}</span><br /><span style={{ fontSize: 10, color: V.muted }}>{f.email}</span></td>
                    <td style={{ padding: "7px 12px", fontSize: 11 }}>{k ? `${k.first_name}${k.age ? `, ${k.age}` : ""}${k.club ? ` - ${k.club}` : ""}` : "--"}</td>
                    <td style={{ padding: "7px 12px" }}><Badge bg={sc(st)}>{st || "--"}</Badge></td>
                    <td style={{ padding: "7px 12px", ...monoStyle, fontSize: 11, fontWeight: 600, color: (f.total_spent || 0) > 0 ? V.green : V.muted }}>{fm(f.total_spent)}</td>
                    <td style={{ padding: "7px 12px", ...monoStyle, fontSize: 10 }}>{fp(f.phone)}</td>
                    <td style={{ padding: "7px 12px", fontSize: 11, color: V.muted }}>{f.city ? `${f.city}, ` : ""}{f.state || ""}</td>
                    <td style={{ padding: "7px 12px" }}><Btn variant="danger" onClick={e => { e.stopPropagation(); deleteFamily(f.id); }} style={{ fontSize: 7, padding: "2px 6px" }}>X</Btn></td>
                  </tr>
                );
              })}</tbody>
            </table>
          )}
        </Card>
      </div>
    </>
  );
}

// ═══════════════════════════════════════════════
// CUSTOMER 360
// ═══════════════════════════════════════════════
function Customer360() {
  const [q, setQ] = useState(""); const [results, setResults] = useState([]); const [profile, setProfile] = useState(null); const [loading, setLoading] = useState(false);
  const dq = useDebounce(q, 300);
  useEffect(() => { if (dq.length >= 2) api(`customer360-search?q=${encodeURIComponent(dq)}`).then(r => setResults(r?.results || [])); }, [dq]);
  const load360 = async key => { setLoading(true); setProfile(await api(`customer360/${encodeURIComponent(key)}`).catch(() => null)); setLoading(false); };
  const typeC = { pipeline: V.purple, follow_up: V.blue, training_booking: V.green, camp_booking: V.orange, sms: V.cyan };

  return (
    <>
      <PageHeader title="Customer 360" />
      <div style={{ flex: 1, overflowY: "auto", padding: "20px 24px" }}>
        {!profile ? (
          <>
            <div style={{ display: "flex", gap: 8, marginBottom: 16 }}>
              <input value={q} onChange={e => setQ(e.target.value)} placeholder="Search by name, email, or phone..." style={{ ...inputStyle, maxWidth: 400 }} />
            </div>
            {results.length > 0 && <Card style={{ padding: 0 }}>{results.map((r, i) => (
              <div key={i} onClick={() => load360(r.lookup)} style={{ padding: "10px 16px", borderBottom: `1px solid ${V.border}`, cursor: "pointer", display: "flex", justifyContent: "space-between" }}>
                <span style={{ fontWeight: 600 }}>{r.name}</span><span style={{ fontSize: 11, color: V.muted }}>{r.email || r.phone} ({r.source})</span>
              </div>
            ))}</Card>}
          </>
        ) : loading ? <Skeleton rows={8} /> : (
          <>
            <Btn variant="ghost" onClick={() => { setProfile(null); setResults([]); setQ(""); }} style={{ marginBottom: 12 }}>Back</Btn>
            <Card style={{ marginBottom: 16 }}>
              <div style={{ display: "flex", gap: 16, alignItems: "flex-start" }}>
                <div style={{ width: 56, height: 56, background: V.gold, display: "flex", alignItems: "center", justifyContent: "center", ...headStyle, fontSize: 20, fontWeight: 700, flexShrink: 0 }}>{ini(profile.name)}</div>
                <div style={{ flex: 1 }}>
                  <div style={{ fontSize: 22, fontWeight: 700 }}>{profile.name}</div>
                  <div style={{ fontSize: 12, color: V.muted }}>{profile.email} | {fp(profile.phone)}</div>
                  <div style={{ display: "flex", gap: 4, marginTop: 6, flexWrap: "wrap" }}>{(profile.tags || []).map((t, i) => <Badge key={i} bg={V.purple}>{t}</Badge>)}</div>
                </div>
                <div style={{ textAlign: "right" }}><div style={{ ...headStyle, fontSize: 28, fontWeight: 700, color: V.green }}>{fm(profile.total_ltv || 0)}</div><div style={{ fontSize: 9, color: V.muted, textTransform: "uppercase" }}>LTV</div></div>
              </div>
            </Card>
            {(profile.players || []).length > 0 && <><ST>Players</ST><div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill,minmax(200px,1fr))", gap: 12, marginBottom: 16 }}>{profile.players.map((p, i) => <Card key={i} style={{ padding: 12 }}><div style={{ fontWeight: 600 }}>{p.first_name} {p.last_name}</div><div style={{ fontSize: 11, color: V.muted }}>{p.age ? `Age ${p.age}` : ""} {p.position ? `/ ${p.position}` : ""}</div>{p.club && <div style={{ fontSize: 11, color: V.blue }}>{p.club}</div>}</Card>)}</div></>}
            <ST>Timeline ({(profile.timeline || []).length})</ST>
            <Card style={{ padding: 0, maxHeight: 400, overflowY: "auto" }}>
              {(profile.timeline || []).sort((a, b) => new Date(b.date) - new Date(a.date)).map((t, i) => (
                <div key={i} style={{ padding: "10px 16px", borderBottom: `1px solid ${V.border}`, display: "flex", gap: 10 }}>
                  <div style={{ width: 8, height: 8, borderRadius: "50%", marginTop: 5, flexShrink: 0, background: typeC[t.type] || V.muted }} />
                  <div style={{ flex: 1 }}><div style={{ fontSize: 12, fontWeight: 600 }}>{t.title}</div><div style={{ fontSize: 11, color: V.muted }}>{t.detail}</div></div>
                  <div style={{ fontSize: 10, color: V.muted, whiteSpace: "nowrap" }}>{fd(t.date)}</div>
                </div>
              ))}
            </Card>
          </>
        )}
      </div>
    </>
  );
}

// ═══════════════════════════════════════════════
// INBOX (real-time, AI drafts)
// ═══════════════════════════════════════════════
function Inbox({ conversations, onRefresh }) {
  const [activePhone, setActivePhone] = useState(null);
  const [messages, setMessages] = useState([]);
  const [search, setSearch] = useState("");
  const [msg, setMsg] = useState("");
  const [aiDraft, setAiDraft] = useState("");
  const threadRef = useRef(null);
  const pollRef = useRef(null);
  const lastIdRef = useRef(0);
  const dSearch = useDebounce(search, 200);

  useEffect(() => { window.__openThread = p => openThread(p); return () => { window.__openThread = null; }; }, []);

  const openThread = async phone => {
    setActivePhone(phone); setAiDraft("");
    const ms = await api(`desktop/thread/${encodeURIComponent(phone)}`).catch(() => []);
    setMessages(ms || []);
    if (ms?.length) lastIdRef.current = Math.max(...ms.map(m => parseInt(m.id) || 0));
    setTimeout(() => { if (threadRef.current) threadRef.current.scrollTop = threadRef.current.scrollHeight; }, 80);
  };

  const sendMsg = async () => {
    if (!msg.trim() || !activePhone) return;
    const body = msg.trim(); setMsg("");
    setMessages(prev => [...prev, { id: "sending", direction: "outgoing", body, created_at: new Date().toISOString() }]);
    setTimeout(() => { if (threadRef.current) threadRef.current.scrollTop = threadRef.current.scrollHeight; }, 30);
    await api("desktop/send", "POST", { phone: activePhone, body }).catch(() => null);
    cacheClear("desktop/conversations"); onRefresh();
    const ms = await api(`desktop/thread/${encodeURIComponent(activePhone)}`).catch(() => null);
    if (ms) setMessages(ms);
  };

  const genAI = async () => {
    if (!activePhone) return; setAiDraft("Generating...");
    const r = await api("ai/generate-reply-for-phone", "POST", { phone: activePhone }).catch(() => null);
    setAiDraft(r?.reply || r?.draft || "Failed");
  };

  useEffect(() => {
    if (pollRef.current) clearInterval(pollRef.current);
    if (!activePhone) return;
    pollRef.current = setInterval(async () => {
      const r = await api(`desktop/poll?since_id=${lastIdRef.current}&phone=${encodeURIComponent(activePhone)}`).catch(() => null);
      if (r?.new_msgs?.length) {
        setMessages(prev => { const ids = new Set(prev.map(m => m.id)); return [...prev.filter(m => m.id !== "sending"), ...r.new_msgs.filter(m => !ids.has(m.id))]; });
        if (r.last_id > lastIdRef.current) lastIdRef.current = r.last_id;
        setTimeout(() => { if (threadRef.current) threadRef.current.scrollTop = threadRef.current.scrollHeight; }, 50);
      }
    }, 5000);
    return () => clearInterval(pollRef.current);
  }, [activePhone]);

  const filteredConvos = useMemo(() => {
    if (!dSearch) return conversations || [];
    const q = dSearch.toLowerCase();
    return (conversations || []).filter(c => (c.display_name || "").toLowerCase().includes(q) || (c.last_message || "").toLowerCase().includes(q));
  }, [conversations, dSearch]);

  const activeName = (conversations || []).find(c => c.phone === activePhone)?.display_name || fp(activePhone);

  return (
    <div style={{ display: "flex", flex: 1, overflow: "hidden" }}>
      <div style={{ width: 340, borderRight: `2px solid ${V.border}`, display: "flex", flexDirection: "column", background: V.white }}>
        <div style={{ padding: "12px 16px", borderBottom: `2px solid ${V.border}`, background: V.light }}>
          <div style={{ ...headStyle, fontSize: 14, fontWeight: 700, textTransform: "uppercase", letterSpacing: 1, marginBottom: 8 }}>Messages</div>
          <input value={search} onChange={e => setSearch(e.target.value)} placeholder="Search..." style={{ ...inputStyle, fontSize: 11 }} />
        </div>
        <div style={{ flex: 1, overflowY: "auto" }}>
          {filteredConvos.map(c => (
            <div key={c.phone} onClick={() => openThread(c.phone)} style={{ padding: "12px 16px", cursor: "pointer", borderBottom: `1px solid ${V.border}`, borderLeft: `3px solid ${activePhone === c.phone ? V.gold : "transparent"}`, background: activePhone === c.phone ? "#FFFDE7" : "transparent" }}>
              <div style={{ fontSize: 13, fontWeight: 600, display: "flex", justifyContent: "space-between" }}>
                <span>{c.display_name || fp(c.phone)}</span>
                <span style={{ display: "flex", alignItems: "center", gap: 6 }}>{c.unread > 0 && <Badge bg={V.red}>{c.unread}</Badge>}<span style={{ ...monoStyle, fontSize: 9, color: V.muted }}>{fd(c.last_ts)}</span></span>
              </div>
              <div style={{ fontSize: 11, color: V.muted, marginTop: 3, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{c.last_message}</div>
            </div>
          ))}
        </div>
      </div>
      <div style={{ flex: 1, display: "flex", flexDirection: "column", background: V.light }}>
        {!activePhone ? <div style={{ flex: 1, display: "flex", alignItems: "center", justifyContent: "center", color: V.muted, ...headStyle, fontSize: 12, textTransform: "uppercase" }}>Select a conversation</div> : (
          <>
            <div style={{ padding: "12px 16px", borderBottom: `2px solid ${V.border}`, background: V.white, display: "flex", justifyContent: "space-between", alignItems: "center", flexShrink: 0 }}>
              <div style={{ display: "flex", alignItems: "center", gap: 10 }}><span style={{ fontSize: 14, fontWeight: 600 }}>{activeName}</span><span style={{ ...monoStyle, fontSize: 10, color: V.muted }}>{fp(activePhone)}</span></div>
              <Btn variant="blue" onClick={genAI} style={{ fontSize: 8, padding: "3px 8px" }}>AI Draft</Btn>
            </div>
            <div ref={threadRef} style={{ flex: 1, overflowY: "auto", padding: 16 }}>
              {messages.map((m, i) => (
                <div key={m.id || i} style={{ display: "flex", marginBottom: 10, justifyContent: m.direction === "outgoing" ? "flex-end" : "flex-start" }}>
                  <div style={{ maxWidth: "75%", padding: "8px 12px", border: `2px solid ${m.direction === "outgoing" ? V.gold : V.border}`, background: m.direction === "outgoing" ? "#FFF8E1" : V.white, opacity: m.id === "sending" ? 0.6 : 1 }}>
                    <div style={{ fontSize: 12, lineHeight: 1.5 }}>{m.body}</div>
                    <div style={{ fontSize: 9, color: V.muted, marginTop: 3, textAlign: "right" }}>{m.id === "sending" ? "Sending..." : ft(m.created_at)}</div>
                  </div>
                </div>
              ))}
            </div>
            {aiDraft && <div style={{ padding: "8px 16px", background: "#E3F2FD", borderTop: `1px solid ${V.blue}`, fontSize: 11 }}><strong style={{ color: V.blue }}>AI:</strong> {aiDraft}{aiDraft !== "Generating..." && <Btn variant="blue" onClick={() => { setMsg(aiDraft); setAiDraft(""); }} style={{ marginLeft: 8, fontSize: 8, padding: "2px 6px" }}>Use</Btn>}</div>}
            <div style={{ padding: "10px 16px", borderTop: `2px solid ${V.border}`, background: V.white, display: "flex", gap: 8, flexShrink: 0 }}>
              <textarea value={msg} onChange={e => setMsg(e.target.value)} onKeyDown={e => { if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); sendMsg(); } }} placeholder="Type a message..." rows={2} style={{ flex: 1, resize: "none", ...inputStyle, border: `2px solid ${V.border}` }} />
              <Btn onClick={sendMsg} style={{ alignSelf: "flex-end", padding: "8px 16px" }}>Send</Btn>
            </div>
          </>
        )}
      </div>
    </div>
  );
}

// ═══════════════════════════════════════════════
// COACHES
// ═══════════════════════════════════════════════
function Coaches() {
  const { data, loading, reload } = useCachedFetch("trainers", [], 60000);
  const [showAdd, setShowAdd] = useState(false);
  const refs = { name: useRef(), phone: useRef(), email: useRef(), rate: useRef(), loc: useRef(), bio: useRef() };
  const trainers = Array.isArray(data) ? data : data?.trainers || [];
  const add = async () => { const g = r => r.current?.value || ""; await api("trainers/create", "POST", { display_name: g(refs.name), phone: g(refs.phone), email: g(refs.email), hourly_rate: g(refs.rate), location: g(refs.loc), bio: g(refs.bio) }); setShowAdd(false); cacheClear("trainers"); reload(true); };
  return (
    <>
      <PageHeader title="Coaches"><Btn onClick={() => setShowAdd(!showAdd)}>{showAdd ? "Cancel" : "+ Add Coach"}</Btn></PageHeader>
      <div style={{ flex: 1, overflowY: "auto", padding: "20px 24px" }}>
        {showAdd && <Card style={{ marginBottom: 16, padding: 16 }}><div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: "0 10px" }}><Input label="Name" inputRef={refs.name} placeholder="Eddy Davis" /><Input label="Phone" inputRef={refs.phone} /><Input label="Email" inputRef={refs.email} /><Input label="Rate" inputRef={refs.rate} type="number" placeholder="80" /><Input label="Location" inputRef={refs.loc} /><Input label="Bio" inputRef={refs.bio} /></div><Btn onClick={add}>Save</Btn></Card>}
        {loading && !data ? <Skeleton rows={4} /> : trainers.length === 0 ? <Empty text="No coaches" /> : (
          <div style={{ display: "grid", gridTemplateColumns: "repeat(auto-fill,minmax(280px,1fr))", gap: 16 }}>
            {trainers.map(t => <Card key={t.id} style={{ display: "flex", gap: 14 }}><div style={{ width: 48, height: 48, background: V.gold, display: "flex", alignItems: "center", justifyContent: "center", ...headStyle, fontWeight: 700, flexShrink: 0 }}>{ini(t.display_name)}</div><div style={{ flex: 1 }}><div style={{ fontSize: 16, fontWeight: 700 }}>{t.display_name}</div><div style={{ fontSize: 11, color: V.muted }}>{t.bio || t.location}</div><div style={{ fontSize: 11, marginTop: 4 }}>{fp(t.phone)} | {t.email}</div><div style={{ ...headStyle, fontSize: 16, fontWeight: 700, color: V.green, marginTop: 4 }}>${t.hourly_rate || 0}/hr</div></div></Card>)}
          </div>
        )}
      </div>
    </>
  );
}

// ═══════════════════════════════════════════════
// BOOKINGS
// ═══════════════════════════════════════════════
function Bookings() {
  const { data, loading } = useCachedFetch("bookings", [], 30000);
  const bookings = Array.isArray(data) ? data : data?.bookings || [];
  const stC = { confirmed: V.green, pending: V.orange, completed: V.blue, cancelled: V.red };
  return (
    <>
      <PageHeader title="Bookings" />
      <div style={{ flex: 1, overflowY: "auto", padding: "20px 24px" }}>
        {loading && !data ? <Skeleton rows={6} /> : bookings.length === 0 ? <Empty text="No bookings" /> : (
          <Card style={{ padding: 0 }}><table style={{ width: "100%", borderCollapse: "collapse" }}><thead><tr>{["Parent", "Player", "Coach", "Date", "Amount", "Status"].map(h => <th key={h} style={thStyle}>{h}</th>)}</tr></thead><tbody>{bookings.map(b => (
            <tr key={b.id} style={{ borderBottom: `1px solid ${V.border}` }}><td style={{ padding: "7px 12px", fontWeight: 600 }}>{b.parent_name || b.display_name || "--"}</td><td style={{ padding: "7px 12px", fontSize: 11 }}>{b.child_name || "--"}</td><td style={{ padding: "7px 12px", fontSize: 11, color: V.blue }}>{b.trainer_name || "--"}</td><td style={{ padding: "7px 12px", ...monoStyle, fontSize: 10 }}>{b.session_date || fd(b.created_at)}</td><td style={{ padding: "7px 12px", ...monoStyle, fontSize: 11, fontWeight: 600, color: V.green }}>{fm(b.total_amount || b.amount)}</td><td style={{ padding: "7px 12px" }}><Badge bg={stC[b.status] || V.muted}>{b.status || "--"}</Badge></td></tr>
          ))}</tbody></table></Card>
        )}
      </div>
    </>
  );
}

// ═══════════════════════════════════════════════
// CAMPS (5 tabs)
// ═══════════════════════════════════════════════
function Camps() {
  const [tab, setTab] = useState("overview");
  const { data: stats, loading: l1 } = useCachedFetch("camps/stats", [], 60000);
  const { data: listings } = useCachedFetch("camps/listings", [], 60000);
  const { data: bkData } = useCachedFetch("camps/bookings", [], 30000);
  const { data: abData } = useCachedFetch("camps/abandoned", [], 30000);
  const { data: cuData } = useCachedFetch("camps/customers", [], 30000);
  const list = Array.isArray(listings) ? listings : listings?.listings || [];
  const bks = Array.isArray(bkData) ? bkData : bkData?.bookings || [];
  const abs = Array.isArray(abData) ? abData : abData?.abandoned || [];
  const cus = Array.isArray(cuData) ? cuData : cuData?.customers || [];

  return (
    <>
      <PageHeader title="Camps" />
      <TabBar tabs={[{ k: "overview", l: "Overview" }, { k: "listings", l: "Listings", count: list.length }, { k: "bookings", l: "Bookings", count: bks.length }, { k: "abandoned", l: "Abandoned", count: abs.length }, { k: "customers", l: "Customers", count: cus.length }]} active={tab} onChange={setTab} />
      <div style={{ flex: 1, overflowY: "auto", padding: "20px 24px" }}>
        {l1 && !stats ? <Skeleton rows={4} /> : tab === "overview" && stats ? (
          <div style={{ display: "grid", gridTemplateColumns: "repeat(3,1fr)", gap: 16 }}>
            <Stat label="Camp Revenue" value={fm(stats.total_revenue)} color={V.green} /><Stat label="Total Bookings" value={stats.total_bookings} /><Stat label="Unique Families" value={stats.unique_families} />
            <Stat label="Avg Order" value={fm(stats.avg_order)} /><Stat label="Abandoned" value={stats.abandoned_count} color={V.red} /><Stat label="Abandoned $" value={fm(stats.abandoned_value)} color={V.red} />
          </div>
        ) : tab === "listings" ? <Card style={{ padding: 0 }}>{list.length === 0 ? <Empty text="No listings" /> : list.map((l, i) => <div key={i} style={{ padding: "12px 16px", borderBottom: `1px solid ${V.border}`, display: "flex", justifyContent: "space-between" }}><div><div style={{ fontWeight: 600 }}>{l.title || l.post_title || `Camp #${l.id}`}</div><div style={{ fontSize: 11, color: V.muted }}>{l.start_date} {l.location}</div></div><div style={{ ...headStyle, fontSize: 18, fontWeight: 700, color: V.green }}>{fm(l.price)}</div></div>)}</Card>
        : tab === "bookings" ? <Card style={{ padding: 0 }}>{bks.length === 0 ? <Empty text="No camp bookings" /> : <table style={{ width: "100%", borderCollapse: "collapse" }}><thead><tr>{["Customer", "Camp", "Amount", "Date", "Status"].map(h => <th key={h} style={thStyle}>{h}</th>)}</tr></thead><tbody>{bks.map((b, i) => <tr key={i} style={{ borderBottom: `1px solid ${V.border}` }}><td style={{ padding: "7px 12px", fontWeight: 600 }}>{b.customer_name || b.parent_name}</td><td style={{ padding: "7px 12px", fontSize: 11 }}>{b.camp_title || b.camp_name || "--"}</td><td style={{ padding: "7px 12px", ...monoStyle, color: V.green }}>{fm(b.amount_paid || b.total_amount)}</td><td style={{ padding: "7px 12px", fontSize: 10 }}>{fd(b.created_at)}</td><td style={{ padding: "7px 12px" }}><Badge bg={b.status === "confirmed" ? V.green : V.orange}>{b.status}</Badge></td></tr>)}</tbody></table>}</Card>
        : tab === "abandoned" ? <Card style={{ padding: 0 }}>{abs.length === 0 ? <Empty text="No abandoned" /> : abs.map((a, i) => <div key={i} style={{ padding: "10px 16px", borderBottom: `1px solid ${V.border}`, display: "flex", justifyContent: "space-between" }}><div><div style={{ fontWeight: 600 }}>{a.customer_name || a.email}</div><div style={{ fontSize: 11, color: V.muted }}>{a.email} | {fd(a.created_at)}</div></div><div style={{ ...headStyle, fontSize: 16, fontWeight: 700, color: V.red }}>{fm(a.cart_total || a.amount)}</div></div>)}</Card>
        : tab === "customers" ? <Card style={{ padding: 0 }}>{cus.length === 0 ? <Empty text="No customers" /> : cus.map((c, i) => <div key={i} style={{ padding: "10px 16px", borderBottom: `1px solid ${V.border}`, display: "flex", justifyContent: "space-between" }}><div><div style={{ fontWeight: 600 }}>{c.customer_name || c.name}</div><div style={{ fontSize: 11, color: V.muted }}>{c.email}</div></div><div style={{ ...monoStyle, fontSize: 11, fontWeight: 600, color: V.green }}>{fm(c.total_spent || c.ltv)}</div></div>)}</Card>
        : null}
      </div>
    </>
  );
}

// ═══════════════════════════════════════════════
// CAMPAIGNS (SMS + Email)
// ═══════════════════════════════════════════════
function Campaigns() {
  const [tab, setTab] = useState("email");
  const { data: ec, loading: l1, reload: r1 } = useCachedFetch("desktop/email-campaigns", [], 20000);
  const { data: sc, loading: l2, reload: r2 } = useCachedFetch("campaigns", [], 20000);
  const emails = Array.isArray(ec) ? ec : []; const sms = Array.isArray(sc) ? sc : sc?.campaigns || [];
  const stC = { draft: V.muted, sending: V.blue, paused: V.orange, completed: V.green, cancelled: V.red, sent: V.green };
  const send = async (type, id) => { if (!confirm("Send?")) return; await api(`${type === "email" ? "desktop/email-campaigns" : "campaigns"}/${id}/send`, "POST"); cacheClear(); r1(true); r2(true); };
  const del = async (type, id) => { if (!confirm("Delete?")) return; await api(`${type === "email" ? "desktop/email-campaigns" : "campaigns"}/${id}`, "DELETE"); cacheClear(); r1(true); r2(true); };
  const renderTable = (items, type) => items.length === 0 ? <Empty text={`No ${type} campaigns`} /> : (
    <Card style={{ padding: 0 }}><table style={{ width: "100%", borderCollapse: "collapse" }}><thead><tr>{["Campaign", "Status", "Audience", "Sent", "Subject", ""].map(h => <th key={h} style={thStyle}>{h}</th>)}</tr></thead><tbody>{items.map(c => (
      <tr key={c.id} style={{ borderBottom: `1px solid ${V.border}` }}><td style={{ padding: "7px 12px", fontWeight: 600 }}>{c.name}</td><td style={{ padding: "7px 12px" }}><Badge bg={stC[c.status] || V.muted}>{c.status}</Badge></td><td style={{ padding: "7px 12px", ...monoStyle, fontSize: 11 }}>{c.audience_count || c.recipient_count || 0}</td><td style={{ padding: "7px 12px", ...monoStyle, fontSize: 11, color: V.green }}>{c.sent_count || 0}</td><td style={{ padding: "7px 12px", fontSize: 11, maxWidth: 200, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }}>{c.subject || c.message?.slice(0, 60) || "--"}</td><td style={{ padding: "7px 12px" }}><div style={{ display: "flex", gap: 4 }}>{(c.status === "draft" || c.status === "paused") && <Btn variant="dark" onClick={() => send(type, c.id)} style={{ fontSize: 7, padding: "2px 6px" }}>Send</Btn>}<Btn variant="danger" onClick={() => del(type, c.id)} style={{ fontSize: 7, padding: "2px 6px" }}>X</Btn></div></td></tr>
    ))}</tbody></table></Card>
  );
  return (
    <>
      <PageHeader title="Campaigns" />
      <TabBar tabs={[{ k: "email", l: "Email", count: emails.length }, { k: "sms", l: "SMS", count: sms.length }]} active={tab} onChange={setTab} />
      <div style={{ flex: 1, overflowY: "auto", padding: "20px 24px" }}>{(l1 || l2) && !(ec || sc) ? <Skeleton rows={4} /> : tab === "email" ? renderTable(emails, "email") : renderTable(sms, "sms")}</div>
    </>
  );
}

// ═══════════════════════════════════════════════
// AI ENGINE
// ═══════════════════════════════════════════════
function AIEngine() {
  const { data: settings, reload: rSettings } = useCachedFetch("ai/settings", [], 60000);
  const { data: stats } = useCachedFetch("ai/stats", [], 30000);
  const { data: draftsData, reload: rDrafts } = useCachedFetch("drafts", [], 10000);
  const drafts = Array.isArray(draftsData) ? draftsData : draftsData?.drafts || [];
  const handle = async (id, action) => { await api(`drafts/${id}/${action}`, "POST"); cacheClear("drafts"); rDrafts(true); };
  const saveSetting = async d => { await api("ai/settings", "POST", d); cacheClear("ai/settings"); rSettings(true); };
  return (
    <>
      <PageHeader title="AI Engine" />
      <div style={{ flex: 1, overflowY: "auto", padding: "20px 24px" }}>
        <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 20 }}>
          <div>
            <ST>Draft Queue ({drafts.length})</ST>
            {drafts.length === 0 ? <Card><Empty text="No pending drafts" /></Card> : drafts.map(d => (
              <Card key={d.id} style={{ marginBottom: 8 }}>
                <div style={{ display: "flex", justifyContent: "space-between", marginBottom: 6 }}><span style={{ ...monoStyle, fontSize: 10, color: V.muted }}>{fp(d.phone)} | {fd(d.created_at)}</span><Badge bg={d.status === "pending" ? V.orange : V.green}>{d.status}</Badge></div>
                <div style={{ fontSize: 12, lineHeight: 1.5, marginBottom: 8, padding: 8, background: V.light, border: `1px solid ${V.border}` }}>{d.body}</div>
                {d.status === "pending" && <div style={{ display: "flex", gap: 4 }}><Btn onClick={() => handle(d.id, "approve")}>Approve</Btn><Btn variant="danger" onClick={() => handle(d.id, "reject")}>Reject</Btn></div>}
              </Card>
            ))}
          </div>
          <div>
            {stats && <><ST>Stats</ST><Card style={{ marginBottom: 16 }}><div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 12 }}>{[{ l: "Generated", v: stats.total_generated }, { l: "Approved", v: stats.total_approved, c: V.green }, { l: "Rejected", v: stats.total_rejected, c: V.red }, { l: "Rate", v: `${stats.approval_rate || 0}%`, c: V.gold }].map((s, i) => <div key={i}><div style={{ fontSize: 11, color: V.muted }}>{s.l}</div><div style={{ ...headStyle, fontSize: 24, fontWeight: 700, color: s.c || V.text }}>{s.v || 0}</div></div>)}</div></Card></>}
            {settings && <><ST>Settings</ST><Card>
              {[{ k: "enabled", l: "AI Engine Enabled" }, { k: "auto_draft", l: "Auto-Draft Replies" }].map(s => <div key={s.k} style={{ marginBottom: 12 }}><label style={{ display: "flex", alignItems: "center", gap: 8, cursor: "pointer" }}><input type="checkbox" checked={settings[s.k]} onChange={e => saveSetting({ [s.k]: e.target.checked })} /><span style={{ fontSize: 12 }}>{s.l}</span></label></div>)}
              <Select label="Tone" value={settings.tone || "friendly"} onChange={e => saveSetting({ tone: e.target.value })}><option>friendly</option><option>professional</option><option>casual</option><option>urgent</option></Select>
            </Card></>}
          </div>
        </div>
      </div>
    </>
  );
}

// ═══════════════════════════════════════════════
// TEMPLATES (new)
// ═══════════════════════════════════════════════
function Templates() {
  const { data, loading, reload } = useCachedFetch("templates", [], 60000);
  const templates = data?.templates || [];
  const refs = { name: useRef(), cat: useRef(), body: useRef() };
  const add = async () => { const g = r => r.current?.value || ""; if (!g(refs.name)) return; await api("templates", "POST", { name: g(refs.name), category: g(refs.cat), body: g(refs.body) }); cacheClear("templates"); reload(true); };
  const del = async id => { await api(`templates/${id}`, "DELETE"); cacheClear("templates"); reload(true); };
  const use = async (id) => { const r = await api(`templates/${id}/use`, "POST"); if (r?.body) navigator.clipboard.writeText(r.body); };
  return (
    <>
      <PageHeader title="SMS Templates" />
      <div style={{ flex: 1, overflowY: "auto", padding: "20px 24px" }}>
        <Card style={{ marginBottom: 16, padding: 16 }}>
          <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: "0 10px" }}>
            <Input label="Name" inputRef={refs.name} placeholder="Follow-Up After Camp" />
            <Select label="Category" inputRef={refs.cat}><option>general</option><option>follow_up</option><option>pricing</option><option>scheduling</option><option>onboarding</option></Select>
          </div>
          <div style={{ marginBottom: 8 }}><label style={lblStyle}>Message Body</label><textarea ref={refs.body} placeholder="Hey {name}! Thanks for coming to camp..." rows={3} style={{ ...inputStyle, resize: "vertical" }} /></div>
          <Btn onClick={add}>Save Template</Btn>
        </Card>
        {loading && !data ? <Skeleton rows={4} /> : templates.length === 0 ? <Empty text="No templates" /> : templates.map(t => (
          <Card key={t.id} style={{ marginBottom: 8 }}>
            <div style={{ display: "flex", justifyContent: "space-between", alignItems: "flex-start" }}>
              <div style={{ flex: 1 }}><div style={{ fontWeight: 600, display: "flex", gap: 8, alignItems: "center" }}>{t.name} <Badge bg={V.purple}>{t.category}</Badge> <span style={{ ...monoStyle, fontSize: 9, color: V.muted }}>used {t.use_count || 0}x</span></div><div style={{ fontSize: 12, color: V.muted, marginTop: 4, lineHeight: 1.4 }}>{t.body}</div></div>
              <div style={{ display: "flex", gap: 4, flexShrink: 0 }}><Btn variant="ghost" onClick={() => use(t.id)} style={{ fontSize: 8 }}>Copy</Btn><Btn variant="danger" onClick={() => del(t.id)} style={{ fontSize: 8 }}>X</Btn></div>
            </div>
          </Card>
        ))}
      </div>
    </>
  );
}

// ═══════════════════════════════════════════════
// RULES ENGINE
// ═══════════════════════════════════════════════
function Rules() {
  const { data, loading, reload } = useCachedFetch("rules", [], 60000);
  const rules = Array.isArray(data) ? data : data?.rules || [];
  const refs = { name: useRef(), trigger: useRef(), kw: useRef(), action: useRef(), msg: useRef() };
  const add = async () => { const g = r => r.current?.value || ""; await api("rules", "POST", { name: g(refs.name), trigger: g(refs.trigger), keyword: g(refs.kw), action: g(refs.action), message: g(refs.msg), active: true }); cacheClear("rules"); reload(true); };
  const del = async id => { await api(`rules/${id}`, "DELETE"); cacheClear("rules"); reload(true); };
  const toggle = async (id, active) => { await api(`rules/${id}`, "PATCH", { active: !active }); cacheClear("rules"); reload(true); };
  return (
    <>
      <PageHeader title="Rules Engine" />
      <div style={{ flex: 1, overflowY: "auto", padding: "20px 24px" }}>
        <Card style={{ marginBottom: 16, padding: 16 }}><div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: "0 10px" }}><Input label="Name" inputRef={refs.name} placeholder="Pricing Inquiry" /><Select label="Trigger" inputRef={refs.trigger}><option value="keyword">Keyword</option><option value="intent">Intent</option><option value="time">Time</option></Select><Input label="Keywords (pipe-sep)" inputRef={refs.kw} placeholder="price|cost" /><Select label="Action" inputRef={refs.action}><option value="auto_reply">Auto Reply</option><option value="tag">Tag</option><option value="notify">Notify</option></Select><div style={{ gridColumn: "span 2" }}><Input label="Message" inputRef={refs.msg} /></div></div><Btn onClick={add}>Add Rule</Btn></Card>
        {loading && !data ? <Skeleton rows={4} /> : rules.length === 0 ? <Empty text="No rules" /> : (
          <Card style={{ padding: 0 }}><table style={{ width: "100%", borderCollapse: "collapse" }}><thead><tr>{["Name", "Trigger", "Keywords", "Action", "Active", ""].map(h => <th key={h} style={thStyle}>{h}</th>)}</tr></thead><tbody>{rules.map(r => (
            <tr key={r.id} style={{ borderBottom: `1px solid ${V.border}` }}><td style={{ padding: "7px 12px", fontWeight: 600 }}>{r.name}</td><td style={{ padding: "7px 12px" }}><Badge bg={V.purple}>{r.trigger}</Badge></td><td style={{ padding: "7px 12px", ...monoStyle, fontSize: 10, color: V.muted }}>{r.keyword || "--"}</td><td style={{ padding: "7px 12px" }}><Badge bg={V.blue}>{r.action}</Badge></td><td style={{ padding: "7px 12px" }}><span style={{ cursor: "pointer", color: r.active ? V.green : V.red, fontWeight: 600 }} onClick={() => toggle(r.id, r.active)}>{r.active ? "ON" : "OFF"}</span></td><td style={{ padding: "7px 12px" }}><Btn variant="danger" onClick={() => del(r.id)} style={{ fontSize: 7, padding: "2px 6px" }}>X</Btn></td></tr>
          ))}</tbody></table></Card>
        )}
      </div>
    </>
  );
}

// ═══════════════════════════════════════════════
// SEQUENCES
// ═══════════════════════════════════════════════
function Sequences() {
  const { data, loading } = useCachedFetch("sequences/active", [], 30000);
  const seqs = Array.isArray(data) ? data : data?.sequences || [];
  return <><PageHeader title="Sequences" /><div style={{ flex: 1, overflowY: "auto", padding: "20px 24px" }}>{loading && !data ? <Skeleton rows={3} /> : seqs.length === 0 ? <Empty text="No active sequences" /> : seqs.map((s, i) => <Card key={i} style={{ marginBottom: 8, display: "flex", justifyContent: "space-between" }}><div><div style={{ fontWeight: 600 }}>{s.name || `Sequence ${s.id}`}</div><div style={{ fontSize: 11, color: V.muted }}>Step {s.current_step || 0}/{s.total_steps || 0}</div></div><Badge bg={s.active ? V.green : V.muted}>{s.active ? "Active" : "Paused"}</Badge></Card>)}</div></>;
}

// ═══════════════════════════════════════════════
// TRAINING LINKS
// ═══════════════════════════════════════════════
function TrainingLinks() {
  const { data, loading, reload } = useCachedFetch("training-links", [], 60000);
  const links = Array.isArray(data) ? data : data?.links || [];
  const refs = { name: useRef(), url: useRef(), tid: useRef() };
  const add = async () => { const g = r => r.current?.value || ""; await api("training-links", "POST", { name: g(refs.name), url: g(refs.url), trainer_id: g(refs.tid) }); cacheClear("training-links"); reload(true); };
  const del = async id => { await api(`training-links/${id}`, "DELETE"); cacheClear("training-links"); reload(true); };
  return (
    <>
      <PageHeader title="Training Links" />
      <div style={{ flex: 1, overflowY: "auto", padding: "20px 24px" }}>
        <Card style={{ marginBottom: 16, padding: 16 }}><div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: "0 10px" }}><Input label="Name" inputRef={refs.name} placeholder="Eddy's Page" /><Input label="URL" inputRef={refs.url} placeholder="https://ptpsummercamps.com/training/eddy" /><Input label="Trainer ID" inputRef={refs.tid} type="number" /></div><Btn onClick={add}>Add Link</Btn></Card>
        {loading && !data ? <Skeleton rows={3} /> : links.length === 0 ? <Empty text="No links" /> : links.map(l => <Card key={l.id} style={{ marginBottom: 8, display: "flex", justifyContent: "space-between", alignItems: "center" }}><div><div style={{ fontWeight: 600 }}>{l.name}</div><div style={{ fontSize: 11, color: V.blue }}>{l.url}</div></div><div style={{ display: "flex", gap: 4 }}><Btn variant="ghost" onClick={() => navigator.clipboard.writeText(l.url)} style={{ fontSize: 8 }}>Copy</Btn><Btn variant="danger" onClick={() => del(l.id)} style={{ fontSize: 8 }}>X</Btn></div></Card>)}
      </div>
    </>
  );
}

// ═══════════════════════════════════════════════
// ATTRIBUTION + FINANCE (with expense CRUD)
// ═══════════════════════════════════════════════
function AttribFinance() {
  const [tab, setTab] = useState("attribution");
  const { data: attr } = useCachedFetch("attribution/overview", [], 60000);
  const { data: fin, reload: rFin } = useCachedFetch("finance/summary", [], 30000);
  const { data: expData, reload: rExp } = useCachedFetch("finance/expenses", [], 30000);
  const expenses = Array.isArray(expData) ? expData : expData?.expenses || [];
  const expRefs = { cat: useRef(), desc: useRef(), amt: useRef(), date: useRef(), vendor: useRef() };

  const addExpense = async () => {
    const g = r => r.current?.value || "";
    await api("finance/expenses", "POST", { category: g(expRefs.cat), description: g(expRefs.desc), amount: g(expRefs.amt), expense_date: g(expRefs.date) || new Date().toISOString().slice(0, 10), vendor: g(expRefs.vendor) });
    cacheClear("finance/"); rExp(true); rFin(true);
  };
  const delExpense = async id => { await api(`finance/expenses/${id}`, "DELETE"); cacheClear("finance/"); rExp(true); rFin(true); };

  return (
    <>
      <PageHeader title="Attribution & Finance" />
      <TabBar tabs={[{ k: "attribution", l: "Attribution" }, { k: "finance", l: "Finance" }, { k: "expenses", l: "Expenses", count: expenses.length }]} active={tab} onChange={setTab} />
      <div style={{ flex: 1, overflowY: "auto", padding: "20px 24px" }}>
        {tab === "attribution" && attr ? (
          <>
            <div style={{ display: "grid", gridTemplateColumns: "repeat(4,1fr)", gap: 16, marginBottom: 20 }}>
              <Stat label="Touches" value={attr.total_touches || 0} /><Stat label="Conversions" value={attr.total_conversions || 0} color={V.green} /><Stat label="CAC" value={`$${attr.overall_cac || 0}`} color={V.purple} /><Stat label="Total Spend" value={fm((attr.meta_spend || 0) + (attr.google_spend || 0))} color={V.blue} />
            </div>
            <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 16 }}>
              <Card><ST>Meta Ads</ST><div style={{ ...headStyle, fontSize: 28, fontWeight: 700, color: V.blue }}>{fm(attr.meta_spend)}</div><div style={{ fontSize: 11, color: V.muted }}>{attr.meta_conversions || 0} conv | CPL ${attr.meta_conversions ? Math.round(attr.meta_spend / attr.meta_conversions) : "--"}</div></Card>
              <Card><ST>Google Ads</ST><div style={{ ...headStyle, fontSize: 28, fontWeight: 700, color: V.green }}>{fm(attr.google_spend)}</div><div style={{ fontSize: 11, color: V.muted }}>{attr.google_conversions || 0} conv | CPL ${attr.google_conversions ? Math.round(attr.google_spend / attr.google_conversions) : "--"}</div></Card>
            </div>
          </>
        ) : tab === "attribution" ? <Empty text="No attribution data" />
        : tab === "finance" && fin ? (
          <>
            <div style={{ display: "grid", gridTemplateColumns: "repeat(4,1fr)", gap: 16, marginBottom: 20 }}>
              <Stat label="Revenue" value={fm(fin.total_rev)} color={V.green} /><Stat label="Training" value={fm(fin.training_rev)} color={V.blue} /><Stat label="Camps" value={fm(fin.camp_rev)} color={V.orange} /><Stat label="Profit" value={fm(fin.net_profit)} color={fin.net_profit >= 0 ? V.green : V.red} />
            </div>
            <ST>Monthly ({fin.year})</ST>
            <Card style={{ padding: 0 }}><table style={{ width: "100%", borderCollapse: "collapse" }}><thead><tr>{["Month", "Training", "Camps", "Revenue", "Expenses", "Profit"].map(h => <th key={h} style={thStyle}>{h}</th>)}</tr></thead><tbody>{(fin.months || []).map(m => (
              <tr key={m.month} style={{ borderBottom: `1px solid ${V.border}` }}><td style={{ padding: "7px 12px", fontWeight: 600 }}>{m.label}</td><td style={{ padding: "7px 12px", ...monoStyle, fontSize: 10, color: V.blue }}>{fm(m.training)}</td><td style={{ padding: "7px 12px", ...monoStyle, fontSize: 10, color: V.orange }}>{fm(m.camps)}</td><td style={{ padding: "7px 12px", ...monoStyle, fontSize: 10, color: V.green, fontWeight: 600 }}>{fm(m.revenue)}</td><td style={{ padding: "7px 12px", ...monoStyle, fontSize: 10, color: V.red }}>{fm(m.expenses)}</td><td style={{ padding: "7px 12px", ...monoStyle, fontSize: 10, fontWeight: 700, color: m.profit >= 0 ? V.green : V.red }}>{fm(m.profit)}</td></tr>
            ))}</tbody></table></Card>
          </>
        ) : tab === "finance" ? <Empty text="No finance data" />
        : tab === "expenses" ? (
          <>
            <Card style={{ marginBottom: 16, padding: 16 }}>
              <ST>Add Expense</ST>
              <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr 1fr 1fr", gap: "0 8px" }}>
                <Select label="Category" inputRef={expRefs.cat}><option>marketing</option><option>software</option><option>equipment</option><option>facility</option><option>staff</option><option>insurance</option><option>other</option></Select>
                <Input label="Description" inputRef={expRefs.desc} placeholder="Meta ads March" />
                <Input label="Amount" inputRef={expRefs.amt} type="number" placeholder="500" />
                <Input label="Date" inputRef={expRefs.date} type="date" />
                <Input label="Vendor" inputRef={expRefs.vendor} placeholder="Meta" />
              </div>
              <Btn onClick={addExpense}>Log Expense</Btn>
            </Card>
            <Card style={{ padding: 0, maxHeight: 400, overflowY: "auto" }}>
              {expenses.length === 0 ? <Empty text="No expenses" /> : <table style={{ width: "100%", borderCollapse: "collapse" }}><thead><tr>{["Date", "Category", "Description", "Vendor", "Amount", ""].map(h => <th key={h} style={thStyle}>{h}</th>)}</tr></thead><tbody>{expenses.map(e => (
                <tr key={e.id} style={{ borderBottom: `1px solid ${V.border}` }}><td style={{ padding: "7px 12px", fontSize: 10 }}>{fd(e.expense_date)}</td><td style={{ padding: "7px 12px" }}><Badge bg={V.purple}>{e.category}</Badge></td><td style={{ padding: "7px 12px", fontSize: 11 }}>{e.description}</td><td style={{ padding: "7px 12px", fontSize: 11, color: V.muted }}>{e.vendor}</td><td style={{ padding: "7px 12px", ...monoStyle, fontWeight: 600, color: V.red }}>{fm(e.amount)}</td><td style={{ padding: "7px 12px" }}><Btn variant="danger" onClick={() => delExpense(e.id)} style={{ fontSize: 7, padding: "2px 6px" }}>X</Btn></td></tr>
              ))}</tbody></table>}
            </Card>
          </>
        ) : null}
      </div>
    </>
  );
}

// ═══════════════════════════════════════════════
// SCHEDULE / GCAL (with events + call scheduling)
// ═══════════════════════════════════════════════
function Schedule() {
  const { data: gs } = useCachedFetch("gcal/status", [], 60000);
  const { data: events } = useCachedFetch("gcal/events", [], 30000);
  const { data: schData, reload: rSch } = useCachedFetch("calls/scheduled", [], 20000);
  const { data: cStats } = useCachedFetch("calls/stats", [], 30000);
  const scheduled = Array.isArray(schData) ? schData : schData?.calls || [];
  const evts = events?.events || [];
  const refs = { name: useRef(), phone: useRef(), date: useRef(), notes: useRef() };

  const scheduleCall = async () => {
    const g = r => r.current?.value || "";
    await api("calls/schedule", "POST", { contact_name: g(refs.name), contact_phone: g(refs.phone), scheduled_at: g(refs.date), notes: g(refs.notes) });
    cacheClear("calls/"); rSch(true);
  };
  const complete = async id => { await api(`calls/complete/${id}`, "POST"); cacheClear("calls/"); rSch(true); };

  return (
    <>
      <PageHeader title="Schedule" />
      <div style={{ flex: 1, overflowY: "auto", padding: "20px 24px" }}>
        <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 20 }}>
          <div>
            <Card style={{ marginBottom: 16 }}>
              <ST>Google Calendar</ST>
              <div style={{ display: "flex", alignItems: "center", gap: 8 }}>
                <div style={{ width: 10, height: 10, borderRadius: "50%", background: gs?.connected ? V.green : V.red }} />
                <span style={{ fontSize: 12 }}>{gs?.connected ? "Connected" : "Not Connected"}</span>
                {!gs?.connected && <Btn variant="blue" onClick={async () => { const r = await api("gcal/connect"); if (r?.url) window.open(r.url, "_blank"); }} style={{ fontSize: 9 }}>Connect</Btn>}
              </div>
            </Card>
            {cStats && <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 12, marginBottom: 16 }}><Stat label="This Week" value={cStats.this_week || 0} /><Stat label="Completed" value={cStats.completed || 0} color={V.green} /><Stat label="No-Shows" value={cStats.no_shows || 0} color={V.red} /></div>}
            <Card style={{ marginBottom: 16, padding: 16 }}>
              <ST>Schedule a Call</ST>
              <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: "0 8px" }}>
                <Input label="Contact Name" inputRef={refs.name} /><Input label="Phone" inputRef={refs.phone} />
                <Input label="Date/Time" inputRef={refs.date} type="datetime-local" /><Input label="Notes" inputRef={refs.notes} />
              </div>
              <Btn onClick={scheduleCall}>Schedule</Btn>
            </Card>
          </div>
          <div>
            {evts.length > 0 && <><ST>Upcoming Events ({evts.length})</ST><Card style={{ padding: 0, maxHeight: 200, overflowY: "auto", marginBottom: 16 }}>{evts.map((e, i) => <div key={i} style={{ padding: "8px 12px", borderBottom: `1px solid ${V.border}`, fontSize: 11 }}><div style={{ fontWeight: 600 }}>{e.summary || e.title}</div><div style={{ color: V.muted }}>{e.start?.dateTime ? `${fd(e.start.dateTime)} ${ft(e.start.dateTime)}` : fd(e.start?.date)}</div></div>)}</Card></>}
            <ST>Scheduled Calls ({scheduled.length})</ST>
            {scheduled.length === 0 ? <Card><Empty text="No calls scheduled" /></Card> : scheduled.map((c, i) => (
              <Card key={i} style={{ marginBottom: 8, display: "flex", justifyContent: "space-between", alignItems: "center" }}>
                <div><div style={{ fontWeight: 600 }}>{c.contact_name || c.parent_name || "--"}</div><div style={{ fontSize: 11, color: V.muted }}>{c.scheduled_at || c.date} | {fp(c.contact_phone || c.phone)}</div>{c.notes && <div style={{ fontSize: 10, color: V.blue }}>{c.notes}</div>}</div>
                <div style={{ display: "flex", gap: 4 }}><Badge bg={c.status === "completed" ? V.green : c.status === "no_show" ? V.red : V.blue}>{c.status || "upcoming"}</Badge>{c.status !== "completed" && <Btn onClick={() => complete(c.id)} style={{ fontSize: 8 }}>Done</Btn>}</div>
              </Card>
            ))}
          </div>
        </div>
      </div>
    </>
  );
}

// ═══════════════════════════════════════════════
// OPENPHONE PLATFORM
// ═══════════════════════════════════════════════
function OpenPhone() {
  const [tab, setTab] = useState("stats");
  const { data: stats } = useCachedFetch("op-platform/stats", [], 30000);
  const { data: callsData } = useCachedFetch("op-platform/calls", [], 30000);
  const { data: vmData } = useCachedFetch("op-platform/voicemails", [], 30000);
  const calls = Array.isArray(callsData) ? callsData : callsData?.calls || [];
  const vms = Array.isArray(vmData) ? vmData : vmData?.voicemails || [];
  return (
    <>
      <PageHeader title="OpenPhone Platform" />
      <TabBar tabs={[{ k: "stats", l: "Stats" }, { k: "calls", l: "Calls", count: calls.length }, { k: "voicemails", l: "Voicemails", count: vms.length }]} active={tab} onChange={setTab} />
      <div style={{ flex: 1, overflowY: "auto", padding: "20px 24px" }}>
        {tab === "stats" && stats ? <div style={{ display: "grid", gridTemplateColumns: "repeat(3,1fr)", gap: 16 }}><Stat label="Conversations" value={stats.total_conversations || 0} /><Stat label="Response Rate" value={`${stats.response_rate || 0}%`} color={V.green} /><Stat label="Unique Contacts" value={stats.unique_contacts || 0} color={V.blue} /><Stat label="Sent" value={stats.messages_sent || 0} /><Stat label="Received" value={stats.messages_received || 0} /><Stat label="Calls Today" value={stats.calls_today || 0} color={V.orange} /></div>
        : tab === "calls" ? <Card style={{ padding: 0 }}>{calls.length === 0 ? <Empty text="No calls" /> : calls.map((c, i) => <div key={i} style={{ padding: "10px 16px", borderBottom: `1px solid ${V.border}`, display: "flex", justifyContent: "space-between" }}><div><div style={{ fontWeight: 600 }}>{c.contact_name || fp(c.phone || c.from)}</div><div style={{ fontSize: 11, color: V.muted }}>{c.direction} | {c.duration ? `${Math.round(c.duration / 60)}m` : "--"} | {fd(c.created_at)}</div>{c.ai_summary && <div style={{ fontSize: 11, color: V.blue, marginTop: 4 }}>AI: {c.ai_summary.slice(0, 120)}</div>}</div><Badge bg={c.status === "completed" ? V.green : c.status === "missed" ? V.red : V.muted}>{c.status || "?"}</Badge></div>)}</Card>
        : tab === "voicemails" ? <Card style={{ padding: 0 }}>{vms.length === 0 ? <Empty text="No voicemails" /> : vms.map((v, i) => <div key={i} style={{ padding: "10px 16px", borderBottom: `1px solid ${V.border}` }}><div style={{ display: "flex", justifyContent: "space-between" }}><div style={{ fontWeight: 600 }}>{v.contact_name || fp(v.phone || v.from)}</div><Badge bg={v.status === "new" ? V.red : V.green}>{v.status || "new"}</Badge></div>{v.transcript && <div style={{ fontSize: 11, color: V.muted, marginTop: 4 }}>{v.transcript.slice(0, 120)}</div>}<div style={{ fontSize: 9, color: V.muted }}>{fd(v.created_at)} {ft(v.created_at)}</div></div>)}</Card>
        : <Empty text="No data" />}
      </div>
    </>
  );
}

// ═══════════════════════════════════════════════
// ANALYTICS (spend + activity + digest)
// ═══════════════════════════════════════════════
function Analytics({ onRefresh }) {
  const { data: spendData, reload: rSpend } = useCachedFetch("desktop/spend", [], 20000);
  const { data: actData } = useCachedFetch("desktop/activity", [], 20000);
  const [digest, setDigest] = useState("");
  const spend = Array.isArray(spendData) ? spendData : [];
  const activity = Array.isArray(actData) ? actData : [];
  const refs = { date: useRef(), plat: useRef(), amt: useRef(), camp: useRef(), clicks: useRef(), leads: useRef() };

  const logSpend = async () => { const g = r => r.current?.value || ""; await api("desktop/spend", "POST", { date: g(refs.date), platform: g(refs.plat), amount: g(refs.amt), campaign: g(refs.camp), clicks: g(refs.clicks), leads: g(refs.leads) }); cacheClear("desktop/spend"); cacheClear("desktop/dashboard"); rSpend(true); onRefresh(); };
  const genDigest = async () => { setDigest("Generating..."); const r = await api("desktop/digest/preview").catch(() => null); setDigest(r?.text || "Failed"); };

  const actC = { family_created: V.purple, sms_sent: V.cyan, spend_logged: V.blue, message_received: V.blue, daily_digest_sent: V.orange, landing_lead_synced: V.green };

  return (
    <>
      <PageHeader title="Analytics"><Btn onClick={genDigest}>Generate Digest</Btn>{digest && digest !== "Generating..." && <><Btn variant="ghost" onClick={() => navigator.clipboard.writeText(digest)}>Copy</Btn><Btn variant="dark" onClick={() => api("desktop/digest/send", "POST")}>Send</Btn></>}</PageHeader>
      <div style={{ flex: 1, overflowY: "auto", padding: "20px 24px" }}>
        <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 20 }}>
          <div>
            <ST>Log Ad Spend</ST>
            <Card style={{ padding: 12 }}>
              <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: "0 8px" }}>
                <Input label="Date" inputRef={refs.date} type="date" />
                <div style={{ marginBottom: 8 }}><label style={lblStyle}>Platform</label><select ref={refs.plat} style={inputStyle}><option value="meta">Meta</option><option value="google">Google</option></select></div>
                <Input label="Amount ($)" inputRef={refs.amt} type="number" placeholder="85" /><Input label="Campaign" inputRef={refs.camp} />
                <Input label="Clicks" inputRef={refs.clicks} type="number" /><Input label="Leads" inputRef={refs.leads} type="number" />
              </div>
              <Btn onClick={logSpend} style={{ width: "100%" }}>Log Spend</Btn>
            </Card>
            <ST>History</ST>
            <Card style={{ padding: 0, maxHeight: 260, overflowY: "auto" }}>
              <table style={{ width: "100%", borderCollapse: "collapse" }}><thead><tr>{["Date", "Platform", "Amount", "Leads"].map(h => <th key={h} style={{ ...thStyle, padding: "6px 8px" }}>{h}</th>)}</tr></thead><tbody>{spend.map((s, i) => <tr key={i} style={{ borderBottom: `1px solid ${V.border}` }}><td style={{ padding: "5px 8px", fontSize: 10 }}>{fd(s.spend_date)}</td><td style={{ padding: "5px 8px", fontSize: 10, textTransform: "uppercase", color: s.platform === "meta" ? V.blue : V.green }}>{s.platform}</td><td style={{ padding: "5px 8px", ...monoStyle, fontSize: 10 }}>{fm(s.amount)}</td><td style={{ padding: "5px 8px", ...monoStyle, fontSize: 10 }}>{s.conversions || s.leads || 0}</td></tr>)}</tbody></table>
            </Card>
          </div>
          <div>
            <ST>Activity</ST>
            <Card style={{ maxHeight: 440, overflowY: "auto", padding: 12 }}>
              {activity.slice(0, 30).map((a, i) => <div key={i} style={{ display: "flex", gap: 8, padding: "5px 0", borderBottom: i < 29 ? `1px solid ${V.border}` : "none" }}><div style={{ width: 6, height: 6, borderRadius: "50%", marginTop: 5, flexShrink: 0, background: actC[a.action] || V.muted }} /><div style={{ flex: 1 }}><div style={{ fontSize: 11 }}>{a.action}{a.detail ? ` -- ${(a.detail || "").slice(0, 80)}` : ""}</div><div style={{ fontSize: 9, color: V.muted }}>{fd(a.created_at)} {ft(a.created_at)}</div></div></div>)}
              {activity.length === 0 && <Empty text="No activity" />}
            </Card>
          </div>
          <div>
            <ST>Daily Digest</ST>
            <Card style={{ maxHeight: 440, overflowY: "auto", padding: 12 }}>
              {digest ? <pre style={{ fontSize: 11, lineHeight: 1.5, whiteSpace: "pre-wrap", wordBreak: "break-word", fontFamily: "'DM Sans',sans-serif" }}>{digest}</pre> : <Empty text="Click Generate" />}
            </Card>
          </div>
        </div>
      </div>
    </>
  );
}

// ═══════════════════════════════════════════════
// SETTINGS & HEALTH
// ═══════════════════════════════════════════════
function Settings() {
  const { data: health, loading: l1, reload } = useCachedFetch("desktop/health", [], 10000);
  const { data: op } = useCachedFetch("openphone/settings", [], 60000);
  const { data: cron } = useCachedFetch("cron-status", [], 60000);
  return (
    <>
      <PageHeader title="Settings & Health"><Btn onClick={() => { cacheClear(); reload(true); }}>Run Health Check</Btn></PageHeader>
      <div style={{ flex: 1, overflowY: "auto", padding: "20px 24px", maxWidth: 800 }}>
        {l1 && !health ? <Skeleton rows={6} /> : health && (
          <Card style={{ marginBottom: 16 }}>
            <div style={{ display: "flex", alignItems: "center", gap: 10, marginBottom: 16 }}><div style={{ width: 12, height: 12, borderRadius: "50%", background: health.ok ? V.green : V.red }} /><span style={{ ...headStyle, fontSize: 16, fontWeight: 700, textTransform: "uppercase" }}>{health.ok ? "All Systems OK" : "Issues Found"}</span><span style={{ ...monoStyle, fontSize: 11, color: V.muted }}>v{health.version}</span></div>
            {health.issues?.length > 0 && health.issues.map((iss, i) => <div key={i} style={{ padding: "8px 10px", background: "#FFF3E0", border: `1px solid ${V.orange}`, marginBottom: 4, fontSize: 12, color: V.orange }}>{iss}</div>)}
            <ST>Database Tables</ST>
            <div style={{ display: "grid", gridTemplateColumns: "1fr 1fr", gap: 4, marginBottom: 16 }}>{health.tables && Object.entries(health.tables).map(([t, ok]) => <div key={t} style={{ display: "flex", alignItems: "center", gap: 6, padding: "4px 0" }}><div style={{ width: 8, height: 8, borderRadius: "50%", background: ok ? V.green : V.red }} /><span style={{ ...monoStyle, fontSize: 10 }}>{t}</span></div>)}</div>
          </Card>
        )}
        {op && <Card style={{ marginBottom: 16 }}><ST>OpenPhone / Quo</ST><div style={{ display: "flex", alignItems: "center", gap: 6 }}><div style={{ width: 8, height: 8, borderRadius: "50%", background: op.connected ? V.green : V.red }} /><span style={{ fontSize: 12 }}>API {op.connected ? "Connected" : op.has_key ? "Key Set" : "Not Configured"}</span></div>{op.account_name && <div style={{ fontSize: 11, color: V.muted, marginLeft: 14 }}>{op.account_name}</div>}</Card>}
        {cron && <Card><ST>Cron Jobs</ST>{Array.isArray(cron) ? cron.map((c, i) => <div key={i} style={{ display: "flex", justifyContent: "space-between", padding: "6px 0", borderBottom: `1px solid ${V.border}` }}><span style={{ ...monoStyle, fontSize: 10 }}>{c.hook || c.name}</span><Badge bg={c.active || c.scheduled ? V.green : V.muted}>{c.interval || c.schedule || "manual"}</Badge></div>) : <div style={{ fontSize: 11, color: V.muted }}>Cron status loaded</div>}</Card>}
      </div>
    </>
  );
}

// ═══════════════════════════════════════════════
// CONTACT PANEL (slide-out)
// ═══════════════════════════════════════════════
function ContactPanel({ familyId, onClose, onRefresh, onNav }) {
  const [fam, setFam] = useState(null);
  const noteRef = useRef(null);
  useEffect(() => { if (familyId) api(`desktop/families/${familyId}`).then(setFam).catch(() => setFam(null)); }, [familyId]);
  if (!familyId) return null;
  const st = (fam?.tags || []).find(t => STAGES.some(s => s.k === t)) || "";
  const changeStage = async v => { await api(`desktop/families/${familyId}`, "PUT", { stage: v }); cacheClear("desktop/"); onRefresh(); };
  const addNote = async () => { const n = noteRef.current?.value?.trim(); if (!n) return; noteRef.current.value = ""; await api(`desktop/families/${familyId}`, "PUT", { note: n }); setFam(await api(`desktop/families/${familyId}`)); };

  return (
    <>
      <div onClick={onClose} style={{ position: "fixed", inset: 0, background: "rgba(0,0,0,.2)", zIndex: 99 }} />
      <div style={{ position: "fixed", top: 0, right: 0, width: 480, height: "100vh", background: V.white, borderLeft: `3px solid ${V.gold}`, zIndex: 100, display: "flex", flexDirection: "column" }}>
        {!fam ? <div style={{ padding: 40 }}><Skeleton rows={6} /></div> : <>
          <div style={{ padding: "16px 20px", borderBottom: `2px solid ${V.border}`, display: "flex", justifyContent: "space-between", alignItems: "flex-start", flexShrink: 0 }}>
            <div style={{ display: "flex", gap: 14 }}>
              <div style={{ width: 48, height: 48, background: V.gold, display: "flex", alignItems: "center", justifyContent: "center", ...headStyle, fontSize: 18, fontWeight: 700, flexShrink: 0 }}>{ini(fam.display_name)}</div>
              <div><div style={{ fontSize: 18, fontWeight: 700 }}>{fam.display_name}</div><div style={{ ...monoStyle, fontSize: 11, color: V.muted }}>{fp(fam.phone)} / {fam.email || "--"}</div><div style={{ display: "flex", gap: 4, marginTop: 6, flexWrap: "wrap" }}><Badge bg={sc(st)}>{st || "--"}</Badge>{(fam.tags || []).filter(t => !STAGES.some(s => s.k === t)).map((t, i) => <span key={i} style={{ fontSize: 9, padding: "2px 6px", background: "#EDEDEB" }}>{t}</span>)}</div></div>
            </div>
            <Btn variant="danger" onClick={onClose} style={{ fontSize: 9, padding: "3px 8px" }}>Close</Btn>
          </div>
          <div style={{ flex: 1, overflowY: "auto", padding: 20 }}>
            <ST>Stage</ST>
            <select style={{ ...inputStyle, maxWidth: 200, marginBottom: 16 }} value={st} onChange={e => changeStage(e.target.value)}>{STAGES.map(s => <option key={s.k} value={s.k}>{s.k}</option>)}</select>
            {(fam.children || []).length > 0 && <><ST>Player</ST>{(fam.children || []).map((k, i) => <div key={i} style={{ marginBottom: 8 }}><div style={{ fontSize: 13, fontWeight: 600 }}>{k.first_name}{k.age ? `, ${k.age}` : ""}</div><div style={{ fontSize: 11, color: V.muted }}>{k.club || "No club"}{k.position ? ` / ${k.position}` : ""}</div></div>)}</>}
            <ST>Lifetime Value</ST>
            <div style={{ ...headStyle, fontSize: 28, fontWeight: 700, color: (fam.total_spent || 0) > 0 ? V.green : V.muted, marginBottom: 16 }}>{fm(fam.total_spent || 0)}</div>
            <ST>Notes ({(fam.notes || []).length})</ST>
            <div style={{ maxHeight: 120, overflowY: "auto", marginBottom: 6 }}>{(fam.notes || []).map((n, i) => <div key={i} style={{ fontSize: 11, padding: "4px 0", borderBottom: `1px solid ${V.border}` }}>{typeof n === "string" ? n : n.note_text || ""}</div>)}</div>
            <div style={{ display: "flex", gap: 4, marginBottom: 16 }}><input ref={noteRef} placeholder="Add note..." style={{ ...inputStyle, flex: 1, padding: "4px 6px", fontSize: 11 }} onKeyDown={e => e.key === "Enter" && addNote()} /><Btn onClick={addNote} style={{ padding: "3px 8px" }}>+</Btn></div>
            <ST>Messages</ST>
            <div style={{ maxHeight: 120, overflowY: "auto", marginBottom: 8 }}>{(fam.messages || []).slice(0, 8).map((m, i) => <div key={i} style={{ padding: "3px 0", borderBottom: `1px solid ${V.border}` }}><Badge bg={m.direction === "outgoing" ? V.gold : V.blue}>{m.direction === "outgoing" ? "OUT" : "IN"}</Badge><span style={{ fontSize: 11, marginLeft: 4 }}>{(m.body || "").slice(0, 60)}</span></div>)}</div>
            <Btn onClick={() => { onClose(); onNav("inbox"); setTimeout(() => window.__openThread?.(fam.phone), 200); }} style={{ width: "100%" }}>Open Thread</Btn>
          </div>
        </>}
      </div>
    </>
  );
}

// ═══════════════════════════════════════════════
// MAIN APP
// ═══════════════════════════════════════════════
const NAV = [
  { k: "dashboard", l: "Dashboard", i: "M3 13h8V3H3v10zm0 8h8v-6H3v6zm10 0h8V11h-8v10zm0-18v6h8V3h-8z" },
  { k: "pipeline", l: "Pipeline", i: "M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z" },
  { k: "contacts", l: "Contacts", i: "M16 11c1.66 0 2.99-1.34 2.99-3S17.66 5 16 5c-1.66 0-3 1.34-3 3s1.34 3 3 3zm-8 0c1.66 0 2.99-1.34 2.99-3S9.66 5 8 5C6.34 5 5 6.34 5 8s1.34 3 3 3z" },
  { k: "customer360", l: "Customer 360", i: "M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 3c1.66 0 3 1.34 3 3s-1.34 3-3 3-3-1.34-3-3 1.34-3 3-3z" },
  { k: "inbox", l: "Inbox", i: "M20 2H4c-1.1 0-2 .9-2 2v18l4-4h14c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z" },
  { k: "campaigns", l: "Campaigns", i: "M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z" },
  { k: "bookings", l: "Bookings", i: "M19 3h-1V1h-2v2H8V1H6v2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2z" },
  { k: "coaches", l: "Coaches", i: "M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z" },
  { k: "camps", l: "Camps", i: "M1 21h22L12 2 1 21zm12-3h-2v-2h2v2zm0-4h-2v-4h2v4z" },
  { k: "schedule", l: "Schedule", i: "M11.99 2C6.47 2 2 6.48 2 12s4.47 10 9.99 10C17.52 22 22 17.52 22 12S17.52 2 11.99 2zM12 20c-4.42 0-8-3.58-8-8s3.58-8 8-8 8 3.58 8 8-3.58 8-8 8zm.5-13H11v6l5.25 3.15.75-1.23-4.5-2.67z" },
  { k: "ai", l: "AI Engine", i: "M21 10.12h-6.78l2.74-2.82c-2.73-2.7-7.15-2.8-9.88-.1-2.73 2.71-2.73 7.08 0 9.79s7.15 2.71 9.88 0C18.32 15.65 19 14.08 19 12.1h2c0 1.98-.88 4.55-2.64 6.29-3.51 3.48-9.21 3.48-12.72 0-3.5-3.47-3.5-9.11 0-12.58 3.51-3.47 9.14-3.49 12.65 0L21 3v7.12z" },
  { k: "rules", l: "Rules", i: "M22 11V3h-7v3H9V3H2v8h7V8h2v10h4v3h7v-8h-7v3h-2V8h2v3h7z" },
  { k: "templates", l: "Templates", i: "M14 2H6c-1.1 0-2 .9-2 2v16c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8l-6-6zm2 16H8v-2h8v2zm0-4H8v-2h8v2zm-3-5V3.5L18.5 9H13z" },
  { k: "sequences", l: "Sequences", i: "M4 6H2v14c0 1.1.9 2 2 2h14v-2H4V6zm16-4H8c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V4c0-1.1-.9-2-2-2z" },
  { k: "links", l: "Training Links", i: "M3.9 12c0-1.71 1.39-3.1 3.1-3.1h4V7H7c-2.76 0-5 2.24-5 5s2.24 5 5 5h4v-1.9H7c-1.71 0-3.1-1.39-3.1-3.1zM8 13h8v-2H8v2zm9-6h-4v1.9h4c1.71 0 3.1 1.39 3.1 3.1s-1.39 3.1-3.1 3.1h-4V17h4c2.76 0 5-2.24 5-5s-2.24-5-5-5z" },
  { k: "finance", l: "Finance", i: "M5 9.2h3V19H5V9.2zM10.6 5h2.8v14h-2.8V5zm5.6 8H19v6h-2.8v-6z" },
  { k: "openphone", l: "OpenPhone", i: "M6.62 10.79c1.44 2.83 3.76 5.14 6.59 6.59l2.2-2.2c.27-.27.67-.36 1.02-.24 1.12.37 2.33.57 3.57.57.55 0 1 .45 1 1V20c0 .55-.45 1-1 1-9.39 0-17-7.61-17-17 0-.55.45-1 1-1h3.5c.55 0 1 .45 1 1 0 1.25.2 2.45.57 3.57.11.35.03.74-.25 1.02l-2.2 2.2z" },
  { k: "analytics", l: "Analytics", i: "M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zM9 17H7v-7h2v7zm4 0h-2V7h2v10zm4 0h-2v-4h2v4z" },
  { k: "settings", l: "Settings", i: "M19.14 12.94c.04-.3.06-.61.06-.94s-.02-.64-.07-.94l2.03-1.58a.49.49 0 00.12-.61l-1.92-3.32a.49.49 0 00-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54a.484.484 0 00-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.07.62-.07.94s.02.64.07.94l-2.03 1.58a.49.49 0 00-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58z" },
];

export default function App() {
  const [tab, setTab] = useState("dashboard");
  const [families, setFamilies] = useState([]);
  const [convos, setConvos] = useState([]);
  const [loading, setLoading] = useState(true);
  const [cpId, setCpId] = useState(null);
  const [unread, setUnread] = useState(0);
  const pollRef = useRef(null);

  const loadCore = useCallback(async () => {
    setLoading(true);
    const [f, c] = await Promise.all([cachedApi("desktop/families", 30000), cachedApi("desktop/conversations", 15000)]);
    setFamilies(Array.isArray(f) ? f : []); setConvos(Array.isArray(c) ? c : []);
    const d = cacheGet("desktop/dashboard") || await cachedApi("desktop/dashboard", 15000);
    setUnread(d?.unread || 0);
    setLoading(false);
  }, []);

  useEffect(() => { loadCore(); }, [loadCore]);

  // Background unread poll (10s, 30s when unfocused)
  useEffect(() => {
    const poll = () => api("desktop/poll?since_id=0").then(r => { if (r?.unread !== undefined) setUnread(r.unread); }).catch(() => {});
    pollRef.current = setInterval(poll, document.hasFocus() ? 10000 : 30000);
    const onFocus = () => { clearInterval(pollRef.current); pollRef.current = setInterval(poll, 10000); };
    const onBlur = () => { clearInterval(pollRef.current); pollRef.current = setInterval(poll, 30000); };
    window.addEventListener("focus", onFocus); window.addEventListener("blur", onBlur);
    return () => { clearInterval(pollRef.current); window.removeEventListener("focus", onFocus); window.removeEventListener("blur", onBlur); };
  }, []);

  const refresh = () => { cacheClear("desktop/"); loadCore(); };

  const renderTab = () => {
    const wrap = (C) => <ErrorBound>{C}</ErrorBound>;
    switch (tab) {
      case "dashboard": return wrap(<Dashboard onNav={setTab} onOpenContact={setCpId} />);
      case "pipeline": return wrap(<Pipeline />);
      case "contacts": return wrap(<Contacts families={families} onRefresh={refresh} onOpenContact={setCpId} />);
      case "customer360": return wrap(<Customer360 />);
      case "inbox": return wrap(<Inbox conversations={convos} onRefresh={refresh} />);
      case "campaigns": return wrap(<Campaigns />);
      case "bookings": return wrap(<Bookings />);
      case "coaches": return wrap(<Coaches />);
      case "camps": return wrap(<Camps />);
      case "schedule": return wrap(<Schedule />);
      case "ai": return wrap(<AIEngine />);
      case "rules": return wrap(<Rules />);
      case "templates": return wrap(<Templates />);
      case "sequences": return wrap(<Sequences />);
      case "links": return wrap(<TrainingLinks />);
      case "finance": return wrap(<AttribFinance />);
      case "openphone": return wrap(<OpenPhone />);
      case "analytics": return wrap(<Analytics onRefresh={refresh} />);
      case "settings": return wrap(<Settings />);
      default: return wrap(<Dashboard onNav={setTab} onOpenContact={setCpId} />);
    }
  };

  return (
    <div style={{ display: "flex", height: "100vh", fontFamily: "'DM Sans',-apple-system,sans-serif", background: V.bg, color: V.text, overflow: "hidden" }}>
      <style>{`
        @import url('https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&family=DM+Sans:ital,wght@0,400;0,500;0,600;0,700&family=IBM+Plex+Mono:wght@400;500;600&display=swap');
        *{margin:0;padding:0;box-sizing:border-box}
        ::-webkit-scrollbar{width:5px}::-webkit-scrollbar-thumb{background:#C8C7C3}
        ::selection{background:${V.gold};color:${V.black}}
        @keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
        button:active{transform:scale(.97)}button:disabled{opacity:.4;cursor:default}
      `}</style>

      <CommandPalette onNav={setTab} onOpenContact={setCpId} />

      {/* SIDEBAR */}
      <div style={{ width: 190, background: V.black, display: "flex", flexDirection: "column", flexShrink: 0 }}>
        <div style={{ padding: "16px 12px 16px", borderBottom: "1px solid rgba(255,255,255,.08)" }}>
          <div style={{ width: 32, height: 32, background: V.gold, display: "flex", alignItems: "center", justifyContent: "center", ...headStyle, fontSize: 14, fontWeight: 800, color: V.black, marginBottom: 6 }}>E</div>
          <div style={{ ...headStyle, fontSize: 13, fontWeight: 700, color: V.white, letterSpacing: 0.5 }}>PTP ENGINE</div>
          <div style={{ ...monoStyle, fontSize: 9, color: "rgba(255,255,255,.3)", marginTop: 2 }}>v3.1</div>
        </div>
        <div style={{ flex: 1, padding: "6px 0", overflowY: "auto" }}>
          {NAV.map(n => (
            <div key={n.k} onClick={() => setTab(n.k)} style={{ display: "flex", alignItems: "center", gap: 7, padding: "7px 12px", cursor: "pointer", borderLeft: `3px solid ${tab === n.k ? V.gold : "transparent"}`, background: tab === n.k ? "rgba(252,185,0,.06)" : "transparent", color: tab === n.k ? V.gold : "rgba(255,255,255,.4)", ...headStyle, fontSize: 9, fontWeight: 600, textTransform: "uppercase", letterSpacing: 1, transition: "all .1s" }}>
              <svg width="13" height="13" viewBox="0 0 24 24" fill="currentColor" style={{ opacity: tab === n.k ? 1 : 0.5, flexShrink: 0 }}><path d={n.i} /></svg>
              {n.l}
              {n.k === "inbox" && unread > 0 && <span style={{ marginLeft: "auto", ...monoStyle, fontSize: 9, fontWeight: 600, background: V.red, color: "#fff", padding: "1px 5px", minWidth: 16, textAlign: "center" }}>{unread}</span>}
            </div>
          ))}
        </div>
        <div style={{ padding: "10px 12px", borderTop: "1px solid rgba(255,255,255,.08)" }}>
          <div style={{ fontSize: 10, color: "rgba(255,255,255,.35)", display: "flex", alignItems: "center", gap: 6 }}><span style={{ width: 6, height: 6, borderRadius: "50%", background: V.green }} />{USER}</div>
          <div style={{ ...monoStyle, fontSize: 8, color: "rgba(255,255,255,.2)", marginTop: 4 }}>Cmd+K search</div>
        </div>
      </div>

      {/* MAIN */}
      <div style={{ flex: 1, display: "flex", flexDirection: "column", overflow: "hidden" }}>
        {loading && families.length === 0 ? <div style={{ flex: 1, display: "flex", alignItems: "center", justifyContent: "center" }}><Skeleton rows={4} style={{ width: 300 }} /></div> : renderTab()}
      </div>

      {cpId && <ContactPanel familyId={cpId} onClose={() => setCpId(null)} onRefresh={refresh} onNav={setTab} />}
    </div>
  );
}
