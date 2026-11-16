const ROLES = ['Administrador', 'Gerente de proyecto', 'Miembro del equipo'];
const ESTADOS = ['Pendiente', 'En proceso', 'Completado'];
const DB_KEY = 'taskline_db_v1';
const SESSION_KEY = 'taskline_session_v1';

const memory = {};
const store = {
  get(key) {
    try {
      return localStorage.getItem(key);
    } catch (error) {
      return memory[key] ?? null;
    }
  },
  set(key, value) {
    try {
      localStorage.setItem(key, value);
    } catch (error) {
      memory[key] = value;
    }
  },
  remove(key) {
    try {
      localStorage.removeItem(key);
    } catch (error) {
      delete memory[key];
    }
  }
};

let db = null;
let flashMessage = null;
let filterState = '';

async function sha(text) {
  if (window.crypto && window.crypto.subtle) {
    const buffer = await window.crypto.subtle.digest('SHA-256', new TextEncoder().encode(text));
    return Array.from(new Uint8Array(buffer)).map(b => b.toString(16).padStart(2, '0')).join('');
  }
  let hash = 5381;
  for (const char of text) {
    hash = ((hash << 5) + hash + char.charCodeAt(0)) >>> 0;
  }
  return 'x' + hash.toString(16);
}

function esc(value) {
  return String(value ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}

function load() {
  try {
    db = JSON.parse(store.get(DB_KEY));
  } catch (error) {
    db = null;
  }
}

function save() {
  store.set(DB_KEY, JSON.stringify(db));
}

async function seed() {
  db = {
    seq: { usuarios: 2, proyectos: 1, tareas: 1 },
    usuarios: [{ id: 1, nombre: 'Administrador', email: 'admin@local.test', hash: await sha('password'), rol: 'Administrador' }],
    proyectos: [],
    proyecto_usuarios: [],
    tareas: []
  };
  save();
}

function nextId(table) {
  const id = db.seq[table];
  db.seq[table] = id + 1;
  return id;
}

function findById(list, id) {
  return list.find(item => item.id === Number(id));
}

function currentUser() {
  const id = Number(store.get(SESSION_KEY));
  return id ? findById(db.usuarios, id) || null : null;
}

function requireRole(...roles) {
  const user = currentUser();
  if (!user) {
    throw new Error('Debes iniciar sesión.');
  }
  if (!roles.includes(user.rol)) {
    throw new Error('No tienes permiso para realizar esta acción.');
  }
  return user;
}

function isMember(projectId, userId) {
  return db.proyecto_usuarios.some(row => row.p === projectId && row.u === userId);
}

function canAccessProject(user, projectId) {
  return user.rol === 'Administrador' || isMember(projectId, user.id);
}

function membersOf(projectId) {
  return db.proyecto_usuarios
    .filter(row => row.p === projectId)
    .map(row => findById(db.usuarios, row.u))
    .filter(user => user && user.rol === 'Miembro del equipo')
    .sort((a, b) => a.nombre.localeCompare(b.nombre));
}

function ok(message) {
  flashMessage = { message, type: 'success' };
}

const actions = {
  async login(form) {
    const email = String(form.get('email') || '').trim().toLowerCase();
    const account = db.usuarios.find(user => user.email.toLowerCase() === email);
    if (account && account.hash === await sha(String(form.get('password') || ''))) {
      store.set(SESSION_KEY, String(account.id));
      return;
    }
    throw new Error('Correo o contraseña incorrectos.');
  },

  async logout() {
    store.remove(SESSION_KEY);
  },

  async create_user(form) {
    requireRole('Administrador');
    const nombre = String(form.get('nombre') || '').trim();
    const email = String(form.get('email') || '').trim();
    const password = String(form.get('password') || '');
    const rol = String(form.get('rol') || '');
    if (!nombre || nombre.length > 50) {
      throw new Error('Escribe un nombre válido (máximo 50 caracteres).');
    }
    if (!email || email.length > 100) {
      throw new Error('Escribe un correo válido.');
    }
    if (db.usuarios.some(user => user.email.toLowerCase() === email.toLowerCase())) {
      throw new Error('El correo ya está registrado.');
    }
    if (password.length < 8) {
      throw new Error('La contraseña debe tener al menos 8 caracteres.');
    }
    if (!ROLES.includes(rol)) {
      throw new Error('Selecciona un rol válido.');
    }
    db.usuarios.push({ id: nextId('usuarios'), nombre, email, hash: await sha(password), rol });
    ok('Usuario creado correctamente.');
  },

  async delete_user(form) {
    const admin = requireRole('Administrador');
    const id = Number(form.get('id'));
    if (id === admin.id) {
      throw new Error('No puedes eliminar tu propia cuenta.');
    }
    const projectIds = db.proyectos.filter(p => p.gerente_id === id).map(p => p.id);
    db.proyectos = db.proyectos.filter(p => !projectIds.includes(p.id));
    db.proyecto_usuarios = db.proyecto_usuarios.filter(row => row.u !== id && !projectIds.includes(row.p));
    db.tareas = db.tareas.filter(t => t.usuario_id !== id && t.creador_id !== id && !projectIds.includes(t.proyecto_id));
    db.usuarios = db.usuarios.filter(user => user.id !== id);
    ok('Usuario eliminado.');
  },

  async create_project(form) {
    requireRole('Administrador');
    const managerId = Number(form.get('gerente_id'));
    const manager = findById(db.usuarios, managerId);
    if (!manager || manager.rol !== 'Gerente de proyecto') {
      throw new Error('Selecciona un gerente válido.');
    }
    const nombre = String(form.get('nombre') || '').trim();
    if (!nombre || nombre.length > 100) {
      throw new Error('Escribe un nombre de proyecto válido.');
    }
    const id = nextId('proyectos');
    db.proyectos.push({ id, nombre, descripcion: String(form.get('descripcion') || '').trim().slice(0, 300), gerente_id: managerId });
    db.proyecto_usuarios.push({ p: id, u: managerId });
    ok('Proyecto creado y asignado al gerente.');
  },

  async add_member(form) {
    const user = requireRole('Gerente de proyecto', 'Administrador');
    const projectId = Number(form.get('proyecto_id'));
    if (!findById(db.proyectos, projectId) || !canAccessProject(user, projectId)) {
      throw new Error('No tienes acceso a ese proyecto.');
    }
    const memberId = Number(form.get('usuario_id'));
    const member = findById(db.usuarios, memberId);
    if (!member || member.rol !== 'Miembro del equipo') {
      throw new Error('Selecciona un miembro válido.');
    }
    if (!isMember(projectId, memberId)) {
      db.proyecto_usuarios.push({ p: projectId, u: memberId });
    }
    ok('Miembro agregado al proyecto.');
  },

  async save_task(form) {
    const user = requireRole('Gerente de proyecto', 'Administrador');
    const projectId = Number(form.get('proyecto_id'));
    if (!findById(db.proyectos, projectId) || !canAccessProject(user, projectId)) {
      throw new Error('No tienes acceso a ese proyecto.');
    }
    const memberId = Number(form.get('usuario_id'));
    if (!memberId || !isMember(projectId, memberId)) {
      throw new Error('El responsable debe pertenecer al proyecto.');
    }
    const titulo = String(form.get('titulo') || '').trim();
    if (!titulo || titulo.length > 100) {
      throw new Error('Escribe un título válido.');
    }
    const descripcion = String(form.get('descripcion') || '').trim().slice(0, 500);
    const fecha = String(form.get('fecha_limite') || '') || null;
    const taskId = Number(form.get('id'));
    if (taskId) {
      const task = db.tareas.find(t => t.id === taskId && t.proyecto_id === projectId);
      if (!task) {
        throw new Error('La tarea no pertenece al proyecto.');
      }
      Object.assign(task, { titulo, descripcion, usuario_id: memberId, fecha_limite: fecha });
      ok('Tarea actualizada.');
    } else {
      db.tareas.push({ id: nextId('tareas'), proyecto_id: projectId, titulo, descripcion, estado: 'Pendiente', usuario_id: memberId, creador_id: user.id, fecha_limite: fecha });
      ok('Tarea creada.');
    }
  },

  async delete_task(form) {
    const user = requireRole('Gerente de proyecto', 'Administrador');
    const task = findById(db.tareas, form.get('id'));
    if (!task || !canAccessProject(user, task.proyecto_id)) {
      throw new Error('No puedes eliminar esta tarea.');
    }
    db.tareas = db.tareas.filter(t => t.id !== task.id);
    ok('Tarea eliminada.');
  },

  async update_status(form) {
    const user = currentUser();
    if (!user) {
      throw new Error('Debes iniciar sesión.');
    }
    const state = String(form.get('estado') || '');
    if (!ESTADOS.includes(state)) {
      throw new Error('Estado no válido.');
    }
    const task = findById(db.tareas, form.get('id'));
    const allowed = task && (task.usuario_id === user.id || (user.rol !== 'Miembro del equipo' && isMember(task.proyecto_id, user.id)));
    if (!allowed) {
      throw new Error('No puedes actualizar esta tarea.');
    }
    task.estado = state;
    ok('Estado actualizado.');
  }
};

function visibleProjects(user) {
  const list = user.rol === 'Administrador'
    ? db.proyectos
    : db.proyectos.filter(p => isMember(p.id, user.id));
  return list
    .map(p => ({ ...p, gerente: (findById(db.usuarios, p.gerente_id) || {}).nombre || '—' }))
    .sort((a, b) => b.id - a.id);
}

function visibleTasks(user) {
  let list;
  if (user.rol === 'Administrador') {
    list = db.tareas;
  } else if (user.rol === 'Gerente de proyecto') {
    list = db.tareas.filter(t => isMember(t.proyecto_id, user.id));
  } else {
    list = db.tareas.filter(t => t.usuario_id === user.id);
  }
  return list
    .map(t => ({
      ...t,
      proyecto: (findById(db.proyectos, t.proyecto_id) || {}).nombre || '—',
      responsable: (findById(db.usuarios, t.usuario_id) || {}).nombre || '—'
    }))
    .sort((a, b) => b.id - a.id);
}

function formatDate(value) {
  if (!value) {
    return '—';
  }
  const [year, month, day] = value.split('-');
  return `${day}/${month}/${year}`;
}

function slug(value) {
  return value.toLowerCase().replace(/\s+/g, '-');
}

function flashHtml(flash, dismissible) {
  if (!flash) {
    return '';
  }
  const type = flash.type === 'error' ? 'danger' : 'success';
  if (dismissible) {
    return `<div class="alert alert-${type} alert-dismissible fade show">${esc(flash.message)}<button class="btn-close" data-bs-dismiss="alert"></button></div>`;
  }
  return `<div class="alert alert-${type}">${esc(flash.message)}</div>`;
}

function loginView(flash) {
  return `<main class="login-wrap"><section class="login-card"><span class="eyebrow">ESPACIO DE TRABAJO · 2025</span><h1>El trabajo<br><span>avanza aquí.</span></h1><p>Inicia sesión para revisar proyectos y tareas del equipo.</p>${flashHtml(flash, false)}<form data-action="login" class="login-form"><label>Correo electrónico<input type="email" name="email" placeholder="nombre@empresa.com" required></label><label>Contraseña<input type="password" name="password" placeholder="Ingresa tu contraseña" required></label><button class="btn btn-red w-100">Entrar al espacio <span>→</span></button></form><div class="demo-login"><span>CUENTA DE PRUEBA</span><div>admin@local.test <b>·</b> password</div></div></section><div class="login-graphic"><div class="graphic-ring"></div><div class="graphic-copy"><span>PLANEA · ASIGNA · AVANZA</span><strong>Las grandes<br>ideas se hacen<br><em>en equipo.</em></strong></div><div class="graphic-foot">PROYECTOS EN MOVIMIENTO <span>↗</span></div></div></main>`;
}

function taskRow(task, manager) {
  let cell;
  if (manager) {
    cell = `<button class="icon-button" title="Editar" onclick="editTask(${task.id})">✎</button><form data-action="delete_task" data-confirm="¿Eliminar esta tarea?"><input type="hidden" name="id" value="${task.id}"><button class="icon-button danger-text" title="Eliminar">×</button></form>`;
  } else {
    cell = `<form class="status-form" data-action="update_status"><input type="hidden" name="id" value="${task.id}"><select name="estado" class="form-select form-select-sm status-select">${ESTADOS.map(s => `<option ${task.estado === s ? 'selected' : ''}>${esc(s)}</option>`).join('')}</select></form>`;
  }
  return `<tr data-status="${esc(task.estado)}"><td><strong>${esc(task.titulo)}</strong><small>${esc(task.descripcion)}</small></td><td>${esc(task.proyecto)}</td><td><span class="avatar">${esc(task.responsable.charAt(0))}</span>${esc(task.responsable)}</td><td>${formatDate(task.fecha_limite)}</td><td><span class="status status-${slug(task.estado)}">${esc(task.estado)}</span></td><td class="actions">${cell}</td></tr>`;
}

function projectCard(project, manager) {
  const button = manager ? `<button class="text-button" onclick="openMemberModal(${project.id})">＋ Miembro</button>` : '';
  return `<article class="project-card"><div class="project-icon">▦</div><span class="project-number">PROYECTO ${String(project.id).padStart(2, '0')}</span><h3>${esc(project.nombre)}</h3><p>${esc(project.descripcion || 'Sin descripción')}</p><div class="project-bottom"><span>Gerente: ${esc(project.gerente)}</span>${button}</div></article>`;
}

function userRow(account, current) {
  const del = account.id !== current.id
    ? `<form data-action="delete_user" data-confirm="¿Eliminar este usuario?"><input type="hidden" name="id" value="${account.id}"><button class="icon-button danger-text">×</button></form>`
    : '';
  return `<tr><td><span class="avatar">${esc(account.nombre.charAt(0))}</span>${esc(account.nombre)}</td><td>${esc(account.email)}</td><td><span class="status status-neutral">${esc(account.rol)}</span></td><td>${del}</td></tr>`;
}

function modalHeader(eyebrow, title) {
  return `<div class="modal-header"><div><span class="eyebrow">${eyebrow}</span><h2 class="modal-title">${title}</h2></div><button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button></div>`;
}

function modalFooter(label) {
  return `<div class="modal-footer"><button type="button" class="btn btn-outline-light" data-bs-dismiss="modal">Cancelar</button><button class="btn btn-red">${label}</button></div>`;
}

function taskModal(projects) {
  return `<div class="modal fade" id="taskModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><form data-action="save_task" class="modal-content"><input type="hidden" name="id" id="taskId">${modalHeader('GESTIÓN DEL EQUIPO', 'Nueva tarea')}<div class="modal-body"><label>Proyecto<select name="proyecto_id" id="taskProject" class="form-select" required><option value="">Selecciona un proyecto</option>${projects.map(p => `<option value="${p.id}">${esc(p.nombre)}</option>`).join('')}</select></label><label>Título<input name="titulo" id="taskTitle" class="form-control" maxlength="100" required></label><label>Descripción<textarea name="descripcion" id="taskDescription" class="form-control" rows="3" maxlength="500"></textarea></label><label>Asignar a<select name="usuario_id" id="taskMember" class="form-select" required><option value="">Selecciona el proyecto primero</option></select></label><label>Fecha límite<input type="date" name="fecha_limite" id="taskDate" class="form-control"></label></div>${modalFooter('Guardar tarea')}</form></div></div>`;
}

function userModal() {
  return `<div class="modal fade" id="userModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><form data-action="create_user" class="modal-content">${modalHeader('ACCESOS Y PERMISOS', 'Nuevo usuario')}<div class="modal-body"><label>Nombre<input name="nombre" class="form-control" maxlength="50" required></label><label>Correo electrónico<input type="email" name="email" class="form-control" maxlength="100" required></label><label>Contraseña<input type="password" name="password" class="form-control" minlength="8" required></label><label>Rol<select name="rol" class="form-select" required>${ROLES.map(r => `<option value="${esc(r)}">${esc(r)}</option>`).join('')}</select></label></div>${modalFooter('Crear usuario')}</form></div></div>`;
}

function projectModal() {
  const managers = db.usuarios.filter(u => u.rol === 'Gerente de proyecto');
  return `<div class="modal fade" id="projectModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><form data-action="create_project" class="modal-content">${modalHeader('ORGANIZACIÓN', 'Nuevo proyecto')}<div class="modal-body"><label>Nombre<input name="nombre" class="form-control" maxlength="100" required></label><label>Descripción<textarea name="descripcion" class="form-control" rows="3" maxlength="300"></textarea></label><label>Gerente<select name="gerente_id" class="form-select" required><option value="">Selecciona un gerente</option>${managers.map(m => `<option value="${m.id}">${esc(m.nombre)}</option>`).join('')}</select></label></div>${modalFooter('Crear proyecto')}</form></div></div>`;
}

function memberModal() {
  const members = db.usuarios.filter(u => u.rol === 'Miembro del equipo').sort((a, b) => a.nombre.localeCompare(b.nombre));
  return `<div class="modal fade" id="memberModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><form data-action="add_member" class="modal-content"><input type="hidden" name="proyecto_id" id="memberProject">${modalHeader('EQUIPO DE PROYECTO', 'Agregar miembro')}<div class="modal-body"><label>Miembro<select name="usuario_id" class="form-select" required><option value="">Selecciona un miembro</option>${members.map(m => `<option value="${m.id}">${esc(m.nombre)}</option>`).join('')}</select></label></div>${modalFooter('Agregar al proyecto')}</form></div></div>`;
}

function dashboardView(user, flash) {
  const role = user.rol;
  const admin = role === 'Administrador';
  const manager = admin || role === 'Gerente de proyecto';
  const projects = visibleProjects(user);
  const tasks = visibleTasks(user);
  const users = admin ? [...db.usuarios].sort((a, b) => b.id - a.id) : [];
  const inProgress = tasks.filter(t => t.estado === 'En proceso').length;
  const done = tasks.filter(t => t.estado === 'Completado').length;

  const heading = `<div class="page-heading"><div><span class="eyebrow">PANEL DE CONTROL / ${esc(role.toUpperCase())}</span><h1>Hola, ${esc(user.nombre.split(' ')[0])} <span class="wave">✳</span></h1><p>Esto es lo que sucede en tu espacio de trabajo.</p></div>${manager ? '<button class="btn btn-red" data-bs-toggle="modal" data-bs-target="#taskModal" onclick="prepareTask()">＋ Nueva tarea</button>' : ''}</div>`;

  const stats = `<div class="stats-row"><article class="stat-card"><span>TAREAS ASIGNADAS</span><strong>${tasks.length}</strong><small>En tus proyectos</small></article><article class="stat-card"><span>EN PROCESO</span><strong>${inProgress}</strong><small>Trabajo activo</small></article><article class="stat-card"><span>COMPLETADAS</span><strong>${done}</strong><small>Buen trabajo</small></article><article class="stat-card accent-card"><span>PROYECTOS</span><strong>${projects.length}</strong><small>En seguimiento</small></article></div>`;

  const taskRows = tasks.length
    ? tasks.map(t => taskRow(t, manager)).join('')
    : '<tr><td colspan="6" class="empty-state">Aún no hay tareas. Cuando se asignen, aparecerán aquí.</td></tr>';

  const taskSection = `<section class="section-card"><div class="section-heading"><div><span class="eyebrow">SEGUIMIENTO</span><h2>Tareas del equipo</h2></div><div class="filter-wrap"><select id="statusFilter" class="form-select form-select-sm"><option value="">Todos los estados</option>${ESTADOS.map(s => `<option>${esc(s)}</option>`).join('')}</select></div></div><div class="table-responsive"><table class="table task-table"><thead><tr><th>TAREA</th><th>PROYECTO</th><th>RESPONSABLE</th><th>FECHA LÍMITE</th><th>ESTADO</th><th></th></tr></thead><tbody>${taskRows}</tbody></table></div></section>`;

  const projectCards = projects.length
    ? projects.map(p => projectCard(p, manager)).join('')
    : '<div class="empty-project">No tienes proyectos asignados todavía.</div>';

  const projectSection = `<section class="section-card project-section"><div class="section-heading"><div><span class="eyebrow">ORGANIZACIÓN</span><h2>Proyectos</h2></div>${admin ? '<button class="btn btn-light-outline btn-sm" data-bs-toggle="modal" data-bs-target="#projectModal">＋ Crear proyecto</button>' : ''}</div><div class="project-grid">${projectCards}</div></section>`;

  const userSection = admin
    ? `<section class="section-card"><div class="section-heading"><div><span class="eyebrow">ADMINISTRACIÓN</span><h2>Usuarios</h2></div><button class="btn btn-red btn-sm" data-bs-toggle="modal" data-bs-target="#userModal">＋ Nuevo usuario</button></div><div class="table-responsive"><table class="table task-table"><thead><tr><th>NOMBRE</th><th>CORREO</th><th>ROL</th><th></th></tr></thead><tbody>${users.map(a => userRow(a, user)).join('')}</tbody></table></div></section>`
    : '';

  const modals = (manager ? taskModal(projects) + memberModal() : '') + (admin ? userModal() + projectModal() : '');

  return `<main class="dashboard container-fluid">${heading}${flashHtml(flash, true)}${stats}${taskSection}${projectSection}${userSection}</main>${modals}`;
}

function applyFilter() {
  document.querySelectorAll('.task-table tbody tr[data-status]').forEach(row => {
    row.hidden = filterState !== '' && row.dataset.status !== filterState;
  });
}

function cleanModals() {
  document.querySelectorAll('.modal-backdrop').forEach(node => node.remove());
  document.body.classList.remove('modal-open');
  document.body.style.removeProperty('overflow');
  document.body.style.removeProperty('padding-right');
}

function render() {
  const flash = flashMessage;
  flashMessage = null;
  const user = currentUser();
  cleanModals();
  document.querySelector('#userMenu').innerHTML = user
    ? `<span class="user-name">${esc(user.nombre)}<small>${esc(user.rol)}</small></span><form data-action="logout"><button class="btn btn-outline-light btn-sm">Cerrar sesión</button></form>`
    : '';
  document.querySelector('#app').innerHTML = user ? dashboardView(user, flash) : loginView(flash);
  const select = document.querySelector('#statusFilter');
  if (select) {
    select.value = filterState;
    applyFilter();
  }
}

function populateMembers(selected) {
  const projectId = Number(document.querySelector('#taskProject').value);
  const select = document.querySelector('#taskMember');
  if (!projectId) {
    select.innerHTML = '<option value="">Selecciona el proyecto primero</option>';
    return;
  }
  select.innerHTML = '<option value="">Selecciona un miembro</option>' + membersOf(projectId).map(m => `<option value="${m.id}">${esc(m.nombre)}</option>`).join('');
  if (selected) {
    select.value = String(selected);
  }
}

function prepareTask() {
  document.querySelector('#taskId').value = '';
  document.querySelector('#taskTitle').value = '';
  document.querySelector('#taskDescription').value = '';
  document.querySelector('#taskDate').value = '';
  document.querySelector('#taskProject').value = '';
  document.querySelector('#taskMember').innerHTML = '<option value="">Selecciona el proyecto primero</option>';
  document.querySelector('#taskModal .modal-title').textContent = 'Nueva tarea';
}

function editTask(id) {
  const task = findById(db.tareas, id);
  if (!task) {
    return;
  }
  prepareTask();
  document.querySelector('#taskId').value = task.id;
  document.querySelector('#taskTitle').value = task.titulo;
  document.querySelector('#taskDescription').value = task.descripcion || '';
  document.querySelector('#taskDate').value = task.fecha_limite || '';
  document.querySelector('#taskProject').value = task.proyecto_id;
  populateMembers(task.usuario_id);
  document.querySelector('#taskModal .modal-title').textContent = 'Editar tarea';
  bootstrap.Modal.getOrCreateInstance(document.querySelector('#taskModal')).show();
}

function openMemberModal(projectId) {
  document.querySelector('#memberProject').value = projectId;
  bootstrap.Modal.getOrCreateInstance(document.querySelector('#memberModal')).show();
}

document.addEventListener('submit', async event => {
  const form = event.target.closest('form[data-action]');
  if (!form) {
    return;
  }
  event.preventDefault();
  if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
    return;
  }
  try {
    await actions[form.dataset.action](new FormData(form));
    save();
  } catch (error) {
    flashMessage = { message: error.message, type: 'error' };
  }
  render();
});

document.addEventListener('change', event => {
  if (event.target.id === 'statusFilter') {
    filterState = event.target.value;
    applyFilter();
  } else if (event.target.id === 'taskProject') {
    populateMembers();
  } else if (event.target.classList.contains('status-select')) {
    event.target.form.requestSubmit();
  }
});

(async function start() {
  load();
  if (!db || !Array.isArray(db.usuarios)) {
    await seed();
  }
  render();
})();
