const form = document.getElementById("loginForm");
const email = document.getElementById("email");
const pass = document.getElementById("password");

const emailHelp = document.getElementById("emailHelp");
const passHelp = document.getElementById("passHelp");
const msg = document.getElementById("msg");

function setFieldState(input, helpEl, ok, text){
  input.classList.remove("is-error","is-ok");
  helpEl.classList.remove("error","ok");

  if(ok){
    input.classList.add("is-ok");
    helpEl.classList.add("ok");
  } else {
    input.classList.add("is-error");
    helpEl.classList.add("error");
  }
  helpEl.textContent = text;
}

function validateEmail(){
  const v = email.value.trim();

  if(v === ""){
    setFieldState(email, emailHelp, false, "El correo es obligatorio.");
    return false;
  }

  const ok = /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v);
  if(!ok){
    setFieldState(email, emailHelp, false, "Ingresa un correo válido (ej. nombre@dominio.com).");
    return false;
  }

  setFieldState(email, emailHelp, true, "Correcto.");
  return true;
}

function validatePass(){
  const v = pass.value.trim();

  if(v === ""){
    setFieldState(pass, passHelp, false, "La contraseña es obligatoria.");
    return false;
  }

  if(v.length < 6){
    setFieldState(pass, passHelp, false, "Mínimo 6 caracteres.");
    return false;
  }

  setFieldState(pass, passHelp, true, "Correcto.");
  return true;
}

function showMsg(type, text){
  msg.className = "msg";
  if(type) msg.classList.add(type);
  msg.textContent = text || "";
}

// Validación en vivo
email.addEventListener("input", () => { validateEmail(); showMsg("", ""); });
pass.addEventListener("input", () => { validatePass(); showMsg("", ""); });

// Envío
form.addEventListener("submit", (e) => {
  e.preventDefault();
  showMsg("", "");

  const ok1 = validateEmail();
  const ok2 = validatePass();

  if(!ok1 || !ok2){
    showMsg("error", "Revisa los campos marcados.");
    return;
  }

  // Front-only: listo para conectar a backend con fetch()
  showMsg("ok", "Validación correcta. (Listo para conectar a backend)");
});
