const API = 'api.php';

let codigoActual = localStorage.getItem('fogon_codigo') || '';
let clienteActual = null;
let productos = [];
let rubroActivo = 'Todos';
let carrito = {}; // sku -> {producto, cantidad}

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
  const data = await llamar('catalogo', { codigo: codigoActual });
  productos = data.productos;
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
    const sinStock = p.stock_status === 'outofstock';
    const card = document.createElement('div');
    card.className = 'producto-fila' + (sinStock ? ' sin-stock' : '');

    const precioHtml = p.precio_descuento
      ? `<span class="precio-tachado">${money(p.precio_regular)}</span><span class="precio-actual">${money(p.precio_descuento)}</span>`
      : `<span class="precio-actual">${money(p.precio)}</span>`;

    const enCarrito = carrito[p.sku]?.cantidad || 0;

    card.innerHTML = `
      <div class="producto-info">
        <div class="producto-nombre">${escapeHtml(p.nombre)}</div>
        <div class="producto-sub">${escapeHtml([p.laboratorio, p.subrubro].filter(Boolean).join(' · '))}</div>
      </div>
      <div class="producto-precio-row">${precioHtml}</div>
      <div class="producto-footer">
        ${sinStock
          ? '<span class="badge-sin-stock">Sin stock</span>'
          : `<div class="qty-control">
               <button data-accion="menos">-</button>
               <input type="text" value="${enCarrito || 1}" data-qty readonly>
               <button data-accion="mas">+</button>
             </div>
             <button class="btn-agregar" data-accion="agregar">Agregar</button>`
        }
      </div>
    `;

    if (!sinStock) {
      const qtyInput = card.querySelector('[data-qty]');
      card.querySelector('[data-accion="menos"]').addEventListener('click', () => {
        qtyInput.value = Math.max(1, parseInt(qtyInput.value, 10) - 1);
      });
      card.querySelector('[data-accion="mas"]').addEventListener('click', () => {
        qtyInput.value = parseInt(qtyInput.value, 10) + 1;
      });
      card.querySelector('[data-accion="agregar"]').addEventListener('click', () => {
        agregarAlCarrito(p, parseInt(qtyInput.value, 10));
      });
    }

    cont.appendChild(card);
  });
}

function escapeHtml(s) {
  const d = document.createElement('div');
  d.textContent = s || '';
  return d.innerHTML;
}

// ---------------------------------------------------------------- carrito
function agregarAlCarrito(producto, cantidad) {
  const actual = carrito[producto.sku]?.cantidad || 0;
  carrito[producto.sku] = { producto, cantidad: actual + cantidad };
  actualizarCarritoUI();
}

function actualizarCarritoUI() {
  const items = Object.values(carrito);
  $('#carritoCount').textContent = items.reduce((s, i) => s + i.cantidad, 0);

  const cont = $('#carritoItems');
  cont.innerHTML = '';
  let total = 0;

  items.forEach(({ producto, cantidad }) => {
    const precio = producto.precio_descuento || producto.precio;
    total += precio * cantidad;
    const row = document.createElement('div');
    row.className = 'carrito-item';
    row.innerHTML = `
      <div>
        <div class="nombre">${escapeHtml(producto.nombre)}</div>
        <div class="detalle">${cantidad} x ${money(precio)}</div>
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

  $('#carritoTotal').textContent = money(total);
}

$('#btnCarrito').addEventListener('click', () => $('#panelCarrito').classList.remove('hidden'));
$('#btnCerrarCarrito').addEventListener('click', () => $('#panelCarrito').classList.add('hidden'));

$('#btnConfirmarPedido').addEventListener('click', async () => {
  const itemsCarrito = Object.values(carrito);
  const items = itemsCarrito.map(({ producto, cantidad }) => ({ sku: producto.sku, cantidad }));
  $('#carritoError').classList.add('hidden');
  if (items.length === 0) {
    $('#carritoError').textContent = 'Agrega al menos un producto.';
    $('#carritoError').classList.remove('hidden');
    return;
  }

  const notas = $('#notasPedido').value.trim();
  let total = 0;
  const resumen = itemsCarrito.map(({ producto, cantidad }) => {
    const precio = producto.precio_descuento || producto.precio;
    const subtotal = precio * cantidad;
    total += subtotal;
    return `${cantidad} x ${producto.nombre} - ${money(subtotal)}`;
  }).join('\n');

  const mensajeConfirmacion =
    `Vas a enviar este pedido:\n\n${resumen}\n\nTotal: ${money(total)}` +
    (notas ? `\nNotas: ${notas}` : '') +
    `\n\nConfirmar el envio?`;

  if (!window.confirm(mensajeConfirmacion)) {
    return;
  }

  try {
    const data = await llamar('pedido_crear', {
      codigo: codigoActual,
      items,
      notas,
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

function mostrarConfirmacion(pedido) {
  $('#confPedidoId').textContent = `Pedido #${pedido.id}`;
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
    row.innerHTML = `<span>${it.cantidad} x ${escapeHtml(it.nombre)}</span><span>${money(it.subtotal)}</span>`;
    cont.appendChild(row);
  });
  $('#confTotal').textContent = money(pedido.total);
  mostrarVista('#vistaConfirmacion');
}

$('#btnNuevoPedido').addEventListener('click', () => mostrarVista('#vistaCatalogo'));

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
    const data = await llamar('mis_pedidos', { codigo: codigoActual });
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

    const itemsHtml = (p.items || []).map((it) =>
      `<div class="pedido-item-row"><span>${it.cantidad} x ${escapeHtml(it.nombre)}</span><span>${money(it.subtotal)}</span></div>`
    ).join('');

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
        <div class="pedido-card-total">Total: <strong>${money(p.total)}</strong></div>
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
  doc.text(`Pedido #${pedido.id}`, margenIzq, y);
  y += 7;
  doc.setFontSize(10);
  doc.setTextColor(100);
  doc.text(fechaLegible(pedido.creado_en), margenIzq, y);
  if (clienteActual && clienteActual.nombre) {
    doc.text(`Cliente: ${clienteActual.nombre}`, margenDer, y, { align: 'right' });
  }
  doc.setTextColor(0);
  y += 10;

  doc.setFontSize(10);
  doc.setFont(undefined, 'bold');
  doc.text('Cant.', margenIzq, y);
  doc.text('Producto', margenIzq + 16, y);
  doc.text('Subtotal', margenDer, y, { align: 'right' });
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
    doc.text(money(it.subtotal), margenDer, y, { align: 'right' });
    y += 7;
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

  doc.setFontSize(12);
  doc.setFont(undefined, 'bold');
  doc.text(`Total: ${money(pedido.total)}`, margenDer, y, { align: 'right' });

  doc.save(`pedido-${pedido.id}.pdf`);
}
