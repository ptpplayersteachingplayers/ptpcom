var PTPEngineApp = (() => {
  var __defProp = Object.defineProperty;
  var __getOwnPropDesc = Object.getOwnPropertyDescriptor;
  var __getOwnPropNames = Object.getOwnPropertyNames;
  var __hasOwnProp = Object.prototype.hasOwnProperty;
  var __defNormalProp = (obj, key, value) => key in obj ? __defProp(obj, key, { enumerable: true, configurable: true, writable: true, value }) : obj[key] = value;
  var __export = (target, all) => {
    for (var name in all)
      __defProp(target, name, { get: all[name], enumerable: true });
  };
  var __copyProps = (to, from, except, desc) => {
    if (from && typeof from === "object" || typeof from === "function") {
      for (let key of __getOwnPropNames(from))
        if (!__hasOwnProp.call(to, key) && key !== except)
          __defProp(to, key, { get: () => from[key], enumerable: !(desc = __getOwnPropDesc(from, key)) || desc.enumerable });
    }
    return to;
  };
  var __toCommonJS = (mod) => __copyProps(__defProp({}, "__esModule", { value: true }), mod);
  var __publicField = (obj, key, value) => __defNormalProp(obj, typeof key !== "symbol" ? key + "" : key, value);

  // ptp-app.jsx
  var ptp_app_exports = {};
  __export(ptp_app_exports, {
    default: () => App
  });

  // wp-react:react
  var R = window.wp?.element || window.React;
  var useState = R.useState;
  var useEffect = R.useEffect;
  var useCallback = R.useCallback;
  var useRef = R.useRef;
  var useMemo = R.useMemo;
  var useReducer = R.useReducer;
  var createContext = R.createContext;
  var useContext = R.useContext;
  var Fragment = R.Fragment;
  var createElement = R.createElement;
  var Component = R.Component;

  // wp-jsx:react/jsx-runtime
  var R2 = window.wp?.element || window.React;
  function jsx(t, p, k) {
    if (k !== void 0) p = { ...p, key: k };
    return R2.createElement(t, p);
  }
  function jsxs(t, p, k) {
    if (k !== void 0) p = { ...p, key: k };
    return R2.createElement(t, p);
  }
  var Fragment2 = R2.Fragment;

  // ptp-app.jsx
  var API_BASE = (window?.PTP_ENGINE?.api || "/wp-json/ptp-cc/v1").replace(/\/desktop\/?$/, "");
  var NONCE = window?.PTP_ENGINE?.nonce || "";
  var USER = window?.PTP_ENGINE?.user || "Luke";
  async function api(path, method = "GET", body = null) {
    const opts = { method, headers: { "Content-Type": "application/json" }, credentials: "same-origin" };
    if (NONCE) opts.headers["X-WP-Nonce"] = NONCE;
    if (body) opts.body = JSON.stringify(body);
    const r = await fetch(`${API_BASE}/${path}`, opts);
    if (!r.ok) {
      const e = await r.json().catch(() => ({}));
      throw new Error(e.message || r.statusText);
    }
    return r.json();
  }
  var _cache = {};
  function cacheGet(key) {
    const c = _cache[key];
    if (!c) return null;
    if (Date.now() > c.exp) {
      delete _cache[key];
      return null;
    }
    return c.data;
  }
  function cacheSet(key, data, ttl = 3e4) {
    _cache[key] = { data, exp: Date.now() + ttl };
  }
  function cacheClear(prefix) {
    Object.keys(_cache).forEach((k) => {
      if (!prefix || k.startsWith(prefix)) delete _cache[k];
    });
  }
  async function cachedApi(path, ttl = 3e4) {
    const cached = cacheGet(path);
    if (cached) return cached;
    const data = await api(path);
    cacheSet(path, data, ttl);
    return data;
  }
  function useDebounce(value, delay = 200) {
    const [d, setD] = useState(value);
    useEffect(() => {
      const t = setTimeout(() => setD(value), delay);
      return () => clearTimeout(t);
    }, [value, delay]);
    return d;
  }
  function useCachedFetch(path, deps = [], ttl = 3e4) {
    const [data, setData] = useState(() => cacheGet(path));
    const [loading, setLoading] = useState(!cacheGet(path));
    const [error, setError] = useState(null);
    const reload = useCallback(async (force = false) => {
      if (force) cacheClear(path);
      if (!force && cacheGet(path)) {
        setData(cacheGet(path));
        setLoading(false);
        return;
      }
      setLoading(true);
      setError(null);
      try {
        const d = await cachedApi(path, ttl);
        setData(d);
      } catch (e) {
        setError(e.message);
      }
      setLoading(false);
    }, [path, ttl]);
    useEffect(() => {
      reload();
    }, [path, ...deps]);
    return { data, loading, error, reload };
  }
  var STAGES = [
    { k: "New Lead", c: "#5C6BC0" },
    { k: "Contacted", c: "#1E88E5" },
    { k: "Camp Registered", c: "#0097A7" },
    { k: "Camp Attended", c: "#2E7D32" },
    { k: "48hr Window", c: "#FCB900" },
    { k: "Training Converted", c: "#E65100" },
    { k: "Recurring", c: "#2D8A4E" },
    { k: "VIP", c: "#C62828" }
  ];
  var sc = (k) => STAGES.find((s) => s.k === k)?.c || "#918F89";
  var fm = (n) => "$" + Number(n || 0).toLocaleString("en", { minimumFractionDigits: 0 });
  var fd = (d) => d ? new Date(d).toLocaleDateString("en", { month: "short", day: "numeric" }) : "--";
  var ft = (d) => d ? new Date(d).toLocaleTimeString("en", { hour: "numeric", minute: "2-digit" }) : "";
  var fp = (p) => {
    if (!p) return "";
    const d = p.replace(/\D/g, "").slice(-10);
    return d.length === 10 ? `(${d.slice(0, 3)}) ${d.slice(3, 6)}-${d.slice(6)}` : p;
  };
  var ini = (n) => (n || "?").split(" ").map((w) => w[0] || "").join("").slice(0, 2).toUpperCase();
  var V = { gold: "#FCB900", black: "#0A0A0A", white: "#FFF", bg: "#F5F4F0", card: "#FFF", border: "#E0DFDB", muted: "#918F89", text: "#1C1B18", light: "#FAFAF7", green: "#2D8A4E", red: "#C62828", blue: "#1565C0", purple: "#6A3EA1", orange: "#E65100", cyan: "#0097A7" };
  var ErrorBound = class extends Component {
    constructor() {
      super(...arguments);
      __publicField(this, "state", { err: null });
    }
    static getDerivedStateFromError(err) {
      return { err };
    }
    render() {
      if (this.state.err) return /* @__PURE__ */ jsxs("div", { style: { padding: 40, textAlign: "center" }, children: [
        /* @__PURE__ */ jsx("div", { style: { fontFamily: "'Oswald',sans-serif", fontSize: 14, color: V.red, marginBottom: 8 }, children: "MODULE ERROR" }),
        /* @__PURE__ */ jsx("div", { style: { fontSize: 12, color: V.muted, marginBottom: 16 }, children: this.state.err.message }),
        /* @__PURE__ */ jsx("button", { onClick: () => this.setState({ err: null }), style: { ...btnStyle("ghost"), cursor: "pointer" }, children: "Retry" })
      ] });
      return this.props.children;
    }
  };
  var btnStyle = (v = "gold") => {
    const map = { gold: { background: V.gold, color: V.black, borderColor: V.gold }, dark: { background: V.black, color: V.white, borderColor: V.black }, ghost: { background: "transparent", color: V.text, borderColor: V.border }, danger: { background: "transparent", color: V.red, borderColor: V.red }, blue: { background: V.blue, color: V.white, borderColor: V.blue } };
    return { cursor: "pointer", fontFamily: "'Oswald',sans-serif", fontSize: 10, fontWeight: 600, textTransform: "uppercase", letterSpacing: 1.2, border: "2px solid", padding: "5px 12px", ...map[v] || map.gold };
  };
  var lblStyle = { fontFamily: "'Oswald',sans-serif", fontSize: 8, textTransform: "uppercase", letterSpacing: 1.2, color: V.muted, display: "block", marginBottom: 2 };
  var secStyle = { fontFamily: "'Oswald',sans-serif", fontSize: 12, fontWeight: 600, textTransform: "uppercase", letterSpacing: 2, color: V.muted, marginBottom: 8 };
  var monoStyle = { fontFamily: "'IBM Plex Mono',monospace" };
  var headStyle = { fontFamily: "'Oswald',sans-serif" };
  var inputStyle = { fontFamily: "'DM Sans',sans-serif", fontSize: 12, padding: "6px 8px", border: `2px solid ${V.border}`, outline: "none", background: V.white, color: V.text, width: "100%" };
  var thStyle = { padding: "8px 12px", textAlign: "left", ...headStyle, fontSize: 8, textTransform: "uppercase", letterSpacing: 1.2, color: V.muted, fontWeight: 600, position: "sticky", top: 0, background: V.light, zIndex: 1 };
  var Btn = ({ children, variant = "gold", style, ...p }) => /* @__PURE__ */ jsx("button", { style: { ...btnStyle(variant), ...style }, ...p, children });
  var Badge = ({ children, bg = V.muted }) => /* @__PURE__ */ jsx("span", { style: { ...headStyle, fontSize: 9, fontWeight: 600, textTransform: "uppercase", letterSpacing: 0.8, padding: "2px 7px", color: "#fff", background: bg, display: "inline-block", whiteSpace: "nowrap" }, children });
  var Card = ({ children, style, ...p }) => /* @__PURE__ */ jsx("div", { style: { background: V.card, border: `2px solid ${V.border}`, padding: 16, ...style }, ...p, children });
  var Stat = ({ label, value, color = V.black }) => /* @__PURE__ */ jsxs("div", { style: { background: V.card, border: `2px solid ${V.border}`, padding: "14px 16px" }, children: [
    /* @__PURE__ */ jsx("div", { style: { ...headStyle, fontSize: 26, fontWeight: 700, color, lineHeight: 1 }, children: value }),
    /* @__PURE__ */ jsx("div", { style: { ...headStyle, fontSize: 8, textTransform: "uppercase", letterSpacing: 1.2, color: V.muted, marginTop: 4 }, children: label })
  ] });
  var ST = ({ children }) => /* @__PURE__ */ jsx("div", { style: secStyle, children });
  var Empty = ({ text }) => /* @__PURE__ */ jsx("div", { style: { textAlign: "center", padding: 40, color: V.muted, fontSize: 12 }, children: text });
  var Skeleton = ({ rows = 3, style }) => /* @__PURE__ */ jsx("div", { style, children: Array.from({ length: rows }).map((_, i) => /* @__PURE__ */ jsx("div", { style: { height: 18, background: `linear-gradient(90deg, ${V.border} 25%, #EEEEE8 50%, ${V.border} 75%)`, backgroundSize: "200% 100%", animation: "shimmer 1.5s infinite", marginBottom: 8, borderRadius: 2, width: `${70 + Math.random() * 30}%` } }, i)) });
  var PageHeader = ({ title, children }) => /* @__PURE__ */ jsxs("div", { style: { background: V.white, borderBottom: `2px solid ${V.border}`, padding: "14px 24px", display: "flex", justifyContent: "space-between", alignItems: "center", flexShrink: 0 }, children: [
    /* @__PURE__ */ jsx("div", { style: { ...headStyle, fontSize: 18, fontWeight: 700, textTransform: "uppercase", letterSpacing: 1 }, children: title }),
    /* @__PURE__ */ jsx("div", { style: { display: "flex", gap: 6, alignItems: "center" }, children })
  ] });
  var Input = ({ label, style, inputRef, ...p }) => /* @__PURE__ */ jsxs("div", { style: { marginBottom: 8 }, children: [
    label && /* @__PURE__ */ jsx("label", { style: lblStyle, children: label }),
    /* @__PURE__ */ jsx("input", { ref: inputRef, style: { ...inputStyle, ...style }, ...p })
  ] });
  var Select = ({ label, children, style, ...p }) => /* @__PURE__ */ jsxs("div", { style: { marginBottom: 8 }, children: [
    label && /* @__PURE__ */ jsx("label", { style: lblStyle, children: label }),
    /* @__PURE__ */ jsx("select", { style: { ...inputStyle, ...style }, ...p, children })
  ] });
  var TabBar = ({ tabs, active, onChange }) => /* @__PURE__ */ jsx("div", { style: { display: "flex", gap: 4, padding: "8px 24px", borderBottom: `1px solid ${V.border}`, background: V.white }, children: tabs.map((t) => /* @__PURE__ */ jsxs(Btn, { variant: active === t.k ? "dark" : "ghost", onClick: () => onChange(t.k), style: { fontSize: 9, padding: "4px 10px" }, children: [
    t.l,
    t.count !== void 0 ? ` (${t.count})` : ""
  ] }, t.k)) });
  function CommandPalette({ onNav, onOpenContact }) {
    const [open, setOpen] = useState(false);
    const [q, setQ] = useState("");
    const [results, setResults] = useState([]);
    const inputRef = useRef(null);
    const dq = useDebounce(q, 250);
    useEffect(() => {
      const handler = (e) => {
        if ((e.metaKey || e.ctrlKey) && e.key === "k") {
          e.preventDefault();
          setOpen((o) => !o);
        }
        if (e.key === "Escape") setOpen(false);
      };
      window.addEventListener("keydown", handler);
      return () => window.removeEventListener("keydown", handler);
    }, []);
    useEffect(() => {
      if (open) setTimeout(() => inputRef.current?.focus(), 50);
    }, [open]);
    useEffect(() => {
      if (!dq || dq.length < 2) {
        setResults([]);
        return;
      }
      api(`search?q=${encodeURIComponent(dq)}`).then((r) => setResults(r?.results || [])).catch(() => setResults([]));
    }, [dq]);
    if (!open) return null;
    const typeIcons = { application: "Pipeline", parent: "Contact", camp: "Camp", booking: "Booking" };
    return /* @__PURE__ */ jsx("div", { onClick: () => setOpen(false), style: { position: "fixed", inset: 0, background: "rgba(0,0,0,.4)", zIndex: 999, display: "flex", alignItems: "flex-start", justifyContent: "center", paddingTop: 120 }, children: /* @__PURE__ */ jsxs("div", { onClick: (e) => e.stopPropagation(), style: { width: 560, background: V.white, border: `2px solid ${V.gold}`, boxShadow: "0 20px 60px rgba(0,0,0,.2)" }, children: [
      /* @__PURE__ */ jsx("div", { style: { padding: "12px 16px", borderBottom: `2px solid ${V.border}` }, children: /* @__PURE__ */ jsx("input", { ref: inputRef, value: q, onChange: (e) => setQ(e.target.value), placeholder: "Search everything... (names, phones, emails)", style: { ...inputStyle, border: "none", fontSize: 14, padding: 0 } }) }),
      /* @__PURE__ */ jsxs("div", { style: { maxHeight: 400, overflowY: "auto" }, children: [
        results.length === 0 && dq.length >= 2 && /* @__PURE__ */ jsx("div", { style: { padding: 20, textAlign: "center", color: V.muted, fontSize: 12 }, children: "No results" }),
        results.map((r, i) => /* @__PURE__ */ jsxs("div", { onClick: () => {
          setOpen(false);
          setQ("");
          if (r.type === "parent") onOpenContact(r.id);
          else if (r.type === "application") onNav("pipeline");
          else onNav("customer360");
        }, style: { padding: "10px 16px", cursor: "pointer", borderBottom: `1px solid ${V.border}`, display: "flex", justifyContent: "space-between", alignItems: "center" }, children: [
          /* @__PURE__ */ jsxs("div", { children: [
            /* @__PURE__ */ jsx("div", { style: { fontWeight: 600, fontSize: 13 }, children: r.name }),
            /* @__PURE__ */ jsxs("div", { style: { fontSize: 11, color: V.muted }, children: [
              r.email || fp(r.phone),
              r.child_name ? ` | ${r.child_name}` : ""
            ] })
          ] }),
          /* @__PURE__ */ jsx(Badge, { bg: V.purple, children: typeIcons[r.type] || r.type })
        ] }, i))
      ] }),
      /* @__PURE__ */ jsxs("div", { style: { padding: "8px 16px", background: V.light, borderTop: `1px solid ${V.border}`, fontSize: 10, color: V.muted, display: "flex", gap: 12 }, children: [
        /* @__PURE__ */ jsxs("span", { children: [
          /* @__PURE__ */ jsx("kbd", { style: { ...monoStyle, background: V.white, padding: "1px 4px", border: `1px solid ${V.border}` }, children: "ESC" }),
          " close"
        ] }),
        /* @__PURE__ */ jsxs("span", { children: [
          /* @__PURE__ */ jsx("kbd", { style: { ...monoStyle, background: V.white, padding: "1px 4px", border: `1px solid ${V.border}` }, children: "Enter" }),
          " open"
        ] })
      ] })
    ] }) });
  }
  function Dashboard({ onNav, onOpenContact }) {
    const { data, loading } = useCachedFetch("desktop/dashboard", [], 15e3);
    if (loading && !data) return /* @__PURE__ */ jsxs(Fragment2, { children: [
      /* @__PURE__ */ jsx(PageHeader, { title: "Dashboard" }),
      /* @__PURE__ */ jsx("div", { style: { padding: 24 }, children: /* @__PURE__ */ jsx(Skeleton, { rows: 6 }) })
    ] });
    if (!data) return /* @__PURE__ */ jsxs(Fragment2, { children: [
      /* @__PURE__ */ jsx(PageHeader, { title: "Dashboard" }),
      /* @__PURE__ */ jsx(Empty, { text: "Could not load dashboard" })
    ] });
    const p = data.pipeline || {};
    return /* @__PURE__ */ jsxs(Fragment2, { children: [
      /* @__PURE__ */ jsxs(PageHeader, { title: "Dashboard", children: [
        /* @__PURE__ */ jsx("span", { style: { ...monoStyle, fontSize: 10, color: V.muted }, children: "Cmd+K to search" }),
        /* @__PURE__ */ jsx(Btn, { variant: "ghost", onClick: () => {
          cacheClear("desktop/");
          window.location.reload();
        }, children: "Refresh" })
      ] }),
      /* @__PURE__ */ jsxs("div", { style: { flex: 1, overflowY: "auto", padding: "20px 24px" }, children: [
        /* @__PURE__ */ jsxs("div", { style: { display: "grid", gridTemplateColumns: "repeat(6,1fr)", gap: 12, marginBottom: 20 }, children: [
          /* @__PURE__ */ jsx(Stat, { label: "Families", value: data.total_families }),
          /* @__PURE__ */ jsx(Stat, { label: "Revenue", value: fm(data.total_revenue), color: V.green }),
          /* @__PURE__ */ jsx(Stat, { label: "Ad Spend", value: fm(data.spend_all), color: V.blue }),
          /* @__PURE__ */ jsx(Stat, { label: "CAC", value: `$${data.cac}`, color: V.purple }),
          /* @__PURE__ */ jsx(Stat, { label: "Conv Rate", value: `${data.conv_rate}%`, color: V.gold }),
          /* @__PURE__ */ jsx(Stat, { label: "Unread", value: data.unread, color: V.orange })
        ] }),
        (data.w48_families || []).length > 0 && /* @__PURE__ */ jsxs("div", { style: { background: "#FFFDE7", border: `2px solid ${V.gold}`, padding: 12, marginBottom: 16 }, children: [
          /* @__PURE__ */ jsx("div", { style: { ...headStyle, fontSize: 10, textTransform: "uppercase", letterSpacing: 1.5, color: V.gold, marginBottom: 8, fontWeight: 700 }, children: "48HR WINDOW - FOLLOW UP NOW" }),
          data.w48_families.map((c, i) => /* @__PURE__ */ jsxs("div", { style: { display: "flex", justifyContent: "space-between", alignItems: "center", padding: "6px 0", borderBottom: i < data.w48_families.length - 1 ? "1px solid rgba(252,185,0,.2)" : "none" }, children: [
            /* @__PURE__ */ jsxs("span", { style: { fontSize: 12 }, children: [
              /* @__PURE__ */ jsx("strong", { children: c.display_name }),
              " -- ",
              fp(c.phone)
            ] }),
            /* @__PURE__ */ jsxs("div", { style: { display: "flex", gap: 4 }, children: [
              /* @__PURE__ */ jsx(Btn, { onClick: () => {
                onNav("inbox");
                setTimeout(() => window.__openThread?.(c.phone), 200);
              }, style: { fontSize: 8, padding: "2px 8px" }, children: "MSG" }),
              /* @__PURE__ */ jsx(Btn, { variant: "ghost", onClick: () => onOpenContact(c.id), style: { fontSize: 8, padding: "2px 8px" }, children: "VIEW" })
            ] })
          ] }, i))
        ] }),
        /* @__PURE__ */ jsx(ST, { children: "Pipeline" }),
        /* @__PURE__ */ jsxs(Card, { style: { padding: 14, marginBottom: 20 }, children: [
          /* @__PURE__ */ jsx("div", { style: { display: "flex", gap: 2, marginBottom: 4 }, children: STAGES.map((s) => /* @__PURE__ */ jsx("div", { style: { flex: Math.max(p[s.k] || 0, 1), height: 36, display: "flex", alignItems: "center", justifyContent: "center", background: s.c }, children: /* @__PURE__ */ jsx("span", { style: { ...headStyle, fontSize: 12, fontWeight: 700, color: "#fff" }, children: p[s.k] || 0 }) }, s.k)) }),
          /* @__PURE__ */ jsx("div", { style: { display: "flex", gap: 2 }, children: STAGES.map((s) => /* @__PURE__ */ jsx("div", { style: { flex: Math.max(p[s.k] || 0, 1), textAlign: "center", ...headStyle, fontSize: 7, textTransform: "uppercase", color: V.muted }, children: s.k }, s.k)) })
        ] }),
        /* @__PURE__ */ jsxs("div", { style: { display: "grid", gridTemplateColumns: "1fr 1fr", gap: 16 }, children: [
          /* @__PURE__ */ jsxs("div", { children: [
            /* @__PURE__ */ jsx(ST, { children: "Funnel" }),
            /* @__PURE__ */ jsx(Card, { children: [{ l: "All Contacts", v: data.total_families }, { l: "Contacted+", v: data.total_families - (p["New Lead"] || 0) }, { l: "Camp Reg+", v: (p["Camp Registered"] || 0) + (p["Camp Attended"] || 0) + (p["48hr Window"] || 0) + (p["Training Converted"] || 0) + (p["Recurring"] || 0) + (p["VIP"] || 0) }, { l: "Training+", v: (p["Training Converted"] || 0) + (p["Recurring"] || 0) + (p["VIP"] || 0) }].map((r, i) => /* @__PURE__ */ jsxs("div", { style: { marginBottom: 10 }, children: [
              /* @__PURE__ */ jsxs("div", { style: { display: "flex", justifyContent: "space-between", marginBottom: 3 }, children: [
                /* @__PURE__ */ jsx("span", { style: { fontSize: 11 }, children: r.l }),
                /* @__PURE__ */ jsx("span", { style: { ...monoStyle, fontSize: 11, fontWeight: 600 }, children: r.v })
              ] }),
              /* @__PURE__ */ jsx("div", { style: { height: 3, background: "#EEEEE8" }, children: /* @__PURE__ */ jsx("div", { style: { height: "100%", background: V.gold, width: `${r.v / (data.total_families || 1) * 100}%` } }) })
            ] }, i)) })
          ] }),
          /* @__PURE__ */ jsxs("div", { children: [
            /* @__PURE__ */ jsx(ST, { children: "Today" }),
            /* @__PURE__ */ jsx(Card, { children: /* @__PURE__ */ jsx("div", { style: { display: "grid", gridTemplateColumns: "1fr 1fr", gap: 12 }, children: [{ l: "Msgs In", v: data.msgs_today_in || 0 }, { l: "Msgs Out", v: data.msgs_today_out || 0 }, { l: "New This Week", v: data.new_7d || 0, c: V.green }, { l: "Landing Leads", v: data.landing?.total || 0, c: V.blue }].map((s, i) => /* @__PURE__ */ jsxs("div", { children: [
              /* @__PURE__ */ jsx("div", { style: { fontSize: 11, color: V.muted }, children: s.l }),
              /* @__PURE__ */ jsx("div", { style: { ...headStyle, fontSize: 20, fontWeight: 700, color: s.c || V.text }, children: s.v })
            ] }, i)) }) })
          ] })
        ] })
      ] })
    ] });
  }
  function Pipeline() {
    const [filter, setFilter] = useState("all");
    const [search, setSearch] = useState("");
    const [selected, setSelected] = useState(null);
    const dSearch = useDebounce(search, 300);
    const { data, loading, reload } = useCachedFetch(`applications?status=${filter}&search=${encodeURIComponent(dSearch)}`, [filter, dSearch], 2e4);
    const fuRef = useRef(null);
    const apps = data?.applications || [];
    const counts = data?.stage_counts || {};
    const statusC = { new: V.purple, contacted: V.blue, scheduled: V.cyan, accepted: V.green, converted: V.gold, lost: V.muted };
    const tempC = { hot: V.red, warm: V.orange, cold: V.blue };
    const update = async (id, d) => {
      await api(`applications/${id}`, "PATCH", d);
      cacheClear("applications");
      reload(true);
    };
    const followUp = async (id) => {
      const msg = fuRef.current?.value;
      if (!msg?.trim()) return;
      await api(`applications/${id}/follow-up`, "POST", { body: msg });
      fuRef.current.value = "";
    };
    return /* @__PURE__ */ jsxs(Fragment2, { children: [
      /* @__PURE__ */ jsx(PageHeader, { title: "Pipeline", children: /* @__PURE__ */ jsxs("span", { style: { ...monoStyle, fontSize: 11, color: V.muted }, children: [
        apps.length,
        " apps"
      ] }) }),
      /* @__PURE__ */ jsxs("div", { style: { flex: 1, overflowY: "auto", padding: "20px 24px" }, children: [
        /* @__PURE__ */ jsxs("div", { style: { display: "flex", gap: 4, marginBottom: 16, flexWrap: "wrap" }, children: [
          [{ k: "all", l: "All" }, { k: "new", l: "New" }, { k: "contacted", l: "Contacted" }, { k: "scheduled", l: "Scheduled" }, { k: "accepted", l: "Accepted" }, { k: "converted", l: "Converted" }, { k: "lost", l: "Lost" }].map((f) => /* @__PURE__ */ jsxs(Btn, { variant: filter === f.k ? "dark" : "ghost", onClick: () => setFilter(f.k), style: { fontSize: 9, padding: "4px 10px" }, children: [
            f.l,
            counts[f.k] !== void 0 ? ` (${counts[f.k]})` : ""
          ] }, f.k)),
          /* @__PURE__ */ jsx("div", { style: { marginLeft: "auto" }, children: /* @__PURE__ */ jsx("input", { value: search, onChange: (e) => setSearch(e.target.value), placeholder: "Search...", style: { ...inputStyle, width: 200 } }) })
        ] }),
        loading && !data ? /* @__PURE__ */ jsx(Skeleton, { rows: 8 }) : apps.length === 0 ? /* @__PURE__ */ jsx(Empty, { text: "No applications match" }) : /* @__PURE__ */ jsx(Card, { style: { padding: 0, maxHeight: "calc(100vh - 220px)", overflowY: "auto" }, children: /* @__PURE__ */ jsxs("table", { style: { width: "100%", borderCollapse: "collapse" }, children: [
          /* @__PURE__ */ jsx("thead", { children: /* @__PURE__ */ jsx("tr", { children: ["Parent", "Player", "Status", "Temp", "Days", "Follow-ups", ""].map((h) => /* @__PURE__ */ jsx("th", { style: thStyle, children: h }, h)) }) }),
          /* @__PURE__ */ jsx("tbody", { children: apps.map((a) => /* @__PURE__ */ jsxs("tr", { style: { borderBottom: `1px solid ${V.border}`, cursor: "pointer" }, onClick: () => setSelected(a), children: [
            /* @__PURE__ */ jsxs("td", { style: { padding: "7px 12px" }, children: [
              /* @__PURE__ */ jsx("span", { style: { fontWeight: 600, color: V.blue }, children: a.parent_name }),
              /* @__PURE__ */ jsx("br", {}),
              /* @__PURE__ */ jsx("span", { style: { fontSize: 10, color: V.muted }, children: a.email || fp(a.phone) })
            ] }),
            /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px", fontSize: 11 }, children: a.child_name || "--" }),
            /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px" }, children: /* @__PURE__ */ jsx(Badge, { bg: statusC[a.status] || V.muted, children: a.status }) }),
            /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px" }, children: /* @__PURE__ */ jsx(Badge, { bg: tempC[a.lead_temperature] || V.muted, children: a.lead_temperature || "--" }) }),
            /* @__PURE__ */ jsxs("td", { style: { padding: "7px 12px", ...monoStyle, fontSize: 10 }, children: [
              a.days_since_apply || 0,
              "d"
            ] }),
            /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px", ...monoStyle, fontSize: 10 }, children: a.follow_up_count || 0 }),
            /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px" }, children: /* @__PURE__ */ jsx("select", { style: { fontSize: 10, padding: "2px 4px", border: `1px solid ${V.border}` }, value: a.status, onClick: (e) => e.stopPropagation(), onChange: (e) => update(a.id, { status: e.target.value }), children: ["new", "contacted", "scheduled", "accepted", "converted", "lost"].map((s) => /* @__PURE__ */ jsx("option", { children: s }, s)) }) })
          ] }, a.id)) })
        ] }) }),
        selected && /* @__PURE__ */ jsxs("div", { style: { position: "fixed", top: 0, right: 0, width: 480, height: "100vh", background: V.white, borderLeft: `3px solid ${V.gold}`, zIndex: 200, display: "flex", flexDirection: "column", boxShadow: "-4px 0 20px rgba(0,0,0,.1)" }, children: [
          /* @__PURE__ */ jsxs("div", { style: { padding: "16px 20px", borderBottom: `2px solid ${V.border}`, display: "flex", justifyContent: "space-between" }, children: [
            /* @__PURE__ */ jsxs("div", { children: [
              /* @__PURE__ */ jsx("div", { style: { fontSize: 18, fontWeight: 700 }, children: selected.parent_name }),
              /* @__PURE__ */ jsxs("div", { style: { fontSize: 11, color: V.muted }, children: [
                fp(selected.phone),
                " | ",
                selected.email
              ] })
            ] }),
            /* @__PURE__ */ jsx(Btn, { variant: "danger", onClick: () => setSelected(null), style: { fontSize: 9, padding: "3px 8px" }, children: "Close" })
          ] }),
          /* @__PURE__ */ jsxs("div", { style: { flex: 1, overflowY: "auto", padding: 20 }, children: [
            /* @__PURE__ */ jsxs("div", { style: { display: "grid", gridTemplateColumns: "1fr 1fr", gap: 8, marginBottom: 16 }, children: [
              /* @__PURE__ */ jsx(Select, { label: "Status", value: selected.status, onChange: (e) => update(selected.id, { status: e.target.value }), children: ["new", "contacted", "scheduled", "accepted", "converted", "lost"].map((s) => /* @__PURE__ */ jsx("option", { children: s }, s)) }),
              /* @__PURE__ */ jsxs(Select, { label: "Temperature", value: selected.lead_temperature || "", onChange: (e) => update(selected.id, { lead_temperature: e.target.value }), children: [
                /* @__PURE__ */ jsx("option", { value: "", children: "--" }),
                /* @__PURE__ */ jsx("option", { children: "hot" }),
                /* @__PURE__ */ jsx("option", { children: "warm" }),
                /* @__PURE__ */ jsx("option", { children: "cold" })
              ] })
            ] }),
            /* @__PURE__ */ jsx(ST, { children: "Quick Follow-Up SMS" }),
            /* @__PURE__ */ jsxs("div", { style: { display: "flex", gap: 4 }, children: [
              /* @__PURE__ */ jsx("input", { ref: fuRef, placeholder: "Type follow-up message...", style: { ...inputStyle, flex: 1 }, onKeyDown: (e) => e.key === "Enter" && followUp(selected.id) }),
              /* @__PURE__ */ jsx(Btn, { onClick: () => followUp(selected.id), children: "Send" })
            ] })
          ] })
        ] })
      ] })
    ] });
  }
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
      if (stageFilter !== "all") f = f.filter((x) => (x.tags || []).includes(stageFilter));
      if (dSearch) {
        const q = dSearch.toLowerCase();
        f = f.filter((x) => [x.display_name, x.phone, x.email].some((v) => (v || "").toLowerCase().includes(q)));
      }
      return f;
    }, [families, dSearch, stageFilter]);
    const totalPages = Math.ceil(filtered.length / PER_PAGE);
    const visible = filtered.slice(page * PER_PAGE, (page + 1) * PER_PAGE);
    useEffect(() => {
      setPage(0);
    }, [dSearch, stageFilter]);
    const addFamily = async () => {
      const g = (r) => r.current?.value || "";
      await api("desktop/families", "POST", { name: g(refs.name), phone: g(refs.phone), email: g(refs.email), kid_name: g(refs.kid), kid_age: g(refs.age), club: g(refs.club), city: g(refs.city), state: g(refs.state), stage: g(refs.stage), source: g(refs.source), tags: g(refs.tags) });
      setShowAdd(false);
      cacheClear("desktop/");
      onRefresh();
    };
    const deleteFamily = async (id) => {
      if (!confirm("Delete?")) return;
      await api(`desktop/families/${id}`, "DELETE");
      cacheClear("desktop/");
      onRefresh();
    };
    const exportCSV = () => {
      const rows = [["Name", "Email", "Phone", "City", "State", "Stage", "LTV", "Kid", "Age", "Club"]];
      (families || []).forEach((f) => {
        const k = (f.children || [])[0];
        const st = (f.tags || []).find((t) => STAGES.some((s) => s.k === t)) || "";
        rows.push([f.display_name, f.email, f.phone, f.city, f.state, st, f.total_spent || 0, k?.first_name, k?.age, k?.club]);
      });
      const csv = rows.map((r) => r.map((c) => `"${(c + "").replace(/"/g, '""')}"`).join(",")).join("\n");
      const a = document.createElement("a");
      a.href = URL.createObjectURL(new Blob([csv], { type: "text/csv" }));
      a.download = `ptp-contacts-${(/* @__PURE__ */ new Date()).toISOString().slice(0, 10)}.csv`;
      a.click();
    };
    return /* @__PURE__ */ jsxs(Fragment2, { children: [
      /* @__PURE__ */ jsxs(PageHeader, { title: "Contacts", children: [
        /* @__PURE__ */ jsxs("span", { style: { ...monoStyle, fontSize: 11, color: V.muted }, children: [
          filtered.length,
          " of ",
          (families || []).length
        ] }),
        /* @__PURE__ */ jsx(Btn, { variant: "ghost", onClick: exportCSV, children: "Export CSV" }),
        /* @__PURE__ */ jsx(Btn, { onClick: () => setShowAdd(!showAdd), children: showAdd ? "Cancel" : "+ Add" })
      ] }),
      /* @__PURE__ */ jsxs("div", { style: { flex: 1, overflowY: "auto", padding: "0 24px" }, children: [
        showAdd && /* @__PURE__ */ jsxs(Card, { style: { margin: "16px 0", padding: 16 }, children: [
          /* @__PURE__ */ jsxs("div", { style: { display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: "0 10px" }, children: [
            /* @__PURE__ */ jsx(Input, { label: "Parent Name", inputRef: refs.name, placeholder: "Sarah Mitchell" }),
            /* @__PURE__ */ jsx(Input, { label: "Phone", inputRef: refs.phone, placeholder: "6105550142" }),
            /* @__PURE__ */ jsx(Input, { label: "Email", inputRef: refs.email, placeholder: "email@gmail.com" }),
            /* @__PURE__ */ jsx(Input, { label: "Kid Name", inputRef: refs.kid }),
            /* @__PURE__ */ jsx(Input, { label: "Kid Age", inputRef: refs.age, type: "number" }),
            /* @__PURE__ */ jsx(Input, { label: "Club", inputRef: refs.club }),
            /* @__PURE__ */ jsx(Input, { label: "City", inputRef: refs.city }),
            /* @__PURE__ */ jsxs(Select, { label: "State", inputRef: refs.state, children: [
              /* @__PURE__ */ jsx("option", { children: "PA" }),
              /* @__PURE__ */ jsx("option", { children: "NJ" }),
              /* @__PURE__ */ jsx("option", { children: "DE" }),
              /* @__PURE__ */ jsx("option", { children: "MD" }),
              /* @__PURE__ */ jsx("option", { children: "NY" })
            ] }),
            /* @__PURE__ */ jsx(Select, { label: "Stage", inputRef: refs.stage, children: STAGES.map((s) => /* @__PURE__ */ jsx("option", { value: s.k, children: s.k }, s.k)) }),
            /* @__PURE__ */ jsxs(Select, { label: "Source", inputRef: refs.source, children: [
              /* @__PURE__ */ jsx("option", { children: "manual" }),
              /* @__PURE__ */ jsx("option", { children: "landing_page" }),
              /* @__PURE__ */ jsx("option", { children: "meta_ads" }),
              /* @__PURE__ */ jsx("option", { children: "google_ads" }),
              /* @__PURE__ */ jsx("option", { children: "referral" })
            ] }),
            /* @__PURE__ */ jsx(Input, { label: "Tags", inputRef: refs.tags, placeholder: "Cherry Hill, Competitive" })
          ] }),
          /* @__PURE__ */ jsx(Btn, { onClick: addFamily, children: "Save Contact" })
        ] }),
        /* @__PURE__ */ jsxs("div", { style: { display: "flex", gap: 8, padding: "14px 0", position: "sticky", top: 0, background: V.bg, zIndex: 5, alignItems: "center" }, children: [
          /* @__PURE__ */ jsx("input", { value: search, onChange: (e) => setSearch(e.target.value), placeholder: "Search contacts...", style: { ...inputStyle, maxWidth: 300 } }),
          /* @__PURE__ */ jsxs("select", { style: { ...inputStyle, minWidth: 140 }, value: stageFilter, onChange: (e) => setStageFilter(e.target.value), children: [
            /* @__PURE__ */ jsxs("option", { value: "all", children: [
              "All (",
              (families || []).length,
              ")"
            ] }),
            STAGES.map((s) => /* @__PURE__ */ jsx("option", { value: s.k, children: s.k }, s.k))
          ] }),
          totalPages > 1 && /* @__PURE__ */ jsxs("div", { style: { marginLeft: "auto", display: "flex", gap: 4, alignItems: "center" }, children: [
            /* @__PURE__ */ jsx(Btn, { variant: "ghost", disabled: page === 0, onClick: () => setPage((p) => p - 1), style: { padding: "3px 8px" }, children: "Prev" }),
            /* @__PURE__ */ jsxs("span", { style: { ...monoStyle, fontSize: 10, color: V.muted }, children: [
              page + 1,
              "/",
              totalPages
            ] }),
            /* @__PURE__ */ jsx(Btn, { variant: "ghost", disabled: page >= totalPages - 1, onClick: () => setPage((p) => p + 1), style: { padding: "3px 8px" }, children: "Next" })
          ] })
        ] }),
        /* @__PURE__ */ jsx(Card, { style: { padding: 0 }, children: visible.length === 0 ? /* @__PURE__ */ jsx(Empty, { text: "No contacts match" }) : /* @__PURE__ */ jsxs("table", { style: { width: "100%", borderCollapse: "collapse" }, children: [
          /* @__PURE__ */ jsx("thead", { children: /* @__PURE__ */ jsx("tr", { children: ["Parent", "Player", "Stage", "LTV", "Phone", "Location", ""].map((h) => /* @__PURE__ */ jsx("th", { style: thStyle, children: h }, h)) }) }),
          /* @__PURE__ */ jsx("tbody", { children: visible.map((f) => {
            const k = (f.children || [])[0];
            const st = (f.tags || []).find((t) => STAGES.some((s) => s.k === t)) || "";
            return /* @__PURE__ */ jsxs("tr", { style: { borderBottom: `1px solid ${V.border}`, cursor: "pointer" }, onClick: () => onOpenContact(f.id), children: [
              /* @__PURE__ */ jsxs("td", { style: { padding: "7px 12px" }, children: [
                /* @__PURE__ */ jsx("span", { style: { fontWeight: 600, color: V.blue }, children: f.display_name }),
                /* @__PURE__ */ jsx("br", {}),
                /* @__PURE__ */ jsx("span", { style: { fontSize: 10, color: V.muted }, children: f.email })
              ] }),
              /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px", fontSize: 11 }, children: k ? `${k.first_name}${k.age ? `, ${k.age}` : ""}${k.club ? ` - ${k.club}` : ""}` : "--" }),
              /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px" }, children: /* @__PURE__ */ jsx(Badge, { bg: sc(st), children: st || "--" }) }),
              /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px", ...monoStyle, fontSize: 11, fontWeight: 600, color: (f.total_spent || 0) > 0 ? V.green : V.muted }, children: fm(f.total_spent) }),
              /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px", ...monoStyle, fontSize: 10 }, children: fp(f.phone) }),
              /* @__PURE__ */ jsxs("td", { style: { padding: "7px 12px", fontSize: 11, color: V.muted }, children: [
                f.city ? `${f.city}, ` : "",
                f.state || ""
              ] }),
              /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px" }, children: /* @__PURE__ */ jsx(Btn, { variant: "danger", onClick: (e) => {
                e.stopPropagation();
                deleteFamily(f.id);
              }, style: { fontSize: 7, padding: "2px 6px" }, children: "X" }) })
            ] }, f.id);
          }) })
        ] }) })
      ] })
    ] });
  }
  function Customer360() {
    const [q, setQ] = useState("");
    const [results, setResults] = useState([]);
    const [profile, setProfile] = useState(null);
    const [loading, setLoading] = useState(false);
    const dq = useDebounce(q, 300);
    useEffect(() => {
      if (dq.length >= 2) api(`customer360-search?q=${encodeURIComponent(dq)}`).then((r) => setResults(r?.results || []));
    }, [dq]);
    const load360 = async (key) => {
      setLoading(true);
      setProfile(await api(`customer360/${encodeURIComponent(key)}`).catch(() => null));
      setLoading(false);
    };
    const typeC = { pipeline: V.purple, follow_up: V.blue, training_booking: V.green, camp_booking: V.orange, sms: V.cyan };
    return /* @__PURE__ */ jsxs(Fragment2, { children: [
      /* @__PURE__ */ jsx(PageHeader, { title: "Customer 360" }),
      /* @__PURE__ */ jsx("div", { style: { flex: 1, overflowY: "auto", padding: "20px 24px" }, children: !profile ? /* @__PURE__ */ jsxs(Fragment2, { children: [
        /* @__PURE__ */ jsx("div", { style: { display: "flex", gap: 8, marginBottom: 16 }, children: /* @__PURE__ */ jsx("input", { value: q, onChange: (e) => setQ(e.target.value), placeholder: "Search by name, email, or phone...", style: { ...inputStyle, maxWidth: 400 } }) }),
        results.length > 0 && /* @__PURE__ */ jsx(Card, { style: { padding: 0 }, children: results.map((r, i) => /* @__PURE__ */ jsxs("div", { onClick: () => load360(r.lookup), style: { padding: "10px 16px", borderBottom: `1px solid ${V.border}`, cursor: "pointer", display: "flex", justifyContent: "space-between" }, children: [
          /* @__PURE__ */ jsx("span", { style: { fontWeight: 600 }, children: r.name }),
          /* @__PURE__ */ jsxs("span", { style: { fontSize: 11, color: V.muted }, children: [
            r.email || r.phone,
            " (",
            r.source,
            ")"
          ] })
        ] }, i)) })
      ] }) : loading ? /* @__PURE__ */ jsx(Skeleton, { rows: 8 }) : /* @__PURE__ */ jsxs(Fragment2, { children: [
        /* @__PURE__ */ jsx(Btn, { variant: "ghost", onClick: () => {
          setProfile(null);
          setResults([]);
          setQ("");
        }, style: { marginBottom: 12 }, children: "Back" }),
        /* @__PURE__ */ jsx(Card, { style: { marginBottom: 16 }, children: /* @__PURE__ */ jsxs("div", { style: { display: "flex", gap: 16, alignItems: "flex-start" }, children: [
          /* @__PURE__ */ jsx("div", { style: { width: 56, height: 56, background: V.gold, display: "flex", alignItems: "center", justifyContent: "center", ...headStyle, fontSize: 20, fontWeight: 700, flexShrink: 0 }, children: ini(profile.name) }),
          /* @__PURE__ */ jsxs("div", { style: { flex: 1 }, children: [
            /* @__PURE__ */ jsx("div", { style: { fontSize: 22, fontWeight: 700 }, children: profile.name }),
            /* @__PURE__ */ jsxs("div", { style: { fontSize: 12, color: V.muted }, children: [
              profile.email,
              " | ",
              fp(profile.phone)
            ] }),
            /* @__PURE__ */ jsx("div", { style: { display: "flex", gap: 4, marginTop: 6, flexWrap: "wrap" }, children: (profile.tags || []).map((t, i) => /* @__PURE__ */ jsx(Badge, { bg: V.purple, children: t }, i)) })
          ] }),
          /* @__PURE__ */ jsxs("div", { style: { textAlign: "right" }, children: [
            /* @__PURE__ */ jsx("div", { style: { ...headStyle, fontSize: 28, fontWeight: 700, color: V.green }, children: fm(profile.total_ltv || 0) }),
            /* @__PURE__ */ jsx("div", { style: { fontSize: 9, color: V.muted, textTransform: "uppercase" }, children: "LTV" })
          ] })
        ] }) }),
        (profile.players || []).length > 0 && /* @__PURE__ */ jsxs(Fragment2, { children: [
          /* @__PURE__ */ jsx(ST, { children: "Players" }),
          /* @__PURE__ */ jsx("div", { style: { display: "grid", gridTemplateColumns: "repeat(auto-fill,minmax(200px,1fr))", gap: 12, marginBottom: 16 }, children: profile.players.map((p, i) => /* @__PURE__ */ jsxs(Card, { style: { padding: 12 }, children: [
            /* @__PURE__ */ jsxs("div", { style: { fontWeight: 600 }, children: [
              p.first_name,
              " ",
              p.last_name
            ] }),
            /* @__PURE__ */ jsxs("div", { style: { fontSize: 11, color: V.muted }, children: [
              p.age ? `Age ${p.age}` : "",
              " ",
              p.position ? `/ ${p.position}` : ""
            ] }),
            p.club && /* @__PURE__ */ jsx("div", { style: { fontSize: 11, color: V.blue }, children: p.club })
          ] }, i)) })
        ] }),
        /* @__PURE__ */ jsxs(ST, { children: [
          "Timeline (",
          (profile.timeline || []).length,
          ")"
        ] }),
        /* @__PURE__ */ jsx(Card, { style: { padding: 0, maxHeight: 400, overflowY: "auto" }, children: (profile.timeline || []).sort((a, b) => new Date(b.date) - new Date(a.date)).map((t, i) => /* @__PURE__ */ jsxs("div", { style: { padding: "10px 16px", borderBottom: `1px solid ${V.border}`, display: "flex", gap: 10 }, children: [
          /* @__PURE__ */ jsx("div", { style: { width: 8, height: 8, borderRadius: "50%", marginTop: 5, flexShrink: 0, background: typeC[t.type] || V.muted } }),
          /* @__PURE__ */ jsxs("div", { style: { flex: 1 }, children: [
            /* @__PURE__ */ jsx("div", { style: { fontSize: 12, fontWeight: 600 }, children: t.title }),
            /* @__PURE__ */ jsx("div", { style: { fontSize: 11, color: V.muted }, children: t.detail })
          ] }),
          /* @__PURE__ */ jsx("div", { style: { fontSize: 10, color: V.muted, whiteSpace: "nowrap" }, children: fd(t.date) })
        ] }, i)) })
      ] }) })
    ] });
  }
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
    useEffect(() => {
      window.__openThread = (p) => openThread(p);
      return () => {
        window.__openThread = null;
      };
    }, []);
    const openThread = async (phone) => {
      setActivePhone(phone);
      setAiDraft("");
      const ms = await api(`desktop/thread/${encodeURIComponent(phone)}`).catch(() => []);
      setMessages(ms || []);
      if (ms?.length) lastIdRef.current = Math.max(...ms.map((m) => parseInt(m.id) || 0));
      setTimeout(() => {
        if (threadRef.current) threadRef.current.scrollTop = threadRef.current.scrollHeight;
      }, 80);
    };
    const sendMsg = async () => {
      if (!msg.trim() || !activePhone) return;
      const body = msg.trim();
      setMsg("");
      setMessages((prev) => [...prev, { id: "sending", direction: "outgoing", body, created_at: (/* @__PURE__ */ new Date()).toISOString() }]);
      setTimeout(() => {
        if (threadRef.current) threadRef.current.scrollTop = threadRef.current.scrollHeight;
      }, 30);
      await api("desktop/send", "POST", { phone: activePhone, body }).catch(() => null);
      cacheClear("desktop/conversations");
      onRefresh();
      const ms = await api(`desktop/thread/${encodeURIComponent(activePhone)}`).catch(() => null);
      if (ms) setMessages(ms);
    };
    const genAI = async () => {
      if (!activePhone) return;
      setAiDraft("Generating...");
      const r = await api("ai/generate-reply-for-phone", "POST", { phone: activePhone }).catch(() => null);
      setAiDraft(r?.reply || r?.draft || "Failed");
    };
    useEffect(() => {
      if (pollRef.current) clearInterval(pollRef.current);
      if (!activePhone) return;
      pollRef.current = setInterval(async () => {
        const r = await api(`desktop/poll?since_id=${lastIdRef.current}&phone=${encodeURIComponent(activePhone)}`).catch(() => null);
        if (r?.new_msgs?.length) {
          setMessages((prev) => {
            const ids = new Set(prev.map((m) => m.id));
            return [...prev.filter((m) => m.id !== "sending"), ...r.new_msgs.filter((m) => !ids.has(m.id))];
          });
          if (r.last_id > lastIdRef.current) lastIdRef.current = r.last_id;
          setTimeout(() => {
            if (threadRef.current) threadRef.current.scrollTop = threadRef.current.scrollHeight;
          }, 50);
        }
      }, 5e3);
      return () => clearInterval(pollRef.current);
    }, [activePhone]);
    const filteredConvos = useMemo(() => {
      if (!dSearch) return conversations || [];
      const q = dSearch.toLowerCase();
      return (conversations || []).filter((c) => (c.display_name || "").toLowerCase().includes(q) || (c.last_message || "").toLowerCase().includes(q));
    }, [conversations, dSearch]);
    const activeName = (conversations || []).find((c) => c.phone === activePhone)?.display_name || fp(activePhone);
    return /* @__PURE__ */ jsxs("div", { style: { display: "flex", flex: 1, overflow: "hidden" }, children: [
      /* @__PURE__ */ jsxs("div", { style: { width: 340, borderRight: `2px solid ${V.border}`, display: "flex", flexDirection: "column", background: V.white }, children: [
        /* @__PURE__ */ jsxs("div", { style: { padding: "12px 16px", borderBottom: `2px solid ${V.border}`, background: V.light }, children: [
          /* @__PURE__ */ jsx("div", { style: { ...headStyle, fontSize: 14, fontWeight: 700, textTransform: "uppercase", letterSpacing: 1, marginBottom: 8 }, children: "Messages" }),
          /* @__PURE__ */ jsx("input", { value: search, onChange: (e) => setSearch(e.target.value), placeholder: "Search...", style: { ...inputStyle, fontSize: 11 } })
        ] }),
        /* @__PURE__ */ jsx("div", { style: { flex: 1, overflowY: "auto" }, children: filteredConvos.map((c) => /* @__PURE__ */ jsxs("div", { onClick: () => openThread(c.phone), style: { padding: "12px 16px", cursor: "pointer", borderBottom: `1px solid ${V.border}`, borderLeft: `3px solid ${activePhone === c.phone ? V.gold : "transparent"}`, background: activePhone === c.phone ? "#FFFDE7" : "transparent" }, children: [
          /* @__PURE__ */ jsxs("div", { style: { fontSize: 13, fontWeight: 600, display: "flex", justifyContent: "space-between" }, children: [
            /* @__PURE__ */ jsx("span", { children: c.display_name || fp(c.phone) }),
            /* @__PURE__ */ jsxs("span", { style: { display: "flex", alignItems: "center", gap: 6 }, children: [
              c.unread > 0 && /* @__PURE__ */ jsx(Badge, { bg: V.red, children: c.unread }),
              /* @__PURE__ */ jsx("span", { style: { ...monoStyle, fontSize: 9, color: V.muted }, children: fd(c.last_ts) })
            ] })
          ] }),
          /* @__PURE__ */ jsx("div", { style: { fontSize: 11, color: V.muted, marginTop: 3, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }, children: c.last_message })
        ] }, c.phone)) })
      ] }),
      /* @__PURE__ */ jsx("div", { style: { flex: 1, display: "flex", flexDirection: "column", background: V.light }, children: !activePhone ? /* @__PURE__ */ jsx("div", { style: { flex: 1, display: "flex", alignItems: "center", justifyContent: "center", color: V.muted, ...headStyle, fontSize: 12, textTransform: "uppercase" }, children: "Select a conversation" }) : /* @__PURE__ */ jsxs(Fragment2, { children: [
        /* @__PURE__ */ jsxs("div", { style: { padding: "12px 16px", borderBottom: `2px solid ${V.border}`, background: V.white, display: "flex", justifyContent: "space-between", alignItems: "center", flexShrink: 0 }, children: [
          /* @__PURE__ */ jsxs("div", { style: { display: "flex", alignItems: "center", gap: 10 }, children: [
            /* @__PURE__ */ jsx("span", { style: { fontSize: 14, fontWeight: 600 }, children: activeName }),
            /* @__PURE__ */ jsx("span", { style: { ...monoStyle, fontSize: 10, color: V.muted }, children: fp(activePhone) })
          ] }),
          /* @__PURE__ */ jsx(Btn, { variant: "blue", onClick: genAI, style: { fontSize: 8, padding: "3px 8px" }, children: "AI Draft" })
        ] }),
        /* @__PURE__ */ jsx("div", { ref: threadRef, style: { flex: 1, overflowY: "auto", padding: 16 }, children: messages.map((m, i) => /* @__PURE__ */ jsx("div", { style: { display: "flex", marginBottom: 10, justifyContent: m.direction === "outgoing" ? "flex-end" : "flex-start" }, children: /* @__PURE__ */ jsxs("div", { style: { maxWidth: "75%", padding: "8px 12px", border: `2px solid ${m.direction === "outgoing" ? V.gold : V.border}`, background: m.direction === "outgoing" ? "#FFF8E1" : V.white, opacity: m.id === "sending" ? 0.6 : 1 }, children: [
          /* @__PURE__ */ jsx("div", { style: { fontSize: 12, lineHeight: 1.5 }, children: m.body }),
          /* @__PURE__ */ jsx("div", { style: { fontSize: 9, color: V.muted, marginTop: 3, textAlign: "right" }, children: m.id === "sending" ? "Sending..." : ft(m.created_at) })
        ] }) }, m.id || i)) }),
        aiDraft && /* @__PURE__ */ jsxs("div", { style: { padding: "8px 16px", background: "#E3F2FD", borderTop: `1px solid ${V.blue}`, fontSize: 11 }, children: [
          /* @__PURE__ */ jsx("strong", { style: { color: V.blue }, children: "AI:" }),
          " ",
          aiDraft,
          aiDraft !== "Generating..." && /* @__PURE__ */ jsx(Btn, { variant: "blue", onClick: () => {
            setMsg(aiDraft);
            setAiDraft("");
          }, style: { marginLeft: 8, fontSize: 8, padding: "2px 6px" }, children: "Use" })
        ] }),
        /* @__PURE__ */ jsxs("div", { style: { padding: "10px 16px", borderTop: `2px solid ${V.border}`, background: V.white, display: "flex", gap: 8, flexShrink: 0 }, children: [
          /* @__PURE__ */ jsx("textarea", { value: msg, onChange: (e) => setMsg(e.target.value), onKeyDown: (e) => {
            if (e.key === "Enter" && !e.shiftKey) {
              e.preventDefault();
              sendMsg();
            }
          }, placeholder: "Type a message...", rows: 2, style: { flex: 1, resize: "none", ...inputStyle, border: `2px solid ${V.border}` } }),
          /* @__PURE__ */ jsx(Btn, { onClick: sendMsg, style: { alignSelf: "flex-end", padding: "8px 16px" }, children: "Send" })
        ] })
      ] }) })
    ] });
  }
  function Coaches() {
    const { data, loading, reload } = useCachedFetch("trainers", [], 6e4);
    const [showAdd, setShowAdd] = useState(false);
    const refs = { name: useRef(), phone: useRef(), email: useRef(), rate: useRef(), loc: useRef(), bio: useRef() };
    const trainers = Array.isArray(data) ? data : data?.trainers || [];
    const add = async () => {
      const g = (r) => r.current?.value || "";
      await api("trainers/create", "POST", { display_name: g(refs.name), phone: g(refs.phone), email: g(refs.email), hourly_rate: g(refs.rate), location: g(refs.loc), bio: g(refs.bio) });
      setShowAdd(false);
      cacheClear("trainers");
      reload(true);
    };
    return /* @__PURE__ */ jsxs(Fragment2, { children: [
      /* @__PURE__ */ jsx(PageHeader, { title: "Coaches", children: /* @__PURE__ */ jsx(Btn, { onClick: () => setShowAdd(!showAdd), children: showAdd ? "Cancel" : "+ Add Coach" }) }),
      /* @__PURE__ */ jsxs("div", { style: { flex: 1, overflowY: "auto", padding: "20px 24px" }, children: [
        showAdd && /* @__PURE__ */ jsxs(Card, { style: { marginBottom: 16, padding: 16 }, children: [
          /* @__PURE__ */ jsxs("div", { style: { display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: "0 10px" }, children: [
            /* @__PURE__ */ jsx(Input, { label: "Name", inputRef: refs.name, placeholder: "Eddy Davis" }),
            /* @__PURE__ */ jsx(Input, { label: "Phone", inputRef: refs.phone }),
            /* @__PURE__ */ jsx(Input, { label: "Email", inputRef: refs.email }),
            /* @__PURE__ */ jsx(Input, { label: "Rate", inputRef: refs.rate, type: "number", placeholder: "80" }),
            /* @__PURE__ */ jsx(Input, { label: "Location", inputRef: refs.loc }),
            /* @__PURE__ */ jsx(Input, { label: "Bio", inputRef: refs.bio })
          ] }),
          /* @__PURE__ */ jsx(Btn, { onClick: add, children: "Save" })
        ] }),
        loading && !data ? /* @__PURE__ */ jsx(Skeleton, { rows: 4 }) : trainers.length === 0 ? /* @__PURE__ */ jsx(Empty, { text: "No coaches" }) : /* @__PURE__ */ jsx("div", { style: { display: "grid", gridTemplateColumns: "repeat(auto-fill,minmax(280px,1fr))", gap: 16 }, children: trainers.map((t) => /* @__PURE__ */ jsxs(Card, { style: { display: "flex", gap: 14 }, children: [
          /* @__PURE__ */ jsx("div", { style: { width: 48, height: 48, background: V.gold, display: "flex", alignItems: "center", justifyContent: "center", ...headStyle, fontWeight: 700, flexShrink: 0 }, children: ini(t.display_name) }),
          /* @__PURE__ */ jsxs("div", { style: { flex: 1 }, children: [
            /* @__PURE__ */ jsx("div", { style: { fontSize: 16, fontWeight: 700 }, children: t.display_name }),
            /* @__PURE__ */ jsx("div", { style: { fontSize: 11, color: V.muted }, children: t.bio || t.location }),
            /* @__PURE__ */ jsxs("div", { style: { fontSize: 11, marginTop: 4 }, children: [
              fp(t.phone),
              " | ",
              t.email
            ] }),
            /* @__PURE__ */ jsxs("div", { style: { ...headStyle, fontSize: 16, fontWeight: 700, color: V.green, marginTop: 4 }, children: [
              "$",
              t.hourly_rate || 0,
              "/hr"
            ] })
          ] })
        ] }, t.id)) })
      ] })
    ] });
  }
  function Bookings() {
    const { data, loading } = useCachedFetch("bookings", [], 3e4);
    const bookings = Array.isArray(data) ? data : data?.bookings || [];
    const stC = { confirmed: V.green, pending: V.orange, completed: V.blue, cancelled: V.red };
    return /* @__PURE__ */ jsxs(Fragment2, { children: [
      /* @__PURE__ */ jsx(PageHeader, { title: "Bookings" }),
      /* @__PURE__ */ jsx("div", { style: { flex: 1, overflowY: "auto", padding: "20px 24px" }, children: loading && !data ? /* @__PURE__ */ jsx(Skeleton, { rows: 6 }) : bookings.length === 0 ? /* @__PURE__ */ jsx(Empty, { text: "No bookings" }) : /* @__PURE__ */ jsx(Card, { style: { padding: 0 }, children: /* @__PURE__ */ jsxs("table", { style: { width: "100%", borderCollapse: "collapse" }, children: [
        /* @__PURE__ */ jsx("thead", { children: /* @__PURE__ */ jsx("tr", { children: ["Parent", "Player", "Coach", "Date", "Amount", "Status"].map((h) => /* @__PURE__ */ jsx("th", { style: thStyle, children: h }, h)) }) }),
        /* @__PURE__ */ jsx("tbody", { children: bookings.map((b) => /* @__PURE__ */ jsxs("tr", { style: { borderBottom: `1px solid ${V.border}` }, children: [
          /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px", fontWeight: 600 }, children: b.parent_name || b.display_name || "--" }),
          /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px", fontSize: 11 }, children: b.child_name || "--" }),
          /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px", fontSize: 11, color: V.blue }, children: b.trainer_name || "--" }),
          /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px", ...monoStyle, fontSize: 10 }, children: b.session_date || fd(b.created_at) }),
          /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px", ...monoStyle, fontSize: 11, fontWeight: 600, color: V.green }, children: fm(b.total_amount || b.amount) }),
          /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px" }, children: /* @__PURE__ */ jsx(Badge, { bg: stC[b.status] || V.muted, children: b.status || "--" }) })
        ] }, b.id)) })
      ] }) }) })
    ] });
  }
  function Camps() {
    const [tab, setTab] = useState("overview");
    const { data: stats, loading: l1 } = useCachedFetch("camps/stats", [], 6e4);
    const { data: listings } = useCachedFetch("camps/listings", [], 6e4);
    const { data: bkData } = useCachedFetch("camps/bookings", [], 3e4);
    const { data: abData } = useCachedFetch("camps/abandoned", [], 3e4);
    const { data: cuData } = useCachedFetch("camps/customers", [], 3e4);
    const list = Array.isArray(listings) ? listings : listings?.listings || [];
    const bks = Array.isArray(bkData) ? bkData : bkData?.bookings || [];
    const abs = Array.isArray(abData) ? abData : abData?.abandoned || [];
    const cus = Array.isArray(cuData) ? cuData : cuData?.customers || [];
    return /* @__PURE__ */ jsxs(Fragment2, { children: [
      /* @__PURE__ */ jsx(PageHeader, { title: "Camps" }),
      /* @__PURE__ */ jsx(TabBar, { tabs: [{ k: "overview", l: "Overview" }, { k: "listings", l: "Listings", count: list.length }, { k: "bookings", l: "Bookings", count: bks.length }, { k: "abandoned", l: "Abandoned", count: abs.length }, { k: "customers", l: "Customers", count: cus.length }], active: tab, onChange: setTab }),
      /* @__PURE__ */ jsx("div", { style: { flex: 1, overflowY: "auto", padding: "20px 24px" }, children: l1 && !stats ? /* @__PURE__ */ jsx(Skeleton, { rows: 4 }) : tab === "overview" && stats ? /* @__PURE__ */ jsxs("div", { style: { display: "grid", gridTemplateColumns: "repeat(3,1fr)", gap: 16 }, children: [
        /* @__PURE__ */ jsx(Stat, { label: "Camp Revenue", value: fm(stats.total_revenue), color: V.green }),
        /* @__PURE__ */ jsx(Stat, { label: "Total Bookings", value: stats.total_bookings }),
        /* @__PURE__ */ jsx(Stat, { label: "Unique Families", value: stats.unique_families }),
        /* @__PURE__ */ jsx(Stat, { label: "Avg Order", value: fm(stats.avg_order) }),
        /* @__PURE__ */ jsx(Stat, { label: "Abandoned", value: stats.abandoned_count, color: V.red }),
        /* @__PURE__ */ jsx(Stat, { label: "Abandoned $", value: fm(stats.abandoned_value), color: V.red })
      ] }) : tab === "listings" ? /* @__PURE__ */ jsx(Card, { style: { padding: 0 }, children: list.length === 0 ? /* @__PURE__ */ jsx(Empty, { text: "No listings" }) : list.map((l, i) => /* @__PURE__ */ jsxs("div", { style: { padding: "12px 16px", borderBottom: `1px solid ${V.border}`, display: "flex", justifyContent: "space-between" }, children: [
        /* @__PURE__ */ jsxs("div", { children: [
          /* @__PURE__ */ jsx("div", { style: { fontWeight: 600 }, children: l.title || l.post_title || `Camp #${l.id}` }),
          /* @__PURE__ */ jsxs("div", { style: { fontSize: 11, color: V.muted }, children: [
            l.start_date,
            " ",
            l.location
          ] })
        ] }),
        /* @__PURE__ */ jsx("div", { style: { ...headStyle, fontSize: 18, fontWeight: 700, color: V.green }, children: fm(l.price) })
      ] }, i)) }) : tab === "bookings" ? /* @__PURE__ */ jsx(Card, { style: { padding: 0 }, children: bks.length === 0 ? /* @__PURE__ */ jsx(Empty, { text: "No camp bookings" }) : /* @__PURE__ */ jsxs("table", { style: { width: "100%", borderCollapse: "collapse" }, children: [
        /* @__PURE__ */ jsx("thead", { children: /* @__PURE__ */ jsx("tr", { children: ["Customer", "Camp", "Amount", "Date", "Status"].map((h) => /* @__PURE__ */ jsx("th", { style: thStyle, children: h }, h)) }) }),
        /* @__PURE__ */ jsx("tbody", { children: bks.map((b, i) => /* @__PURE__ */ jsxs("tr", { style: { borderBottom: `1px solid ${V.border}` }, children: [
          /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px", fontWeight: 600 }, children: b.customer_name || b.parent_name }),
          /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px", fontSize: 11 }, children: b.camp_title || b.camp_name || "--" }),
          /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px", ...monoStyle, color: V.green }, children: fm(b.amount_paid || b.total_amount) }),
          /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px", fontSize: 10 }, children: fd(b.created_at) }),
          /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px" }, children: /* @__PURE__ */ jsx(Badge, { bg: b.status === "confirmed" ? V.green : V.orange, children: b.status }) })
        ] }, i)) })
      ] }) }) : tab === "abandoned" ? /* @__PURE__ */ jsx(Card, { style: { padding: 0 }, children: abs.length === 0 ? /* @__PURE__ */ jsx(Empty, { text: "No abandoned" }) : abs.map((a, i) => /* @__PURE__ */ jsxs("div", { style: { padding: "10px 16px", borderBottom: `1px solid ${V.border}`, display: "flex", justifyContent: "space-between" }, children: [
        /* @__PURE__ */ jsxs("div", { children: [
          /* @__PURE__ */ jsx("div", { style: { fontWeight: 600 }, children: a.customer_name || a.email }),
          /* @__PURE__ */ jsxs("div", { style: { fontSize: 11, color: V.muted }, children: [
            a.email,
            " | ",
            fd(a.created_at)
          ] })
        ] }),
        /* @__PURE__ */ jsx("div", { style: { ...headStyle, fontSize: 16, fontWeight: 700, color: V.red }, children: fm(a.cart_total || a.amount) })
      ] }, i)) }) : tab === "customers" ? /* @__PURE__ */ jsx(Card, { style: { padding: 0 }, children: cus.length === 0 ? /* @__PURE__ */ jsx(Empty, { text: "No customers" }) : cus.map((c, i) => /* @__PURE__ */ jsxs("div", { style: { padding: "10px 16px", borderBottom: `1px solid ${V.border}`, display: "flex", justifyContent: "space-between" }, children: [
        /* @__PURE__ */ jsxs("div", { children: [
          /* @__PURE__ */ jsx("div", { style: { fontWeight: 600 }, children: c.customer_name || c.name }),
          /* @__PURE__ */ jsx("div", { style: { fontSize: 11, color: V.muted }, children: c.email })
        ] }),
        /* @__PURE__ */ jsx("div", { style: { ...monoStyle, fontSize: 11, fontWeight: 600, color: V.green }, children: fm(c.total_spent || c.ltv) })
      ] }, i)) }) : null })
    ] });
  }
  function Campaigns() {
    const [tab, setTab] = useState("email");
    const { data: ec, loading: l1, reload: r1 } = useCachedFetch("desktop/email-campaigns", [], 2e4);
    const { data: sc2, loading: l2, reload: r2 } = useCachedFetch("campaigns", [], 2e4);
    const emails = Array.isArray(ec) ? ec : [];
    const sms = Array.isArray(sc2) ? sc2 : sc2?.campaigns || [];
    const stC = { draft: V.muted, sending: V.blue, paused: V.orange, completed: V.green, cancelled: V.red, sent: V.green };
    const send = async (type, id) => {
      if (!confirm("Send?")) return;
      await api(`${type === "email" ? "desktop/email-campaigns" : "campaigns"}/${id}/send`, "POST");
      cacheClear();
      r1(true);
      r2(true);
    };
    const del = async (type, id) => {
      if (!confirm("Delete?")) return;
      await api(`${type === "email" ? "desktop/email-campaigns" : "campaigns"}/${id}`, "DELETE");
      cacheClear();
      r1(true);
      r2(true);
    };
    const renderTable = (items, type) => items.length === 0 ? /* @__PURE__ */ jsx(Empty, { text: `No ${type} campaigns` }) : /* @__PURE__ */ jsx(Card, { style: { padding: 0 }, children: /* @__PURE__ */ jsxs("table", { style: { width: "100%", borderCollapse: "collapse" }, children: [
      /* @__PURE__ */ jsx("thead", { children: /* @__PURE__ */ jsx("tr", { children: ["Campaign", "Status", "Audience", "Sent", "Subject", ""].map((h) => /* @__PURE__ */ jsx("th", { style: thStyle, children: h }, h)) }) }),
      /* @__PURE__ */ jsx("tbody", { children: items.map((c) => /* @__PURE__ */ jsxs("tr", { style: { borderBottom: `1px solid ${V.border}` }, children: [
        /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px", fontWeight: 600 }, children: c.name }),
        /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px" }, children: /* @__PURE__ */ jsx(Badge, { bg: stC[c.status] || V.muted, children: c.status }) }),
        /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px", ...monoStyle, fontSize: 11 }, children: c.audience_count || c.recipient_count || 0 }),
        /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px", ...monoStyle, fontSize: 11, color: V.green }, children: c.sent_count || 0 }),
        /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px", fontSize: 11, maxWidth: 200, whiteSpace: "nowrap", overflow: "hidden", textOverflow: "ellipsis" }, children: c.subject || c.message?.slice(0, 60) || "--" }),
        /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px" }, children: /* @__PURE__ */ jsxs("div", { style: { display: "flex", gap: 4 }, children: [
          (c.status === "draft" || c.status === "paused") && /* @__PURE__ */ jsx(Btn, { variant: "dark", onClick: () => send(type, c.id), style: { fontSize: 7, padding: "2px 6px" }, children: "Send" }),
          /* @__PURE__ */ jsx(Btn, { variant: "danger", onClick: () => del(type, c.id), style: { fontSize: 7, padding: "2px 6px" }, children: "X" })
        ] }) })
      ] }, c.id)) })
    ] }) });
    return /* @__PURE__ */ jsxs(Fragment2, { children: [
      /* @__PURE__ */ jsx(PageHeader, { title: "Campaigns" }),
      /* @__PURE__ */ jsx(TabBar, { tabs: [{ k: "email", l: "Email", count: emails.length }, { k: "sms", l: "SMS", count: sms.length }], active: tab, onChange: setTab }),
      /* @__PURE__ */ jsx("div", { style: { flex: 1, overflowY: "auto", padding: "20px 24px" }, children: (l1 || l2) && !(ec || sc2) ? /* @__PURE__ */ jsx(Skeleton, { rows: 4 }) : tab === "email" ? renderTable(emails, "email") : renderTable(sms, "sms") })
    ] });
  }
  function AIEngine() {
    const { data: settings, reload: rSettings } = useCachedFetch("ai/settings", [], 6e4);
    const { data: stats } = useCachedFetch("ai/stats", [], 3e4);
    const { data: draftsData, reload: rDrafts } = useCachedFetch("drafts", [], 1e4);
    const drafts = Array.isArray(draftsData) ? draftsData : draftsData?.drafts || [];
    const handle = async (id, action) => {
      await api(`drafts/${id}/${action}`, "POST");
      cacheClear("drafts");
      rDrafts(true);
    };
    const saveSetting = async (d) => {
      await api("ai/settings", "POST", d);
      cacheClear("ai/settings");
      rSettings(true);
    };
    return /* @__PURE__ */ jsxs(Fragment2, { children: [
      /* @__PURE__ */ jsx(PageHeader, { title: "AI Engine" }),
      /* @__PURE__ */ jsx("div", { style: { flex: 1, overflowY: "auto", padding: "20px 24px" }, children: /* @__PURE__ */ jsxs("div", { style: { display: "grid", gridTemplateColumns: "1fr 1fr", gap: 20 }, children: [
        /* @__PURE__ */ jsxs("div", { children: [
          /* @__PURE__ */ jsxs(ST, { children: [
            "Draft Queue (",
            drafts.length,
            ")"
          ] }),
          drafts.length === 0 ? /* @__PURE__ */ jsx(Card, { children: /* @__PURE__ */ jsx(Empty, { text: "No pending drafts" }) }) : drafts.map((d) => /* @__PURE__ */ jsxs(Card, { style: { marginBottom: 8 }, children: [
            /* @__PURE__ */ jsxs("div", { style: { display: "flex", justifyContent: "space-between", marginBottom: 6 }, children: [
              /* @__PURE__ */ jsxs("span", { style: { ...monoStyle, fontSize: 10, color: V.muted }, children: [
                fp(d.phone),
                " | ",
                fd(d.created_at)
              ] }),
              /* @__PURE__ */ jsx(Badge, { bg: d.status === "pending" ? V.orange : V.green, children: d.status })
            ] }),
            /* @__PURE__ */ jsx("div", { style: { fontSize: 12, lineHeight: 1.5, marginBottom: 8, padding: 8, background: V.light, border: `1px solid ${V.border}` }, children: d.body }),
            d.status === "pending" && /* @__PURE__ */ jsxs("div", { style: { display: "flex", gap: 4 }, children: [
              /* @__PURE__ */ jsx(Btn, { onClick: () => handle(d.id, "approve"), children: "Approve" }),
              /* @__PURE__ */ jsx(Btn, { variant: "danger", onClick: () => handle(d.id, "reject"), children: "Reject" })
            ] })
          ] }, d.id))
        ] }),
        /* @__PURE__ */ jsxs("div", { children: [
          stats && /* @__PURE__ */ jsxs(Fragment2, { children: [
            /* @__PURE__ */ jsx(ST, { children: "Stats" }),
            /* @__PURE__ */ jsx(Card, { style: { marginBottom: 16 }, children: /* @__PURE__ */ jsx("div", { style: { display: "grid", gridTemplateColumns: "1fr 1fr", gap: 12 }, children: [{ l: "Generated", v: stats.total_generated }, { l: "Approved", v: stats.total_approved, c: V.green }, { l: "Rejected", v: stats.total_rejected, c: V.red }, { l: "Rate", v: `${stats.approval_rate || 0}%`, c: V.gold }].map((s, i) => /* @__PURE__ */ jsxs("div", { children: [
              /* @__PURE__ */ jsx("div", { style: { fontSize: 11, color: V.muted }, children: s.l }),
              /* @__PURE__ */ jsx("div", { style: { ...headStyle, fontSize: 24, fontWeight: 700, color: s.c || V.text }, children: s.v || 0 })
            ] }, i)) }) })
          ] }),
          settings && /* @__PURE__ */ jsxs(Fragment2, { children: [
            /* @__PURE__ */ jsx(ST, { children: "Settings" }),
            /* @__PURE__ */ jsxs(Card, { children: [
              [{ k: "enabled", l: "AI Engine Enabled" }, { k: "auto_draft", l: "Auto-Draft Replies" }].map((s) => /* @__PURE__ */ jsx("div", { style: { marginBottom: 12 }, children: /* @__PURE__ */ jsxs("label", { style: { display: "flex", alignItems: "center", gap: 8, cursor: "pointer" }, children: [
                /* @__PURE__ */ jsx("input", { type: "checkbox", checked: settings[s.k], onChange: (e) => saveSetting({ [s.k]: e.target.checked }) }),
                /* @__PURE__ */ jsx("span", { style: { fontSize: 12 }, children: s.l })
              ] }) }, s.k)),
              /* @__PURE__ */ jsxs(Select, { label: "Tone", value: settings.tone || "friendly", onChange: (e) => saveSetting({ tone: e.target.value }), children: [
                /* @__PURE__ */ jsx("option", { children: "friendly" }),
                /* @__PURE__ */ jsx("option", { children: "professional" }),
                /* @__PURE__ */ jsx("option", { children: "casual" }),
                /* @__PURE__ */ jsx("option", { children: "urgent" })
              ] })
            ] })
          ] })
        ] })
      ] }) })
    ] });
  }
  function Templates() {
    const { data, loading, reload } = useCachedFetch("templates", [], 6e4);
    const templates = data?.templates || [];
    const refs = { name: useRef(), cat: useRef(), body: useRef() };
    const add = async () => {
      const g = (r) => r.current?.value || "";
      if (!g(refs.name)) return;
      await api("templates", "POST", { name: g(refs.name), category: g(refs.cat), body: g(refs.body) });
      cacheClear("templates");
      reload(true);
    };
    const del = async (id) => {
      await api(`templates/${id}`, "DELETE");
      cacheClear("templates");
      reload(true);
    };
    const use = async (id) => {
      const r = await api(`templates/${id}/use`, "POST");
      if (r?.body) navigator.clipboard.writeText(r.body);
    };
    return /* @__PURE__ */ jsxs(Fragment2, { children: [
      /* @__PURE__ */ jsx(PageHeader, { title: "SMS Templates" }),
      /* @__PURE__ */ jsxs("div", { style: { flex: 1, overflowY: "auto", padding: "20px 24px" }, children: [
        /* @__PURE__ */ jsxs(Card, { style: { marginBottom: 16, padding: 16 }, children: [
          /* @__PURE__ */ jsxs("div", { style: { display: "grid", gridTemplateColumns: "1fr 1fr", gap: "0 10px" }, children: [
            /* @__PURE__ */ jsx(Input, { label: "Name", inputRef: refs.name, placeholder: "Follow-Up After Camp" }),
            /* @__PURE__ */ jsxs(Select, { label: "Category", inputRef: refs.cat, children: [
              /* @__PURE__ */ jsx("option", { children: "general" }),
              /* @__PURE__ */ jsx("option", { children: "follow_up" }),
              /* @__PURE__ */ jsx("option", { children: "pricing" }),
              /* @__PURE__ */ jsx("option", { children: "scheduling" }),
              /* @__PURE__ */ jsx("option", { children: "onboarding" })
            ] })
          ] }),
          /* @__PURE__ */ jsxs("div", { style: { marginBottom: 8 }, children: [
            /* @__PURE__ */ jsx("label", { style: lblStyle, children: "Message Body" }),
            /* @__PURE__ */ jsx("textarea", { ref: refs.body, placeholder: "Hey {name}! Thanks for coming to camp...", rows: 3, style: { ...inputStyle, resize: "vertical" } })
          ] }),
          /* @__PURE__ */ jsx(Btn, { onClick: add, children: "Save Template" })
        ] }),
        loading && !data ? /* @__PURE__ */ jsx(Skeleton, { rows: 4 }) : templates.length === 0 ? /* @__PURE__ */ jsx(Empty, { text: "No templates" }) : templates.map((t) => /* @__PURE__ */ jsx(Card, { style: { marginBottom: 8 }, children: /* @__PURE__ */ jsxs("div", { style: { display: "flex", justifyContent: "space-between", alignItems: "flex-start" }, children: [
          /* @__PURE__ */ jsxs("div", { style: { flex: 1 }, children: [
            /* @__PURE__ */ jsxs("div", { style: { fontWeight: 600, display: "flex", gap: 8, alignItems: "center" }, children: [
              t.name,
              " ",
              /* @__PURE__ */ jsx(Badge, { bg: V.purple, children: t.category }),
              " ",
              /* @__PURE__ */ jsxs("span", { style: { ...monoStyle, fontSize: 9, color: V.muted }, children: [
                "used ",
                t.use_count || 0,
                "x"
              ] })
            ] }),
            /* @__PURE__ */ jsx("div", { style: { fontSize: 12, color: V.muted, marginTop: 4, lineHeight: 1.4 }, children: t.body })
          ] }),
          /* @__PURE__ */ jsxs("div", { style: { display: "flex", gap: 4, flexShrink: 0 }, children: [
            /* @__PURE__ */ jsx(Btn, { variant: "ghost", onClick: () => use(t.id), style: { fontSize: 8 }, children: "Copy" }),
            /* @__PURE__ */ jsx(Btn, { variant: "danger", onClick: () => del(t.id), style: { fontSize: 8 }, children: "X" })
          ] })
        ] }) }, t.id))
      ] })
    ] });
  }
  function Rules() {
    const { data, loading, reload } = useCachedFetch("rules", [], 6e4);
    const rules = Array.isArray(data) ? data : data?.rules || [];
    const refs = { name: useRef(), trigger: useRef(), kw: useRef(), action: useRef(), msg: useRef() };
    const add = async () => {
      const g = (r) => r.current?.value || "";
      await api("rules", "POST", { name: g(refs.name), trigger: g(refs.trigger), keyword: g(refs.kw), action: g(refs.action), message: g(refs.msg), active: true });
      cacheClear("rules");
      reload(true);
    };
    const del = async (id) => {
      await api(`rules/${id}`, "DELETE");
      cacheClear("rules");
      reload(true);
    };
    const toggle = async (id, active) => {
      await api(`rules/${id}`, "PATCH", { active: !active });
      cacheClear("rules");
      reload(true);
    };
    return /* @__PURE__ */ jsxs(Fragment2, { children: [
      /* @__PURE__ */ jsx(PageHeader, { title: "Rules Engine" }),
      /* @__PURE__ */ jsxs("div", { style: { flex: 1, overflowY: "auto", padding: "20px 24px" }, children: [
        /* @__PURE__ */ jsxs(Card, { style: { marginBottom: 16, padding: 16 }, children: [
          /* @__PURE__ */ jsxs("div", { style: { display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: "0 10px" }, children: [
            /* @__PURE__ */ jsx(Input, { label: "Name", inputRef: refs.name, placeholder: "Pricing Inquiry" }),
            /* @__PURE__ */ jsxs(Select, { label: "Trigger", inputRef: refs.trigger, children: [
              /* @__PURE__ */ jsx("option", { value: "keyword", children: "Keyword" }),
              /* @__PURE__ */ jsx("option", { value: "intent", children: "Intent" }),
              /* @__PURE__ */ jsx("option", { value: "time", children: "Time" })
            ] }),
            /* @__PURE__ */ jsx(Input, { label: "Keywords (pipe-sep)", inputRef: refs.kw, placeholder: "price|cost" }),
            /* @__PURE__ */ jsxs(Select, { label: "Action", inputRef: refs.action, children: [
              /* @__PURE__ */ jsx("option", { value: "auto_reply", children: "Auto Reply" }),
              /* @__PURE__ */ jsx("option", { value: "tag", children: "Tag" }),
              /* @__PURE__ */ jsx("option", { value: "notify", children: "Notify" })
            ] }),
            /* @__PURE__ */ jsx("div", { style: { gridColumn: "span 2" }, children: /* @__PURE__ */ jsx(Input, { label: "Message", inputRef: refs.msg }) })
          ] }),
          /* @__PURE__ */ jsx(Btn, { onClick: add, children: "Add Rule" })
        ] }),
        loading && !data ? /* @__PURE__ */ jsx(Skeleton, { rows: 4 }) : rules.length === 0 ? /* @__PURE__ */ jsx(Empty, { text: "No rules" }) : /* @__PURE__ */ jsx(Card, { style: { padding: 0 }, children: /* @__PURE__ */ jsxs("table", { style: { width: "100%", borderCollapse: "collapse" }, children: [
          /* @__PURE__ */ jsx("thead", { children: /* @__PURE__ */ jsx("tr", { children: ["Name", "Trigger", "Keywords", "Action", "Active", ""].map((h) => /* @__PURE__ */ jsx("th", { style: thStyle, children: h }, h)) }) }),
          /* @__PURE__ */ jsx("tbody", { children: rules.map((r) => /* @__PURE__ */ jsxs("tr", { style: { borderBottom: `1px solid ${V.border}` }, children: [
            /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px", fontWeight: 600 }, children: r.name }),
            /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px" }, children: /* @__PURE__ */ jsx(Badge, { bg: V.purple, children: r.trigger }) }),
            /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px", ...monoStyle, fontSize: 10, color: V.muted }, children: r.keyword || "--" }),
            /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px" }, children: /* @__PURE__ */ jsx(Badge, { bg: V.blue, children: r.action }) }),
            /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px" }, children: /* @__PURE__ */ jsx("span", { style: { cursor: "pointer", color: r.active ? V.green : V.red, fontWeight: 600 }, onClick: () => toggle(r.id, r.active), children: r.active ? "ON" : "OFF" }) }),
            /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px" }, children: /* @__PURE__ */ jsx(Btn, { variant: "danger", onClick: () => del(r.id), style: { fontSize: 7, padding: "2px 6px" }, children: "X" }) })
          ] }, r.id)) })
        ] }) })
      ] })
    ] });
  }
  function Sequences() {
    const { data, loading } = useCachedFetch("sequences/active", [], 3e4);
    const seqs = Array.isArray(data) ? data : data?.sequences || [];
    return /* @__PURE__ */ jsxs(Fragment2, { children: [
      /* @__PURE__ */ jsx(PageHeader, { title: "Sequences" }),
      /* @__PURE__ */ jsx("div", { style: { flex: 1, overflowY: "auto", padding: "20px 24px" }, children: loading && !data ? /* @__PURE__ */ jsx(Skeleton, { rows: 3 }) : seqs.length === 0 ? /* @__PURE__ */ jsx(Empty, { text: "No active sequences" }) : seqs.map((s, i) => /* @__PURE__ */ jsxs(Card, { style: { marginBottom: 8, display: "flex", justifyContent: "space-between" }, children: [
        /* @__PURE__ */ jsxs("div", { children: [
          /* @__PURE__ */ jsx("div", { style: { fontWeight: 600 }, children: s.name || `Sequence ${s.id}` }),
          /* @__PURE__ */ jsxs("div", { style: { fontSize: 11, color: V.muted }, children: [
            "Step ",
            s.current_step || 0,
            "/",
            s.total_steps || 0
          ] })
        ] }),
        /* @__PURE__ */ jsx(Badge, { bg: s.active ? V.green : V.muted, children: s.active ? "Active" : "Paused" })
      ] }, i)) })
    ] });
  }
  function TrainingLinks() {
    const { data, loading, reload } = useCachedFetch("training-links", [], 6e4);
    const links = Array.isArray(data) ? data : data?.links || [];
    const refs = { name: useRef(), url: useRef(), tid: useRef() };
    const add = async () => {
      const g = (r) => r.current?.value || "";
      await api("training-links", "POST", { name: g(refs.name), url: g(refs.url), trainer_id: g(refs.tid) });
      cacheClear("training-links");
      reload(true);
    };
    const del = async (id) => {
      await api(`training-links/${id}`, "DELETE");
      cacheClear("training-links");
      reload(true);
    };
    return /* @__PURE__ */ jsxs(Fragment2, { children: [
      /* @__PURE__ */ jsx(PageHeader, { title: "Training Links" }),
      /* @__PURE__ */ jsxs("div", { style: { flex: 1, overflowY: "auto", padding: "20px 24px" }, children: [
        /* @__PURE__ */ jsxs(Card, { style: { marginBottom: 16, padding: 16 }, children: [
          /* @__PURE__ */ jsxs("div", { style: { display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: "0 10px" }, children: [
            /* @__PURE__ */ jsx(Input, { label: "Name", inputRef: refs.name, placeholder: "Eddy's Page" }),
            /* @__PURE__ */ jsx(Input, { label: "URL", inputRef: refs.url, placeholder: "https://ptpsummercamps.com/training/eddy" }),
            /* @__PURE__ */ jsx(Input, { label: "Trainer ID", inputRef: refs.tid, type: "number" })
          ] }),
          /* @__PURE__ */ jsx(Btn, { onClick: add, children: "Add Link" })
        ] }),
        loading && !data ? /* @__PURE__ */ jsx(Skeleton, { rows: 3 }) : links.length === 0 ? /* @__PURE__ */ jsx(Empty, { text: "No links" }) : links.map((l) => /* @__PURE__ */ jsxs(Card, { style: { marginBottom: 8, display: "flex", justifyContent: "space-between", alignItems: "center" }, children: [
          /* @__PURE__ */ jsxs("div", { children: [
            /* @__PURE__ */ jsx("div", { style: { fontWeight: 600 }, children: l.name }),
            /* @__PURE__ */ jsx("div", { style: { fontSize: 11, color: V.blue }, children: l.url })
          ] }),
          /* @__PURE__ */ jsxs("div", { style: { display: "flex", gap: 4 }, children: [
            /* @__PURE__ */ jsx(Btn, { variant: "ghost", onClick: () => navigator.clipboard.writeText(l.url), style: { fontSize: 8 }, children: "Copy" }),
            /* @__PURE__ */ jsx(Btn, { variant: "danger", onClick: () => del(l.id), style: { fontSize: 8 }, children: "X" })
          ] })
        ] }, l.id))
      ] })
    ] });
  }
  function AttribFinance() {
    const [tab, setTab] = useState("attribution");
    const { data: attr } = useCachedFetch("attribution/overview", [], 6e4);
    const { data: fin, reload: rFin } = useCachedFetch("finance/summary", [], 3e4);
    const { data: expData, reload: rExp } = useCachedFetch("finance/expenses", [], 3e4);
    const expenses = Array.isArray(expData) ? expData : expData?.expenses || [];
    const expRefs = { cat: useRef(), desc: useRef(), amt: useRef(), date: useRef(), vendor: useRef() };
    const addExpense = async () => {
      const g = (r) => r.current?.value || "";
      await api("finance/expenses", "POST", { category: g(expRefs.cat), description: g(expRefs.desc), amount: g(expRefs.amt), expense_date: g(expRefs.date) || (/* @__PURE__ */ new Date()).toISOString().slice(0, 10), vendor: g(expRefs.vendor) });
      cacheClear("finance/");
      rExp(true);
      rFin(true);
    };
    const delExpense = async (id) => {
      await api(`finance/expenses/${id}`, "DELETE");
      cacheClear("finance/");
      rExp(true);
      rFin(true);
    };
    return /* @__PURE__ */ jsxs(Fragment2, { children: [
      /* @__PURE__ */ jsx(PageHeader, { title: "Attribution & Finance" }),
      /* @__PURE__ */ jsx(TabBar, { tabs: [{ k: "attribution", l: "Attribution" }, { k: "finance", l: "Finance" }, { k: "expenses", l: "Expenses", count: expenses.length }], active: tab, onChange: setTab }),
      /* @__PURE__ */ jsx("div", { style: { flex: 1, overflowY: "auto", padding: "20px 24px" }, children: tab === "attribution" && attr ? /* @__PURE__ */ jsxs(Fragment2, { children: [
        /* @__PURE__ */ jsxs("div", { style: { display: "grid", gridTemplateColumns: "repeat(4,1fr)", gap: 16, marginBottom: 20 }, children: [
          /* @__PURE__ */ jsx(Stat, { label: "Touches", value: attr.total_touches || 0 }),
          /* @__PURE__ */ jsx(Stat, { label: "Conversions", value: attr.total_conversions || 0, color: V.green }),
          /* @__PURE__ */ jsx(Stat, { label: "CAC", value: `$${attr.overall_cac || 0}`, color: V.purple }),
          /* @__PURE__ */ jsx(Stat, { label: "Total Spend", value: fm((attr.meta_spend || 0) + (attr.google_spend || 0)), color: V.blue })
        ] }),
        /* @__PURE__ */ jsxs("div", { style: { display: "grid", gridTemplateColumns: "1fr 1fr", gap: 16 }, children: [
          /* @__PURE__ */ jsxs(Card, { children: [
            /* @__PURE__ */ jsx(ST, { children: "Meta Ads" }),
            /* @__PURE__ */ jsx("div", { style: { ...headStyle, fontSize: 28, fontWeight: 700, color: V.blue }, children: fm(attr.meta_spend) }),
            /* @__PURE__ */ jsxs("div", { style: { fontSize: 11, color: V.muted }, children: [
              attr.meta_conversions || 0,
              " conv | CPL $",
              attr.meta_conversions ? Math.round(attr.meta_spend / attr.meta_conversions) : "--"
            ] })
          ] }),
          /* @__PURE__ */ jsxs(Card, { children: [
            /* @__PURE__ */ jsx(ST, { children: "Google Ads" }),
            /* @__PURE__ */ jsx("div", { style: { ...headStyle, fontSize: 28, fontWeight: 700, color: V.green }, children: fm(attr.google_spend) }),
            /* @__PURE__ */ jsxs("div", { style: { fontSize: 11, color: V.muted }, children: [
              attr.google_conversions || 0,
              " conv | CPL $",
              attr.google_conversions ? Math.round(attr.google_spend / attr.google_conversions) : "--"
            ] })
          ] })
        ] })
      ] }) : tab === "attribution" ? /* @__PURE__ */ jsx(Empty, { text: "No attribution data" }) : tab === "finance" && fin ? /* @__PURE__ */ jsxs(Fragment2, { children: [
        /* @__PURE__ */ jsxs("div", { style: { display: "grid", gridTemplateColumns: "repeat(4,1fr)", gap: 16, marginBottom: 20 }, children: [
          /* @__PURE__ */ jsx(Stat, { label: "Revenue", value: fm(fin.total_rev), color: V.green }),
          /* @__PURE__ */ jsx(Stat, { label: "Training", value: fm(fin.training_rev), color: V.blue }),
          /* @__PURE__ */ jsx(Stat, { label: "Camps", value: fm(fin.camp_rev), color: V.orange }),
          /* @__PURE__ */ jsx(Stat, { label: "Profit", value: fm(fin.net_profit), color: fin.net_profit >= 0 ? V.green : V.red })
        ] }),
        /* @__PURE__ */ jsxs(ST, { children: [
          "Monthly (",
          fin.year,
          ")"
        ] }),
        /* @__PURE__ */ jsx(Card, { style: { padding: 0 }, children: /* @__PURE__ */ jsxs("table", { style: { width: "100%", borderCollapse: "collapse" }, children: [
          /* @__PURE__ */ jsx("thead", { children: /* @__PURE__ */ jsx("tr", { children: ["Month", "Training", "Camps", "Revenue", "Expenses", "Profit"].map((h) => /* @__PURE__ */ jsx("th", { style: thStyle, children: h }, h)) }) }),
          /* @__PURE__ */ jsx("tbody", { children: (fin.months || []).map((m) => /* @__PURE__ */ jsxs("tr", { style: { borderBottom: `1px solid ${V.border}` }, children: [
            /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px", fontWeight: 600 }, children: m.label }),
            /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px", ...monoStyle, fontSize: 10, color: V.blue }, children: fm(m.training) }),
            /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px", ...monoStyle, fontSize: 10, color: V.orange }, children: fm(m.camps) }),
            /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px", ...monoStyle, fontSize: 10, color: V.green, fontWeight: 600 }, children: fm(m.revenue) }),
            /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px", ...monoStyle, fontSize: 10, color: V.red }, children: fm(m.expenses) }),
            /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px", ...monoStyle, fontSize: 10, fontWeight: 700, color: m.profit >= 0 ? V.green : V.red }, children: fm(m.profit) })
          ] }, m.month)) })
        ] }) })
      ] }) : tab === "finance" ? /* @__PURE__ */ jsx(Empty, { text: "No finance data" }) : tab === "expenses" ? /* @__PURE__ */ jsxs(Fragment2, { children: [
        /* @__PURE__ */ jsxs(Card, { style: { marginBottom: 16, padding: 16 }, children: [
          /* @__PURE__ */ jsx(ST, { children: "Add Expense" }),
          /* @__PURE__ */ jsxs("div", { style: { display: "grid", gridTemplateColumns: "1fr 1fr 1fr 1fr 1fr", gap: "0 8px" }, children: [
            /* @__PURE__ */ jsxs(Select, { label: "Category", inputRef: expRefs.cat, children: [
              /* @__PURE__ */ jsx("option", { children: "marketing" }),
              /* @__PURE__ */ jsx("option", { children: "software" }),
              /* @__PURE__ */ jsx("option", { children: "equipment" }),
              /* @__PURE__ */ jsx("option", { children: "facility" }),
              /* @__PURE__ */ jsx("option", { children: "staff" }),
              /* @__PURE__ */ jsx("option", { children: "insurance" }),
              /* @__PURE__ */ jsx("option", { children: "other" })
            ] }),
            /* @__PURE__ */ jsx(Input, { label: "Description", inputRef: expRefs.desc, placeholder: "Meta ads March" }),
            /* @__PURE__ */ jsx(Input, { label: "Amount", inputRef: expRefs.amt, type: "number", placeholder: "500" }),
            /* @__PURE__ */ jsx(Input, { label: "Date", inputRef: expRefs.date, type: "date" }),
            /* @__PURE__ */ jsx(Input, { label: "Vendor", inputRef: expRefs.vendor, placeholder: "Meta" })
          ] }),
          /* @__PURE__ */ jsx(Btn, { onClick: addExpense, children: "Log Expense" })
        ] }),
        /* @__PURE__ */ jsx(Card, { style: { padding: 0, maxHeight: 400, overflowY: "auto" }, children: expenses.length === 0 ? /* @__PURE__ */ jsx(Empty, { text: "No expenses" }) : /* @__PURE__ */ jsxs("table", { style: { width: "100%", borderCollapse: "collapse" }, children: [
          /* @__PURE__ */ jsx("thead", { children: /* @__PURE__ */ jsx("tr", { children: ["Date", "Category", "Description", "Vendor", "Amount", ""].map((h) => /* @__PURE__ */ jsx("th", { style: thStyle, children: h }, h)) }) }),
          /* @__PURE__ */ jsx("tbody", { children: expenses.map((e) => /* @__PURE__ */ jsxs("tr", { style: { borderBottom: `1px solid ${V.border}` }, children: [
            /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px", fontSize: 10 }, children: fd(e.expense_date) }),
            /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px" }, children: /* @__PURE__ */ jsx(Badge, { bg: V.purple, children: e.category }) }),
            /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px", fontSize: 11 }, children: e.description }),
            /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px", fontSize: 11, color: V.muted }, children: e.vendor }),
            /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px", ...monoStyle, fontWeight: 600, color: V.red }, children: fm(e.amount) }),
            /* @__PURE__ */ jsx("td", { style: { padding: "7px 12px" }, children: /* @__PURE__ */ jsx(Btn, { variant: "danger", onClick: () => delExpense(e.id), style: { fontSize: 7, padding: "2px 6px" }, children: "X" }) })
          ] }, e.id)) })
        ] }) })
      ] }) : null })
    ] });
  }
  function Schedule() {
    const { data: gs } = useCachedFetch("gcal/status", [], 6e4);
    const { data: events } = useCachedFetch("gcal/events", [], 3e4);
    const { data: schData, reload: rSch } = useCachedFetch("calls/scheduled", [], 2e4);
    const { data: cStats } = useCachedFetch("calls/stats", [], 3e4);
    const scheduled = Array.isArray(schData) ? schData : schData?.calls || [];
    const evts = events?.events || [];
    const refs = { name: useRef(), phone: useRef(), date: useRef(), notes: useRef() };
    const scheduleCall = async () => {
      const g = (r) => r.current?.value || "";
      await api("calls/schedule", "POST", { contact_name: g(refs.name), contact_phone: g(refs.phone), scheduled_at: g(refs.date), notes: g(refs.notes) });
      cacheClear("calls/");
      rSch(true);
    };
    const complete = async (id) => {
      await api(`calls/complete/${id}`, "POST");
      cacheClear("calls/");
      rSch(true);
    };
    return /* @__PURE__ */ jsxs(Fragment2, { children: [
      /* @__PURE__ */ jsx(PageHeader, { title: "Schedule" }),
      /* @__PURE__ */ jsx("div", { style: { flex: 1, overflowY: "auto", padding: "20px 24px" }, children: /* @__PURE__ */ jsxs("div", { style: { display: "grid", gridTemplateColumns: "1fr 1fr", gap: 20 }, children: [
        /* @__PURE__ */ jsxs("div", { children: [
          /* @__PURE__ */ jsxs(Card, { style: { marginBottom: 16 }, children: [
            /* @__PURE__ */ jsx(ST, { children: "Google Calendar" }),
            /* @__PURE__ */ jsxs("div", { style: { display: "flex", alignItems: "center", gap: 8 }, children: [
              /* @__PURE__ */ jsx("div", { style: { width: 10, height: 10, borderRadius: "50%", background: gs?.connected ? V.green : V.red } }),
              /* @__PURE__ */ jsx("span", { style: { fontSize: 12 }, children: gs?.connected ? "Connected" : "Not Connected" }),
              !gs?.connected && /* @__PURE__ */ jsx(Btn, { variant: "blue", onClick: async () => {
                const r = await api("gcal/connect");
                if (r?.url) window.open(r.url, "_blank");
              }, style: { fontSize: 9 }, children: "Connect" })
            ] })
          ] }),
          cStats && /* @__PURE__ */ jsxs("div", { style: { display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 12, marginBottom: 16 }, children: [
            /* @__PURE__ */ jsx(Stat, { label: "This Week", value: cStats.this_week || 0 }),
            /* @__PURE__ */ jsx(Stat, { label: "Completed", value: cStats.completed || 0, color: V.green }),
            /* @__PURE__ */ jsx(Stat, { label: "No-Shows", value: cStats.no_shows || 0, color: V.red })
          ] }),
          /* @__PURE__ */ jsxs(Card, { style: { marginBottom: 16, padding: 16 }, children: [
            /* @__PURE__ */ jsx(ST, { children: "Schedule a Call" }),
            /* @__PURE__ */ jsxs("div", { style: { display: "grid", gridTemplateColumns: "1fr 1fr", gap: "0 8px" }, children: [
              /* @__PURE__ */ jsx(Input, { label: "Contact Name", inputRef: refs.name }),
              /* @__PURE__ */ jsx(Input, { label: "Phone", inputRef: refs.phone }),
              /* @__PURE__ */ jsx(Input, { label: "Date/Time", inputRef: refs.date, type: "datetime-local" }),
              /* @__PURE__ */ jsx(Input, { label: "Notes", inputRef: refs.notes })
            ] }),
            /* @__PURE__ */ jsx(Btn, { onClick: scheduleCall, children: "Schedule" })
          ] })
        ] }),
        /* @__PURE__ */ jsxs("div", { children: [
          evts.length > 0 && /* @__PURE__ */ jsxs(Fragment2, { children: [
            /* @__PURE__ */ jsxs(ST, { children: [
              "Upcoming Events (",
              evts.length,
              ")"
            ] }),
            /* @__PURE__ */ jsx(Card, { style: { padding: 0, maxHeight: 200, overflowY: "auto", marginBottom: 16 }, children: evts.map((e, i) => /* @__PURE__ */ jsxs("div", { style: { padding: "8px 12px", borderBottom: `1px solid ${V.border}`, fontSize: 11 }, children: [
              /* @__PURE__ */ jsx("div", { style: { fontWeight: 600 }, children: e.summary || e.title }),
              /* @__PURE__ */ jsx("div", { style: { color: V.muted }, children: e.start?.dateTime ? `${fd(e.start.dateTime)} ${ft(e.start.dateTime)}` : fd(e.start?.date) })
            ] }, i)) })
          ] }),
          /* @__PURE__ */ jsxs(ST, { children: [
            "Scheduled Calls (",
            scheduled.length,
            ")"
          ] }),
          scheduled.length === 0 ? /* @__PURE__ */ jsx(Card, { children: /* @__PURE__ */ jsx(Empty, { text: "No calls scheduled" }) }) : scheduled.map((c, i) => /* @__PURE__ */ jsxs(Card, { style: { marginBottom: 8, display: "flex", justifyContent: "space-between", alignItems: "center" }, children: [
            /* @__PURE__ */ jsxs("div", { children: [
              /* @__PURE__ */ jsx("div", { style: { fontWeight: 600 }, children: c.contact_name || c.parent_name || "--" }),
              /* @__PURE__ */ jsxs("div", { style: { fontSize: 11, color: V.muted }, children: [
                c.scheduled_at || c.date,
                " | ",
                fp(c.contact_phone || c.phone)
              ] }),
              c.notes && /* @__PURE__ */ jsx("div", { style: { fontSize: 10, color: V.blue }, children: c.notes })
            ] }),
            /* @__PURE__ */ jsxs("div", { style: { display: "flex", gap: 4 }, children: [
              /* @__PURE__ */ jsx(Badge, { bg: c.status === "completed" ? V.green : c.status === "no_show" ? V.red : V.blue, children: c.status || "upcoming" }),
              c.status !== "completed" && /* @__PURE__ */ jsx(Btn, { onClick: () => complete(c.id), style: { fontSize: 8 }, children: "Done" })
            ] })
          ] }, i))
        ] })
      ] }) })
    ] });
  }
  function OpenPhone() {
    const [tab, setTab] = useState("stats");
    const { data: stats } = useCachedFetch("op-platform/stats", [], 3e4);
    const { data: callsData } = useCachedFetch("op-platform/calls", [], 3e4);
    const { data: vmData } = useCachedFetch("op-platform/voicemails", [], 3e4);
    const calls = Array.isArray(callsData) ? callsData : callsData?.calls || [];
    const vms = Array.isArray(vmData) ? vmData : vmData?.voicemails || [];
    return /* @__PURE__ */ jsxs(Fragment2, { children: [
      /* @__PURE__ */ jsx(PageHeader, { title: "OpenPhone Platform" }),
      /* @__PURE__ */ jsx(TabBar, { tabs: [{ k: "stats", l: "Stats" }, { k: "calls", l: "Calls", count: calls.length }, { k: "voicemails", l: "Voicemails", count: vms.length }], active: tab, onChange: setTab }),
      /* @__PURE__ */ jsx("div", { style: { flex: 1, overflowY: "auto", padding: "20px 24px" }, children: tab === "stats" && stats ? /* @__PURE__ */ jsxs("div", { style: { display: "grid", gridTemplateColumns: "repeat(3,1fr)", gap: 16 }, children: [
        /* @__PURE__ */ jsx(Stat, { label: "Conversations", value: stats.total_conversations || 0 }),
        /* @__PURE__ */ jsx(Stat, { label: "Response Rate", value: `${stats.response_rate || 0}%`, color: V.green }),
        /* @__PURE__ */ jsx(Stat, { label: "Unique Contacts", value: stats.unique_contacts || 0, color: V.blue }),
        /* @__PURE__ */ jsx(Stat, { label: "Sent", value: stats.messages_sent || 0 }),
        /* @__PURE__ */ jsx(Stat, { label: "Received", value: stats.messages_received || 0 }),
        /* @__PURE__ */ jsx(Stat, { label: "Calls Today", value: stats.calls_today || 0, color: V.orange })
      ] }) : tab === "calls" ? /* @__PURE__ */ jsx(Card, { style: { padding: 0 }, children: calls.length === 0 ? /* @__PURE__ */ jsx(Empty, { text: "No calls" }) : calls.map((c, i) => /* @__PURE__ */ jsxs("div", { style: { padding: "10px 16px", borderBottom: `1px solid ${V.border}`, display: "flex", justifyContent: "space-between" }, children: [
        /* @__PURE__ */ jsxs("div", { children: [
          /* @__PURE__ */ jsx("div", { style: { fontWeight: 600 }, children: c.contact_name || fp(c.phone || c.from) }),
          /* @__PURE__ */ jsxs("div", { style: { fontSize: 11, color: V.muted }, children: [
            c.direction,
            " | ",
            c.duration ? `${Math.round(c.duration / 60)}m` : "--",
            " | ",
            fd(c.created_at)
          ] }),
          c.ai_summary && /* @__PURE__ */ jsxs("div", { style: { fontSize: 11, color: V.blue, marginTop: 4 }, children: [
            "AI: ",
            c.ai_summary.slice(0, 120)
          ] })
        ] }),
        /* @__PURE__ */ jsx(Badge, { bg: c.status === "completed" ? V.green : c.status === "missed" ? V.red : V.muted, children: c.status || "?" })
      ] }, i)) }) : tab === "voicemails" ? /* @__PURE__ */ jsx(Card, { style: { padding: 0 }, children: vms.length === 0 ? /* @__PURE__ */ jsx(Empty, { text: "No voicemails" }) : vms.map((v, i) => /* @__PURE__ */ jsxs("div", { style: { padding: "10px 16px", borderBottom: `1px solid ${V.border}` }, children: [
        /* @__PURE__ */ jsxs("div", { style: { display: "flex", justifyContent: "space-between" }, children: [
          /* @__PURE__ */ jsx("div", { style: { fontWeight: 600 }, children: v.contact_name || fp(v.phone || v.from) }),
          /* @__PURE__ */ jsx(Badge, { bg: v.status === "new" ? V.red : V.green, children: v.status || "new" })
        ] }),
        v.transcript && /* @__PURE__ */ jsx("div", { style: { fontSize: 11, color: V.muted, marginTop: 4 }, children: v.transcript.slice(0, 120) }),
        /* @__PURE__ */ jsxs("div", { style: { fontSize: 9, color: V.muted }, children: [
          fd(v.created_at),
          " ",
          ft(v.created_at)
        ] })
      ] }, i)) }) : /* @__PURE__ */ jsx(Empty, { text: "No data" }) })
    ] });
  }
  function Analytics({ onRefresh }) {
    const { data: spendData, reload: rSpend } = useCachedFetch("desktop/spend", [], 2e4);
    const { data: actData } = useCachedFetch("desktop/activity", [], 2e4);
    const [digest, setDigest] = useState("");
    const spend = Array.isArray(spendData) ? spendData : [];
    const activity = Array.isArray(actData) ? actData : [];
    const refs = { date: useRef(), plat: useRef(), amt: useRef(), camp: useRef(), clicks: useRef(), leads: useRef() };
    const logSpend = async () => {
      const g = (r) => r.current?.value || "";
      await api("desktop/spend", "POST", { date: g(refs.date), platform: g(refs.plat), amount: g(refs.amt), campaign: g(refs.camp), clicks: g(refs.clicks), leads: g(refs.leads) });
      cacheClear("desktop/spend");
      cacheClear("desktop/dashboard");
      rSpend(true);
      onRefresh();
    };
    const genDigest = async () => {
      setDigest("Generating...");
      const r = await api("desktop/digest/preview").catch(() => null);
      setDigest(r?.text || "Failed");
    };
    const actC = { family_created: V.purple, sms_sent: V.cyan, spend_logged: V.blue, message_received: V.blue, daily_digest_sent: V.orange, landing_lead_synced: V.green };
    return /* @__PURE__ */ jsxs(Fragment2, { children: [
      /* @__PURE__ */ jsxs(PageHeader, { title: "Analytics", children: [
        /* @__PURE__ */ jsx(Btn, { onClick: genDigest, children: "Generate Digest" }),
        digest && digest !== "Generating..." && /* @__PURE__ */ jsxs(Fragment2, { children: [
          /* @__PURE__ */ jsx(Btn, { variant: "ghost", onClick: () => navigator.clipboard.writeText(digest), children: "Copy" }),
          /* @__PURE__ */ jsx(Btn, { variant: "dark", onClick: () => api("desktop/digest/send", "POST"), children: "Send" })
        ] })
      ] }),
      /* @__PURE__ */ jsx("div", { style: { flex: 1, overflowY: "auto", padding: "20px 24px" }, children: /* @__PURE__ */ jsxs("div", { style: { display: "grid", gridTemplateColumns: "1fr 1fr 1fr", gap: 20 }, children: [
        /* @__PURE__ */ jsxs("div", { children: [
          /* @__PURE__ */ jsx(ST, { children: "Log Ad Spend" }),
          /* @__PURE__ */ jsxs(Card, { style: { padding: 12 }, children: [
            /* @__PURE__ */ jsxs("div", { style: { display: "grid", gridTemplateColumns: "1fr 1fr", gap: "0 8px" }, children: [
              /* @__PURE__ */ jsx(Input, { label: "Date", inputRef: refs.date, type: "date" }),
              /* @__PURE__ */ jsxs("div", { style: { marginBottom: 8 }, children: [
                /* @__PURE__ */ jsx("label", { style: lblStyle, children: "Platform" }),
                /* @__PURE__ */ jsxs("select", { ref: refs.plat, style: inputStyle, children: [
                  /* @__PURE__ */ jsx("option", { value: "meta", children: "Meta" }),
                  /* @__PURE__ */ jsx("option", { value: "google", children: "Google" })
                ] })
              ] }),
              /* @__PURE__ */ jsx(Input, { label: "Amount ($)", inputRef: refs.amt, type: "number", placeholder: "85" }),
              /* @__PURE__ */ jsx(Input, { label: "Campaign", inputRef: refs.camp }),
              /* @__PURE__ */ jsx(Input, { label: "Clicks", inputRef: refs.clicks, type: "number" }),
              /* @__PURE__ */ jsx(Input, { label: "Leads", inputRef: refs.leads, type: "number" })
            ] }),
            /* @__PURE__ */ jsx(Btn, { onClick: logSpend, style: { width: "100%" }, children: "Log Spend" })
          ] }),
          /* @__PURE__ */ jsx(ST, { children: "History" }),
          /* @__PURE__ */ jsx(Card, { style: { padding: 0, maxHeight: 260, overflowY: "auto" }, children: /* @__PURE__ */ jsxs("table", { style: { width: "100%", borderCollapse: "collapse" }, children: [
            /* @__PURE__ */ jsx("thead", { children: /* @__PURE__ */ jsx("tr", { children: ["Date", "Platform", "Amount", "Leads"].map((h) => /* @__PURE__ */ jsx("th", { style: { ...thStyle, padding: "6px 8px" }, children: h }, h)) }) }),
            /* @__PURE__ */ jsx("tbody", { children: spend.map((s, i) => /* @__PURE__ */ jsxs("tr", { style: { borderBottom: `1px solid ${V.border}` }, children: [
              /* @__PURE__ */ jsx("td", { style: { padding: "5px 8px", fontSize: 10 }, children: fd(s.spend_date) }),
              /* @__PURE__ */ jsx("td", { style: { padding: "5px 8px", fontSize: 10, textTransform: "uppercase", color: s.platform === "meta" ? V.blue : V.green }, children: s.platform }),
              /* @__PURE__ */ jsx("td", { style: { padding: "5px 8px", ...monoStyle, fontSize: 10 }, children: fm(s.amount) }),
              /* @__PURE__ */ jsx("td", { style: { padding: "5px 8px", ...monoStyle, fontSize: 10 }, children: s.conversions || s.leads || 0 })
            ] }, i)) })
          ] }) })
        ] }),
        /* @__PURE__ */ jsxs("div", { children: [
          /* @__PURE__ */ jsx(ST, { children: "Activity" }),
          /* @__PURE__ */ jsxs(Card, { style: { maxHeight: 440, overflowY: "auto", padding: 12 }, children: [
            activity.slice(0, 30).map((a, i) => /* @__PURE__ */ jsxs("div", { style: { display: "flex", gap: 8, padding: "5px 0", borderBottom: i < 29 ? `1px solid ${V.border}` : "none" }, children: [
              /* @__PURE__ */ jsx("div", { style: { width: 6, height: 6, borderRadius: "50%", marginTop: 5, flexShrink: 0, background: actC[a.action] || V.muted } }),
              /* @__PURE__ */ jsxs("div", { style: { flex: 1 }, children: [
                /* @__PURE__ */ jsxs("div", { style: { fontSize: 11 }, children: [
                  a.action,
                  a.detail ? ` -- ${(a.detail || "").slice(0, 80)}` : ""
                ] }),
                /* @__PURE__ */ jsxs("div", { style: { fontSize: 9, color: V.muted }, children: [
                  fd(a.created_at),
                  " ",
                  ft(a.created_at)
                ] })
              ] })
            ] }, i)),
            activity.length === 0 && /* @__PURE__ */ jsx(Empty, { text: "No activity" })
          ] })
        ] }),
        /* @__PURE__ */ jsxs("div", { children: [
          /* @__PURE__ */ jsx(ST, { children: "Daily Digest" }),
          /* @__PURE__ */ jsx(Card, { style: { maxHeight: 440, overflowY: "auto", padding: 12 }, children: digest ? /* @__PURE__ */ jsx("pre", { style: { fontSize: 11, lineHeight: 1.5, whiteSpace: "pre-wrap", wordBreak: "break-word", fontFamily: "'DM Sans',sans-serif" }, children: digest }) : /* @__PURE__ */ jsx(Empty, { text: "Click Generate" }) })
        ] })
      ] }) })
    ] });
  }
  function Settings() {
    const { data: health, loading: l1, reload } = useCachedFetch("desktop/health", [], 1e4);
    const { data: op } = useCachedFetch("openphone/settings", [], 6e4);
    const { data: cron } = useCachedFetch("cron-status", [], 6e4);
    return /* @__PURE__ */ jsxs(Fragment2, { children: [
      /* @__PURE__ */ jsx(PageHeader, { title: "Settings & Health", children: /* @__PURE__ */ jsx(Btn, { onClick: () => {
        cacheClear();
        reload(true);
      }, children: "Run Health Check" }) }),
      /* @__PURE__ */ jsxs("div", { style: { flex: 1, overflowY: "auto", padding: "20px 24px", maxWidth: 800 }, children: [
        l1 && !health ? /* @__PURE__ */ jsx(Skeleton, { rows: 6 }) : health && /* @__PURE__ */ jsxs(Card, { style: { marginBottom: 16 }, children: [
          /* @__PURE__ */ jsxs("div", { style: { display: "flex", alignItems: "center", gap: 10, marginBottom: 16 }, children: [
            /* @__PURE__ */ jsx("div", { style: { width: 12, height: 12, borderRadius: "50%", background: health.ok ? V.green : V.red } }),
            /* @__PURE__ */ jsx("span", { style: { ...headStyle, fontSize: 16, fontWeight: 700, textTransform: "uppercase" }, children: health.ok ? "All Systems OK" : "Issues Found" }),
            /* @__PURE__ */ jsxs("span", { style: { ...monoStyle, fontSize: 11, color: V.muted }, children: [
              "v",
              health.version
            ] })
          ] }),
          health.issues?.length > 0 && health.issues.map((iss, i) => /* @__PURE__ */ jsx("div", { style: { padding: "8px 10px", background: "#FFF3E0", border: `1px solid ${V.orange}`, marginBottom: 4, fontSize: 12, color: V.orange }, children: iss }, i)),
          /* @__PURE__ */ jsx(ST, { children: "Database Tables" }),
          /* @__PURE__ */ jsx("div", { style: { display: "grid", gridTemplateColumns: "1fr 1fr", gap: 4, marginBottom: 16 }, children: health.tables && Object.entries(health.tables).map(([t, ok]) => /* @__PURE__ */ jsxs("div", { style: { display: "flex", alignItems: "center", gap: 6, padding: "4px 0" }, children: [
            /* @__PURE__ */ jsx("div", { style: { width: 8, height: 8, borderRadius: "50%", background: ok ? V.green : V.red } }),
            /* @__PURE__ */ jsx("span", { style: { ...monoStyle, fontSize: 10 }, children: t })
          ] }, t)) })
        ] }),
        op && /* @__PURE__ */ jsxs(Card, { style: { marginBottom: 16 }, children: [
          /* @__PURE__ */ jsx(ST, { children: "OpenPhone / Quo" }),
          /* @__PURE__ */ jsxs("div", { style: { display: "flex", alignItems: "center", gap: 6 }, children: [
            /* @__PURE__ */ jsx("div", { style: { width: 8, height: 8, borderRadius: "50%", background: op.connected ? V.green : V.red } }),
            /* @__PURE__ */ jsxs("span", { style: { fontSize: 12 }, children: [
              "API ",
              op.connected ? "Connected" : op.has_key ? "Key Set" : "Not Configured"
            ] })
          ] }),
          op.account_name && /* @__PURE__ */ jsx("div", { style: { fontSize: 11, color: V.muted, marginLeft: 14 }, children: op.account_name })
        ] }),
        cron && /* @__PURE__ */ jsxs(Card, { children: [
          /* @__PURE__ */ jsx(ST, { children: "Cron Jobs" }),
          Array.isArray(cron) ? cron.map((c, i) => /* @__PURE__ */ jsxs("div", { style: { display: "flex", justifyContent: "space-between", padding: "6px 0", borderBottom: `1px solid ${V.border}` }, children: [
            /* @__PURE__ */ jsx("span", { style: { ...monoStyle, fontSize: 10 }, children: c.hook || c.name }),
            /* @__PURE__ */ jsx(Badge, { bg: c.active || c.scheduled ? V.green : V.muted, children: c.interval || c.schedule || "manual" })
          ] }, i)) : /* @__PURE__ */ jsx("div", { style: { fontSize: 11, color: V.muted }, children: "Cron status loaded" })
        ] })
      ] })
    ] });
  }
  function ContactPanel({ familyId, onClose, onRefresh, onNav }) {
    const [fam, setFam] = useState(null);
    const noteRef = useRef(null);
    useEffect(() => {
      if (familyId) api(`desktop/families/${familyId}`).then(setFam).catch(() => setFam(null));
    }, [familyId]);
    if (!familyId) return null;
    const st = (fam?.tags || []).find((t) => STAGES.some((s) => s.k === t)) || "";
    const changeStage = async (v) => {
      await api(`desktop/families/${familyId}`, "PUT", { stage: v });
      cacheClear("desktop/");
      onRefresh();
    };
    const addNote = async () => {
      const n = noteRef.current?.value?.trim();
      if (!n) return;
      noteRef.current.value = "";
      await api(`desktop/families/${familyId}`, "PUT", { note: n });
      setFam(await api(`desktop/families/${familyId}`));
    };
    return /* @__PURE__ */ jsxs(Fragment2, { children: [
      /* @__PURE__ */ jsx("div", { onClick: onClose, style: { position: "fixed", inset: 0, background: "rgba(0,0,0,.2)", zIndex: 99 } }),
      /* @__PURE__ */ jsx("div", { style: { position: "fixed", top: 0, right: 0, width: 480, height: "100vh", background: V.white, borderLeft: `3px solid ${V.gold}`, zIndex: 100, display: "flex", flexDirection: "column" }, children: !fam ? /* @__PURE__ */ jsx("div", { style: { padding: 40 }, children: /* @__PURE__ */ jsx(Skeleton, { rows: 6 }) }) : /* @__PURE__ */ jsxs(Fragment2, { children: [
        /* @__PURE__ */ jsxs("div", { style: { padding: "16px 20px", borderBottom: `2px solid ${V.border}`, display: "flex", justifyContent: "space-between", alignItems: "flex-start", flexShrink: 0 }, children: [
          /* @__PURE__ */ jsxs("div", { style: { display: "flex", gap: 14 }, children: [
            /* @__PURE__ */ jsx("div", { style: { width: 48, height: 48, background: V.gold, display: "flex", alignItems: "center", justifyContent: "center", ...headStyle, fontSize: 18, fontWeight: 700, flexShrink: 0 }, children: ini(fam.display_name) }),
            /* @__PURE__ */ jsxs("div", { children: [
              /* @__PURE__ */ jsx("div", { style: { fontSize: 18, fontWeight: 700 }, children: fam.display_name }),
              /* @__PURE__ */ jsxs("div", { style: { ...monoStyle, fontSize: 11, color: V.muted }, children: [
                fp(fam.phone),
                " / ",
                fam.email || "--"
              ] }),
              /* @__PURE__ */ jsxs("div", { style: { display: "flex", gap: 4, marginTop: 6, flexWrap: "wrap" }, children: [
                /* @__PURE__ */ jsx(Badge, { bg: sc(st), children: st || "--" }),
                (fam.tags || []).filter((t) => !STAGES.some((s) => s.k === t)).map((t, i) => /* @__PURE__ */ jsx("span", { style: { fontSize: 9, padding: "2px 6px", background: "#EDEDEB" }, children: t }, i))
              ] })
            ] })
          ] }),
          /* @__PURE__ */ jsx(Btn, { variant: "danger", onClick: onClose, style: { fontSize: 9, padding: "3px 8px" }, children: "Close" })
        ] }),
        /* @__PURE__ */ jsxs("div", { style: { flex: 1, overflowY: "auto", padding: 20 }, children: [
          /* @__PURE__ */ jsx(ST, { children: "Stage" }),
          /* @__PURE__ */ jsx("select", { style: { ...inputStyle, maxWidth: 200, marginBottom: 16 }, value: st, onChange: (e) => changeStage(e.target.value), children: STAGES.map((s) => /* @__PURE__ */ jsx("option", { value: s.k, children: s.k }, s.k)) }),
          (fam.children || []).length > 0 && /* @__PURE__ */ jsxs(Fragment2, { children: [
            /* @__PURE__ */ jsx(ST, { children: "Player" }),
            (fam.children || []).map((k, i) => /* @__PURE__ */ jsxs("div", { style: { marginBottom: 8 }, children: [
              /* @__PURE__ */ jsxs("div", { style: { fontSize: 13, fontWeight: 600 }, children: [
                k.first_name,
                k.age ? `, ${k.age}` : ""
              ] }),
              /* @__PURE__ */ jsxs("div", { style: { fontSize: 11, color: V.muted }, children: [
                k.club || "No club",
                k.position ? ` / ${k.position}` : ""
              ] })
            ] }, i))
          ] }),
          /* @__PURE__ */ jsx(ST, { children: "Lifetime Value" }),
          /* @__PURE__ */ jsx("div", { style: { ...headStyle, fontSize: 28, fontWeight: 700, color: (fam.total_spent || 0) > 0 ? V.green : V.muted, marginBottom: 16 }, children: fm(fam.total_spent || 0) }),
          /* @__PURE__ */ jsxs(ST, { children: [
            "Notes (",
            (fam.notes || []).length,
            ")"
          ] }),
          /* @__PURE__ */ jsx("div", { style: { maxHeight: 120, overflowY: "auto", marginBottom: 6 }, children: (fam.notes || []).map((n, i) => /* @__PURE__ */ jsx("div", { style: { fontSize: 11, padding: "4px 0", borderBottom: `1px solid ${V.border}` }, children: typeof n === "string" ? n : n.note_text || "" }, i)) }),
          /* @__PURE__ */ jsxs("div", { style: { display: "flex", gap: 4, marginBottom: 16 }, children: [
            /* @__PURE__ */ jsx("input", { ref: noteRef, placeholder: "Add note...", style: { ...inputStyle, flex: 1, padding: "4px 6px", fontSize: 11 }, onKeyDown: (e) => e.key === "Enter" && addNote() }),
            /* @__PURE__ */ jsx(Btn, { onClick: addNote, style: { padding: "3px 8px" }, children: "+" })
          ] }),
          /* @__PURE__ */ jsx(ST, { children: "Messages" }),
          /* @__PURE__ */ jsx("div", { style: { maxHeight: 120, overflowY: "auto", marginBottom: 8 }, children: (fam.messages || []).slice(0, 8).map((m, i) => /* @__PURE__ */ jsxs("div", { style: { padding: "3px 0", borderBottom: `1px solid ${V.border}` }, children: [
            /* @__PURE__ */ jsx(Badge, { bg: m.direction === "outgoing" ? V.gold : V.blue, children: m.direction === "outgoing" ? "OUT" : "IN" }),
            /* @__PURE__ */ jsx("span", { style: { fontSize: 11, marginLeft: 4 }, children: (m.body || "").slice(0, 60) })
          ] }, i)) }),
          /* @__PURE__ */ jsx(Btn, { onClick: () => {
            onClose();
            onNav("inbox");
            setTimeout(() => window.__openThread?.(fam.phone), 200);
          }, style: { width: "100%" }, children: "Open Thread" })
        ] })
      ] }) })
    ] });
  }
  var NAV = [
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
    { k: "settings", l: "Settings", i: "M19.14 12.94c.04-.3.06-.61.06-.94s-.02-.64-.07-.94l2.03-1.58a.49.49 0 00.12-.61l-1.92-3.32a.49.49 0 00-.59-.22l-2.39.96c-.5-.38-1.03-.7-1.62-.94l-.36-2.54a.484.484 0 00-.48-.41h-3.84c-.24 0-.43.17-.47.41l-.36 2.54c-.59.24-1.13.57-1.62.94l-2.39-.96c-.22-.08-.47 0-.59.22L2.74 8.87c-.12.21-.08.47.12.61l2.03 1.58c-.05.3-.07.62-.07.94s.02.64.07.94l-2.03 1.58a.49.49 0 00-.12.61l1.92 3.32c.12.22.37.29.59.22l2.39-.96c.5.38 1.03.7 1.62.94l.36 2.54c.05.24.24.41.48.41h3.84c.24 0 .44-.17.47-.41l.36-2.54c.59-.24 1.13-.56 1.62-.94l2.39.96c.22.08.47 0 .59-.22l1.92-3.32c.12-.22.07-.47-.12-.61l-2.01-1.58z" }
  ];
  function App() {
    const [tab, setTab] = useState("dashboard");
    const [families, setFamilies] = useState([]);
    const [convos, setConvos] = useState([]);
    const [loading, setLoading] = useState(true);
    const [cpId, setCpId] = useState(null);
    const [unread, setUnread] = useState(0);
    const pollRef = useRef(null);
    const loadCore = useCallback(async () => {
      setLoading(true);
      const [f, c] = await Promise.all([cachedApi("desktop/families", 3e4), cachedApi("desktop/conversations", 15e3)]);
      setFamilies(Array.isArray(f) ? f : []);
      setConvos(Array.isArray(c) ? c : []);
      const d = cacheGet("desktop/dashboard") || await cachedApi("desktop/dashboard", 15e3);
      setUnread(d?.unread || 0);
      setLoading(false);
    }, []);
    useEffect(() => {
      loadCore();
    }, [loadCore]);
    useEffect(() => {
      const poll = () => api("desktop/poll?since_id=0").then((r) => {
        if (r?.unread !== void 0) setUnread(r.unread);
      }).catch(() => {
      });
      pollRef.current = setInterval(poll, document.hasFocus() ? 1e4 : 3e4);
      const onFocus = () => {
        clearInterval(pollRef.current);
        pollRef.current = setInterval(poll, 1e4);
      };
      const onBlur = () => {
        clearInterval(pollRef.current);
        pollRef.current = setInterval(poll, 3e4);
      };
      window.addEventListener("focus", onFocus);
      window.addEventListener("blur", onBlur);
      return () => {
        clearInterval(pollRef.current);
        window.removeEventListener("focus", onFocus);
        window.removeEventListener("blur", onBlur);
      };
    }, []);
    const refresh = () => {
      cacheClear("desktop/");
      loadCore();
    };
    const renderTab = () => {
      const wrap = (C) => /* @__PURE__ */ jsx(ErrorBound, { children: C });
      switch (tab) {
        case "dashboard":
          return wrap(/* @__PURE__ */ jsx(Dashboard, { onNav: setTab, onOpenContact: setCpId }));
        case "pipeline":
          return wrap(/* @__PURE__ */ jsx(Pipeline, {}));
        case "contacts":
          return wrap(/* @__PURE__ */ jsx(Contacts, { families, onRefresh: refresh, onOpenContact: setCpId }));
        case "customer360":
          return wrap(/* @__PURE__ */ jsx(Customer360, {}));
        case "inbox":
          return wrap(/* @__PURE__ */ jsx(Inbox, { conversations: convos, onRefresh: refresh }));
        case "campaigns":
          return wrap(/* @__PURE__ */ jsx(Campaigns, {}));
        case "bookings":
          return wrap(/* @__PURE__ */ jsx(Bookings, {}));
        case "coaches":
          return wrap(/* @__PURE__ */ jsx(Coaches, {}));
        case "camps":
          return wrap(/* @__PURE__ */ jsx(Camps, {}));
        case "schedule":
          return wrap(/* @__PURE__ */ jsx(Schedule, {}));
        case "ai":
          return wrap(/* @__PURE__ */ jsx(AIEngine, {}));
        case "rules":
          return wrap(/* @__PURE__ */ jsx(Rules, {}));
        case "templates":
          return wrap(/* @__PURE__ */ jsx(Templates, {}));
        case "sequences":
          return wrap(/* @__PURE__ */ jsx(Sequences, {}));
        case "links":
          return wrap(/* @__PURE__ */ jsx(TrainingLinks, {}));
        case "finance":
          return wrap(/* @__PURE__ */ jsx(AttribFinance, {}));
        case "openphone":
          return wrap(/* @__PURE__ */ jsx(OpenPhone, {}));
        case "analytics":
          return wrap(/* @__PURE__ */ jsx(Analytics, { onRefresh: refresh }));
        case "settings":
          return wrap(/* @__PURE__ */ jsx(Settings, {}));
        default:
          return wrap(/* @__PURE__ */ jsx(Dashboard, { onNav: setTab, onOpenContact: setCpId }));
      }
    };
    return /* @__PURE__ */ jsxs("div", { style: { display: "flex", height: "100vh", fontFamily: "'DM Sans',-apple-system,sans-serif", background: V.bg, color: V.text, overflow: "hidden" }, children: [
      /* @__PURE__ */ jsx("style", { children: `
        @import url('https://fonts.googleapis.com/css2?family=Oswald:wght@400;500;600;700&family=DM+Sans:ital,wght@0,400;0,500;0,600;0,700&family=IBM+Plex+Mono:wght@400;500;600&display=swap');
        *{margin:0;padding:0;box-sizing:border-box}
        ::-webkit-scrollbar{width:5px}::-webkit-scrollbar-thumb{background:#C8C7C3}
        ::selection{background:${V.gold};color:${V.black}}
        @keyframes shimmer{0%{background-position:200% 0}100%{background-position:-200% 0}}
        button:active{transform:scale(.97)}button:disabled{opacity:.4;cursor:default}
      ` }),
      /* @__PURE__ */ jsx(CommandPalette, { onNav: setTab, onOpenContact: setCpId }),
      /* @__PURE__ */ jsxs("div", { style: { width: 190, background: V.black, display: "flex", flexDirection: "column", flexShrink: 0 }, children: [
        /* @__PURE__ */ jsxs("div", { style: { padding: "16px 12px 16px", borderBottom: "1px solid rgba(255,255,255,.08)" }, children: [
          /* @__PURE__ */ jsx("div", { style: { width: 32, height: 32, background: V.gold, display: "flex", alignItems: "center", justifyContent: "center", ...headStyle, fontSize: 14, fontWeight: 800, color: V.black, marginBottom: 6 }, children: "E" }),
          /* @__PURE__ */ jsx("div", { style: { ...headStyle, fontSize: 13, fontWeight: 700, color: V.white, letterSpacing: 0.5 }, children: "PTP ENGINE" }),
          /* @__PURE__ */ jsx("div", { style: { ...monoStyle, fontSize: 9, color: "rgba(255,255,255,.3)", marginTop: 2 }, children: "v3.1" })
        ] }),
        /* @__PURE__ */ jsx("div", { style: { flex: 1, padding: "6px 0", overflowY: "auto" }, children: NAV.map((n) => /* @__PURE__ */ jsxs("div", { onClick: () => setTab(n.k), style: { display: "flex", alignItems: "center", gap: 7, padding: "7px 12px", cursor: "pointer", borderLeft: `3px solid ${tab === n.k ? V.gold : "transparent"}`, background: tab === n.k ? "rgba(252,185,0,.06)" : "transparent", color: tab === n.k ? V.gold : "rgba(255,255,255,.4)", ...headStyle, fontSize: 9, fontWeight: 600, textTransform: "uppercase", letterSpacing: 1, transition: "all .1s" }, children: [
          /* @__PURE__ */ jsx("svg", { width: "13", height: "13", viewBox: "0 0 24 24", fill: "currentColor", style: { opacity: tab === n.k ? 1 : 0.5, flexShrink: 0 }, children: /* @__PURE__ */ jsx("path", { d: n.i }) }),
          n.l,
          n.k === "inbox" && unread > 0 && /* @__PURE__ */ jsx("span", { style: { marginLeft: "auto", ...monoStyle, fontSize: 9, fontWeight: 600, background: V.red, color: "#fff", padding: "1px 5px", minWidth: 16, textAlign: "center" }, children: unread })
        ] }, n.k)) }),
        /* @__PURE__ */ jsxs("div", { style: { padding: "10px 12px", borderTop: "1px solid rgba(255,255,255,.08)" }, children: [
          /* @__PURE__ */ jsxs("div", { style: { fontSize: 10, color: "rgba(255,255,255,.35)", display: "flex", alignItems: "center", gap: 6 }, children: [
            /* @__PURE__ */ jsx("span", { style: { width: 6, height: 6, borderRadius: "50%", background: V.green } }),
            USER
          ] }),
          /* @__PURE__ */ jsx("div", { style: { ...monoStyle, fontSize: 8, color: "rgba(255,255,255,.2)", marginTop: 4 }, children: "Cmd+K search" })
        ] })
      ] }),
      /* @__PURE__ */ jsx("div", { style: { flex: 1, display: "flex", flexDirection: "column", overflow: "hidden" }, children: loading && families.length === 0 ? /* @__PURE__ */ jsx("div", { style: { flex: 1, display: "flex", alignItems: "center", justifyContent: "center" }, children: /* @__PURE__ */ jsx(Skeleton, { rows: 4, style: { width: 300 } }) }) : renderTab() }),
      cpId && /* @__PURE__ */ jsx(ContactPanel, { familyId: cpId, onClose: () => setCpId(null), onRefresh: refresh, onNav: setTab })
    ] });
  }
  return __toCommonJS(ptp_app_exports);
})();
