/**
 * tickets-actions.js
 * Lógica centralizada para los modales de evidencia y eliminación de tickets
 * Utilizado por tickets.php, ticket_edit.php, etc.
 */

(function(){
  // ========= Evidencia Viewer =========
  const evidenceModal = document.getElementById('evidenceModal');
  const evCanvas = document.getElementById('evCanvas');
  const evTicketCode = document.getElementById('evTicketCode');
  const evFilename = document.getElementById('evFilename');
  const evOpenNew = document.getElementById('evOpenNewTab') || document.getElementById('evOpenNew');
  const evDownload = document.getElementById('evDownload');

  const btnZoomOut = document.getElementById('evZoomOut');
  const btnZoomIn  = document.getElementById('evZoomIn');
  const btnReset   = document.getElementById('evZoomReset') || document.getElementById('evReset');
  const evidenceTitle = document.getElementById('evidenceTitle'); // Usado en edit_ticket
  const evImg = document.getElementById('evImg');
  const evPdf = document.getElementById('evPdf');

  let scale = 1;

  function safePath(p){
    if(!p) return '';
    if(p.includes('..')) return '';
    p = p.replaceAll('\\\\','/');
    p = p.replace(/^\/+/, '');
    if(p.startsWith('public/')) p = p.slice(7);
    return p.split('/').pop() || '';
  }

  function setZoomControls(enabled){
    if(btnZoomOut) btnZoomOut.disabled = !enabled;
    if(btnZoomIn) btnZoomIn.disabled = !enabled;
    if(btnReset) btnReset.disabled = !enabled;
  }

  function applyScale(){
    let targetImg = evImg || evCanvas?.querySelector('img');
    if(!targetImg) return;
    targetImg.style.transform = 'scale(' + scale.toFixed(2) + ')';
  }

  function resetZoom(){
    scale = 1;
    applyScale();
    if(evCanvas) evCanvas.classList.remove('is-zoomed');
  }

  function zoom(delta){
    scale = Math.max(0.4, Math.min(4, +(scale + delta).toFixed(2)));
    applyScale();
    if(evCanvas) {
      if(scale > 1) evCanvas.classList.add('is-zoomed');
      else evCanvas.classList.remove('is-zoomed');
    }
  }

  btnZoomOut?.addEventListener('click', () => zoom(-0.20));
  btnZoomIn?.addEventListener('click',  () => zoom(+0.20));
  btnReset?.addEventListener('click', resetZoom);

  evidenceModal?.addEventListener('show.bs.modal', function (event) {
    const trigger = event.relatedTarget;
    if(!trigger) return;

    // Dependiendo de si de llama desde tickets.php (formato A) o ticket_edit.php (formato B)
    const formatAFile = trigger.getAttribute('data-file');
    const formatBSrc  = trigger.getAttribute('data-ev-src');
    
    // reset global state
    resetZoom();

    if (formatBSrc !== null) {
      // Formato ticket_edit.php
      const src  = formatBSrc || '';
      const type = trigger.getAttribute('data-ev-type') || 'img';
      const name = trigger.getAttribute('data-ev-name') || 'Evidence';

      if(evidenceTitle) evidenceTitle.textContent = 'Evidence: ' + name;
      if(evOpenNew) evOpenNew.href = src;

      if(type === 'pdf'){
        if(evImg) evImg.style.display = 'none';
        if(evPdf) { evPdf.style.display = 'block'; evPdf.src = src; }
        setZoomControls(false);
      } else {
        if(evPdf) { evPdf.style.display = 'none'; evPdf.src = ''; }
        if(evImg) { evImg.style.display = 'block'; evImg.src = src; }
        setZoomControls(true);
      }

    } else {
      // Formato tickets.php
      const rawFile = formatAFile || '';
      const ticketId = trigger.getAttribute('data-ticket') || '';
      const file = safePath(rawFile);

      if(evTicketCode) evTicketCode.textContent = ticketId ? ('#' + ticketId) : '';
      if(evCanvas) evCanvas.innerHTML = '';
      
      if(!file){
        if(evFilename) evFilename.textContent = '';
        if(evCanvas) evCanvas.innerHTML = '<div class="alert alert-warning mb-0">Evidence not found.</div>';
        if(evOpenNew) evOpenNew.href = '#';
        if(evDownload) evDownload.href = '#';
        setZoomControls(false);
        return;
      }

      const url = 'api/download.php?file=' + encodeURIComponent(file);
      if(evFilename) evFilename.textContent = file;
      if(evOpenNew) evOpenNew.href = url;
      if(evDownload) evDownload.href = url;

      const ext = (url.split('.').pop() || '').toLowerCase();

      if(['png','jpg','jpeg','webp','gif'].includes(ext)){
        const iEl = document.createElement('img');
        iEl.src = url;
        iEl.alt = 'Evidence';
        if(evCanvas) evCanvas.appendChild(iEl);
        setZoomControls(true);
        iEl.addEventListener('load', resetZoom);
      } else if(ext === 'pdf'){
        const ifrEl = document.createElement('iframe');
        ifrEl.src = url;
        if(evCanvas) evCanvas.appendChild(ifrEl);
        setZoomControls(false);
      } else {
        setZoomControls(false);
        if(evCanvas) evCanvas.innerHTML = `
          <div class="alert alert-info mb-0">
            Preview not available for <b>.${ext || 'file'}</b>. You can open or download it.
          </div>
        `;
      }
    }
  });

  evidenceModal?.addEventListener('hidden.bs.modal', function(){
    resetZoom();
    if (evImg) evImg.src = '';
    if (evPdf) evPdf.src = '';
    
    // SI era de formato tickets.php (generaba elementos dinámicamente)
    if (!evImg && evCanvas) {
       evCanvas.innerHTML = '';
       if(evTicketCode) evTicketCode.textContent = '';
       if(evFilename) evFilename.textContent = '';
    }
  });

  // ===== Zoom con la rueda (scroll) =====
  if (evCanvas) {
    evCanvas.addEventListener('wheel', function(e){
      // Si estamos en modal de edit que esconde evImg
      if(evImg && evImg.style.display === 'none') return;
      // Si estamos en tickets.php y está renderizando un iframe
      if(!evImg && evCanvas.querySelector('iframe')) return;

      e.preventDefault();
      const delta = Math.sign(e.deltaY);
      zoom(delta > 0 ? -0.15 : 0.15);
    }, { passive:false });
  }

  // ========= Delete modal (usado globalmente) =========
  const deleteModal = document.getElementById('deleteModal');
  const delTicketId = document.getElementById('delTicketId');
  const delTicketCode = document.getElementById('delTicketCode');

  if (deleteModal) {
    deleteModal.addEventListener('show.bs.modal', function(event){
      const btn = event.relatedTarget;
      const id = btn?.getAttribute('data-id') || '';
      const code = btn?.getAttribute('data-ticket') || '';
      if(delTicketId) delTicketId.value = id;
      if(delTicketCode) delTicketCode.textContent = code ? ('#' + code) : '';
    });

    deleteModal.addEventListener('hidden.bs.modal', function(){
      if(delTicketId) delTicketId.value = '';
      if(delTicketCode) delTicketCode.textContent = '';
    });
  }

})();
