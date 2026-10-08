const API = 'api.php';

let codigoActual = localStorage.getItem('fogon_codigo') || '';
let clienteActual = null;
let productos = [];
let rubroActivo = 'Todos';
let carrito = {}; // sku -> {producto, cantidad}

function esInvitado() {
  return !!(clienteActual && clienteActual.invitado);
}

// Credenciales que se mandan en cada llamada: codigo de acceso o modo invitado.
function credenciales() {
  return esInvitado() ? { invitado: true } : { codigo: codigoActual };
}

// Cantidad maxima por pedido de un producto (null = sin limite).
function maxCant(p) {
  return p.max_cantidad === null || p.max_cantidad === undefined ? Infinity : p.max_cantidad;
}

let toastTimer = null;
function toast(msg) {
  let t = $('#toast');
  if (!t) {
    t = document.createElement('div');
    t.id = 'toast';
    t.className = 'toast';
    document.body.appendChild(t);
  }
  t.textContent = msg;
  t.classList.add('visible');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.classList.remove('visible'), 3500);
}

// false para el acceso "sin_precio": solo pedidos, sin ver ningun monto.
function verPrecio() {
  return !clienteActual || clienteActual.ver_precio !== false;
}

const STOCK_BADGES = {
  agotado: { clase: 'stock-rojo', dot: 'dot-rojo', texto: 'Consultar stock' },
  bajo: { clase: 'stock-amarillo', dot: 'dot-amarillo', texto: 'Stock limitado' },
  alto: { clase: 'stock-verde', dot: 'dot-verde', texto: 'En stock' },
};

function stockBadge(p) {
  const nivel = p.stock_status === 'outofstock' ? 'agotado' : (p.stock_nivel || 'alto');
  return STOCK_BADGES[nivel] || STOCK_BADGES.alto;
}

