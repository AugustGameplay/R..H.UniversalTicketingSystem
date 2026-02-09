import { initStore, getStore, updateTicket } from "./store.js";

initStore();

const $ = (q) => document.querySelector(q);

function badgePriority(p) {
  const v = String(p || "").toLowerCase();
  if (v.includes("urg")) return `<span class="badge badge-prio prio-urgent">Urgente</span>`;
  if (v.includes("alta")) return `<span class="badge badge-prio prio-high">Alta</span>`;
  if (v.includes("media")) return `<span class="badge badge-prio prio-medium">Media</span>`;
  return `<span class="badge badge-prio prio-low">Baja</span>`;
}

function badgeStatus(s) {
  const v = String(s || "").toLowerCase();
  if (v.includes("abiert")) return `<span class="badge badge-status st-open">Abierto</span>`;
  if (v.includes("proceso")) return `<span class="badge badge-status st-progress">En proceso</span>`;
  if (v.includes("espera")) return `<span class="badge badge-status st-wait">En espera</span>`;
  if (v.includes("cancel")) return `<span class="badge badge-status st-cancel">Cancelado</span>`;
  if (v.includes("resuelt") || v.includes("finaliz") || v.includes("done")) return `<span class="badge badge-status st-done">Resuelto</span>`;
  return `<span class="badge badge-status st-wait">${s}</span>`;
}

function rowHTML(t) {
  return `
  <tr>
    <td class="th-center fw-bold">${String(t.id).padStart(3, "0")}</td>
    <td>${t.area || "—"}</td>
    <td>${badgePriority(t.priority)}</td>
    <td>${badgeStatus(t.status)}</td>
    <td>${(t.assignedTo || "—")}</td>
    <td class="th-center">
      <button class="icon-action js-edit-ticket" data-id="${t.id}" type="button" title="Editar">
        <i class="fa-regular fa-pen-to-square"></i>
      </button>
    </td>
  </tr>`;
}

function renderTickets(filterText = "") {
  const body = $("#ticketsBody");
  if (!body) return;

  const { tickets } = getStore();
  const q = filterText.trim().toLowerCase();

  const list = (tickets ?? []).filter(t => {
    if (!q) return true;
    const hay = `${t.id} ${t.area} ${t.priority} ${t.status} ${t.assignedTo} ${t.description}`.toLowerCase();
    return hay.includes(q);
  });

  body.innerHTML = list.map(rowHTML).join("");

  body.querySelectorAll(".js-edit-ticket").forEach(btn => {
    btn.addEventListener("click", () => openEditModal(btn.dataset.id));
  });
}

/* Modal dinámico (Bootstrap) */
function ensureModal() {
  let el = document.getElementById("editTicketModal");
  if (el) return el;

  el = document.createElement("div");
  el.className = "modal fade";
  el.id = "editTicketModal";
  el.tabIndex = -1;
  el.innerHTML = `
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content modal-pro">
        <div class="modal-header">
          <h5 class="modal-title fw-bold">Editar Ticket</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
        </div>
        <div class="modal-body">
          <div class="row g-2">
            <div class="col-12">
              <label class="form-label">Asignado a</label>
              <input id="mtAssigned" class="form-control pro-input" type="text" placeholder="Nombre del encargado">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Priority</label>
              <select id="mtPriority" class="form-select pro-input">
                <option>Baja</option><option>Media</option><option>Alta</option><option>Urgente</option>
              </select>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Status</label>
              <select id="mtStatus" class="form-select pro-input">
                <option>Abierto</option><option>En proceso</option><option>En espera</option>
                <option>Resuelto</option><option>Cancelado</option>
              </select>
            </div>
          </div>
          <input id="mtId" type="hidden" />
        </div>
        <div class="modal-footer">
          <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancelar</button>
          <button id="mtSave" class="btn-pro" type="button">Guardar</button>
        </div>
      </div>
    </div>
  `;
  document.body.appendChild(el);

  el.querySelector("#mtSave").addEventListener("click", () => {
    const id = Number(el.querySelector("#mtId").value);
    const assignedTo = el.querySelector("#mtAssigned").value.trim();
    const priority = el.querySelector("#mtPriority").value;
    const status = el.querySelector("#mtStatus").value;

    updateTicket(id, { assignedTo, priority, status });

    // cerrar modal
    const m = bootstrap.Modal.getOrCreateInstance(el);
    m.hide();

    // re-render
    const search = $("#ticketsSearch");
    renderTickets(search ? search.value : "");
  });

  return el;
}

function openEditModal(id) {
  const el = ensureModal();
  const { tickets } = getStore();
  const t = (tickets ?? []).find(x => Number(x.id) === Number(id));
  if (!t) return;

  el.querySelector("#mtId").value = t.id;
  el.querySelector("#mtAssigned").value = t.assignedTo || "";
  el.querySelector("#mtPriority").value = t.priority || "Media";
  el.querySelector("#mtStatus").value = t.status || "Abierto";

  bootstrap.Modal.getOrCreateInstance(el).show();
}

/* Search */
const search = $("#ticketsSearch");
if (search) {
  search.addEventListener("input", () => renderTickets(search.value));
}

renderTickets();