document.querySelectorAll(".dropdown-menu .dropdown-item").forEach((btn) => {
  btn.addEventListener("click", () => {
    const value = btn.getAttribute("data-value");
    // Texto visible
    const textSpan = document.getElementById("catText");
    if (textSpan) textSpan.textContent = value;

    // Valor para backend
    const hidden = document.getElementById("category");
    if (hidden) hidden.value = value;
  });
});
