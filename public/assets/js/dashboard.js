import { initStore, getStore, computeTicketStats } from "./store.js";

initStore();

function setVal(id, v) {
  const el = document.getElementById(id);
  if (el) el.textContent = String(v);
}

function renderDashboard() {
  const { tickets } = getStore();
  const s = computeTicketStats(tickets ?? []);

  setVal("dashTotal", s.total);
  setVal("dashAssigned", s.assigned);
  setVal("dashUnassigned", s.unassigned);
  setVal("dashInprogress", s.inprogress);
  setVal("dashDone", s.done);
}

renderDashboard();

// Opcional: refresca si otra pestaña cambia el store
window.addEventListener("storage", (e) => {
  if (e.key === "RHR_STORE_V1") renderDashboard();
});
