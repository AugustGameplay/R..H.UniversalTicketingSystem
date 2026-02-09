import { initStore, getStore, computeTicketStats } from "./store.js";

initStore();
const $ = (q) => document.querySelector(q);

function parseDMY(dmy) {
  // "dd/mm/yyyy"
  const [dd, mm, yy] = String(dmy).split("/").map(Number);
  if (!dd || !mm || !yy) return null;
  return new Date(yy, mm - 1, dd);
}

function inRange(dateISO, start, end) {
  const d = new Date(dateISO + "T00:00:00");
  return d >= start && d <= end;
}

function setStats(stats) {
  const map = {
    statTotal: stats.total,
    statAssigned: stats.assigned,
    statUnassigned: stats.unassigned,
    statInprogress: stats.inprogress,
    statDone: stats.done
  };

  Object.entries(map).forEach(([id, val]) => {
    const el = document.getElementById(id);
    if (el) el.textContent = String(val);
  });
}

function getTicketsFilteredByRange(start, end) {
  const { tickets } = getStore();
  return (tickets ?? []).filter(t => inRange(t.createdAt, start, end));
}

function updateByRange(start, end) {
  const filtered = getTicketsFilteredByRange(start, end);
  setStats(computeTicketStats(filtered));
}

/* Init rango */
const input = $("#dateRange");
if (input && window.flatpickr) {
  const today = new Date();
  const start = new Date(today);
  start.setDate(today.getDate() - 7);

  const fp = flatpickr(input, {
    mode: "range",
    dateFormat: "d/m/Y",
    defaultDate: [start, today],
    showMonths: 2,
    onClose: (selectedDates) => {
      if (!selectedDates || selectedDates.length < 2) return;
      updateByRange(selectedDates[0], selectedDates[1]);
    }
  });

  // primer render con defaultDate
  const d0 = fp.selectedDates?.[0] ?? start;
  const d1 = fp.selectedDates?.[1] ?? today;
  updateByRange(d0, d1);

} else {
  // fallback: si no hay flatpickr, muestra todos
  const { tickets } = getStore();
  setStats(computeTicketStats(tickets ?? []));
}

/* Download CSV */
const btn = $("#btnDownloadHistory");
if (btn) {
  btn.addEventListener("click", () => {
    const { tickets } = getStore();
    const rows = [["id","area","priority","status","assignedTo","createdAt","description"]]
      .concat((tickets ?? []).map(t => [
        t.id, t.area, t.priority, t.status, t.assignedTo, t.createdAt, (t.description || "").replaceAll("\n"," ")
      ]));

    const csv = rows.map(r => r.map(v => `"${String(v ?? "").replaceAll('"','""')}"`).join(",")).join("\n");
    const blob = new Blob([csv], { type: "text/csv;charset=utf-8;" });
    const url = URL.createObjectURL(blob);

    const a = document.createElement("a");
    a.href = url;
    a.download = "history_tickets.csv";
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  });
}