const $ = (sel) => document.querySelector(sel);
const money = (n) => '$ ' + Number(n || 0).toLocaleString('es-AR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

async function llamar(accion, body) {
  const res = await fetch(`${API}?accion=${accion}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body || {}),
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.error || 'Error de conexion.');
  return data;
}

function mostrarVista(id) {
  document.querySelectorAll('.vista').forEach((v) => v.classList.add('hidden'));
  $(id).classList.remove('hidden');
}

// ---------------------------------------------------------------- login
$('#formLogin').addEventListener('submit', async (e) => {
  e.preventDefault();
  const codigo = $('#inputCodigo').value.trim();
  $('#loginError').classList.add('hidden');
  if (!codigo) return;
  try {
    const data = await llamar('login', { codigo });
    codigoActual = codigo;
    clienteActual = data.cliente;
    localStorage.setItem('fogon_codigo', codigo);
    await entrarCatalogo();
  } catch (err) {
    $('#loginError').textContent = err.message;
    $('#loginError').classList.remove('hidden');
  }
});

$('#btnInvitado').addEventListener('click', async () => {
  $('#loginError').classList.add('hidden');
  try {
    const data = await llamar('login_invitado', {});
    codigoActual = '';
    clienteActual = data.cliente;
    await entrarCatalogo();
  } catch (err) {
    $('#loginError').textContent = err.message;
    $('#loginError').classList.remove('hidden');
  }
});

// Ajusta textos y campos segun sea cliente con codigo o invitado.
function aplicarModoInvitado() {
  const inv = esInvitado();
  $('#btnMisPedidos').classList.toggle('hidden', inv);
  $('#contactoInvitado').classList.toggle('hidden', !inv);
  $('#panelTitulo').textContent = inv ? 'Tu cotizaci\u00f3n' : 'Tu pedido';
  $('#btnConfirmarPedido').textContent = inv ? 'Solicitar cotizaci\u00f3n' : 'Confirmar pedido';
  $('#btnCarritoTexto').textContent = inv ? 'Cotizaci\u00f3n' : 'Carrito';
  $('#avisoInvitado').classList.toggle('hidden', !inv);
}

$('#btnSalir').addEventListener('click', () => {
  localStorage.removeItem('fogon_codigo');
  codigoActual = '';
  clienteActual = null;
  carrito = {};
  actualizarCarritoUI();
  $('#clienteInfo').classList.add('hidden');
  $('#inputCodigo').value = '';
  mostrarVista('#vistaLogin');
});

$('#btnVolverCatalogo').addEventListener('click', () => mostrarVista('#vistaCatalogo'));

async function entrarCatalogo() {
  const data = await llamar('catalogo', credenciales());
  productos = data.productos;
  aplicarModoInvitado();
  rubroActivo = 'Todos';
  $('#clienteNombre').textContent = clienteActual.nombre;
  $('#clienteInfo').classList.remove('hidden');
  renderRubros();
  renderProductos();
  mostrarVista('#vistaCatalogo');
}

// intento de login automatico si ya habia un codigo guardado
(async function initial() {
  if (codigoActual) {
    try {
      const data = await llamar('login', { codigo: codigoActual });
      clienteActual = data.cliente;
      await entrarCatalogo();
      return;
    } catch (e) {
      localStorage.removeItem('fogon_codigo');
      codigoActual = '';
    }
  }
  mostrarVista('#vistaLogin');
})();

// ---------------------------------------------------------------- rubros
function renderRubros() {
  const rubros = ['Todos', ...new Set(productos.map((p) => p.rubro || 'Sin categoria'))];
  const ul = $('#listaRubros');
  ul.innerHTML = '';
  rubros.forEach((r) => {
    const li = document.createElement('li');
    li.textContent = r;
    if (r === rubroActivo) li.classList.add('activo');
    li.addEventListener('click', () => {
      rubroActivo = r;
      renderRubros();
      renderProductos();
    });
    ul.appendChild(li);
  });
}

// ---------------------------------------------------------------- productos
$('#buscador').addEventListener('input', renderProductos);

function renderProductos() {
  const texto = $('#buscador').value.trim().toLowerCase();
  const cont = $('#listaProductos');
  cont.innerHTML = '';

  const filtrados = productos.filter((p) => {
    const okRubro = rubroActivo === 'Todos' || (p.rubro || 'Sin categoria') === rubroActivo;
    const okTexto = !texto || (p.nombre || '').toLowerCase().includes(texto) || (p.sku || '').toLowerCase().includes(texto);
    return okRubro && okTexto;
  });

  if (filtrados.length === 0) {
    cont.innerHTML = '<p class="muted">No se encontraron productos.</p>';
    return;
  }

  filtrados.forEach((p) => {
    const badge = stockBadge(p);
    const sinStock = badge === STOCK_BADGES.agotado;
    const card = document.createElement('div');
    card.className = 'producto-fila' + (sinStock ? ' sin-stock' : '');

    const precioHtml = !verPrecio() ? '' : (p.precio_descuento
      ? `<span class="precio-tachado">${money(p.precio_regular)}</span><span class="precio-actual">${money(p.precio_descuento)}</span>`
      : `<span class="precio-actual">${money(p.precio)}</span>`);

    const enCarrito = carrito[p.sku]?.cantidad || 0;
    const imgHtml = p.imagen_url
      ? `<img class="producto-img" src="${escapeHtml(p.imagen_url)}" alt="" loading="lazy">`
      : `<div class="producto-img producto-img-vacia"></div>`;

    card.innerHTML = `
      ${imgHtml}
      <div class="producto-info">
        <div class="producto-nombre"><span class="stock-dot ${badge.dot}" title="${badge.texto}"></span>${escapeHtml(p.nombre)}</div>
        <div class="producto-sub">${escapeHtml([p.laboratorio, p.subrubro].filter(Boolean).join(' · '))}</div>
        ${sinStock ? '<div class="sin-stock-aviso">Se pide sujeto a confirmacion de stock</div>' : ''}
        ${maxCant(p) !== Infinity ? `<div class="max-aviso">M\u00e1x. ${maxCant(p)} por pedido</div>` : ''}
      </div>
      ${verPrecio() ? `<div class="producto-precio-row">${precioHtml}</div>` : ''}
      <div class="producto-footer">
        <span class="stock-badge ${badge.clase}">${badge.texto}</span>
        <div class="qty-control">
          <button data-accion="menos">-</button>
          <input type="text" value="${enCarrito || 1}" data-qty readonly>
          <button data-accion="mas">+</button>
        </div>
        <button class="btn-agregar" data-accion="agregar">Agregar</button>
      </div>
    `;

    const qtyInput = card.querySelector('[data-qty]');
    card.querySelector('[data-accion="menos"]').addEventListener('click', (e) => {
      e.stopPropagation();
      qtyInput.value = Math.max(1, parseInt(qtyInput.value, 10) - 1);
    });
    card.querySelector('[data-accion="mas"]').addEventListener('click', (e) => {
      e.stopPropagation();
      const sig = parseInt(qtyInput.value, 10) + 1;
      if (sig > maxCant(p)) {
        toast(`M\u00e1ximo ${maxCant(p)} unidad(es) por pedido de este producto.`);
        return;
      }
      qtyInput.value = sig;
    });
    card.querySelector('[data-accion="agregar"]').addEventListener('click', (e) => {
      e.stopPropagation();
      agregarAlCarrito(p, parseInt(qtyInput.value, 10), sinStock);
    });
    card.addEventListener('click', () => abrirDetalleProducto(p, sinStock, badge));

    cont.appendChild(card);
  });
}

function escapeHtml(s) {
  const d = document.createElement('div');
  d.textContent = s || '';
  return d.innerHTML;
}

// ---------------------------------------------------------------- detalle de producto (modal)
function abrirDetalleProducto(p, sinStock, badge) {
  const img = $('#modalProductoImg');
  const imgVacia = $('#modalProductoImgVacia');
  if (p.imagen_url) {
    img.src = p.imagen_url;
    img.classList.remove('hidden');
    imgVacia.classList.add('hidden');
  } else {
    img.classList.add('hidden');
    imgVacia.classList.remove('hidden');
  }

  $('#modalProductoDot').className = `stock-dot ${badge.dot}`;
  $('#modalProductoDot').title = badge.texto;
  $('#modalProductoNombre').textContent = p.nombre || '';
  $('#modalProductoSub').textContent = [p.laboratorio, p.rubro, p.subrubro].filter(Boolean).join(' · ');
  $('#modalProductoDescripcion').textContent = p.descripcion || 'Sin descripcion disponible.';
  $('#modalProductoAviso').classList.toggle('hidden', !sinStock);
  $('#modalProductoBadge').className = `stock-badge ${badge.clase}`;
  $('#modalProductoBadge').textContent = badge.texto;

  const precioRow = $('#modalProductoPrecioRow');
  if (verPrecio()) {
    precioRow.classList.remove('hidden');
    precioRow.innerHTML = p.precio_descuento
      ? `<span class="precio-tachado">${money(p.precio_regular)}</span><span class="precio-actual">${money(p.precio_descuento)}</span>`
      : `<span class="precio-actual">${money(p.precio)}</span>`;
    if (p.iva_incluido) precioRow.innerHTML += '<span class="max-aviso">&nbsp;IVA incluido</span>';
  } else {
    precioRow.classList.add('hidden');
    precioRow.innerHTML = '';
  }

  const qtyInput = $('#modalQtyInput');
  qtyInput.value = carrito[p.sku]?.cantidad || 1;

  $('#modalQtyMenos').onclick = () => {
    qtyInput.value = Math.max(1, parseInt(qtyInput.value, 10) - 1);
  };
  $('#modalQtyMas').onclick = () => {
    const sig = parseInt(qtyInput.value, 10) + 1;
    if (sig > maxCant(p)) {
      toast(`M\u00e1ximo ${maxCant(p)} unidad(es) por pedido de este producto.`);
      return;
    }
    qtyInput.value = sig;
  };
  $('#modalProductoMax').textContent = maxCant(p) !== Infinity ? `M\u00e1x. ${maxCant(p)} por pedido` : '';
  $('#modalBtnAgregar').onclick = () => {
    agregarAlCarrito(p, parseInt(qtyInput.value, 10), sinStock);
    cerrarModalProducto();
  };

  $('#modalProducto').classList.remove('hidden');
}

function cerrarModalProducto() {
  $('#modalProducto').classList.add('hidden');
}

$('#btnCerrarModalProducto').addEventListener('click', cerrarModalProducto);
$('#modalProducto').addEventListener('click', (e) => {
  if (e.target.id === 'modalProducto') cerrarModalProducto();
});
document.addEventListener('keydown', (e) => {
  if (e.key === 'Escape') cerrarModalProducto();
});

// ---------------------------------------------------------------- carrito
function agregarAlCarrito(producto, cantidad, sinStock) {
  const actual = carrito[producto.sku]?.cantidad || 0;
  let nueva = actual + cantidad;
  if (nueva > maxCant(producto)) {
    nueva = maxCant(producto);
    toast(`M\u00e1ximo ${nueva} unidad(es) por pedido de "${producto.nombre}".`);
  }
  carrito[producto.sku] = { producto, cantidad: nueva, sinStock: !!sinStock };
  actualizarCarritoUI();
}

function actualizarCarritoUI() {
  const items = Object.values(carrito);
  $('#carritoCount').textContent = items.reduce((s, i) => s + i.cantidad, 0);

  const cont = $('#carritoItems');
  cont.innerHTML = '';
  let total = 0;

  items.forEach(({ producto, cantidad, sinStock }) => {
    const precio = producto.precio_descuento || producto.precio || 0;
    total += precio * cantidad;
    const detalle = verPrecio() ? `${cantidad} x ${money(precio)}` : `Cantidad: ${cantidad}`;
    const row = document.createElement('div');
    row.className = 'carrito-item';
    row.innerHTML = `
      <div>
        <div class="nombre">${escapeHtml(producto.nombre)}</div>
        <div class="detalle">${detalle}</div>
        ${sinStock ? '<div class="sin-stock-aviso">Sujeto a confirmacion de stock</div>' : ''}
      </div>
      <button class="quitar">Quitar</button>
    `;
    row.querySelector('.quitar').addEventListener('click', () => {
      delete carrito[producto.sku];
      actualizarCarritoUI();
      renderProductos();
    });
    cont.appendChild(row);
  });

  const totalRow = $('.carrito-total');
  if (totalRow) totalRow.classList.toggle('hidden', !verPrecio());
  $('#carritoTotal').textContent = money(total);
}

$('#btnCarrito').addEventListener('click', () => $('#panelCarrito').classList.remove('hidden'));
$('#btnCerrarCarrito').addEventListener('click', () => $('#panelCarrito').classList.add('hidden'));

$('#btnConfirmarPedido').addEventListener('click', async () => {
  const items = Object.values(carrito).map(({ producto, cantidad }) => ({ sku: producto.sku, cantidad }));
  $('#carritoError').classList.add('hidden');
  if (items.length === 0) {
    $('#carritoError').textContent = 'Agrega al menos un producto.';
    $('#carritoError').classList.remove('hidden');
    return;
  }
  const extra = {};
  if (esInvitado()) {
    extra.contacto_nombre = $('#contactoNombre').value.trim();
    extra.contacto_telefono = $('#contactoTelefono').value.trim();
    extra.contacto_email = $('#contactoEmail').value.trim();
    if (!extra.contacto_nombre || (!extra.contacto_telefono && !extra.contacto_email)) {
      $('#carritoError').textContent = 'Ingres\u00e1 tu nombre y un tel\u00e9fono o email de contacto.';
      $('#carritoError').classList.remove('hidden');
      return;
    }
  }
  try {
    const data = await llamar('pedido_crear', {
      ...credenciales(),
      ...extra,
      items,
      notas: $('#notasPedido').value.trim(),
    });
    mostrarConfirmacion(data.pedido);
    carrito = {};
    actualizarCarritoUI();
    $('#notasPedido').value = '';
    $('#panelCarrito').classList.add('hidden');
  } catch (err) {
    $('#carritoError').textContent = err.message;
    $('#carritoError').classList.remove('hidden');
  }
});

let pedidoConfirmadoActual = null;

function mostrarConfirmacion(pedido) {
  pedidoConfirmadoActual = pedido;
  const cot = pedido.tipo === 'cotizacion';
  $('#confTitulo').textContent = cot ? 'Cotizaci\u00f3n enviada' : 'Pedido enviado';
  $('#confMensajeCot').classList.toggle('hidden', !cot);
  $('.conf-qr').classList.toggle('hidden', cot);
  $('#confPedidoId').textContent = `${cot ? 'Cotizaci\u00f3n' : 'Pedido'} #${pedido.id}`;
  const fecha = new Date(pedido.creado_en.replace(' ', 'T'));
  $('#confFecha').textContent = fecha.toLocaleString('es-AR', {
    dateStyle: 'long',
    timeStyle: 'short',
  });
  const cont = $('#confItems');
  cont.innerHTML = '';
  pedido.items.forEach((it) => {
    const row = document.createElement('div');
    row.className = 'conf-item-row';
    const monto = verPrecio() ? `<span>${money(it.subtotal)}</span>` : '';
    const aviso = it.sin_stock_confirmar ? '<div class="sin-stock-aviso">Sujeto a confirmacion de stock</div>' : '';
    row.innerHTML = `<span>${it.cantidad} x ${escapeHtml(it.nombre)}${aviso}</span>${monto}`;
    cont.appendChild(row);
  });
  const totalRow = document.querySelector('.conf-total');
  if (totalRow) totalRow.classList.toggle('hidden', !verPrecio());
  $('#confTotal').textContent = money(pedido.total);
  mostrarVista('#vistaConfirmacion');
}

$('#btnNuevoPedido').addEventListener('click', () => mostrarVista('#vistaCatalogo'));
$('#btnDescargarPdfConf').addEventListener('click', () => {
  if (pedidoConfirmadoActual) exportarPedidoPDF(pedidoConfirmadoActual);
});

// ---------------------------------------------------------------- mis pedidos (seguimiento)
const ESTADO_LABELS = {
  nuevo: 'Nuevo',
  visto: 'Visto',
  preparando: 'Preparando',
  completado: 'Completado',
  cancelado: 'Cancelado',
};

function fechaLegible(creadoEn) {
  if (!creadoEn) return '';
  const fecha = new Date(creadoEn.replace(' ', 'T'));
  if (isNaN(fecha)) return creadoEn;
  return fecha.toLocaleString('es-AR', { dateStyle: 'long', timeStyle: 'short' });
}

let misPedidosCache = [];

$('#btnMisPedidos').addEventListener('click', async () => {
  const cont = $('#listaMisPedidos');
  cont.innerHTML = '<p class="muted">Cargando tus pedidos...</p>';
  mostrarVista('#vistaMisPedidos');
  try {
    const data = await llamar('mis_pedidos', credenciales());
    misPedidosCache = data.pedidos || [];
    renderMisPedidos(misPedidosCache);
  } catch (err) {
    cont.innerHTML = `<p class="error">${escapeHtml(err.message)}</p>`;
  }
});

function renderMisPedidos(pedidos) {
  const cont = $('#listaMisPedidos');
  cont.innerHTML = '';

  if (pedidos.length === 0) {
    cont.innerHTML = '<p class="muted">Todav&iacute;a no hiciste ning&uacute;n pedido.</p>';
    return;
  }

  pedidos.forEach((p) => {
    const card = document.createElement('div');
    card.className = 'pedido-card';

    const itemsHtml = (p.items || []).map((it) => {
      const aviso = it.sin_stock_confirmar ? '<div class="sin-stock-aviso">Sujeto a confirmacion de stock</div>' : '';
      return `<div class="pedido-item-row"><span>${it.cantidad} x ${escapeHtml(it.nombre)}${aviso}</span>${verPrecio() ? `<span>${money(it.subtotal)}</span>` : ''}</div>`;
    }).join('');

    const notasHtml = p.notas
      ? `<div class="pedido-notas"><strong>Notas:</strong> ${escapeHtml(p.notas)}</div>`
      : '';

    card.innerHTML = `
      <div class="pedido-card-header">
        <div>
          <div class="pedido-card-id">Pedido #${p.id}</div>
          <div class="pedido-card-fecha">${escapeHtml(fechaLegible(p.creado_en))}</div>
        </div>
        <span class="estado-badge estado-${escapeHtml(p.estado)}">${ESTADO_LABELS[p.estado] || p.estado}</span>
      </div>
      <div class="pedido-items">${itemsHtml}</div>
      ${notasHtml}
      <div class="pedido-card-footer">
        <button type="button" class="btn-link" data-accion="pdf-pedido" data-id="${p.id}">Descargar PDF</button>
        ${verPrecio() ? `<div class="pedido-card-total">Total: <strong>${money(p.total)}</strong></div>` : ''}
      </div>
    `;
    cont.appendChild(card);
  });
}

$('#listaMisPedidos').addEventListener('click', (e) => {
  const btn = e.target.closest('button[data-accion="pdf-pedido"]');
  if (!btn) return;
  const pedido = misPedidosCache.find((p) => String(p.id) === btn.dataset.id);
  if (!pedido) return;
  exportarPedidoPDF(pedido);
});

function exportarPedidoPDF(pedido) {
  const { jsPDF } = window.jspdf || {};
  if (!jsPDF) {
    alert('No se pudo generar el PDF. Volvé a intentar en un momento.');
    return;
  }
  const doc = new jsPDF();
  const margenIzq = 14;
  const margenDer = 196;
  let y = 20;

  doc.setFontSize(16);
  doc.text('El Fogón', margenIzq, y);
  y += 9;
  doc.setFontSize(13);
  doc.text(`${pedido.tipo === 'cotizacion' ? 'Solicitud de cotizaci\u00f3n' : 'Pedido'} #${pedido.id}`, margenIzq, y);
  y += 7;
  doc.setFontSize(10);
  doc.setTextColor(100);
  doc.text(fechaLegible(pedido.creado_en), margenIzq, y);
  const quien = pedido.tipo === 'cotizacion' ? pedido.cliente : (clienteActual && clienteActual.nombre);
  if (quien) {
    doc.text(`Cliente: ${quien}`, margenDer, y, { align: 'right' });
  }
  doc.setTextColor(0);
  y += 10;

  doc.setFontSize(10);
  doc.setFont(undefined, 'bold');
  doc.text('Cant.', margenIzq, y);
  doc.text('Producto', margenIzq + 16, y);
  if (verPrecio()) doc.text('Subtotal', margenDer, y, { align: 'right' });
  doc.setFont(undefined, 'normal');
  y += 2;
  doc.line(margenIzq, y, margenDer, y);
  y += 6;

  (pedido.items || []).forEach((it) => {
    if (y > 275) {
      doc.addPage();
      y = 20;
    }
    doc.text(String(it.cantidad), margenIzq, y);
    doc.text(String(it.nombre || ''), margenIzq + 16, y, { maxWidth: 130 });
    if (verPrecio()) doc.text(money(it.subtotal), margenDer, y, { align: 'right' });
    y += 7;
    if (it.sin_stock_confirmar) {
      doc.setFontSize(8);
      doc.setTextColor(180, 60, 40);
      doc.text('Sujeto a confirmacion de stock', margenIzq + 16, y);
      doc.setTextColor(0);
      doc.setFontSize(10);
      y += 6;
    }
  });

  y += 2;
  doc.line(margenIzq, y, margenDer, y);
  y += 8;

  if (pedido.notas) {
    doc.setFontSize(9);
    doc.setTextColor(100);
    doc.text(`Notas: ${pedido.notas}`, margenIzq, y, { maxWidth: margenDer - margenIzq });
    doc.setTextColor(0);
    y += 10;
  }

  if (verPrecio()) {
    doc.setFontSize(12);
    doc.setFont(undefined, 'bold');
    doc.text(`Total (IVA incl.): ${money(pedido.total)}`, margenDer, y, { align: 'right' });
  }

  doc.save(`${pedido.tipo === 'cotizacion' ? 'cotizacion' : 'pedido'}-${pedido.id}.pdf`);
}
