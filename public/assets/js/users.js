import { initStore, getStore, addUser, updateUser } from "./store.js";

initStore();
const $ = (q) => document.querySelector(q);

function roleBadge(role) {
  const r = String(role || "").toLowerCase();
  if (r.includes("super")) return `<span class="badge role-badge role-super">Superadmin</span>`;
  if (r.includes("admin")) return `<span class="badge role-badge role-admin">Administrador</span>`;
  return `<span class="badge role-badge role-user">Usuario</span>`;
}

function rowHTML(u) {
  return `
  <tr>
    <td class="th-center fw-bold">${String(u.id).padStart(3, "0")}</td>
    <td>${u.name || "—"}</td>
    <td>${u.area || "—"}</td>
    <td>${u.email || "—"}</td>
    <td>${roleBadge(u.role)}</td>
    <td class="th-center">
      <button class="icon-action js-pass" data-id="${u.id}" type="button" title="Cambiar password">
        <i class="fa-solid fa-key"></i>
      </button>
    </td>
  </tr>`;
}

function renderUsers() {
  const body = $("#usersBody");
  if (!body) return;

  const { users } = getStore();
  body.innerHTML = (users ?? []).map(rowHTML).join("");

  body.querySelectorAll(".js-pass").forEach(btn => {
    btn.addEventListener("click", () => openModifyPass(btn.dataset.id));
  });
}

/* Modal Create User (dinámico) */
function ensureCreateModal() {
  let el = document.getElementById("createUserModal");
  if (el) return el;

  el = document.createElement("div");
  el.className = "modal fade";
  el.id = "createUserModal";
  el.tabIndex = -1;
  el.innerHTML = `
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content modal-pro">
      <div class="modal-header">
        <h5 class="modal-title fw-bold">Create User</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <div class="row g-2">
          <div class="col-12">
            <label class="form-label">Nombre</label>
            <input id="cuName" class="form-control pro-input" type="text" placeholder="Nombre completo">
            <div class="invalid-feedback">Nombre obligatorio.</div>
          </div>
          <div class="col-12">
            <label class="form-label">Email</label>
            <input id="cuEmail" class="form-control pro-input" type="email" placeholder="correo@dominio.com">
            <div class="invalid-feedback">Email inválido.</div>
          </div>
          <div class="col-12">
            <label class="form-label">Area</label>
            <input id="cuArea" class="form-control pro-input" type="text" placeholder="IT Support / Marketing / ...">
            <div class="invalid-feedback">Área obligatoria.</div>
          </div>
          <div class="col-12">
            <label class="form-label">Rol</label>
            <select id="cuRole" class="form-select pro-input">
              <option value="Usuario">Usuario</option>
              <option value="Administrador">Administrador</option>
              <option value="Superadmin">Superadmin</option>
            </select>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancelar</button>
        <button id="cuSave" class="btn-pro" type="button">Guardar</button>
      </div>
    </div>
  </div>`;
  document.body.appendChild(el);

  el.querySelector("#cuSave").addEventListener("click", () => {
    const name = el.querySelector("#cuName");
    const email = el.querySelector("#cuEmail");
    const area = el.querySelector("#cuArea");
    const role = el.querySelector("#cuRole").value;

    const okName = name.value.trim().length >= 3;
    const okArea = area.value.trim().length >= 2;
    const okEmail = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim());

    name.classList.toggle("is-invalid", !okName);
    area.classList.toggle("is-invalid", !okArea);
    email.classList.toggle("is-invalid", !okEmail);

    if (!okName || !okArea || !okEmail) return;

    addUser({ name: name.value.trim(), email: email.value.trim(), area: area.value.trim(), role });
    bootstrap.Modal.getOrCreateInstance(el).hide();
    renderUsers();
  });

  return el;
}

/* Modal Generate Password (dinámico) */
function ensureGenModal() {
  let el = document.getElementById("genPassModal");
  if (el) return el;

  el = document.createElement("div");
  el.className = "modal fade";
  el.id = "genPassModal";
  el.tabIndex = -1;
  el.innerHTML = `
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content modal-pro">
      <div class="modal-header">
        <h5 class="modal-title fw-bold">Generate Password</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <p class="text-muted mb-2">Contraseña segura generada:</p>
        <div class="d-flex gap-2">
          <input id="gpValue" class="form-control pro-input" type="text" readonly>
          <button id="gpCopy" class="btn-pro btn-pro--sm" type="button">
            <i class="fa-regular fa-copy me-2"></i>Copy
          </button>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cerrar</button>
        <button id="gpRegen" class="btn-pro" type="button">Regenerar</button>
      </div>
    </div>
  </div>`;
  document.body.appendChild(el);

  const gen = () => {
    const chars = "ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%&*";
    let out = "RHR-";
    for (let i = 0; i < 10; i++) out += chars[Math.floor(Math.random() * chars.length)];
    el.querySelector("#gpValue").value = out;
  };

  el.addEventListener("shown.bs.modal", gen);
  el.querySelector("#gpRegen").addEventListener("click", gen);
  el.querySelector("#gpCopy").addEventListener("click", async () => {
    const v = el.querySelector("#gpValue").value;
    try { await navigator.clipboard.writeText(v); } catch {}
  });

  return el;
}

/* Modal Modify Password (simulado) */
function ensureModModal() {
  let el = document.getElementById("modPassModal");
  if (el) return el;

  el = document.createElement("div");
  el.className = "modal fade";
  el.id = "modPassModal";
  el.tabIndex = -1;
  el.innerHTML = `
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content modal-pro">
      <div class="modal-header">
        <h5 class="modal-title fw-bold">Modify Password</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Cerrar"></button>
      </div>
      <div class="modal-body">
        <input id="mpUserId" type="hidden">
        <label class="form-label">Nueva contraseña</label>
        <input id="mpPass" class="form-control pro-input" type="password" placeholder="••••••••">
        <div class="invalid-feedback d-block" id="mpErr" style="display:none;">Mínimo 8, mayúscula, número y símbolo.</div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-secondary" data-bs-dismiss="modal" type="button">Cancelar</button>
        <button id="mpSave" class="btn-pro" type="button">Guardar</button>
      </div>
    </div>
  </div>`;
  document.body.appendChild(el);

  el.querySelector("#mpSave").addEventListener("click", () => {
    const pass = el.querySelector("#mpPass").value;
    const ok = pass.length >= 8 && /[A-Z]/.test(pass) && /\d/.test(pass) && /[!@#$%&*]/.test(pass);
    el.querySelector("#mpErr").style.display = ok ? "none" : "block";
    if (!ok) return;

    const uid = Number(el.querySelector("#mpUserId").value);
    // Simulación: guardamos un flag, NO password real
    updateUser(uid, { lastPasswordChange: new Date().toISOString() });

    bootstrap.Modal.getOrCreateInstance(el).hide();
  });

  return el;
}

function openModifyPass(userId) {
  const el = ensureModModal();
  el.querySelector("#mpUserId").value = Number(userId);
  el.querySelector("#mpPass").value = "";
  el.querySelector("#mpErr").style.display = "none";
  bootstrap.Modal.getOrCreateInstance(el).show();
}

/* Botones top */
const btnCreate = $("#btnCreateUser");
if (btnCreate) btnCreate.addEventListener("click", () => bootstrap.Modal.getOrCreateInstance(ensureCreateModal()).show());

const btnGen = $("#btnGenPass");
if (btnGen) btnGen.addEventListener("click", () => bootstrap.Modal.getOrCreateInstance(ensureGenModal()).show());

renderUsers();
