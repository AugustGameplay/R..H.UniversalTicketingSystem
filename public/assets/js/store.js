// Store sin BD: localStorage + helpers
const KEY = "RHR_STORE_V1";

/* Utils */
const todayISO = () => new Date().toISOString().slice(0, 10);

function normalizeTicket(t) {
  // migraciones/compat: si falta createdAt, lo pone
  return {
    id: Number(t.id),
    area: t.area ?? "",
    priority: t.priority ?? "Media",
    status: t.status ?? "Abierto",
    assignedTo: t.assignedTo ?? "",
    description: t.description ?? "",
    createdAt: t.createdAt ?? todayISO()
  };
}

export function initStore() {
  const raw = localStorage.getItem(KEY);
  if (!raw) {
    const seed = window.RHR_MOCK ?? { tickets: [], users: [] };
    seed.tickets = (seed.tickets ?? []).map(normalizeTicket);
    seed.users = seed.users ?? [];
    localStorage.setItem(KEY, JSON.stringify(seed));
    return;
  }

  // Si ya existe, normaliza tickets por si cambiaste estructura
  try {
    const s = JSON.parse(raw);
    s.tickets = (s.tickets ?? []).map(normalizeTicket);
    s.users = s.users ?? [];
    localStorage.setItem(KEY, JSON.stringify(s));
  } catch {
    localStorage.removeItem(KEY);
    initStore();
  }
}

export function getStore() {
  const raw = localStorage.getItem(KEY);
  if (!raw) return { tickets: [], users: [] };
  try { return JSON.parse(raw); } catch { return { tickets: [], users: [] }; }
}

export function setStore(next) {
  localStorage.setItem(KEY, JSON.stringify(next));
}

/* CRUD Tickets */
export function addTicket(ticket) {
  const s = getStore();
  const nextId = Math.max(0, ...(s.tickets ?? []).map(t => Number(t.id))) + 1;

  const newT = normalizeTicket({ id: nextId, ...ticket, createdAt: ticket.createdAt ?? todayISO() });
  s.tickets = [...(s.tickets ?? []), newT];

  setStore(s);
  return nextId;
}

export function updateTicket(id, patch) {
  const s = getStore();
  const tid = Number(id);
  s.tickets = (s.tickets ?? []).map(t => (Number(t.id) === tid ? normalizeTicket({ ...t, ...patch }) : normalizeTicket(t)));
  setStore(s);
}

export function deleteTicket(id) {
  const s = getStore();
  const tid = Number(id);
  s.tickets = (s.tickets ?? []).filter(t => Number(t.id) !== tid).map(normalizeTicket);
  setStore(s);
}

/* CRUD Users */
export function addUser(user) {
  const s = getStore();
  const nextId = Math.max(0, ...(s.users ?? []).map(u => Number(u.id))) + 1;

  const newU = {
    id: nextId,
    name: user.name ?? "",
    area: user.area ?? "",
    email: user.email ?? "",
    role: user.role ?? "Usuario"
  };

  s.users = [...(s.users ?? []), newU];
  setStore(s);
  return nextId;
}

export function updateUser(id, patch) {
  const s = getStore();
  const uid = Number(id);
  s.users = (s.users ?? []).map(u => (Number(u.id) === uid ? { ...u, ...patch } : u));
  setStore(s);
}

/* Stats */
export function computeTicketStats(tickets) {
  const list = tickets ?? [];
  const total = list.length;
  const assigned = list.filter(t => (t.assignedTo ?? "").trim() !== "").length;
  const unassigned = total - assigned;
  const inprogress = list.filter(t => String(t.status).toLowerCase().includes("proceso")).length;
  const done = list.filter(t => ["resuelto", "finalizado", "done"].includes(String(t.status).toLowerCase())).length;

  return { total, assigned, unassigned, inprogress, done };
}