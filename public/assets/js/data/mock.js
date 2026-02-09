window.RHR_MOCK = {
  tickets: [
    { id: 1, area: "IT Support", priority: "Media", status: "Abierto", assignedTo: "" },
    { id: 2, area: "Infraestructura", priority: "Alta", status: "En proceso", assignedTo: "Juan Pérez" },
    { id: 3, area: "Software", priority: "Baja", status: "En espera", assignedTo: "" },
    { id: 4, area: "Red", priority: "Urgente", status: "Resuelto", assignedTo: "María López" }
  ],
  users: [
    { id: 1, name: "Emilio Puigcerver", area: "IT Support", email: "emilio@rhr.com", role: "Administrador" },
    { id: 2, name: "María López", area: "Marketing", email: "maria@rhr.com", role: "Superadmin" },
    { id: 3, name: "Juan Pérez", area: "Infraestructura", email: "juan@rhr.com", role: "Usuario" }
  ]
};

// Datos iniciales (se copian a localStorage la primera vez)
window.RHR_MOCK = {
  tickets: [
    { id: 1, area: "IT Support", priority: "Media", status: "Abierto", assignedTo: "", description: "Laptop lenta", createdAt: "2026-02-10" },
    { id: 2, area: "Infraestructura", priority: "Alta", status: "En proceso", assignedTo: "Juan Pérez", description: "Cableado", createdAt: "2026-02-11" },
    { id: 3, area: "Software", priority: "Baja", status: "En espera", assignedTo: "", description: "Error app", createdAt: "2026-02-12" },
    { id: 4, area: "Red", priority: "Urgente", status: "Resuelto", assignedTo: "María López", description: "Sin internet", createdAt: "2026-02-13" }
  ],
  users: [
    { id: 1, name: "Emilio Puigcerver", area: "IT Support", email: "emilio@rhr.com", role: "Administrador" },
    { id: 2, name: "María López", area: "Marketing", email: "maria@rhr.com", role: "Superadmin" },
    { id: 3, name: "Juan Pérez", area: "Infraestructura", email: "juan@rhr.com", role: "Usuario" }
  ]
};
