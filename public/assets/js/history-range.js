// History - selector de rango (Flatpickr)
document.addEventListener("DOMContentLoaded", () => {
  const input = document.getElementById("dateRange");
  if (!input) return;

  flatpickr(input, {
    mode: "range",
    dateFormat: "d/m/Y",
    defaultDate: [new Date(), new Date()],
    allowInput: false,
    clickOpens: true,

    // opcional: mejora UX
    showMonths: 2,
    locale: {
      firstDayOfWeek: 1,
      rangeSeparator: " - ",
      weekdays: {
        shorthand: ["Dom","Lun","Mar","Mié","Jue","Vie","Sáb"],
        longhand: ["Domingo","Lunes","Martes","Miércoles","Jueves","Viernes","Sábado"]
      },
      months: {
        shorthand: ["Ene","Feb","Mar","Abr","May","Jun","Jul","Ago","Sep","Oct","Nov","Dic"],
        longhand: ["Enero","Febrero","Marzo","Abril","Mayo","Junio","Julio","Agosto","Septiembre","Octubre","Noviembre","Diciembre"]
      }
    },

    onClose: (selectedDates) => {
      // selectedDates: [inicio, fin]
      // Aquí luego podrás disparar el filtro/consulta al backend
      // console.log(selectedDates);
    }
  });
});